<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;

/**
 * Single writer for effective-dated working-time-model assignments.
 *
 * Every mutation of `at_user_models` goes through this service — the new
 * assignment endpoints AND the legacy single-row profile payload
 * ({@see applyLegacyAssignmentPayload}) — so the guard stack is identical
 * on every path: user-profile lock → wtm catalog shared lock →
 * month-closure tier locks → atomic transaction → audit → stats-cache
 * invalidation (caller) → paid-break recompute enqueue (caller).
 *
 * Guards enforced here:
 *  - overlap: only the immediate predecessor of a CREATE is auto-ended at
 *    ab−1; every other collision → 409 ASSIGNMENT_OVERLAP with the row for
 *    edit-recovery. PATCH never auto-ends.
 *  - empty window (end < start) → 400 ASSIGNMENT_EMPTY_WINDOW.
 *  - delete only for never-effective windows (start > today) → else 409
 *    ASSIGNMENT_NOT_DELETABLE.
 *  - finalized-month overlap → warn + allow: `finalized_months_affected[]`
 *    in the result plus a mandatory `user_working_time_model_finalized_overlap`
 *    audit event. Closure-tier locks still serialize against a racing
 *    finalize.
 *  - caps: dates 1970–2100, max {@see MAX_ROWS_PER_USER} rows per user.
 *  - model scoping: personal models must belong to the target user;
 *    missing/foreign → uniform 404 (no existence oracle).
 */
	// unique-conflict:ok — audit writes via logAction pass operation_key=NULL; user-model/model writes target tables without a colliding unique index.
final class UserWorkingTimeAssignmentService
{
	use TTransactional;

	public const ERROR_OVERLAP = 'ASSIGNMENT_OVERLAP';
	public const ERROR_EMPTY_WINDOW = 'ASSIGNMENT_EMPTY_WINDOW';
	public const ERROR_NOT_DELETABLE = 'ASSIGNMENT_NOT_DELETABLE';
	public const ERROR_MODEL_NOT_FOUND = 'MODEL_NOT_FOUND';
	public const ERROR_VALIDATION = 'ASSIGNMENT_VALIDATION';
	public const MAX_ROWS_PER_USER = 500;

	/** Newest deletions shown under the periods table. One extra row detects truncation. */
	public const REMOVED_LIST_LIMIT = 50;

	private const MIN_DATE = '1970-01-01';
	private const MAX_DATE = '2100-12-31';

	public function __construct(
		private readonly IUserManager $userManager,
		private readonly UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private readonly WorkingTimeModelMapper $workingTimeModelMapper,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly IL10N $l10n,
		private readonly IDBConnection $db,
		private readonly ?UserProfileLockGuard $lockGuard = null,
		private readonly ?MonthClosureService $monthClosureService = null,
		private readonly ?MonthClosureGuard $monthClosureGuard = null,
		private readonly ?VacationUnitService $vacationUnitService = null,
		private readonly ?LaborLawProfileFactory $laborLawProfileFactory = null,
	) {
	}

	// ------------------------------------------------------------------
	// Reads
	// ------------------------------------------------------------------

	/**
	 * Assignment list for the admin profile API (newest start first).
	 *
	 * @return list<array<string, mixed>>
	 */
	public function listForUser(string $userId): array
	{
		$rows = $this->userWorkingTimeModelMapper->findByUser($userId);
		usort($rows, function (UserWorkingTimeModel $a, UserWorkingTimeModel $b): int {
			$sa = $a->getStartDate()?->format('Y-m-d') ?? '';
			$sb = $b->getStartDate()?->format('Y-m-d') ?? '';
			return [$sb, $b->getId()] <=> [$sa, $a->getId()];
		});
		$out = [];
		foreach ($rows as $row) {
			$out[] = $this->assignmentWireShape($row);
		}
		return $out;
	}

	/**
	 * Planned periods that were deleted before they started.
	 *
	 * Ended periods stay in {@see listForUser}. This list is only the
	 * hard-delete audit, so a removal stays visible after the row is gone.
	 * The query is scoped, and each row is checked again so a mapper bug
	 * cannot leak another employee, another action, or the raw audit JSON.
	 *
	 * @return array{entries: list<array<string, mixed>>, truncated: bool}
	 */
	public function listRemovedForUser(string $userId): array
	{
		$raw = $this->auditLogMapper->findByUserAction(
			$userId,
			'user_working_time_model_deleted',
			'user_working_time_model',
			self::REMOVED_LIST_LIMIT + 1
		);
		$truncated = count($raw) > self::REMOVED_LIST_LIMIT;
		$entries = [];
		foreach ($raw as $log) {
			if (!$log instanceof \OCA\ArbeitszeitCheck\Db\AuditLog) {
				continue;
			}
			// removed-list: the query is already scoped; this is the leak stop.
			if ($log->getAction() !== 'user_working_time_model_deleted') {
				continue;
			}
			if ($log->getUserId() !== $userId) {
				continue;
			}
			if ($log->getEntityType() !== 'user_working_time_model') {
				continue;
			}
			$entry = $this->removedEntryFromLog($log);
			if ($entry !== null) {
				$entries[] = $entry;
			}
		}
		usort($entries, static function (array $a, array $b): int {
			return [$b['removed_at'] ?? '', $b['id']] <=> [$a['removed_at'] ?? '', $a['id']];
		});
		if (count($entries) > self::REMOVED_LIST_LIMIT) {
			$entries = array_slice($entries, 0, self::REMOVED_LIST_LIMIT);
		}

		return ['entries' => array_values($entries), 'truncated' => $truncated];
	}

	/**
	 * @return array<string, mixed>
	 */
	public function assignmentWireShape(UserWorkingTimeModel $row): array
	{
		$modelName = null;
		$modelIsPersonal = false;
		try {
			$model = $this->workingTimeModelMapper->find($row->getWorkingTimeModelId());
			$modelIsPersonal = $model->isPersonal();
			$modelName = $modelIsPersonal ? $this->l10n->t('Individual contract') : $model->getName();
		} catch (\Throwable) {
			// historical row whose model was deleted — keep the row, flag the name
			$modelName = $this->l10n->t('Deleted model');
		}
		$start = $row->getStartDate();
		$end = $row->getEndDate();
		$today = (new \DateTime())->format('Y-m-d');
		$startYmd = $start?->format('Y-m-d');
		$endYmd = $end?->format('Y-m-d');

		return [
			'id' => $row->getId(),
			'model_id' => $row->getWorkingTimeModelId(),
			'model_name' => $modelName,
			'model_is_personal' => $modelIsPersonal,
			'start_date' => $startYmd,
			'end_date' => $endYmd,
			'vacation_days_per_year' => $this->storedAmountToAdminDays((float)$row->getVacationDaysPerYear()),
			'is_current' => $startYmd !== null && $startYmd <= $today && ($endYmd === null || $endYmd >= $today),
			'is_future' => $startYmd !== null && $startYmd > $today,
			'deletable' => $startYmd !== null && $startYmd > $today,
		];
	}

	// ------------------------------------------------------------------
	// Writes — new effective-dated API
	// ------------------------------------------------------------------

	/**
	 * POST: create an assignment window. Auto-ends only the immediate
	 * predecessor at start−1; every other overlap → 409 ASSIGNMENT_OVERLAP.
	 *
	 * @param array<string, mixed> $params snake_case or camelCase keys
	 * @return array{assignment: array<string, mixed>, finalized_months_affected: list<string>, assignment_changed: bool}
	 */
	public function createAssignment(string $userId, array $params, string $performedBy): array
	{
		$this->assertUserExists($userId);
		$params = $this->normalizeParams($params);

		$start = $this->parseStrictYmd($params['startDate'] ?? null, 'start_date', true);
		$end = $this->parseOptionalYmd($params['endDate'] ?? null, 'end_date');
		$this->assertWindow($start, $end);
		$vacationDays = $this->parseVacationDays($params['vacationDaysPerYear'] ?? null);
		$customModel = $this->customModelFromParams($params);
		$modelId = $customModel === null ? $this->assertAssignableModel($userId, $params['modelId'] ?? null) : null;
		if ($customModel !== null) {
			$this->preflightCustomModel($customModel);
		}

		return $this->runLocked($userId, function () use ($userId, $params, $start, $end, $vacationDays, $customModel, $modelId, $performedBy): array {
			$rows = $this->userWorkingTimeModelMapper->findByUser($userId);
			$this->acquireClosureLocks($userId, [$start, $end], $rows);
			try {
				return $this->atomic(function () use ($userId, $params, $start, $end, $vacationDays, $customModel, $modelId, $performedBy, $rows): array {
					$rows = $this->userWorkingTimeModelMapper->findByUser($userId); // fresh under lock
					if (count($rows) >= self::MAX_ROWS_PER_USER) {
						throw new AdminUserProfileUpdateException(
							$this->l10n->t('Too many assignment periods for this employee.'),
							400, ['start_date' => $this->l10n->t('Too many assignment periods for this employee.')],
							self::ERROR_VALIDATION
						);
					}
					$startYmd = $start->format('Y-m-d');
					$endYmd = $end?->format('Y-m-d');

					$overlapping = $this->rowsOverlapping($rows, $startYmd, $endYmd, null);
					$autoEnd = null;
					if ($overlapping !== []) {
						// Immediate predecessor: row with the greatest start < new
						// start. It is auto-ended only when truncating at start−1
						// cannot silently lose coverage (its end ≤ new end or open).
						$predecessor = null;
						foreach ($overlapping as $r) {
							$rStart = $r->getStartDate()?->format('Y-m-d') ?? '';
							if ($rStart !== '' && $rStart < $startYmd
								&& ($predecessor === null || $rStart > $predecessor->getStartDate()->format('Y-m-d'))) {
								$predecessor = $r;
							}
						}
						$rest = $overlapping;
						if ($predecessor !== null) {
							$pEnd = $predecessor->getEndDate();
							$containsTail = $pEnd !== null && $endYmd !== null && $pEnd->format('Y-m-d') > $endYmd;
							if (!$containsTail) {
								$autoEnd = $predecessor;
								$rest = array_values(array_filter($overlapping, fn ($r) => $r->getId() !== $predecessor->getId()));
							}
						}
						if ($rest !== []) {
							throw $this->overlapException($rest);
						}
					}

					$effectiveModelId = $modelId;
					if ($customModel !== null) {
						$effectiveModelId = $this->upsertPersonalWorkingTimeModel($userId, $customModel, null, $performedBy);
					}
					if ($effectiveModelId === null) {
						throw new AdminUserProfileUpdateException(
							$this->l10n->t('Working time model not found'),
							404, [], self::ERROR_MODEL_NOT_FOUND
						);
					}

					if ($autoEnd !== null) {
						$oldValues = $this->userWorkingTimeModelToAuditValues($autoEnd);
						$autoEnd->setEndDate((clone $start)->modify('-1 day'));
						$autoEnd->setUpdatedAt(new \DateTime());
						$this->assertEntityValid($autoEnd->validate());
						$autoEnd = $this->userWorkingTimeModelMapper->update($autoEnd);
						$this->auditLogMapper->logAction(
							$userId,
							'user_working_time_model_updated',
							'user_working_time_model',
							$autoEnd->getId(),
							$oldValues,
							$this->userWorkingTimeModelToAuditValues($autoEnd),
							$performedBy
						);
					}

					$row = new UserWorkingTimeModel();
					$row->setUserId($userId);
					$row->setWorkingTimeModelId($effectiveModelId);
					$row->setVacationDaysPerYear($vacationDays ?? $this->defaultVacationDaysSuggestion());
					$row->setStartDate($start);
					if ($end !== null) {
						$row->setEndDate($end);
					}
					$row->setCreatedAt(new \DateTime());
					$row->setUpdatedAt(new \DateTime());
					$this->assertEntityValid($row->validate());
					$row = $this->userWorkingTimeModelMapper->insert($row);
					$this->auditLogMapper->logAction(
						$userId,
						'user_working_time_model_created',
						'user_working_time_model',
						$row->getId(),
						null,
						$this->userWorkingTimeModelToAuditValues($row),
						$performedBy
					);
					if ($autoEnd !== null) {
						// The Wechsel itself: predecessor window closed at
						// start−1 and this window opened, as one auditable
						// operation (MH-08).
						$this->auditLogMapper->logAction(
							$userId,
							'user_working_time_model_assignment_split',
							'user_working_time_model',
							$row->getId(),
							$oldValues,
							[
								'ended_assignment' => $this->userWorkingTimeModelToAuditValues($autoEnd),
								'assignment' => $this->userWorkingTimeModelToAuditValues($row),
							],
							$performedBy
						);
					}

					$finalizedAffected = $this->auditFinalizedOverlap($userId, $startYmd, $endYmd, $performedBy, $row->getId());
					$this->pruneOrphanedPersonalModels($userId, $performedBy);

					return [
						'assignment' => $this->assignmentWireShape($row),
						'finalized_months_affected' => $finalizedAffected,
						'assignment_changed' => true,
					];
				}, $this->db);
			} finally {
				$this->releaseClosureLocks();
			}
		});
	}

	/**
	 * PATCH: explicit edit of one window — never auto-ends neighbours; every
	 * overlap with another row → 409 ASSIGNMENT_OVERLAP.
	 *
	 * @param array<string, mixed> $params only provided keys change the row
	 */
	public function updateAssignment(string $userId, int $assignmentId, array $params, string $performedBy): array
	{
		$this->assertUserExists($userId);
		$params = $this->normalizeParams($params);
		$row = $this->findUserRowOr404($userId, $assignmentId);

		$start = array_key_exists('startDate', $params)
			? $this->parseStrictYmd($params['startDate'], 'start_date', true)
			: $row->getStartDate();
		$end = array_key_exists('endDate', $params)
			? $this->parseOptionalYmd($params['endDate'], 'end_date')
			: $row->getEndDate();
		if ($start === null) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Validation failed'), 400,
				['start_date' => $this->l10n->t('Invalid start date')], self::ERROR_VALIDATION
			);
		}
		$this->assertWindow($start, $end);
		$vacationDays = array_key_exists('vacationDaysPerYear', $params)
			? $this->parseVacationDays($params['vacationDaysPerYear'])
			: null;
		$customModel = $this->customModelFromParams($params);
		if ($customModel !== null) {
			$this->preflightCustomModel($customModel);
		}
		$modelId = null;
		$modelTouched = false;
		if ($customModel !== null) {
			$modelTouched = true;
		} elseif (array_key_exists('modelId', $params)) {
			if ((int)($params['modelId'] ?? 0) === (int)$row->getWorkingTimeModelId()) {
				// Re-sending the already-assigned model is a no-op — valid
				// even when that model row is gone (degraded assignments stay
				// editable; only pointing at a *different* model must exist).
				$modelId = (int)$row->getWorkingTimeModelId();
			} else {
				$modelId = $this->assertAssignableModel($userId, $params['modelId']);
			}
			$modelTouched = true;
		}

		return $this->runLocked($userId, function () use ($userId, $row, $start, $end, $vacationDays, $customModel, $modelId, $modelTouched, $performedBy): array {
			$rows = $this->userWorkingTimeModelMapper->findByUser($userId);
			$this->acquireClosureLocks($userId, [$start, $end, $row->getStartDate(), $row->getEndDate()], $rows);
			try {
				return $this->atomic(function () use ($userId, $row, $start, $end, $vacationDays, $customModel, $modelId, $modelTouched, $performedBy): array {
					$fresh = $this->findUserRowOr404($userId, (int)$row->getId());
					$rows = $this->userWorkingTimeModelMapper->findByUser($userId);
					$startYmd = $start->format('Y-m-d');
					$endYmd = $end?->format('Y-m-d');
					$oldStartYmd = $fresh->getStartDate()?->format('Y-m-d');
					$oldEndYmd = $fresh->getEndDate()?->format('Y-m-d');

					$colliding = $this->rowsOverlapping($rows, $startYmd, $endYmd, (int)$fresh->getId());
					if ($colliding !== []) {
						throw $this->overlapException($colliding);
					}

					$oldValues = $this->userWorkingTimeModelToAuditValues($fresh);
					$effectiveModelId = $modelId;
					if ($customModel !== null) {
						$effectiveModelId = $this->upsertPersonalWorkingTimeModel($userId, $customModel, $fresh, $performedBy);
					}
					if ($modelTouched && $effectiveModelId === null) {
						throw new AdminUserProfileUpdateException(
							$this->l10n->t('Working time model not found'),
							404, [], self::ERROR_MODEL_NOT_FOUND
						);
					}
					if ($effectiveModelId !== null) {
						$fresh->setWorkingTimeModelId($effectiveModelId);
					}
					$fresh->setStartDate($start);
					$fresh->setEndDate($end);
					if ($vacationDays !== null) {
						$fresh->setVacationDaysPerYear($vacationDays);
					}
					$fresh->setUpdatedAt(new \DateTime());
					$this->assertEntityValid($fresh->validate());
					$fresh = $this->userWorkingTimeModelMapper->update($fresh);
					$this->auditLogMapper->logAction(
						$userId,
						'user_working_time_model_updated',
						'user_working_time_model',
						$fresh->getId(),
						$oldValues,
						$this->userWorkingTimeModelToAuditValues($fresh),
						$performedBy
					);

					// Finalized overlap covers old ∪ new window.
					$rangeStart = min($startYmd, (string)$oldStartYmd);
					$rangeEnd = $endYmd === null || $oldEndYmd === null ? null : max($endYmd, $oldEndYmd);
					$finalizedAffected = $this->auditFinalizedOverlap($userId, $rangeStart, $rangeEnd, $performedBy, $fresh->getId());
					$this->pruneOrphanedPersonalModels($userId, $performedBy);

					return [
						'assignment' => $this->assignmentWireShape($fresh),
						'finalized_months_affected' => $finalizedAffected,
						'assignment_changed' => true,
					];
				}, $this->db);
			} finally {
				$this->releaseClosureLocks();
			}
		});
	}

	/**
	 * DELETE: only for never-effective windows (start > today). A started
	 * window is history — ending it is a PATCH/end, never a delete (BQ-05).
	 *
	 * @return array{deleted: bool, assignment_changed: bool, removed: ?array<string, mixed>}
	 */
	public function deleteAssignment(string $userId, int $assignmentId, string $performedBy): array
	{
		$this->assertUserExists($userId);
		$row = $this->findUserRowOr404($userId, $assignmentId);

		return $this->runLocked($userId, function () use ($userId, $row, $performedBy): array {
			$this->acquireClosureLocks($userId, [$row->getStartDate(), $row->getEndDate()], $this->userWorkingTimeModelMapper->findByUser($userId));
			try {
				return $this->atomic(function () use ($userId, $row, $performedBy): array {
					$fresh = $this->findUserRowOr404($userId, (int)$row->getId());
					$today = (new \DateTime())->setTime(0, 0, 0);
					$start = $fresh->getStartDate();
					if ($start === null || $start <= $today) {
						throw new AdminUserProfileUpdateException(
							$this->l10n->t('An assignment that already took effect cannot be deleted. Set an end date instead.'),
							409, [], self::ERROR_NOT_DELETABLE
						);
					}
					$oldValues = $this->userWorkingTimeModelToAuditValues($fresh);
					$this->userWorkingTimeModelMapper->delete($fresh);
					$audit = $this->auditLogMapper->logAction(
						$userId,
						'user_working_time_model_deleted',
						'user_working_time_model',
						$fresh->getId(),
						$oldValues,
						null,
						$performedBy
					);
					$removed = $this->removedEntryFromLog($audit);
					$this->pruneOrphanedPersonalModels($userId, $performedBy);
					return ['deleted' => true, 'assignment_changed' => true, 'removed' => $removed];
				}, $this->db);
			} finally {
				$this->releaseClosureLocks();
			}
		});
	}

	// ------------------------------------------------------------------
	// Legacy single-row payload (R-05: routes through the same guard stack)
	// ------------------------------------------------------------------

	/**
	 * The historical profile-payload write: edits the *editable* row
	 * (active-today, else latest) in place, inserts when none exists, ends it
	 * when the model selection is cleared. Semantics are kept byte-identical
	 * to the pre-feature code path; the only additions are the overlap guard
	 * and the finalized-month warning, which NN-10 requires on this path too.
	 *
	 * @param array<string, mixed> $params camelCase profile payload
	 * @return array{updated: ?UserWorkingTimeModel, assignment_changed: bool, finalized_months_affected: list<string>}
	 */
	public function applyLegacyAssignmentPayload(string $userId, array $params, string $performedBy): array
	{
		$workingTimeModelIdRaw = $params['workingTimeModelId'] ?? null;
		$workingTimeModelId = ($workingTimeModelIdRaw !== null && $workingTimeModelIdRaw !== '')
			? (int)$workingTimeModelIdRaw
			: null;
		$vacationDaysPerYear = null;
		if (isset($params['vacationDaysPerYear']) && $params['vacationDaysPerYear'] !== '' && $params['vacationDaysPerYear'] !== null) {
			$daysInput = (float)str_replace(',', '.', (string)$params['vacationDaysPerYear']);
			if ($daysInput < 0 || $daysInput > 366) {
				throw new AdminUserProfileUpdateException(
					$this->l10n->t('Vacation days per year must be between 0 and 366')
				);
			}
			$vacationDaysPerYear = (int)round($this->adminDaysToStoredAmount($daysInput));
		}
		$startDate = $params['startDate'] ?? null;
		$endDate = $params['endDate'] ?? null;
		$customModel = is_array($params['customModel'] ?? null) ? $params['customModel'] : null;
		$customEnabled = $customModel !== null
			&& filter_var($customModel['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);

		$assignmentChanged = false;
		$finalizedAffected = [];
		$updated = $this->runLocked($userId, function () use (
			$userId, $params, $customEnabled, $customModel, $workingTimeModelId,
			$vacationDaysPerYear, $startDate, $endDate, $performedBy,
			&$assignmentChanged, &$finalizedAffected
		): ?UserWorkingTimeModel {
			$rows = $this->userWorkingTimeModelMapper->findByUser($userId);
			$this->acquireClosureLocks($userId, [
				$startDate !== null && $startDate !== '' ? new \DateTime((string)$startDate) : null,
				$endDate !== null && $endDate !== '' ? new \DateTime((string)$endDate) : null,
			], $rows);
			try {
				return $this->atomic(function () use (
					$userId, $params, $customEnabled, $customModel, $workingTimeModelId,
					$vacationDaysPerYear, $startDate, $endDate, $performedBy,
					&$assignmentChanged, &$finalizedAffected
				): ?UserWorkingTimeModel {
					$currentModel = $this->userWorkingTimeModelMapper->findEditableByUser($userId);
					$oldValues = $currentModel ? $this->userWorkingTimeModelToAuditValues($currentModel) : null;

					$effectiveModelId = $workingTimeModelId;
					if ($customEnabled) {
						$effectiveModelId = $this->upsertPersonalWorkingTimeModel(
							$userId,
							$customModel ?? [],
							$currentModel,
							$performedBy
						);
					}

					$updated = null;
					// Assignment "unchanged" must only skip the model-row update —
					// other profile fields in the same payload still apply.
					if ($currentModel && $effectiveModelId !== null && $effectiveModelId > 0) {
						if ($this->workingTimeModelAssignmentMatches($currentModel, $effectiveModelId, $vacationDaysPerYear, $startDate, $endDate)) {
							$updated = $currentModel;
						} else {
							if ($startDate) {
								$currentModel->setStartDate(new \DateTime((string)$startDate));
							}
							if ($endDate !== null) {
								$currentModel->setEndDate($endDate !== '' ? new \DateTime((string)$endDate) : null);
							}
							$currentModel->setWorkingTimeModelId($effectiveModelId);
							if ($vacationDaysPerYear !== null) {
								$currentModel->setVacationDaysPerYear($vacationDaysPerYear);
							}
							$currentModel->setUpdatedAt(new \DateTime());
							// Overlap guard: the edited window must not collide
							// with any OTHER row (NN-10 single-writer).
							$rows = $this->userWorkingTimeModelMapper->findByUser($userId);
							$colliding = $this->rowsOverlapping(
								$rows,
								$currentModel->getStartDate()?->format('Y-m-d') ?? '',
								$currentModel->getEndDate()?->format('Y-m-d'),
								(int)$currentModel->getId()
							);
							if ($colliding !== []) {
								throw $this->overlapException($colliding);
							}
							$this->assertEntityValid($currentModel->validate());
							$updated = $this->userWorkingTimeModelMapper->update($currentModel);
							$assignmentChanged = true;
							$this->auditLogMapper->logAction(
								$userId,
								'user_working_time_model_updated',
								'user_working_time_model',
								$updated->getId(),
								$oldValues,
								$this->userWorkingTimeModelToAuditValues($updated),
								$performedBy
							);
						}
					} elseif ($effectiveModelId !== null && $effectiveModelId > 0) {
						$newModel = new UserWorkingTimeModel();
						$newModel->setUserId($userId);
						$newModel->setWorkingTimeModelId($effectiveModelId);
						$newModel->setVacationDaysPerYear($vacationDaysPerYear ?? $this->defaultVacationDaysSuggestion());
						$newModel->setStartDate(new \DateTime($startDate ? (string)$startDate : 'now'));
						if ($endDate) {
							$newModel->setEndDate(new \DateTime((string)$endDate));
						}
						$newModel->setCreatedAt(new \DateTime());
						$newModel->setUpdatedAt(new \DateTime());
						$rows = $this->userWorkingTimeModelMapper->findByUser($userId);
						if (count($rows) >= self::MAX_ROWS_PER_USER) {
							throw new AdminUserProfileUpdateException(
								$this->l10n->t('Too many assignment periods for this employee.'),
								400, [], self::ERROR_VALIDATION
							);
						}
						$colliding = $this->rowsOverlapping(
							$rows,
							$newModel->getStartDate()?->format('Y-m-d') ?? '',
							$newModel->getEndDate()?->format('Y-m-d'),
							null
						);
						if ($colliding !== []) {
							throw $this->overlapException($colliding);
						}
						$this->assertEntityValid($newModel->validate());
						$updated = $this->userWorkingTimeModelMapper->insert($newModel);
						$assignmentChanged = true;
						$this->auditLogMapper->logAction(
							$userId,
							'user_working_time_model_created',
							'user_working_time_model',
							$updated->getId(),
							null,
							$this->userWorkingTimeModelToAuditValues($updated),
							$performedBy
						);
					} elseif ($currentModel && array_key_exists('workingTimeModelId', $params)) {
						// "No Model Assigned": retire the editable assignment.
						// Future-dated rows are deleted outright; started rows
						// are closed with an end date.
						$today = new \DateTime();
						$today->setTime(0, 0, 0);
						$startsInFuture = $currentModel->getStartDate() !== null && $currentModel->getStartDate() > $today;
						if ($startsInFuture) {
							$this->userWorkingTimeModelMapper->delete($currentModel);
							$assignmentChanged = true;
							$this->auditLogMapper->logAction(
								$userId,
								'user_working_time_model_deleted',
								'user_working_time_model',
								$currentModel->getId(),
								$oldValues,
								null,
								$performedBy
							);
						} else {
							$endDateForRemoval = $endDate ? new \DateTime((string)$endDate) : new \DateTime();
							$currentModel->setEndDate($endDateForRemoval);
							$currentModel->setUpdatedAt(new \DateTime());
							$this->assertEntityValid($currentModel->validate());
							$updated = $this->userWorkingTimeModelMapper->update($currentModel);
							$assignmentChanged = true;
							$this->auditLogMapper->logAction(
								$userId,
								'user_working_time_model_ended',
								'user_working_time_model',
								$currentModel->getId(),
								$oldValues,
								$this->userWorkingTimeModelToAuditValues($updated),
								$performedBy
							);
						}
					}

					if ($updated !== null) {
						$finalizedAffected = $this->auditFinalizedOverlap(
							$userId,
							$updated->getStartDate()?->format('Y-m-d') ?? '',
							$updated->getEndDate()?->format('Y-m-d'),
							$performedBy,
							$updated->getId()
						);
					}
					$this->pruneOrphanedPersonalModels($userId, $performedBy);

					return $updated;
				}, $this->db);
			} finally {
				$this->releaseClosureLocks();
			}
		});

		return [
			'updated' => $updated,
			'assignment_changed' => $assignmentChanged,
			'finalized_months_affected' => $finalizedAffected,
		];
	}

	// ------------------------------------------------------------------
	// Guard helpers
	// ------------------------------------------------------------------

	/**
	 * Profile lock → catalog shared lock. Closure-tier locks are acquired
	 * per operation inside (they need the affected month range).
	 */
	private function runLocked(string $userId, callable $callback): mixed
	{
		if ($this->lockGuard === null) {
			return $callback();
		}
		return $this->lockGuard->runProfileExclusive(
			$userId,
			fn () => $this->lockGuard->runCatalogShared($callback)
		);
	}

	/**
	 * Closure tier locks for the months covering the candidate window and
	 * all existing rows' windows the write can touch. Acquired BEFORE the
	 * transaction (NN-04 ordering); released in each caller's finally.
	 *
	 * @param array<int, \DateTime|null> $windowDates [start, end, ...]
	 * @param list<UserWorkingTimeModel> $rows
	 */
	private array $heldClosureKeys = [];

	private function acquireClosureLocks(string $userId, array $windowDates, array $rows): void
	{
		if ($this->monthClosureService === null || $this->monthClosureGuard === null) {
			return;
		}
		$rangeStart = null;
		$rangeEnd = null;
		$consider = function (?string $s, ?string $e) use (&$rangeStart, &$rangeEnd): void {
			if ($s !== null && ($rangeStart === null || $s < $rangeStart)) {
				$rangeStart = $s;
			}
			if ($e === null || ($rangeEnd !== null && $e > $rangeEnd)) {
				$rangeEnd = $e;
			}
			if ($e === null) {
				$rangeEnd = null;
			}
		};
		for ($i = 0; $i < count($windowDates); $i += 2) {
			$s = $windowDates[$i] ?? null;
			$e = $windowDates[$i + 1] ?? null;
			$consider($s?->format('Y-m-d'), $e?->format('Y-m-d'));
		}
		foreach ($rows as $r) {
			$consider($r->getStartDate()?->format('Y-m-d'), $r->getEndDate()?->format('Y-m-d'));
		}
		if ($rangeStart === null) {
			return;
		}
		// Open-ended range: cap the lock span at today+3 months — a "forever"
		// window must not lock every future closure tier forever.
		$endDt = $rangeEnd !== null ? new \DateTime($rangeEnd) : (new \DateTime())->modify('+3 months');
		$keys = $this->monthClosureGuard->mutableRangeLockKeys($userId, new \DateTime($rangeStart), $endDt);
		$this->monthClosureGuard->acquireMutableRangeLockKeys($keys);
		$this->heldClosureKeys = $keys;
	}

	private function releaseClosureLocks(): void
	{
		if ($this->heldClosureKeys !== [] && $this->monthClosureGuard !== null) {
			$this->monthClosureGuard->releaseMutableRangeLocks($this->heldClosureKeys);
		}
		$this->heldClosureKeys = [];
	}

	/**
	 * Overlapping rows: r.start <= windowEnd && (r.end null || r.end >= windowStart).
	 * Open-ended window ($endYmd null) overlaps every row starting any time.
	 *
	 * @param list<UserWorkingTimeModel> $rows
	 * @return list<UserWorkingTimeModel>
	 */
	private function rowsOverlapping(array $rows, string $startYmd, ?string $endYmd, ?int $excludeId): array
	{
		$out = [];
		foreach ($rows as $r) {
			if ($excludeId !== null && (int)$r->getId() === $excludeId) {
				continue;
			}
			$rStart = $r->getStartDate()?->format('Y-m-d');
			if ($rStart === null) {
				continue; // malformed — cannot overlap (reported by upgrade repair)
			}
			$rEnd = $r->getEndDate()?->format('Y-m-d');
			$startsAfterWindowEnd = $endYmd !== null && $rStart > $endYmd;
			$endsBeforeWindowStart = $rEnd !== null && $rEnd < $startYmd;
			if (!$startsAfterWindowEnd && !$endsBeforeWindowStart) {
				$out[] = $r;
			}
		}
		return $out;
	}

	private function overlapException(array $colliding): AdminUserProfileUpdateException
	{
		$conflicts = [];
		foreach ($colliding as $r) {
			$conflicts[] = $this->assignmentWireShape($r);
		}
		return new AdminUserProfileUpdateException(
			$this->l10n->t('This period overlaps an existing assignment. Adjust the existing period or choose different dates.'),
			409,
			['start_date' => $this->l10n->t('This period overlaps an existing assignment.')],
			self::ERROR_OVERLAP,
			['conflicts' => $conflicts]
		);
	}

	/**
	 * Finalized-month overlap detection (BQ-02 warn+allow). Writes the
	 * mandatory audit event when the touched range crosses a sealed month.
	 *
	 * @return list<string> affected 'YYYY-MM' keys
	 */
	private function auditFinalizedOverlap(string $userId, string $startYmd, ?string $endYmd, string $performedBy, ?int $assignmentId): array
	{
		if ($this->monthClosureService === null || $startYmd === '') {
			return [];
		}
		try {
			$end = $endYmd !== null ? new \DateTime($endYmd) : new \DateTime();
			$months = $this->monthClosureService->monthsOverlappingRange(new \DateTime($startYmd), $end);
			$affected = [];
			foreach ($months as [$y, $m]) {
				if ($this->monthClosureService->isMonthFinalized($userId, $y, $m)) {
					$affected[] = sprintf('%04d-%02d', $y, $m);
				}
			}
			if ($affected !== []) {
				$this->auditLogMapper->logAction(
					$userId,
					'user_working_time_model_finalized_overlap',
					'user_working_time_model',
					$assignmentId,
					null,
					['finalized_months' => $affected],
					$performedBy
				);
			}
			return $affected;
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning('Finalized-overlap check failed: ' . $e->getMessage());
			return [];
		}
	}

	private function assertUserExists(string $userId): void
	{
		if ($this->userManager->get($userId) === null) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('User not found'), 404
			);
		}
	}

	private function findUserRowOr404(string $userId, int $assignmentId): UserWorkingTimeModel
	{
		try {
			$row = $this->userWorkingTimeModelMapper->find($assignmentId);
		} catch (\Throwable) {
			$row = null;
		}
		// Uniform 404: cross-user rows and missing rows collapse identically —
		// no existence oracle for foreign assignment ids.
		if ($row === null || $row->getUserId() !== $userId) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Assignment not found'),
				404, [], 'NOT_FOUND'
			);
		}
		return $row;
	}

	/**
	 * Model must exist and be assignable to this user: global (user_id NULL)
	 * or the user's own personal model. Missing/foreign → uniform 404.
	 */
	private function assertAssignableModel(string $userId, mixed $rawId): ?int
	{
		if ($rawId === null || $rawId === '' || (int)$rawId <= 0) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Working time model not found'),
				404, ['model_id' => $this->l10n->t('Working time model not found')], self::ERROR_MODEL_NOT_FOUND
			);
		}
		try {
			$model = $this->workingTimeModelMapper->find((int)$rawId);
		} catch (\Throwable) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Working time model not found'),
				404, ['model_id' => $this->l10n->t('Working time model not found')], self::ERROR_MODEL_NOT_FOUND
			);
		}
		if ($model->isPersonal() && $model->getUserId() !== $userId) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Working time model not found'),
				404, ['model_id' => $this->l10n->t('Working time model not found')], self::ERROR_MODEL_NOT_FOUND
			);
		}
		return (int)$model->getId();
	}

	private function assertWindow(?\DateTime $start, ?\DateTime $end): void
	{
		if ($start === null) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Validation failed'), 400,
				['start_date' => $this->l10n->t('Invalid start date')], self::ERROR_VALIDATION
			);
		}
		if ($end !== null && $end < $start) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('The end date must not be before the start date.'),
				400,
				['end_date' => $this->l10n->t('The end date must not be before the start date.')],
				self::ERROR_EMPTY_WINDOW
			);
		}
	}

	private function parseStrictYmd(mixed $raw, string $field, bool $required): ?\DateTime
	{
		if ($raw === null || $raw === '') {
			if ($required) {
				throw new AdminUserProfileUpdateException(
					$this->l10n->t('Validation failed'), 400,
					[$field => $this->l10n->t('Invalid date')], self::ERROR_VALIDATION
				);
			}
			return null;
		}
		$str = trim((string)$raw);
		$dt = \DateTime::createFromFormat('Y-m-d', $str);
		$errors = \DateTime::getLastErrors();
		if ($dt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Validation failed'), 400,
				[$field => $this->l10n->t('Invalid date')], self::ERROR_VALIDATION
			);
		}
		$year = (int)$dt->format('Y');
		if ($year < 1970 || $year > 2100) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Validation failed'), 400,
				[$field => $this->l10n->t('Date must be between 1970 and 2100')], self::ERROR_VALIDATION
			);
		}
		$dt->setTime(0, 0, 0);
		return $dt;
	}

	private function parseOptionalYmd(mixed $raw, string $field): ?\DateTime
	{
		return $this->parseStrictYmd($raw, $field, false);
	}

	private function parseVacationDays(mixed $raw): ?int
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		$days = (float)str_replace(',', '.', (string)$raw);
		if (!is_finite($days) || $days < 0 || $days > 366) {
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Vacation days per year must be between 0 and 366'),
				400, ['vacation_days_per_year' => $this->l10n->t('Vacation days per year must be between 0 and 366')],
				self::ERROR_VALIDATION
			);
		}
		return (int)round($this->adminDaysToStoredAmount($days));
	}

	/** snake_case + camelCase wire aliases → internal camelCase keys. */
	private function normalizeParams(array $params): array
	{
		$out = $params;
		$aliases = [
			'model_id' => 'modelId',
			'workingTimeModelId' => 'modelId',
			'start_date' => 'startDate',
			'end_date' => 'endDate',
			'vacation_days_per_year' => 'vacationDaysPerYear',
			'custom_model' => 'customModel',
		];
		foreach ($aliases as $snake => $camel) {
			if (array_key_exists($snake, $params) && !array_key_exists($camel, $out)) {
				$out[$camel] = $params[$snake];
			}
		}
		return $out;
	}

	private function customModelFromParams(array $params): ?array
	{
		$custom = is_array($params['customModel'] ?? null) ? $params['customModel'] : null;
		if ($custom === null) {
			return null;
		}
		// Form-encoded "false" arrives as string — parse explicitly.
		$enabled = filter_var($custom['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
		return $enabled ? $custom : null;
	}

	private function adminDaysToStoredAmount(float $days): float
	{
		return $this->vacationUnitService !== null
			? $this->vacationUnitService->adminDaysToStoredAmount($days)
			: $days;
	}

	private function storedAmountToAdminDays(float $stored): float
	{
		return $this->vacationUnitService !== null
			? $this->vacationUnitService->storedAmountToAdminDays($stored)
			: $stored;
	}

	private function defaultVacationDaysSuggestion(): int
	{
		return $this->laborLawProfileFactory !== null
			? $this->laborLawProfileFactory->getProfile()->vacationDaysSuggestion
			: Constants::DEFAULT_VACATION_DAYS_PER_YEAR;
	}

	private function assertEntityValid(array $errors): void
	{
		if (!empty($errors)) {
			$translated = [];
			foreach ($errors as $field => $message) {
				$translated[$field] = $this->l10n->t($message);
			}
			throw new AdminUserProfileUpdateException(
				$this->l10n->t('Validation failed'), 400, $translated, self::ERROR_VALIDATION
			);
		}
	}

	private function parseDecimalInput(mixed $value, float $default): float
	{
		if ($value === null || $value === '') {
			return $default;
		}
		$normalized = str_replace(',', '.', (string)$value);
		return is_numeric($normalized) ? (float)$normalized : $default;
	}

	private function floatsEqual(?float $a, ?float $b): bool
	{
		if ($a === null || $b === null) {
			return $a === $b;
		}
		return abs($a - $b) < 0.0001;
	}

	private function userWorkingTimeModelToAuditValues(UserWorkingTimeModel $model): array
	{
		// Captured at delete time: a personal model may be pruned in the same
		// transaction, so a later name lookup would have nothing left to read.
		$catalogName = null;
		$catalogIsPersonal = false;
		try {
			$catalog = $this->workingTimeModelMapper->find((int)$model->getWorkingTimeModelId());
			$catalogIsPersonal = $catalog->isPersonal();
			$catalogName = $catalogIsPersonal ? null : $catalog->getName();
		} catch (\Throwable) {
			// The name is resolved again when the removal is shown.
		}

		return [
			'id' => $model->getId(),
			'userId' => $model->getUserId(),
			'workingTimeModelId' => $model->getWorkingTimeModelId(),
			'vacationDaysPerYear' => $model->getVacationDaysPerYear(),
			'startDate' => $model->getStartDate()?->format('Y-m-d'),
			'endDate' => $model->getEndDate()?->format('Y-m-d'),
			'modelName' => $catalogName,
			'modelIsPersonal' => $catalogIsPersonal,
		];
	}

	/**
	 * Profile wire for one deletion. Null when the stored snapshot cannot
	 * describe a period. IP, user agent, and the raw JSON never leave here.
	 *
	 * @return array<string, mixed>|null
	 */
	private function removedEntryFromLog(\OCA\ArbeitszeitCheck\Db\AuditLog $log): ?array
	{
		$raw = $log->getOldValues();
		$old = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
		if (!is_array($old)) {
			return null;
		}
		$start = $this->ymdOrNull($old['startDate'] ?? null);
		if ($start === null) {
			return null;
		}
		$actorId = trim((string)($log->getPerformedBy() ?? ''));
		$actor = $actorId !== '' ? $this->userManager->get($actorId) : null;
		$display = $actor !== null ? trim((string)$actor->getDisplayName()) : '';
		$who = $display !== '' ? $display : ($actorId !== '' ? $actorId : (string)$this->l10n->t('Unknown'));
		$when = $log->getCreatedAt();
		$days = null;
		if (isset($old['vacationDaysPerYear']) && is_numeric($old['vacationDaysPerYear'])) {
			$days = $this->storedAmountToAdminDays((float)$old['vacationDaysPerYear']);
		}

		return [
			'id' => (int)$log->getId(),
			'model_name' => $this->removedModelName($old),
			'start_date' => $start,
			'end_date' => $this->ymdOrNull($old['endDate'] ?? null),
			'vacation_days_per_year' => $days,
			'removed_at' => $when instanceof \DateTimeInterface ? $when->format('Y-m-d H:i:s') : null,
			'removed_by' => $who,
		];
	}

	/**
	 * @param array<string, mixed> $old
	 */
	private function removedModelName(array $old): string
	{
		if (!empty($old['modelIsPersonal'])) {
			return (string)$this->l10n->t('Individual contract');
		}
		$stored = isset($old['modelName']) ? trim((string)$old['modelName']) : '';
		if ($stored !== '') {
			return $stored;
		}
		$modelId = isset($old['workingTimeModelId']) ? (int)$old['workingTimeModelId'] : 0;
		if ($modelId > 0) {
			try {
				$model = $this->workingTimeModelMapper->find($modelId);
				if ($model->isPersonal()) {
					return (string)$this->l10n->t('Individual contract');
				}
				$name = trim((string)$model->getName());
				if ($name !== '') {
					return $name;
				}
			} catch (\Throwable) {
				// Fall through to the deleted label.
			}
		}

		return (string)$this->l10n->t('Deleted model');
	}

	private function ymdOrNull(mixed $value): ?string
	{
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return null;
		}

		return $value;
	}

	private function workingTimeModelAssignmentMatches(
		UserWorkingTimeModel $currentModel,
		int $effectiveModelId,
		?int $vacationDaysPerYear,
		mixed $startDate,
		mixed $endDate
	): bool {
		if ($currentModel->getWorkingTimeModelId() !== $effectiveModelId) {
			return false;
		}
		if ($vacationDaysPerYear !== null && $currentModel->getVacationDaysPerYear() !== $vacationDaysPerYear) {
			return false;
		}
		if ($startDate !== null && $startDate !== ''
			&& $currentModel->getStartDate()?->format('Y-m-d') !== (new \DateTime((string)$startDate))->format('Y-m-d')) {
			return false;
		}
		if ($endDate !== null) {
			$currentEnd = $currentModel->getEndDate()?->format('Y-m-d');
			$newEnd = $endDate !== '' ? (new \DateTime((string)$endDate))->format('Y-m-d') : null;
			if ($currentEnd !== $newEnd) {
				return false;
			}
		}
		return true;
	}

	// ------------------------------------------------------------------
	// Personal model machinery (moved from AdminUserProfileUpdateService —
	// single-writer ownership: only this service creates/updates personal
	// models referenced by assignments)
	// ------------------------------------------------------------------

	/**
	 * @param array<string, mixed> $custom
	 */
	public function preflightCustomModel(array $custom): void
	{
		$scheduleRaw = $custom['weekdaySchedule'] ?? null;
		$hasSchedule = $scheduleRaw !== null && $scheduleRaw !== '';
		if ($hasSchedule) {
			if (!is_array($scheduleRaw)) {
				throw new AdminUserProfileUpdateException(
					$this->l10n->t('The individual weekday schedule is invalid'),
					400,
					['customWeekdaySchedule' => $this->l10n->t('The individual weekday schedule is invalid')]
				);
			}
			$scheduleErrors = WeekdaySchedule::validate($scheduleRaw);
			if ($scheduleErrors !== []) {
				throw new AdminUserProfileUpdateException(
					$this->l10n->t('The individual weekday schedule is invalid'),
					400,
					['customWeekdaySchedule' => $this->l10n->t('The individual weekday schedule is invalid')]
				);
			}
			$net = WeekdaySchedule::fromValidated($scheduleRaw)->weeklyNetHours();
			if ($net <= 0) {
				throw new AdminUserProfileUpdateException(
					$this->l10n->t('The individual weekday schedule has no working days'),
					400,
					['customWeekdaySchedule' => $this->l10n->t('The individual weekday schedule has no working days')]
				);
			}
			return;
		}

		$fieldErrors = [];
		$weekly = $this->parseDecimalInput($custom['weeklyHours'] ?? null, -1.0);
		if ($weekly <= 0 || $weekly > 80) {
			$fieldErrors['customWeeklyHours'] = $this->l10n->t('Weekly hours must be between 0 and 80');
		}
		$daily = $this->parseDecimalInput($custom['dailyHours'] ?? null, -1.0);
		if ($daily <= 0 || $daily > 24) {
			$fieldErrors['customDailyHours'] = $this->l10n->t('Daily hours must be between 0 and 24');
		}
		$days = $this->parseDecimalInput($custom['workDaysPerWeek'] ?? null, -1.0);
		if ($days < 1 || $days > 7) {
			$fieldErrors['customWorkDaysPerWeek'] = $this->l10n->t('Working days must be between 1 and 7');
		}
		if ($fieldErrors !== []) {
			throw new AdminUserProfileUpdateException($this->l10n->t('Validation failed'), 400, $fieldErrors);
		}
	}

	/**
	 * Create or update the employee's personal working time model and return
	 * its id. Same semantics as the pre-feature implementation: reuse the
	 * model referenced by the edited row when it is already this user's
	 * personal model, else adopt an unreferenced leftover, else create.
	 *
	 * @param array<string, mixed> $custom
	 */
	public function upsertPersonalWorkingTimeModel(
		string $userId,
		array $custom,
		?UserWorkingTimeModel $currentModel,
		string $performedBy
	): int {
		$scheduleRaw = $custom['weekdaySchedule'] ?? null;
		$schedule = (is_array($scheduleRaw) && $scheduleRaw !== [] && WeekdaySchedule::validate($scheduleRaw) === [])
			? WeekdaySchedule::fromValidated($scheduleRaw)
			: null;

		if ($schedule !== null) {
			$weekly = $schedule->weeklyNetHours();
			$daily = $schedule->averageDailyNetHours();
			$days = $schedule->workDaysPerWeek();
		} else {
			$weekly = $this->parseDecimalInput($custom['weeklyHours'] ?? null, 0.0);
			$daily = $this->parseDecimalInput($custom['dailyHours'] ?? null, 0.0);
			$days = $this->parseDecimalInput($custom['workDaysPerWeek'] ?? null, 0.0);
		}

		$assigned = null;
		if ($currentModel !== null) {
			try {
				$assigned = $this->workingTimeModelMapper->find($currentModel->getWorkingTimeModelId());
			} catch (DoesNotExistException) {
				$assigned = null;
			}
		}

		$isNew = false;
		if ($assigned !== null && $assigned->isPersonal() && $assigned->getUserId() === $userId) {
			$model = $assigned;
		} else {
			$model = null;
			foreach ($this->workingTimeModelMapper->findPersonalByUser($userId) as $candidate) {
				if ($this->userWorkingTimeModelMapper->findByWorkingTimeModel($candidate->getId(), false) === []) {
					$model = $candidate;
					break;
				}
			}
			if ($model === null) {
				$isNew = true;
				$model = new WorkingTimeModel();
				$model->setUserId($userId);
				$model->setName($this->l10n->t('Individual contract (%s)', [$userId]));
				$model->setType($assigned !== null ? (string)$assigned->getType() : WorkingTimeModel::TYPE_FLEXIBLE);
				$model->setIsDefault(false);
				if ($assigned !== null && $assigned->getOvertimeRulesArray() !== null) {
					$model->setOvertimeRulesArray($assigned->getOvertimeRulesArray());
				}
				$model->setCreatedAt(new \DateTime());
			}
		}

		if (!$isNew && $schedule !== null) {
			$storedRaw = ($model->getBreakRulesArray() ?? [])[WeekdaySchedule::KEY] ?? null;
			$storedVersion = is_array($storedRaw)
				? (WeekdaySchedule::versionOf($storedRaw) ?? PHP_INT_MAX)
				: 0;
			if ($schedule->getVersion() < $storedVersion) {
				throw new AdminUserProfileUpdateException(
					$this->l10n->t('This schedule uses a newer format that this client cannot write. Reload the model.'),
					409,
					['customWeekdaySchedule' => $this->l10n->t('This schedule uses a newer format that this client cannot write. Reload the model.')]
				);
			}
		}

		$oldAuditValues = $isNew ? null : $this->personalModelAuditValues($model);
		$oldScheduleArray = $isNew ? null : ($model->getWeekdaySchedule()?->toArray() ?? null);
		$oldWeekly = $model->getWeeklyHours();
		$oldDaily = $model->getDailyHours();
		$oldDays = $model->getWorkDaysPerWeek();

		$baseRules = $isNew
			? ($assigned !== null ? ($assigned->getBreakRulesArray() ?? []) : [])
			: ($model->getBreakRulesArray() ?? []);
		unset($baseRules[WeekdaySchedule::KEY]);
		if ($schedule !== null) {
			$baseRules[WeekdaySchedule::KEY] = $schedule->toArray();
		}

		$unchanged = !$isNew
			&& $this->floatsEqual($oldWeekly, $weekly)
			&& $this->floatsEqual($oldDaily, $daily)
			&& $this->floatsEqual($oldDays, $days)
			&& $oldScheduleArray === ($schedule?->toArray() ?? null)
			&& ($model->getBreakRulesArray() ?? []) === $baseRules;
		if ($unchanged) {
			return $model->getId();
		}

		$model->setBreakRulesArray($baseRules !== [] ? $baseRules : null);
		$model->setWeeklyHours($weekly);
		$model->setDailyHours($daily);
		$model->setWorkDaysPerWeek($days);
		$model->setUpdatedAt(new \DateTime());
		$this->assertEntityValid($model->validate());
		if ($isNew) {
			$model = $this->workingTimeModelMapper->insert($model);
			$this->auditLogMapper->logAction(
				$userId,
				'personal_working_time_model_created',
				'working_time_model',
				$model->getId(),
				null,
				$this->personalModelAuditValues($model),
				$performedBy
			);
		} else {
			$model = $this->workingTimeModelMapper->update($model);
			$this->auditLogMapper->logAction(
				$userId,
				'personal_working_time_model_updated',
				'working_time_model',
				$model->getId(),
				$oldAuditValues,
				$this->personalModelAuditValues($model),
				$performedBy
			);
		}

		return $model->getId();
	}

	/**
	 * Remove personal models no assignment references (historical rows count
	 * as references — EC-07: never delete a model a past window used).
	 */
	private function pruneOrphanedPersonalModels(string $userId, string $performedBy): void
	{
		foreach ($this->workingTimeModelMapper->findPersonalByUser($userId) as $model) {
			$refs = $this->userWorkingTimeModelMapper->findByWorkingTimeModel($model->getId(), false);
			if ($refs !== []) {
				continue;
			}
			$this->workingTimeModelMapper->delete($model);
			$this->auditLogMapper->logAction(
				$userId,
				'personal_working_time_model_deleted',
				'working_time_model',
				$model->getId(),
				$this->personalModelAuditValues($model),
				null,
				$performedBy
			);
		}
	}

	private function personalModelAuditValues(WorkingTimeModel $model): array
	{
		return [
			'id' => $model->getId(),
			'userId' => $model->getUserId(),
			'name' => $model->getName(),
			'type' => $model->getType(),
			'weeklyHours' => $model->getWeeklyHours(),
			'dailyHours' => $model->getDailyHours(),
			'workDaysPerWeek' => $model->getWorkDaysPerWeek(),
			'weekdaySchedule' => $model->getWeekdaySchedule()?->toArray(),
		];
	}
}
