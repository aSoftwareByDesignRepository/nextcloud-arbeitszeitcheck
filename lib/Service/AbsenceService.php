<?php

declare(strict_types=1);

/**
 * Absence service for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException;
use OCA\ArbeitszeitCheck\Util\AbsenceNotificationPayload;
use OCP\IUserManager;

/**
 * Absence service for absence management business logic
 */
class AbsenceService
{
	private AbsenceMapper $absenceMapper;
	private AuditLogMapper $auditLogMapper;
	private UserSettingsMapper $userSettingsMapper;
	private TeamResolverService $teamResolver;
	private UserWorkingTimeModelMapper $userWorkingTimeModelMapper;
	private IConfig $config;
	private IDBConnection $db;
	private ILockingProvider $lockingProvider;
	private IUserManager $userManager;
	private IL10N $l10n;
	private ?NotificationService $notificationService;
	private ?AbsenceIcalMailService $absenceIcalMailService;
	private ?AbsenceNotificationMailService $absenceNotificationMailService;
	private HolidayService $holidayCalendarService;
	private VacationYearBalanceMapper $vacationYearBalanceMapper;
	private VacationAllocationService $vacationAllocationService;
	private ?MonthClosureService $monthClosureService;
	private TimeZoneService $timeZoneService;
	private VacationYearWindowResolver $vacationYearWindowResolver;
	private ?VacationUnitService $vacationUnitService;
	private ?VacationHoursDebitService $vacationHoursDebitService;
	private ?ManagerPendingApprovalMailService $managerPendingApprovalMailService;
	/** Shared migrate-idle lock held for the duration of a vacation mutation (anti-TOCTOU). */
	private ?string $heldVacationUnitMigrateSharedLock = null;
	/** Shared year-mode lock — blocks exclusive mode flip mid-mutation (lock order: year → migrate). */
	private ?string $heldVacationYearModeSharedLock = null;

	public function __construct(
		AbsenceMapper $absenceMapper,
		AuditLogMapper $auditLogMapper,
		UserSettingsMapper $userSettingsMapper,
		TeamResolverService $teamResolver,
		UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		IConfig $config,
		IDBConnection $db,
		ILockingProvider $lockingProvider,
		IUserManager $userManager,
		IL10N $l10n,
		?NotificationService $notificationService,
		?AbsenceIcalMailService $absenceIcalMailService,
		HolidayService $holidayCalendarService,
		VacationYearBalanceMapper $vacationYearBalanceMapper,
		VacationAllocationService $vacationAllocationService,
		?AbsenceNotificationMailService $absenceNotificationMailService = null,
		?MonthClosureService $monthClosureService = null,
		?TimeZoneService $timeZoneService = null,
		?VacationYearWindowResolver $vacationYearWindowResolver = null,
		?VacationUnitService $vacationUnitService = null,
		?VacationHoursDebitService $vacationHoursDebitService = null,
		?ManagerPendingApprovalMailService $managerPendingApprovalMailService = null,
	) {
		$this->absenceMapper = $absenceMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->teamResolver = $teamResolver;
		$this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
		$this->config = $config;
		$this->db = $db;
		$this->lockingProvider = $lockingProvider;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->notificationService = $notificationService;
		$this->absenceIcalMailService = $absenceIcalMailService;
		$this->holidayCalendarService = $holidayCalendarService;
		$this->vacationYearBalanceMapper = $vacationYearBalanceMapper;
		$this->absenceNotificationMailService = $absenceNotificationMailService;
		$this->vacationAllocationService = $vacationAllocationService;
		$this->monthClosureService = $monthClosureService;
		// Optional for BC with older unit-test constructors; production always injects via Application.php.
		$this->timeZoneService = $timeZoneService ?? \OCP\Server::get(TimeZoneService::class);
		$this->vacationYearWindowResolver = $vacationYearWindowResolver
			?? new VacationYearWindowResolver($config, new UserEmploymentSettingsService($userSettingsMapper, $auditLogMapper));
		$this->vacationUnitService = $vacationUnitService;
		$this->vacationHoursDebitService = $vacationHoursDebitService;
		$this->managerPendingApprovalMailService = $managerPendingApprovalMailService;
	}

	/** Calendar "today" at 00:00 in organisation storage TZ. */
	private function todayDateInStorage(): \DateTimeImmutable
	{
		[$start] = $this->timeZoneService->todayWindowInStorage();
		return \DateTimeImmutable::createFromMutable($start);
	}

	/** Mutable "now" in organisation storage TZ for absence timestamps. */
	private function nowInStorage(): \DateTime
	{
		return $this->timeZoneService->nowInStorage();
	}

	/**
	 * SEC-02 / Argus NG-02 defense-in-depth: never let client-authored debit fields
	 * reach resolveVacationDaysDebit or setDays. Controllers already allowlist
	 * day_fraction only; this strips residual keys if a future caller passes them.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private function scrubClientAuthoredDebitFields(array $data): array
	{
		unset(
			$data['days'],
			$data['working_days'],
			$data['workingDays'],
			$data['Days'],
			$data['WorkingDays']
		);
		return $data;
	}

	/**
	 * Create a new absence request
	 *
	 * @param array $data Absence data
	 * @param string $userId User ID creating the request
	 * @return Absence
	 * @throws \Exception
	 */
	public function createAbsence(array $data, string $userId): Absence
	{
		$data = $this->scrubClientAuthoredDebitFields($data);
		$lockKey = $this->acquireUserMutationLock($userId);
		try {
			if (($data['type'] ?? '') === Absence::TYPE_VACATION) {
				$this->assertVacationUnitMigrationIdle();
			}
			$this->validateAbsenceData($data, $userId, null, null, []);

			$absence = new Absence();
			$absence->setUserId($userId);
			$absence->setType($data['type']);
			$absence->setStartDate($this->parseDate($data['start_date']));
			$absence->setEndDate($this->parseDate($data['end_date']));
			$absence->setReason($data['reason'] ?? null);
			$substituteUserId = isset($data['substitute_user_id']) ? trim((string)$data['substitute_user_id']) : null;

			/* Historical entries (end date strictly before today) skip the
			 * substitute workflow: Vertretung is a forward-looking concept and
			 * cannot meaningfully gate an absence whose period has already
			 * elapsed. We discard any submitted substitute_user_id rather than
			 * persisting a stale reference, and route directly into PENDING
			 * (auto-approval may then take over below if no approver exists). */
			$todayForSubstitute = $this->nowInStorage();
			$todayForSubstitute->setTime(0, 0, 0);
			$absenceFullyInPast = $absence->getEndDate() < $todayForSubstitute;
			if ($absenceFullyInPast) {
				$substituteUserId = null;
			}

			$absence->setSubstituteUserId($substituteUserId ?: null);
			// If substitute is selected: wait for substitute approval first (Vertretungs-Freigabe)
			$absence->setStatus($substituteUserId ? Absence::STATUS_SUBSTITUTE_PENDING : Absence::STATUS_PENDING);
			$absence->setCreatedAt(new \DateTime());
			$absence->setUpdatedAt(new \DateTime());

			// Calculate working days (Mon–Fri minus Feiertage inkl. Firmenfeiertage)
			if ($data['type'] === Absence::TYPE_VACATION) {
				$workingDays = $this->resolveVacationDaysDebit(
					$userId,
					$absence->getStartDate(),
					$absence->getEndDate(),
					$data
				);
				$absence->setDays($workingDays);
				$this->applyVacationDurationHours($absence, $data, $workingDays);
			} else {
				$workingDays = $this->computeWorkingDaysForUser($userId, $absence->getStartDate(), $absence->getEndDate());
				$absence->setDays($workingDays);
			}

			// All DB writes are atomic: if the audit log insertion fails, the absence
			// insertion is rolled back and the user sees an error rather than having an
			// absence in the DB with no audit trail.
			$savedAbsence = null;
			$autoApproved = false;

			$this->db->beginTransaction();
			try {
				$this->absenceMapper->lockUserAbsenceWindow($userId, $absence->getStartDate(), $absence->getEndDate());
				$overlappingInTx = $this->absenceMapper->findOverlapping($userId, $absence->getStartDate(), $absence->getEndDate(), null);
				if (!empty($overlappingInTx)) {
					throw new \Exception($this->l10n->t('This period overlaps with an existing absence.'));
				}

				$savedAbsence = $this->absenceMapper->insert($absence);

			$this->auditLogMapper->logAction(
				$userId,
				'absence_created',
				'absence',
				$savedAbsence->getId(),
				null,
				$savedAbsence->getSummary()
			);

			// Auto-approve when no assignable manager exists (avoids deadlock: pending with nobody who can approve)
			if ($savedAbsence->getStatus() === Absence::STATUS_PENDING && !$this->teamResolver->hasAssignableManagerForEmployee($userId)) {
				$savedAbsence = $this->doAutoApproveDbWork($savedAbsence);
				$autoApproved = true;
			}

				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

		// Side effects (notifications / emails) always happen after the DB commit so
		// that a) no DB lock is held while sending mail, and b) notifications are only
		// dispatched when the data is actually persisted.
		if ($autoApproved) {
			if ($this->notificationService) {
				$this->notificationService->notifyAbsenceApproved(
					$savedAbsence->getUserId(),
					$this->buildAbsenceNotificationData($savedAbsence)
				);
			}
			if ($this->absenceIcalMailService) {
				$this->absenceIcalMailService->sendIcalForApprovedAbsence($savedAbsence);
			}
			if ($this->absenceNotificationMailService) {
				$this->absenceNotificationMailService->sendHrOfficeNotification($savedAbsence, 'request_created', $userId);
				$this->absenceNotificationMailService->sendHrOfficeNotification($savedAbsence, 'manager_approved', $userId);
			}
		} elseif ($substituteUserId) {
			if ($this->notificationService) {
				$startDate = $savedAbsence->getStartDate();
				$endDate = $savedAbsence->getEndDate();
				$this->notificationService->notifySubstitutionRequest(
					$substituteUserId,
					$userId,
					[
						'id' => $savedAbsence->getId(),
						'type' => $savedAbsence->getType(),
						'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
						'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
						'days' => $savedAbsence->getDays()
					]
				);
			}
			if ($this->absenceNotificationMailService) {
				$this->absenceNotificationMailService->sendSubstitutionRequestToSubstitute($savedAbsence);
				$this->absenceNotificationMailService->sendHrOfficeNotification($savedAbsence, 'request_created', $userId);
			}
		} else {
			if ($this->absenceNotificationMailService) {
				$this->absenceNotificationMailService->sendHrOfficeNotification($savedAbsence, 'request_created', $userId);
			}
			// Direct manager approval (no substitute): email team managers when enabled.
			if ($savedAbsence->getStatus() === Absence::STATUS_PENDING) {
				$this->managerPendingApprovalMailService?->notifyPendingAbsence($savedAbsence);
			}
		}

			return $savedAbsence;
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * Create an absence on behalf of an employee and persist it as **already approved**.
	 * Used for migration / historical corrections by managers or administrators.
	 *
	 * Security: the caller MUST verify the actor may manage the target user (same rules as approvals).
	 * Substitute workflow is skipped; Vertretung does not apply to manager-recorded history.
	 *
	 * @param string $managerUserId Acting manager or administrator
	 * @param string $targetUserId Absence owner
	 * @param array $data Same shape as {@see createAbsence()} (type, start_date, end_date, optional reason)
	 */
	public function createApprovedAbsenceForEmployeeByManager(string $managerUserId, string $targetUserId, array $data): Absence
	{
		$data = $this->scrubClientAuthoredDebitFields($data);
		if ($managerUserId === $targetUserId) {
			throw new \Exception($this->l10n->t('You cannot record an absence for yourself with this action.'));
		}

		$lockKey = $this->acquireUserMutationLock($targetUserId);
		try {
			if (($data['type'] ?? '') === Absence::TYPE_VACATION) {
				$this->assertVacationUnitMigrationIdle();
			}
			$this->validateAbsenceData($data, $targetUserId, null, null, ['skip_substitute_rules' => true]);

			$absence = new Absence();
			$absence->setUserId($targetUserId);
			$absence->setType($data['type']);
			$absence->setStartDate($this->parseDate($data['start_date']));
			$absence->setEndDate($this->parseDate($data['end_date']));
			$reason = isset($data['reason']) ? trim((string)$data['reason']) : '';
			$absence->setReason($reason !== '' ? $reason : null);
			$absence->setSubstituteUserId(null);
			$absence->setStatus(Absence::STATUS_APPROVED);
			$absence->setApproverComment($this->l10n->t('Recorded and approved by a manager (historical or administrative entry).'));
			$absence->setApprovedBy(null);
			$absence->setApprovedByUserId($managerUserId);
			$now = new \DateTime();
			$absence->setApprovedAt($now);
			$absence->setCreatedAt($now);
			$absence->setUpdatedAt($now);

			$workingDays = $absence->getType() === Absence::TYPE_VACATION
				? $this->resolveVacationDaysDebit(
					$targetUserId,
					$absence->getStartDate(),
					$absence->getEndDate(),
					$data
				)
				: $this->computeWorkingDaysForUser($targetUserId, $absence->getStartDate(), $absence->getEndDate());
			$absence->setDays($workingDays);
			if ($absence->getType() === Absence::TYPE_VACATION) {
				$this->applyVacationDurationHours($absence, $data, $workingDays);
			}

			$savedAbsence = null;
			$this->db->beginTransaction();
			try {
				$this->absenceMapper->lockUserAbsenceWindow($targetUserId, $absence->getStartDate(), $absence->getEndDate());
				$overlappingInTx = $this->absenceMapper->findOverlapping($targetUserId, $absence->getStartDate(), $absence->getEndDate(), null);
				if (!empty($overlappingInTx)) {
					throw new \Exception($this->l10n->t('This period overlaps with an existing absence.'));
				}
				if ($absence->getType() === Absence::TYPE_VACATION) {
					$sd = $absence->getStartDate();
					$ed = $absence->getEndDate();
					if ($sd && $ed) {
						$this->lockVacationApprovalScope($targetUserId, $sd, $ed);
						$this->assertVacationAllocationForRequest(
							$targetUserId,
							$sd,
							$ed,
							null,
							$absence->getCreatedAt(),
							$absence->getDurationHours() !== null ? (float)$absence->getDurationHours() : null,
							$absence->getDays() !== null ? (float)$absence->getDays() : null
						);
					}
				}

				$savedAbsence = $this->absenceMapper->insert($absence);

				$this->auditLogMapper->logAction(
					$targetUserId,
					'absence_manager_recorded',
					'absence',
					$savedAbsence->getId(),
					null,
					$savedAbsence->getSummary(),
					$managerUserId
				);

				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			if ($this->notificationService) {
				$this->notificationService->notifyAbsenceApproved(
					$savedAbsence->getUserId(),
					$this->buildAbsenceNotificationData($savedAbsence)
				);
			}
			if ($this->absenceIcalMailService) {
				$this->absenceIcalMailService->sendIcalForApprovedAbsence($savedAbsence);
			}
			if ($this->absenceNotificationMailService) {
				$this->absenceNotificationMailService->sendHrOfficeNotification($savedAbsence, 'manager_approved', $managerUserId);
			}

			return $savedAbsence;
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * Get an absence by ID
	 *
	 * @param int $id Absence ID
	 * @param string $userId User ID (for access control)
	 * @return Absence|null
	 */
	public function getAbsence(int $id, string $userId): ?Absence
	{
		try {
			$absence = $this->absenceMapper->find($id);

			// Check if user has access to this absence
			// Note: Manager/admin access is handled at the controller level
			// Managers use ManagerController methods which check permissions separately
			if ($absence->getUserId() !== $userId) {
				return null;
			}

			return $absence;
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Update an absence
	 *
	 * @param int $id Absence ID
	 * @param array $data Update data
	 * @param string $userId User ID performing the update
	 * @return Absence
	 * @throws \Exception
	 */
	public function updateAbsence(int $id, array $data, string $userId): Absence
	{
		$data = $this->scrubClientAuthoredDebitFields($data);
		$lockKey = $this->acquireUserMutationLock($userId);
		try {
			$absence = $this->getAbsence($id, $userId);
			if (!$absence) {
				throw new \Exception($this->l10n->t('Absence not found'));
			}
			if ($absence->getType() === Absence::TYPE_VACATION
				|| (($data['type'] ?? null) === Absence::TYPE_VACATION)) {
				$this->assertVacationUnitMigrationIdle();
			}
		// Check if absence can be updated (pending, substitute_pending, or substitute_declined can be modified by owner)
		if (!in_array($absence->getStatus(), [Absence::STATUS_PENDING, Absence::STATUS_SUBSTITUTE_PENDING, Absence::STATUS_SUBSTITUTE_DECLINED], true)) {
			throw new \Exception($this->l10n->t('Only pending absences can be updated'));
		}
		$this->assertAbsenceMutable($absence);

		$oldData = $absence->getSummary();

		$oldStatus = $absence->getStatus();
		$oldSubstituteId = $absence->getSubstituteUserId();
		$substituteFieldTouched = array_key_exists('substitute_user_id', $data);

		// Update allowed fields (use parseDate for consistent validation)
		if (isset($data['start_date'])) {
			$absence->setStartDate($this->parseDate($data['start_date']));
		}
		if (isset($data['end_date'])) {
			$absence->setEndDate($this->parseDate($data['end_date']));
		}
		if (isset($data['reason'])) {
			$absence->setReason($data['reason']);
		}
		if ($substituteFieldTouched) {
			$absence->setSubstituteUserId($data['substitute_user_id'] ? (string)$data['substitute_user_id'] : null);
		}

		$startDate = $absence->getStartDate();
		$endDate = $absence->getEndDate();
		if (!$startDate || !$endDate) {
			throw new \Exception($this->l10n->t('Start date and end date are required'));
		}
		$validateData = [
			'type' => $absence->getType(),
			'start_date' => $startDate->format('Y-m-d'),
			'end_date' => $endDate->format('Y-m-d'),
			'reason' => $absence->getReason(),
		];
		if (array_key_exists('substitute_user_id', $data)) {
			$validateData['substitute_user_id'] = $data['substitute_user_id'];
		}
		if (array_key_exists('duration_hours', $data)) {
			$validateData['duration_hours'] = $data['duration_hours'];
		} elseif ($absence->getDurationHours() !== null) {
			$validateData['duration_hours'] = $absence->getDurationHours();
		}
		if (array_key_exists('day_fraction', $data)) {
			$validateData['day_fraction'] = $data['day_fraction'];
		} elseif (
			$absence->getType() === Absence::TYPE_VACATION
			&& $this->vacationUnitService?->isHoursMode() !== true
			&& $absence->getDays() !== null
		) {
			// Preserve trusted half-day when clients omit day_fraction (reason/substitute-only
			// patches, older mobile APIs). Without this, normalizeDayFraction(null) → 1.0 and
			// silently inflates a 0.5 debit to a full working day (Zeus MF-01).
			$storedDays = (float)$absence->getDays();
			$calendarForNewDates = $this->computeWorkingDaysForUser(
				$userId,
				$absence->getStartDate(),
				$absence->getEndDate()
			);
			$probe = new Absence();
			$probe->setStartDate(clone $absence->getStartDate());
			$probe->setEndDate(clone $absence->getEndDate());
			$probe->setDays($storedDays);
			if (
				VacationAllocationService::isTrustedStoredVacationDays($probe, $storedDays, $calendarForNewDates)
				&& abs($storedDays - 0.5) <= 0.011
			) {
				$validateData['day_fraction'] = '0.5';
			}
		}
		$this->validateAbsenceData($validateData, $userId, $id, $absence->getCreatedAt(), []);

		// Recalculate working days (Mon–Fri minus Feiertage inkl. Firmenfeiertage)
		if ($absence->getType() === Absence::TYPE_VACATION) {
			$workingDays = $this->resolveVacationDaysDebit($userId, $absence->getStartDate(), $absence->getEndDate(), $validateData);
			$absence->setDays($workingDays);
			$this->applyVacationDurationHours($absence, $validateData, $workingDays);
		} else {
			$workingDays = $this->computeWorkingDaysForUser($userId, $absence->getStartDate(), $absence->getEndDate());
			$absence->setDays($workingDays);
		}
		$absence->setUpdatedAt(new \DateTime());

		// Normalize substitute workflow whenever substitute assignment changes.
		$wasDeclined = $oldStatus === Absence::STATUS_SUBSTITUTE_DECLINED;
		$newSubstituteId = $absence->getSubstituteUserId();
		$substituteChanged = $substituteFieldTouched && $newSubstituteId !== $oldSubstituteId;
		if ($substituteChanged) {
			$absence->setApproverComment(null);
			$absence->setStatus($newSubstituteId ? Absence::STATUS_SUBSTITUTE_PENDING : Absence::STATUS_PENDING);
		}

		$updatedAbsence = null;

		$this->db->beginTransaction();
		try {
			$this->absenceMapper->lockUserAbsenceWindow($userId, $absence->getStartDate(), $absence->getEndDate());
			$overlappingInTx = $this->absenceMapper->findOverlapping($userId, $absence->getStartDate(), $absence->getEndDate(), $id);
			if (!empty($overlappingInTx)) {
				throw new \Exception($this->l10n->t('This period overlaps with an existing absence.'));
			}

			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$userId,
				'absence_updated',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary()
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Side effects after commit
		if ($substituteChanged && $newSubstituteId && $this->notificationService) {
			$startDate = $updatedAbsence->getStartDate();
			$endDate = $updatedAbsence->getEndDate();
			$this->notificationService->notifySubstitutionRequest(
				$newSubstituteId,
				$updatedAbsence->getUserId(),
				[
					'id' => $updatedAbsence->getId(),
					'type' => $updatedAbsence->getType(),
					'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
					'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
					'days' => $updatedAbsence->getDays(),
				]
			);
		}
		if ($substituteChanged && $newSubstituteId && $this->absenceNotificationMailService) {
			$this->absenceNotificationMailService->sendSubstitutionRequestToSubstitute($updatedAbsence);
			$this->absenceNotificationMailService->sendHrOfficeNotification($updatedAbsence, 'request_created', $userId);
		}

			return $updatedAbsence;
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * Delete an absence
	 *
	 * @param int $id Absence ID
	 * @param string $userId User ID performing the deletion
	 * @throws \Exception
	 */
	public function deleteAbsence(int $id, string $userId): void
	{
		$absence = $this->getAbsence($id, $userId);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}

		$lockKey = $this->acquireUserMutationLock($absence->getUserId());
		try {
			// Re-read under lock so concurrent approve cannot race a delete.
			$absence = $this->absenceMapper->find($id);
			if ($absence->getUserId() !== $userId) {
				throw new \Exception($this->l10n->t('Absence not found'));
			}
			// Check if absence can be deleted (pending, substitute_pending, or substitute_declined can be deleted by owner)
			if (!in_array($absence->getStatus(), [Absence::STATUS_PENDING, Absence::STATUS_SUBSTITUTE_PENDING, Absence::STATUS_SUBSTITUTE_DECLINED], true)) {
				throw new \Exception($this->l10n->t('Only pending absences can be deleted'));
			}
			$this->assertAbsenceMutable($absence);

			$this->db->beginTransaction();
			try {
				$this->absenceMapper->delete($absence);

				$this->auditLogMapper->logAction(
					$userId,
					'absence_deleted',
					'absence',
					$id,
					$absence->getSummary(),
					null
				);

				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * Cancel an existing absence.
	 *
	 * Security / business rules:
	 * - Only the owner can cancel their own absences (enforced via getAbsence()).
	 * - Cancellation is only allowed if the absence has not started yet
	 *   (start date strictly greater than today, in server timezone).
	 * - Only absences in one of the "active" states can be cancelled:
	 *   pending, substitute_pending, or approved.
	 *
	 * We keep the record (status = cancelled) for auditability instead of
	 * deleting it, so reports and logs remain consistent.
	 *
	 * @param int $id Absence ID
	 * @param string $userId User ID performing the cancellation
	 * @return Absence
	 * @throws \Exception
	 */
	public function cancelAbsence(int $id, string $userId): Absence
	{
		$absence = $this->getAbsence($id, $userId);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}

		$lockKey = $this->acquireUserMutationLock($absence->getUserId());
		$updatedAbsence = null;
		try {
			$absence = $this->absenceMapper->find($id);
			if ($absence->getUserId() !== $userId) {
				throw new \Exception($this->l10n->t('Absence not found'));
			}
			if ($absence->getType() === Absence::TYPE_VACATION) {
				$this->assertVacationUnitMigrationIdle();
			}
			$status = $absence->getStatus();
			if (!in_array($status, [Absence::STATUS_PENDING, Absence::STATUS_SUBSTITUTE_PENDING, Absence::STATUS_APPROVED], true)) {
				throw new \Exception($this->l10n->t('This absence cannot be cancelled.'));
			}

			$startDate = $absence->getStartDate();
			if (!$startDate) {
				throw new \Exception($this->l10n->t('Start date is missing for this absence.'));
			}

			$today = $this->todayDateInStorage();
			// Only allow cancellation before the first day of the absence
			if ($startDate <= $today) {
				throw new \Exception($this->l10n->t('You can only cancel absences that have not started yet.'));
			}
			$this->assertAbsenceMutable($absence);

			$oldData = $absence->getSummary();

			$absence->setStatus(Absence::STATUS_CANCELLED);
			$absence->setUpdatedAt($this->nowInStorage());

			$this->db->beginTransaction();
			try {
				$updatedAbsence = $this->absenceMapper->update($absence);

				$this->auditLogMapper->logAction(
					$userId,
					'absence_cancelled',
					'absence',
					$updatedAbsence->getId(),
					$oldData,
					$updatedAbsence->getSummary()
				);

				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}

		if ($this->absenceNotificationMailService) {
			$this->absenceNotificationMailService->sendHrOfficeNotification($updatedAbsence, 'employee_cancelled', $userId);
		}

		return $updatedAbsence;
	}

	/**
	 * Shorten an approved absence (early return).
	 *
	 * Security / business rules:
	 * - Only the owner can shorten their own absences (enforced via getAbsence()).
	 * - Only approved absences can be shortened.
	 * - The absence must have started (start_date <= today) but not yet ended
	 *   (end_date > today), i.e. the employee is currently in the absence period.
	 * - The new end date must be strictly earlier than the original end date.
	 * - The new end date must be >= start date.
	 *
	 * Recalculates working days for the new range and logs the change for audit.
	 *
	 * @param int $id Absence ID
	 * @param string $userId User ID performing the change
	 * @param string $newEndDate New end date (Y-m-d or d.m.Y)
	 * @return Absence
	 * @throws \Exception
	 */
	public function shortenAbsence(int $id, string $userId, string $newEndDate): Absence
	{
		$absence = $this->getAbsence($id, $userId);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}

		$lockKey = $this->acquireUserMutationLock($absence->getUserId());
		$updatedAbsence = null;
		try {
			$absence = $this->absenceMapper->find($id);
			if ($absence->getUserId() !== $userId) {
				throw new \Exception($this->l10n->t('Absence not found'));
			}
			if ($absence->getType() === Absence::TYPE_VACATION) {
				$this->assertVacationUnitMigrationIdle();
			}
			if ($absence->getStatus() !== Absence::STATUS_APPROVED) {
				throw new \Exception($this->l10n->t('Only approved absences can be shortened.'));
			}

			$startDate = $absence->getStartDate();
			$originalEndDate = $absence->getEndDate();
			if (!$startDate || !$originalEndDate) {
				throw new \Exception($this->l10n->t('Start date or end date is missing for this absence.'));
			}
			$this->assertAbsenceMutable($absence);

			$today = $this->todayDateInStorage();
			if ($startDate > $today) {
				throw new \Exception($this->l10n->t('You can only shorten absences that have already started.'));
			}
			if ($originalEndDate <= $today) {
				throw new \Exception($this->l10n->t('This absence has already ended. It cannot be shortened.'));
			}

			$newEnd = $this->parseDate($newEndDate);
			$newEnd->setTime(0, 0, 0);

			if ($this->monthClosureService !== null) {
				$newEndWithDayEnd = clone $newEnd;
				$newEndWithDayEnd->setTime(23, 59, 59);
				$this->monthClosureService->assertDateRangeMutable($userId, $startDate, $newEndWithDayEnd);
			}

			if ($newEnd < $startDate) {
				throw new \Exception($this->l10n->t('The new end date cannot be before the start date.'));
			}
			if ($newEnd >= $originalEndDate) {
				throw new \Exception($this->l10n->t('The new end date must be earlier than the original end date.'));
			}

			$oldData = $absence->getSummary();

			$absence->setEndDate($newEnd);
			$workingDays = $this->computeWorkingDaysForUser($userId, $absence->getStartDate(), $newEnd);
			if ($absence->getType() === Absence::TYPE_VACATION && $workingDays < 0.01) {
				throw new \Exception($this->l10n->t(
					'Vacation must include at least one working day. The selected period contains only weekends or public holidays.'
				));
			}
			$absence->setDays($workingDays);
			if ($absence->getType() === Absence::TYPE_VACATION && $this->vacationUnitService?->isHoursMode()) {
				$this->recomputeVacationHoursAfterShorten(
					$absence,
					$originalEndDate,
					$oldData,
					$workingDays
				);
			}
			$absence->setUpdatedAt($this->nowInStorage());

			$this->db->beginTransaction();
			try {
				$updatedAbsence = $this->absenceMapper->update($absence);

				$this->auditLogMapper->logAction(
					$userId,
					'absence_shortened',
					'absence',
					$updatedAbsence->getId(),
					$oldData,
					$updatedAbsence->getSummary()
				);

				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}

			return $updatedAbsence;
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * Approve an absence request
	 *
	 * @param int $id Absence ID
	 * @param string $approverId User ID of the approver
	 * @param string|null $comment Approval comment
	 * @return Absence
	 * @throws \Exception
	 */
	public function approveAbsence(int $id, string $approverId, ?string $comment = null): Absence
	{
		$lockAbsence = $this->absenceMapper->find($id);
		try {
			$lockKey = $this->acquireUserMutationLock($lockAbsence->getUserId());
		} catch (\OCP\Lock\LockedException $e) {
			throw new ConcurrentDecisionException(
				$this->l10n->t('This absence was already decided by another manager.')
			);
		}
		$updatedAbsence = null;
		try {
			if ($lockAbsence->getType() === Absence::TYPE_VACATION) {
				$this->assertVacationUnitMigrationIdle();
			}
			$this->db->beginTransaction();
			$absence = $this->absenceMapper->find($id);
			if (!$absence) {
				throw new \Exception($this->l10n->t('Absence not found'));
			}
			if ($absence->getStatus() !== Absence::STATUS_PENDING) {
				throw new ConcurrentDecisionException(
					$this->l10n->t('This absence was already decided by another manager.')
				);
			}
			$this->assertAbsenceMutable($absence);

			if ($absence->getType() === Absence::TYPE_VACATION) {
				$sd = $absence->getStartDate();
				$ed = $absence->getEndDate();
				if ($sd && $ed) {
					$this->lockVacationApprovalScope($absence->getUserId(), $sd, $ed);
				}
			}

			$oldData = $absence->getSummary();
			$absence->setStatus(Absence::STATUS_APPROVED);
			$absence->setApproverComment($comment);
			$absence->setApprovedBy(null);
			$absence->setApprovedByUserId($approverId);
			$absence->setApprovedAt(new \DateTime());
			$absence->setUpdatedAt(new \DateTime());

			if ($absence->getType() === Absence::TYPE_VACATION) {
				$sd = $absence->getStartDate();
				$ed = $absence->getEndDate();
				if ($sd && $ed) {
					$this->assertVacationAllocationForRequest(
						$absence->getUserId(),
						$sd,
						$ed,
						null,
						$absence->getCreatedAt(),
						$absence->getDurationHours() !== null ? (float)$absence->getDurationHours() : null,
						$absence->getDays() !== null ? (float)$absence->getDays() : null
					);
				}
			}

			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$updatedAbsence->getUserId(),
				'absence_approved',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary(),
				$approverId
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}

		// Side effects after commit
		if ($this->notificationService) {
			$this->notificationService->notifyAbsenceApproved(
				$updatedAbsence->getUserId(),
				$this->buildAbsenceNotificationData($updatedAbsence)
			);
		}

		if ($this->absenceIcalMailService) {
			$this->absenceIcalMailService->sendIcalForApprovedAbsence($updatedAbsence);
		}
		if ($this->absenceNotificationMailService) {
			$this->absenceNotificationMailService->sendHrOfficeNotification($updatedAbsence, 'manager_approved', $approverId);
		}

		return $updatedAbsence;
	}

	/**
	 * Reject an absence request
	 *
	 * @param int $id Absence ID
	 * @param string $approverId User ID of the approver
	 * @param string|null $comment Rejection comment
	 * @return Absence
	 * @throws \Exception
	 */
	public function rejectAbsence(int $id, string $approverId, ?string $comment = null): Absence
	{
		$lockAbsence = $this->absenceMapper->find($id);
		try {
			$lockKey = $this->acquireUserMutationLock($lockAbsence->getUserId());
		} catch (\OCP\Lock\LockedException $e) {
			throw new ConcurrentDecisionException(
				$this->l10n->t('This absence was already decided by another manager.')
			);
		}
		$updatedAbsence = null;
		try {
			if ($lockAbsence->getType() === Absence::TYPE_VACATION) {
				$this->assertVacationUnitMigrationIdle();
			}
			$this->db->beginTransaction();
			$absence = $this->absenceMapper->find($id);
			if (!$absence) {
				throw new \Exception($this->l10n->t('Absence not found'));
			}
			if ($absence->getStatus() !== Absence::STATUS_PENDING) {
				throw new ConcurrentDecisionException(
					$this->l10n->t('This absence was already decided by another manager.')
				);
			}
			$this->assertAbsenceMutable($absence);

			$oldData = $absence->getSummary();
			$absence->setStatus(Absence::STATUS_REJECTED);
			$absence->setApproverComment($comment);
			$absence->setApprovedBy(null);
			$absence->setApprovedByUserId($approverId);
			$absence->setApprovedAt(new \DateTime());
			$absence->setUpdatedAt(new \DateTime());

			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$updatedAbsence->getUserId(),
				'absence_rejected',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary(),
				$approverId
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}

		// Side effect after commit
		if ($this->notificationService) {
			$startDate = $updatedAbsence->getStartDate();
			$endDate = $updatedAbsence->getEndDate();
			$this->notificationService->notifyAbsenceRejected($updatedAbsence->getUserId(), [
				'id' => $updatedAbsence->getId(),
				'type' => $updatedAbsence->getType(),
				'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
				'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
				'days' => $updatedAbsence->getDays()
			], $comment);
		}
		if ($this->absenceNotificationMailService) {
			$this->absenceNotificationMailService->sendHrOfficeNotification($updatedAbsence, 'manager_rejected', $approverId);
		}
		return $updatedAbsence;
	}

	/**
	 * Serialize vacation approval calculations for one employee by locking overlapping vacation rows.
	 *
	 * Scope follows vacation-year windows (calendar years or hire-anniversary windows)
	 * that overlap the request — never blind calendar Jan–Dec when anniversary mode is on,
	 * so two approvals in the same hire year cannot slip past non-overlapping year locks.
	 *
	 * For MySQL/PostgreSQL/Oracle we issue a `SELECT ... FOR UPDATE` over approved+pending vacation
	 * rows in that scope. SQLite does not support row-level `FOR UPDATE`; transaction semantics remain best-effort there.
	 */
	private function lockVacationApprovalScope(string $userId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): void
	{
		$platform = $this->db->getDatabaseProvider();
		if (!in_array($platform, [IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_POSTGRES, IDBConnection::PLATFORM_ORACLE], true)) {
			return;
		}

		$windows = $this->vacationYearWindowResolver->windowsOverlappingRange($userId, $startDate, $endDate);
		if ($windows === []) {
			$startYear = (int)$startDate->format('Y');
			$endYear = (int)$endDate->format('Y');
			$scopeStart = new \DateTime(sprintf('%04d-01-01', min($startYear, $endYear)));
			$scopeEnd = new \DateTime(sprintf('%04d-12-31', max($startYear, $endYear)));
		} else {
			$scopeStart = null;
			$scopeEnd = null;
			foreach ($windows as $window) {
				$wStart = \DateTime::createFromImmutable($window->startInclusive);
				$wEnd = \DateTime::createFromImmutable($window->lastInclusiveDay());
				if ($scopeStart === null || $wStart < $scopeStart) {
					$scopeStart = $wStart;
				}
				if ($scopeEnd === null || $wEnd > $scopeEnd) {
					$scopeEnd = $wEnd;
				}
			}
			/** @var \DateTime $scopeStart */
			/** @var \DateTime $scopeEnd */
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('at_absences')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(Absence::TYPE_VACATION, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter([Absence::STATUS_APPROVED, Absence::STATUS_PENDING], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($scopeEnd->format('Y-m-d'), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($scopeStart->format('Y-m-d'), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR)))
			->orderBy('id', 'ASC');

		$sql = $qb->getSQL() . ' FOR UPDATE';
		$result = $this->db->executeQuery($sql, $qb->getParameters(), $qb->getParameterTypes());
		$result->fetchAll();
		$result->closeCursor();
	}

	/**
	 * Approve absence by substitute (Vertretungs-Freigabe)
	 * Transitions status from substitute_pending to pending (ready for manager approval)
	 *
	 * @param int $id Absence ID
	 * @param string $substituteUserId User ID of the substitute (must match absence.substitute_user_id)
	 * @return Absence
	 * @throws \Exception
	 */
	public function approveBySubstitute(int $id, string $substituteUserId): Absence
	{
		$absence = $this->absenceMapper->find($id);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}
		$lockKey = $this->acquireUserMutationLock($absence->getUserId());
		try {
			$absence = $this->absenceMapper->find($id);
			if (!$absence) {
				throw new \Exception($this->l10n->t('Absence not found'));
			}
			if ($absence->getType() === Absence::TYPE_VACATION) {
				$this->assertVacationUnitMigrationIdle();
			}
		if ($absence->getStatus() !== Absence::STATUS_SUBSTITUTE_PENDING) {
			throw new \Exception($this->l10n->t('Absence is not awaiting substitute approval'));
		}

		$actualSubstitute = $absence->getSubstituteUserId();
		if ($actualSubstitute === null || $actualSubstitute !== $substituteUserId) {
			throw new \Exception($this->l10n->t('You are not the designated substitute for this absence'));
		}
		$this->assertAbsenceMutable($absence);

		$oldData = $absence->getSummary();
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setUpdatedAt(new \DateTime());

		$updatedAbsence = null;
		$wasAutoApproved = false;

		$this->db->beginTransaction();
		try {
			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$updatedAbsence->getUserId(),
				'absence_substitute_approved',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary(),
				$substituteUserId
			);

			// When no assignable manager: auto-approve immediately (DB work only; notifications after commit)
			if (!$this->teamResolver->hasAssignableManagerForEmployee($absence->getUserId())) {
				$updatedAbsence = $this->doAutoApproveDbWork($updatedAbsence);
				$wasAutoApproved = true;
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Side effects after commit
		if ($wasAutoApproved) {
			if ($this->notificationService) {
				$this->notificationService->notifyAbsenceApproved(
					$updatedAbsence->getUserId(),
					$this->buildAbsenceNotificationData($updatedAbsence)
				);
			}
			if ($this->absenceIcalMailService) {
				$this->absenceIcalMailService->sendIcalForApprovedAbsence($updatedAbsence);
			}
			if ($this->absenceNotificationMailService) {
				$this->absenceNotificationMailService->sendHrOfficeNotification($updatedAbsence, 'substitute_approved', $substituteUserId);
				$this->absenceNotificationMailService->sendHrOfficeNotification($updatedAbsence, 'manager_approved', $substituteUserId);
			}
		} else {
			// Employee has manager: notify about substitute approval and that manager approval is pending
			if ($this->notificationService) {
				$startDate = $updatedAbsence->getStartDate();
				$endDate = $updatedAbsence->getEndDate();
				$this->notificationService->notifySubstituteApproved(
					$updatedAbsence->getUserId(),
					$substituteUserId,
					[
						'id' => $updatedAbsence->getId(),
						'type' => $updatedAbsence->getType(),
						'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
						'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
						'days' => $updatedAbsence->getDays()
					]
				);
			}

			if ($this->absenceNotificationMailService) {
				$this->absenceNotificationMailService->sendSubstituteApprovedToEmployee($updatedAbsence);
				$this->absenceNotificationMailService->sendSubstituteApprovedToManagers($updatedAbsence);
				$this->absenceNotificationMailService->sendHrOfficeNotification($updatedAbsence, 'substitute_approved', $substituteUserId);
			}

			if ($this->absenceIcalMailService) {
				$this->absenceIcalMailService->sendIcalToSubstituteOnSubstitutionApproval($updatedAbsence);
			}
		}
			return $updatedAbsence;
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	/**
	 * Decline absence by substitute
	 * Transitions status to substitute_declined
	 *
	 * @param int $id Absence ID
	 * @param string $substituteUserId User ID of the substitute
	 * @param string|null $comment Optional comment for the employee
	 * @return Absence
	 * @throws \Exception
	 */
	public function declineBySubstitute(int $id, string $substituteUserId, ?string $comment = null): Absence
	{
		$absence = $this->absenceMapper->find($id);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}
		$lockKey = $this->acquireUserMutationLock($absence->getUserId());
		try {
			$absence = $this->absenceMapper->find($id);
			if (!$absence) {
				throw new \Exception($this->l10n->t('Absence not found'));
			}
			if ($absence->getType() === Absence::TYPE_VACATION) {
				$this->assertVacationUnitMigrationIdle();
			}
		if ($absence->getStatus() !== Absence::STATUS_SUBSTITUTE_PENDING) {
			throw new \Exception($this->l10n->t('Absence is not awaiting substitute approval'));
		}

		$actualSubstitute = $absence->getSubstituteUserId();
		if ($actualSubstitute === null || $actualSubstitute !== $substituteUserId) {
			throw new \Exception($this->l10n->t('You are not the designated substitute for this absence'));
		}
		$this->assertAbsenceMutable($absence);

		$oldData = $absence->getSummary();
		$absence->setStatus(Absence::STATUS_SUBSTITUTE_DECLINED);
		$absence->setApproverComment($comment);
		$absence->setUpdatedAt(new \DateTime());

		$updatedAbsence = null;

		$this->db->beginTransaction();
		try {
			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$updatedAbsence->getUserId(),
				'absence_substitute_declined',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary(),
				$substituteUserId
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Side effect after commit
		if ($this->notificationService) {
			$startDate = $updatedAbsence->getStartDate();
			$endDate = $updatedAbsence->getEndDate();
			$this->notificationService->notifySubstituteDeclined(
				$updatedAbsence->getUserId(),
				$substituteUserId,
				[
					'id' => $updatedAbsence->getId(),
					'type' => $updatedAbsence->getType(),
					'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
					'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
					'days' => $updatedAbsence->getDays()
				],
				$comment
			);
		}
		if ($this->absenceNotificationMailService) {
			$this->absenceNotificationMailService->sendHrOfficeNotification($updatedAbsence, 'substitute_declined', $substituteUserId);
		}
			return $updatedAbsence;
		} finally {
			$this->releaseUserMutationLock($lockKey);
		}
	}

	private function acquireUserMutationLock(string $userId): string
	{
		$key = DbLockKeys::absenceUser($userId);
		$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE, 'Absence workflow lock for user ' . $userId);
		return $key;
	}

	/**
	 * Block vacation mutations while days↔hours migration holds its exclusive lock
	 * or has a pending crash-window flag, and while vacation year mode is flipping.
	 * Lock order (must match AdminController year flip): year shared → migrate shared.
	 * Holds both until {@see releaseUserMutationLock()} (anti-TOCTOU).
	 */
	private function assertVacationUnitMigrationIdle(): void
	{
		$this->acquireVacationYearModeSharedLock();
		if ($this->heldVacationUnitMigrateSharedLock !== null) {
			return;
		}
		$pending = trim((string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING,
			''
		));
		if ($pending !== '') {
			$this->releaseVacationYearModeSharedLock();
			throw new BusinessRuleException(
				$this->l10n->t('Vacation unit migration is in progress. Please try again in a moment.'),
				Constants::VAC_UNIT_MIGRATE_IN_PROGRESS
			);
		}
		$key = DbLockKeys::vacationUnitMigration();
		try {
			$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_SHARED, 'Vacation unit migrate idle shared');
		} catch (\OCP\Lock\LockedException $e) {
			$this->releaseVacationYearModeSharedLock();
			throw new BusinessRuleException(
				$this->l10n->t('Vacation unit migration is in progress. Please try again in a moment.'),
				Constants::VAC_UNIT_MIGRATE_IN_PROGRESS
			);
		}
		// Re-check pending after acquire (flag may flip while waiting for the lock).
		$pendingAfter = trim((string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING,
			''
		));
		if ($pendingAfter !== '') {
			try {
				$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_SHARED);
			} catch (\Throwable) {
				// best-effort
			}
			$this->releaseVacationYearModeSharedLock();
			throw new BusinessRuleException(
				$this->l10n->t('Vacation unit migration is in progress. Please try again in a moment.'),
				Constants::VAC_UNIT_MIGRATE_IN_PROGRESS
			);
		}
		$this->heldVacationUnitMigrateSharedLock = $key;
	}

	private function acquireVacationYearModeSharedLock(): void
	{
		if ($this->heldVacationYearModeSharedLock !== null) {
			return;
		}
		$key = DbLockKeys::vacationYearMode();
		try {
			$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_SHARED, 'Vacation year mode idle shared');
		} catch (\OCP\Lock\LockedException $e) {
			throw new BusinessRuleException(
				$this->l10n->t('Vacation year mode is being updated. Please try again.'),
				'VAC_YEAR_MODE_BUSY'
			);
		}
		$this->heldVacationYearModeSharedLock = $key;
	}

	private function releaseVacationYearModeSharedLock(): void
	{
		$key = $this->heldVacationYearModeSharedLock;
		if ($key === null) {
			return;
		}
		$this->heldVacationYearModeSharedLock = null;
		try {
			$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_SHARED);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Failed to release vacation year mode shared lock: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}

	private function releaseVacationUnitMigrationSharedLock(): void
	{
		$key = $this->heldVacationUnitMigrateSharedLock;
		if ($key === null) {
			return;
		}
		$this->heldVacationUnitMigrateSharedLock = null;
		try {
			$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_SHARED);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Failed to release vacation unit migrate shared lock: ' . $e->getMessage(),
				['exception' => $e]
			);
		}
	}

	private function releaseUserMutationLock(string $key): void
	{
		try {
			$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning('Failed to release absence workflow lock: ' . $e->getMessage(), ['exception' => $e]);
		}
		// Reverse of acquire order: migrate shared, then year shared.
		$this->releaseVacationUnitMigrationSharedLock();
		$this->releaseVacationYearModeSharedLock();
	}

	/**
	 * Get absences for a user with optional filters
	 *
	 * @param string $userId User ID (empty string to get all users - for manager views)
	 * @param array $filters Optional filters (status, type, date_range)
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return Absence[]
	 */
	public function getAbsencesByUser(string $userId, array $filters = [], ?int $limit = null, ?int $offset = null): array
	{
		// Default: require non-empty userId (no cross-user listing)
		if (empty($userId)) {
			return [];
		}

		$absences = [];

		// Date-range consumers such as the calendar must not rely on the latest
		// 500 rows only; otherwise older migration records disappear from past months.
		if (isset($filters['date_range']) && isset($filters['date_range']['start']) && isset($filters['date_range']['end'])) {
			$absences = $this->absenceMapper->findByUserAndDateRange(
				$userId,
				$filters['date_range']['start'],
				$filters['date_range']['end']
			);
		} else {
			$absences = $this->absenceMapper->findByUser($userId, $limit, $offset);
		}

		if (isset($filters['status'])) {
			$status = $filters['status'];
			$absences = array_values(array_filter($absences, function ($absence) use ($status) {
				// "pending" = awaiting any approval (substitute or manager)
				if ($status === 'pending') {
					return in_array($absence->getStatus(), [Absence::STATUS_PENDING, Absence::STATUS_SUBSTITUTE_PENDING], true);
				}
				return $absence->getStatus() === $status;
			}));
		}

		if (isset($filters['type']) && $filters['type'] !== '') {
			$type = (string)$filters['type'];
			$absences = array_values(array_filter($absences, static function ($absence) use ($type) {
				return $absence->getType() === $type;
			}));
		}

		return $absences;
	}

	/**
	 * Get vacation statistics for a user
	 *
	 * @param string $userId User ID
	 * @param int $year Year to get statistics for
	 * @return array
	 */
	public function getVacationStats(string $userId, int $year): array
	{
		$sickDays = 0.0;
		try {
			$sickDays = $this->absenceMapper->getSickLeaveDays($userId, $year);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting sick leave days: ' . $e->getMessage(), ['exception' => $e]);
			$sickDays = 0.0;
		}

		try {
			$today = \DateTime::createFromImmutable($this->todayDateInStorage());
			// Anniversary mode: always resolve the window containing "today" so a calendar
			// year argument from the UI cannot pick the wrong balance year (AC-101 / §9.4).
			$allocYear = $year;
			if ($this->vacationYearWindowResolver->isAnniversaryMode()) {
				$window = $this->vacationYearWindowResolver->resolveForUser($userId, $today);
				$allocYear = $window->balanceYearKey;
			}
			// Read-only: do not persist entitlement snapshots (avoids DB writes/locks on every dashboard/widget poll).
			$alloc = $this->vacationAllocationService->computeYearAllocation($userId, $allocYear, null, null, null, $today, null, false);
			$totalEntitlement = (float)$alloc['entitlement'];
			$carryoverOpening = (float)$alloc['carryover_opening'];
			$totalAvailable = $totalEntitlement + $carryoverOpening;
			$usedDays = (float)$alloc['used_total_working_days'];
			$remaining = (float)$alloc['total_remaining_for_new_requests'];
			$carryoverRem = (float)($alloc['carryover_remaining_after_approved'] ?? 0);
			$annualRem = (float)($alloc['annual_remaining_after_approved'] ?? 0);
			$carryoverBlocked = $carryoverRem > 0.0001
				&& !$this->vacationAllocationService->isCarryoverUsableForNewRequests($allocYear, $today, $userId);
			$cap = $this->vacationAllocationService->getMaxCarryoverOpeningCap();

			return [
				'year' => (int)($alloc['year'] ?? $allocYear),
				'entitlement' => round($totalEntitlement, 2),
				'entitlement_source' => (string)($alloc['entitlement_source'] ?? 'manual'),
				'entitlement_rule_set_id' => $alloc['entitlement_rule_set_id'] ?? null,
				'entitlement_trace' => $alloc['entitlement_trace'] ?? null,
				'carryover_days' => round($carryoverOpening, 2),
				'carryover_usable' => round((float)$alloc['carryover_usable_for_new_requests'], 2),
				'carryover_expires_on' => $alloc['carryover_expires_on'],
				'carryover_unused_locked_after_deadline' => $carryoverBlocked,
				'carryover_remaining_after_approved' => round($carryoverRem, 2),
				'annual_remaining_after_approved' => round($annualRem, 2),
				'carryover_max_cap' => $cap !== null ? round($cap, 2) : null,
				'total_available' => round($totalAvailable, 2),
				'used' => round($usedDays, 2),
				'remaining' => round($remaining, 2),
				'sick_days' => $sickDays,
				'vacation_unit' => (string)($alloc['vacation_unit'] ?? ($this->vacationUnitService?->getUnit() ?? Constants::VACATION_UNIT_DAYS)),
				'vacation_hours_per_day' => $this->vacationUnitService?->getHoursPerDay() ?? Constants::DEFAULT_VACATION_HOURS_PER_DAY,
				'vacation_year_mode' => (string)($alloc['vacation_year_mode'] ?? Constants::VACATION_YEAR_MODE_CALENDAR),
				'vacation_year_label' => (string)($alloc['vacation_year_label'] ?? (string)$allocYear),
				'vacation_year_start' => $alloc['vacation_year_start'] ?? null,
				'vacation_year_end_inclusive' => $alloc['vacation_year_end_inclusive'] ?? null,
				'vacation_year_error' => $alloc['vacation_year_error'] ?? null,
			];
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning('Error getting vacation stats (using fallback): ' . $e->getMessage(), ['exception' => $e]);
			return [
				'year' => $year,
				'entitlement' => Constants::DEFAULT_VACATION_DAYS_PER_YEAR,
				'entitlement_source' => 'manual',
				'entitlement_rule_set_id' => null,
				'entitlement_trace' => null,
				'carryover_days' => 0.0,
				'carryover_usable' => 0.0,
				'carryover_expires_on' => null,
				'carryover_unused_locked_after_deadline' => false,
				'carryover_remaining_after_approved' => 0.0,
				'annual_remaining_after_approved' => 0.0,
				'carryover_max_cap' => null,
				'total_available' => (float)Constants::DEFAULT_VACATION_DAYS_PER_YEAR,
				'used' => 0.0,
				'remaining' => (float)Constants::DEFAULT_VACATION_DAYS_PER_YEAR,
				'sick_days' => $sickDays,
				'vacation_unit' => $this->vacationUnitService?->getUnit() ?? Constants::VACATION_UNIT_DAYS,
				'vacation_hours_per_day' => $this->vacationUnitService?->getHoursPerDay() ?? Constants::DEFAULT_VACATION_HOURS_PER_DAY,
				'vacation_year_mode' => Constants::VACATION_YEAR_MODE_CALENDAR,
				'vacation_year_label' => (string)$year,
				'vacation_year_start' => null,
				'vacation_year_end_inclusive' => null,
				'vacation_year_error' => null,
			];
		}
	}

	/**
	 * Enforce FIFO carryover + annual pools for vacation (create/update/approve).
	 *
	 * @throws \Exception
	 */
	private function assertVacationAllocationForRequest(
		string $userId,
		\DateTime $startDate,
		\DateTime $endDate,
		?int $excludeAbsenceId = null,
		?\DateTimeInterface $prospectiveRequestCreatedAt = null,
		?float $prospectiveDurationHours = null,
		?float $prospectiveDays = null,
	): void {
		$today = \DateTime::createFromImmutable($this->todayDateInStorage());
		$hoursMode = $this->vacationUnitService?->isHoursMode() === true;
		$unitLabel = $hoursMode
			? $this->l10n->t('hours')
			: $this->l10n->t('days');

		if ($this->vacationYearWindowResolver->isAnniversaryMode()) {
			$windows = $this->vacationYearWindowResolver->windowsOverlappingRange($userId, $startDate, $endDate);
			foreach ($windows as $window) {
				if ($window->missingEmploymentStart) {
					throw new \Exception($this->l10n->t('Vacation year is set to hire anniversary, but this account has no employment start date. Ask your admin to set it under Employees.'));
				}
				$alloc = $this->vacationAllocationService->computeAllocationForWindow(
					$userId,
					$window,
					$excludeAbsenceId,
					$startDate,
					$endDate,
					$today,
					$prospectiveRequestCreatedAt,
					false,
					$prospectiveDurationHours,
					$prospectiveDays
				);
				if ($alloc['allocation_valid']) {
					continue;
				}
				$before = $this->vacationAllocationService->computeAllocationForWindow(
					$userId,
					$window,
					$excludeAbsenceId,
					null,
					null,
					$today,
					null,
					false,
					null,
					null
				);
				$remaining = (float)$before['total_remaining_for_new_requests'];
				throw new \Exception($this->l10n->t(
					'Not enough vacation %1$s left for %2$s (remaining: %3$s).',
					[$unitLabel, $window->label, (string)round($remaining, 1)]
				));
			}
			return;
		}

		$requestedWorkingDaysPerYear = $this->computeWorkingDaysPerYear($startDate, $endDate, $userId);
		if ($requestedWorkingDaysPerYear === []) {
			$requestedWorkingDaysPerYear = $this->holidayCalendarService->computeWorkingDaysPerYearForUser(
				$userId,
				clone $startDate,
				clone $endDate
			);
		}
		foreach ($requestedWorkingDaysPerYear as $y => $requestedDays) {
			if ($requestedDays <= 0 && !($hoursMode && $prospectiveDurationHours !== null && $prospectiveDurationHours > 0)) {
				continue;
			}
			$year = (int)$y;
			$alloc = $this->vacationAllocationService->computeYearAllocation(
				$userId,
				$year,
				$excludeAbsenceId,
				$startDate,
				$endDate,
				$today,
				$prospectiveRequestCreatedAt,
				false,
				$prospectiveDurationHours,
				$prospectiveDays
			);
			if ($alloc['allocation_valid']) {
				continue;
			}
			$before = $this->vacationAllocationService->computeYearAllocation(
				$userId,
				$year,
				$excludeAbsenceId,
				null,
				null,
				$today,
				null,
				false,
				null,
				null
			);
			$requestedDisplay = $hoursMode && $prospectiveDurationHours !== null
				? (float)$prospectiveDurationHours
				: ($prospectiveDays !== null ? (float)$prospectiveDays : (float)$requestedDays);
			$msg = $this->l10n->t(
				'Not enough vacation remaining. You have %1$s %2$s left for %3$s but requested %4$s %2$s.',
				[
					(string)round($before['total_remaining_for_new_requests'], 1),
					$unitLabel,
					(string)$year,
					(string)round($requestedDisplay, 1),
				]
			);
			throw new \Exception($msg);
		}
	}

	/**
	 * After shortening an approved hours-mode vacation, re-debit from schedule Sollzeit
	 * for the new range. Preserves partial-day ratio vs the original full-range estimate
	 * (never blind working-day ratio — BANSS Mon–Fri 38.5 → Mon–Thu is 34.0, not 30.8).
	 *
	 * @param array<string, mixed> $oldData Prior absence summary (days, etc.)
	 */
	private function recomputeVacationHoursAfterShorten(
		Absence $absence,
		\DateTimeInterface $originalEndDate,
		array $oldData,
		float $workingDays,
	): void {
		if ($this->vacationUnitService === null || !$this->vacationUnitService->isHoursMode()) {
			return;
		}
		$userId = (string)$absence->getUserId();
		$oldHoursStored = $absence->getDurationHours();
		$oldDays = (float)($oldData['days'] ?? 0);
		$startForDebit = $absence->getStartDate();
		$newEnd = $absence->getEndDate();
		if ($startForDebit === null || $newEnd === null) {
			throw new \Exception($this->l10n->t('Start date or end date is missing for this absence.'));
		}
		$newDebit = $this->resolveRangeDebitHours($userId, $startForDebit, $newEnd, $workingDays);
		$oldDebit = $this->resolveRangeDebitHours($userId, $startForDebit, $originalEndDate, max(0.0, $oldDays));
		$oldEstHours = (float)($oldDebit['hours'] ?? 0.0);
		$newEstHours = (float)($newDebit['hours'] ?? 0.0);
		if ($oldHoursStored !== null && is_finite((float)$oldHoursStored) && (float)$oldHoursStored > 0 && $oldEstHours > 0.0001) {
			$ratio = min(1.0, (float)$oldHoursStored / $oldEstHours);
			$absence->setDurationHours(
				$this->vacationUnitService->roundAmount($newEstHours * $ratio)
			);
			return;
		}
		// Missing stored hours or zero old estimate: authoritative schedule refill.
		$this->applyVacationDurationHours(
			$absence,
			['server_may_fill_hours' => true],
			$workingDays
		);
	}

	/**
	 * Resolve and attach duration_hours for vacation when org unit is hours.
	 *
	 * @param array<string, mixed> $data
	 */
	private function applyVacationDurationHours(Absence $absence, array $data, float $workingDays): void
	{
		if ($this->vacationUnitService === null || !$this->vacationUnitService->isHoursMode()) {
			$absence->setDurationHours(null);
			return;
		}
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		if ($start === null || $end === null) {
			throw new BusinessRuleException($this->l10n->t('Type, start date, and end date are required'));
		}
		$hours = $this->resolveVacationDurationHours(
			$data,
			$workingDays,
			(string)$absence->getUserId(),
			$start,
			$end
		);
		$absence->setDurationHours($hours);
	}

	/**
	 * Hours debit for a range: prefer work-model Sollzeit, never blind org 8 h × days
	 * when a weekday schedule or model daily hours exist.
	 *
	 * @return array{hours: float, one_day: float, average_daily: float, basis: string}
	 */
	private function resolveRangeDebitHours(
		string $userId,
		\DateTimeInterface $start,
		\DateTimeInterface $end,
		float $workingDays,
	): array {
		if ($this->vacationHoursDebitService !== null) {
			$est = $this->vacationHoursDebitService->estimateForUserRange($userId, $start, $end);
			return [
				'hours' => (float)$est['hours'],
				'one_day' => (float)$est['one_day_hours'],
				'average_daily' => (float)$est['average_daily'],
				'basis' => (string)$est['basis'],
			];
		}
		// Unit-test / legacy DI without debit service: org conversion factor only.
		$one = $this->vacationUnitService?->getHoursPerDay() ?? Constants::DEFAULT_VACATION_HOURS_PER_DAY;
		$max = $this->vacationUnitService !== null
			? $this->vacationUnitService->daysToHours($workingDays)
			: round($workingDays * $one, 2);
		return [
			'hours' => $max,
			'one_day' => $one,
			'average_daily' => $one,
			'basis' => 'org_hours_per_day',
		];
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function resolveVacationDurationHours(
		array $data,
		float $workingDays,
		string $userId = '',
		?\DateTimeInterface $start = null,
		?\DateTimeInterface $end = null,
	): float {
		if ($this->vacationUnitService === null) {
			return 0.0;
		}
		$debit = null;
		if ($userId !== '' && $start !== null && $end !== null) {
			$debit = $this->resolveRangeDebitHours($userId, $start, $end, $workingDays);
		}
		$oneDay = (float)($debit['one_day'] ?? $this->vacationUnitService->getHoursPerDay());
		$maxForRange = (float)($debit['hours'] ?? $this->vacationUnitService->daysToHours($workingDays));

		if (isset($data['duration_hours']) && $data['duration_hours'] !== '' && $data['duration_hours'] !== null) {
			$hours = (float)str_replace(',', '.', (string)$data['duration_hours']);
			if (!is_finite($hours) || $hours <= 0) {
				throw new BusinessRuleException($this->l10n->t('Vacation hours must be greater than zero.'));
			}
			if ($hours > 24.0 * 31.0) {
				throw new BusinessRuleException($this->l10n->t('Vacation hours exceed the maximum for one request.'));
			}
			$hours = $this->vacationUnitService->roundAmount($hours);

			// Hard ceiling: never debit more than schedule-/holiday-aware max for the
			// selected range. Client Mon–Fri nets (or flat N×hours) often ignore
			// public holidays; heuristic “looks like flat days” missed BANSS-style
			// weekday matrices (e.g. 30 h posted vs 21.5 h max after a mid-week holiday).
			if ($hours > $maxForRange + 0.011) {
				return $maxForRange;
			}

			// Expand one-day posts only for legacy/date-only clients that did not
			// mark the total as authoritative. Unit-aware clients send
			// require_duration_hours=1 and must not have their preview rewritten.
			$authoritative = !empty($data['require_duration_hours']);
			if (!$authoritative && $workingDays >= 1.99 && abs($hours - $oneDay) < 0.011) {
				return $maxForRange;
			}
			return $hours;
		}
		if ($workingDays < 0.01) {
			throw new BusinessRuleException(
				$this->l10n->t('Vacation must include at least one working day. The selected period contains only weekends or public holidays.')
			);
		}
		// Explicit opt-in for server fill (web backup when field empty) — schedule-aware.
		if (!empty($data['server_may_fill_hours'])) {
			return $maxForRange;
		}
		if (!empty($data['require_duration_hours'])) {
			throw new BusinessRuleException(
				$this->l10n->t('This organisation books vacation in hours. Please enter the number of hours.'),
				Constants::ABSENCE_HOURS_CLIENT_REQUIRED
			);
		}
		throw new BusinessRuleException(
			$this->l10n->t('This organisation books vacation in hours. Please update the Employee app and enter the number of hours.'),
			Constants::ABSENCE_HOURS_CLIENT_REQUIRED
		);
	}

	/**
	 * Validate absence data
	 *
	 * @param array $data Absence data
	 * @param string $userId User ID (absence owner)
	 * @param int|null $excludeAbsenceId When updating, ID of the absence to exclude from overlap check
	 * @param array<string, bool> $options Recognized keys: `skip_substitute_rules` (bool) — skips substitute requirement and colleague checks (manager-recorded absences).
	 * @throws \Exception
	 */
	private function validateAbsenceData(array $data, string $userId, ?int $excludeAbsenceId = null, ?\DateTimeInterface $vacationRequestCreatedAt = null, array $options = []): void
	{
		// Validate required fields
		if (empty($data['type']) || empty($data['start_date']) || empty($data['end_date'])) {
			throw new \Exception($this->l10n->t('Type, start date, and end date are required'));
		}

		// Validate dates (parseDate handles both ISO and German format)
		$startDate = $this->parseDate($data['start_date']);
		$endDate = $this->parseDate($data['end_date']);

		if ($startDate > $endDate) {
			throw new \Exception($this->l10n->t('Start date cannot be after end date'));
		}

		// Extract type early (needed for past-date and overlap logic)
		$type = isset($data['type']) && !is_array($data['type']) ? (string)$data['type'] : (is_array($data['type'] ?? null) && !empty($data['type']) ? (string)reset($data['type']) : '');

		// Check for overlapping absences (exclude current absence when updating)
		$overlapping = $this->absenceMapper->findOverlapping($userId, $startDate, $endDate, $excludeAbsenceId);
		if (!empty($overlapping)) {
			$first = $overlapping[0];
			$overlapType = $first->getType();
			$overlapStart = $first->getStartDate() ? $first->getStartDate()->format('d.m.Y') : '?';
			$overlapEnd = $first->getEndDate() ? $first->getEndDate()->format('d.m.Y') : '?';
			$typeLabels = [
				'vacation' => $this->l10n->t('Vacation'),
				'sick_leave' => $this->l10n->t('Sick Leave'),
				'personal_leave' => $this->l10n->t('Personal Leave'),
				'parental_leave' => $this->l10n->t('Parental Leave'),
				'special_leave' => $this->l10n->t('Special Leave'),
				'unpaid_leave' => $this->l10n->t('Unpaid Leave'),
				'home_office' => $this->l10n->t('Home Office'),
				'business_trip' => $this->l10n->t('Business Trip'),
			];
			$overlapTypeLabel = $typeLabels[$overlapType] ?? $this->l10n->t('Absence');
			$baseMsg = $this->l10n->t('This period overlaps with an existing %1$s (%2$s – %3$s).', [$overlapTypeLabel, $overlapStart, $overlapEnd]);
			if ($type === Absence::TYPE_SICK_LEAVE && $overlapType === Absence::TYPE_VACATION) {
				$hint = $this->l10n->t('If you were sick during vacation, please shorten or cancel the vacation first, then submit a separate sick leave request.');
				throw new \Exception($baseMsg . ' ' . $hint);
			}
			throw new \Exception($baseMsg);
		}

		// Validate type
		if (empty($type)) {
			throw new \Exception($this->l10n->t('Absence type is required'));
		}
		$validTypes = [
			Absence::TYPE_VACATION,
			Absence::TYPE_SICK_LEAVE,
			Absence::TYPE_PERSONAL_LEAVE,
			Absence::TYPE_PARENTAL_LEAVE,
			Absence::TYPE_SPECIAL_LEAVE,
			Absence::TYPE_UNPAID_LEAVE,
			Absence::TYPE_HOME_OFFICE,
			Absence::TYPE_BUSINESS_TRIP,
		];
		if (!in_array($type, $validTypes, true)) {
			throw new \Exception($this->l10n->t('Invalid absence type'));
		}
		$this->validateAbsenceTypeRules($type, $startDate, $endDate);

		// Vacation entitlement: FIFO carryover + annual (see VacationAllocationService)
		if ($type === Absence::TYPE_VACATION) {
			$requestedWorkingDaysPerYear = $this->computeWorkingDaysPerYear($startDate, $endDate, $userId);
			if ($requestedWorkingDaysPerYear === []) {
				$requestedWorkingDaysPerYear = $this->holidayCalendarService->computeWorkingDaysPerYearForUser(
					$userId,
					clone $startDate,
					clone $endDate
				);
			}
			$totalRequested = array_sum($requestedWorkingDaysPerYear);
			$hoursMode = $this->vacationUnitService?->isHoursMode() === true;
			$durationHours = null;
			$prospectiveDays = null;
			if ($hoursMode) {
				// Must use the same schedule-/holiday-aware debit as applyVacationDurationHours
				// so entitlement checks match the amount that will be stored.
				$durationHours = $this->resolveVacationDurationHours(
					$data,
					(float)$totalRequested,
					$userId,
					$startDate,
					$endDate
				);
			} else {
				// Days mode: resolve half/full debit before allocation gate (ADR-05).
				$prospectiveDays = $this->resolveVacationDaysDebit($userId, $startDate, $endDate, $data);
			}
			// Hours mode: schedule-/holiday-aware debit is authoritative (Sat work models
			// can have net hours while Mon–Fri “working days” is 0).
			if ($hoursMode && ($durationHours === null || $durationHours < 0.01)) {
				throw new \Exception($this->l10n->t('Vacation must include at least one working day. The selected period contains only weekends or public holidays.'));
			}
			if (!$hoursMode && ($totalRequested < 0.01 || $prospectiveDays === null || $prospectiveDays < 0.01)) {
				throw new \Exception($this->l10n->t('Vacation must include at least one working day. The selected period contains only weekends or public holidays.'));
			}
			$this->assertVacationAllocationForRequest(
				$userId,
				$startDate,
				$endDate,
				$excludeAbsenceId,
				$vacationRequestCreatedAt,
				$durationHours,
				$prospectiveDays
			);
		}

		$skipSubstituteRules = !empty($options['skip_substitute_rules']);

		/* Historical entries (end date strictly before today, in server TZ) are
		 * exempt from substitute rules: Vertretung is a forward-looking workflow
		 * (someone needs to *cover* future tasks) and has no meaning for an
		 * absence that already happened. The frontend mirrors this and disables
		 * the Vertretung field for past dates, but we enforce it here too so
		 * API consumers cannot trip over the same rule. */
		$todayForSubstitute = \DateTime::createFromImmutable($this->todayDateInStorage());
		$absenceFullyInPast = $endDate < $todayForSubstitute;

		if (!$skipSubstituteRules && !$absenceFullyInPast) {
			$requireSubstituteTypesJson = $this->config->getAppValue('arbeitszeitcheck', 'require_substitute_types', '[]');
			$requireSubstituteTypes = json_decode($requireSubstituteTypesJson, true);
			if (is_array($requireSubstituteTypes) && in_array($type, $requireSubstituteTypes, true)) {
				$substituteId = isset($data['substitute_user_id']) ? trim((string)$data['substitute_user_id']) : '';
				if ($substituteId === '') {
					throw new \Exception($this->l10n->t('A substitute is required for this absence type. Please select who will cover for you.'));
				}
			}

			// Validate substitute: must be a colleague (same team/group), existing and enabled (not self)
			$substituteId = isset($data['substitute_user_id']) ? trim((string)$data['substitute_user_id']) : '';
			if ($substituteId !== '') {
				if ($substituteId === $userId) {
					throw new \Exception($this->l10n->t('Substitute cannot be yourself'));
				}
				$substituteUser = $this->userManager->get($substituteId);
				if ($substituteUser === null || !$substituteUser->isEnabled()) {
					throw new \Exception($this->l10n->t('Substitute must be an existing user'));
				}
				$colleagueIds = $this->teamResolver->getColleagueIds($userId);
				if (!in_array($substituteId, $colleagueIds, true)) {
					throw new \Exception($this->l10n->t('Substitute must be a colleague in your team. Please select someone who shares a team or group with you.'));
				}
			}
		}
	}

	/**
	 * Validate absence type specific rules
	 *
	 * @param string $type Absence type
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @throws \Exception
	 */
	/**
	 * Validate absence type rules (max calendar days per type).
	 * Limits are documented here and enforced for consistency and abuse prevention.
	 */
	private function validateAbsenceTypeRules(string $type, \DateTime $startDate, \DateTime $endDate): void
	{
		$days = $startDate->diff($endDate)->days + 1;

		switch ($type) {
			case Absence::TYPE_VACATION:
				if ($days > 30) {
					throw new \Exception($this->l10n->t('Vacation cannot exceed 30 days'));
				}
				break;
			case Absence::TYPE_SICK_LEAVE:
				if ($days > Constants::MAX_ABSENCE_DAYS) {
					throw new \Exception($this->l10n->t('Sick leave duration seems unreasonable'));
				}
				break;
			case Absence::TYPE_PERSONAL_LEAVE:
				if ($days > 5) {
					throw new \Exception($this->l10n->t('Personal leave cannot exceed 5 days'));
				}
				break;
			case Absence::TYPE_PARENTAL_LEAVE:
				if ($days > 1095) { // ~3 years
					throw new \Exception($this->l10n->t('Parental leave cannot exceed 3 years per request'));
				}
				break;
			case Absence::TYPE_SPECIAL_LEAVE:
				if ($days > 30) {
					throw new \Exception($this->l10n->t('Special leave cannot exceed 30 days'));
				}
				break;
			case Absence::TYPE_UNPAID_LEAVE:
				if ($days > Constants::MAX_ABSENCE_DAYS) {
					throw new \Exception($this->l10n->t('Unpaid leave cannot exceed 365 days'));
				}
				break;
			case Absence::TYPE_HOME_OFFICE:
			case Absence::TYPE_BUSINESS_TRIP:
				if ($days > Constants::MAX_ABSENCE_DAYS) {
					throw new \Exception($this->l10n->t('Duration cannot exceed 365 days'));
				}
				break;
		}
	}

	/**
	 * Perform only the DB writes needed to auto-approve an absence (no notifications/emails).
	 *
	 * Call this inside an open transaction so the status update and the audit log are
	 * committed atomically. Send notifications after the caller commits.
	 */
	private function doAutoApproveDbWork(Absence $absence): Absence
	{
		$this->assertAbsenceMutable($absence);

		if ($absence->getType() === Absence::TYPE_VACATION) {
			$this->assertVacationUnitMigrationIdle();
			$sd = $absence->getStartDate();
			$ed = $absence->getEndDate();
			if ($sd && $ed) {
				$this->lockVacationApprovalScope($absence->getUserId(), $sd, $ed);
				$this->assertVacationAllocationForRequest(
					$absence->getUserId(),
					$sd,
					$ed,
					null,
					$absence->getCreatedAt(),
					$absence->getDurationHours() !== null ? (float)$absence->getDurationHours() : null,
					$absence->getDays() !== null ? (float)$absence->getDays() : null
				);
			}
		}

		$oldData = $absence->getSummary();
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setApproverComment($this->l10n->t('Auto-approved: no approver is assigned to your team in the app.'));
		$absence->setApprovedBy(null);
		$absence->setApprovedByUserId('system');
		$absence->setApprovedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

		$updatedAbsence = $this->absenceMapper->update($absence);

		$this->auditLogMapper->logAction(
			$absence->getUserId(),
			'absence_auto_approved',
			'absence',
			$updatedAbsence->getId(),
			$oldData,
			$updatedAbsence->getSummary(),
			'system'
		);

		return $updatedAbsence;
	}

	/**
	 * Auto-approve an absence when no assignable manager exists for the employee.
	 * Ensures absences are not stuck in PENDING when nobody can approve under current team rules.
	 *
	 * This method wraps `doAutoApproveDbWork` in its own transaction and then sends
	 * notifications. Prefer calling `doAutoApproveDbWork` directly inside a caller-owned
	 * transaction (e.g. createAbsence, approveBySubstitute) to keep everything atomic.
	 */
	private function autoApproveForNoManager(Absence $absence): Absence
	{
		$updatedAbsence = null;

		$this->db->beginTransaction();
		try {
			$updatedAbsence = $this->doAutoApproveDbWork($absence);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		if ($this->notificationService) {
			$this->notificationService->notifyAbsenceApproved(
				$updatedAbsence->getUserId(),
				$this->buildAbsenceNotificationData($updatedAbsence)
			);
		}

		if ($this->absenceIcalMailService) {
			$this->absenceIcalMailService->sendIcalForApprovedAbsence($updatedAbsence);
		}

		return $updatedAbsence;
	}

	/**
	 * Repair / migration: if an absence is still pending but no user can approve it under current
	 * team rules, auto-approve it (same outcome as at creation time). Idempotent.
	 *
	 * @return bool True if the absence was updated
	 */
	public function autoApprovePendingIfNoAssignableManager(int $absenceId): bool
	{
		try {
			$absence = $this->absenceMapper->find($absenceId);
		} catch (DoesNotExistException $e) {
			return false;
		}
		if ($absence->getStatus() !== Absence::STATUS_PENDING) {
			return false;
		}
		if ($this->teamResolver->hasAssignableManagerForEmployee($absence->getUserId())) {
			return false;
		}
		$this->autoApproveForNoManager($absence);
		return true;
	}

	/**
	 * Parse date string - supports both ISO (yyyy-mm-dd) and German format (dd.mm.yyyy)
	 *
	 * @param string $dateString Date string in either format
	 * @return \DateTime
	 * @throws \Exception if date cannot be parsed
	 */
	private function parseDate(string $dateString): \DateTime
	{
		$dateString = trim($dateString);
		if ($dateString === '') {
			throw new \Exception($this->l10n->t('Date is required and cannot be empty'));
		}

		// Try German format first (dd.mm.yyyy)
		if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $dateString, $matches)) {
			$day = (int)$matches[1];
			$month = (int)$matches[2];
			$year = (int)$matches[3];
			
			// Validate date
			if (!checkdate($month, $day, $year)) {
				throw new \Exception($this->l10n->t('Invalid date: %s', [$dateString]));
			}
			
			return new \DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
		}
		
		// Strict ISO format only (yyyy-mm-dd). Reject ambiguous or relative strings.
		$iso = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateString);
		$isoErrors = \DateTimeImmutable::getLastErrors();
		$isoIsValid = $iso !== false
			&& ($isoErrors === false || (($isoErrors['warning_count'] ?? 0) === 0 && ($isoErrors['error_count'] ?? 0) === 0))
			&& $iso->format('Y-m-d') === $dateString;
		if ($isoIsValid) {
			return new \DateTime($iso->format('Y-m-d'));
		}

		throw new \Exception($this->l10n->t('Invalid date format. Expected yyyy-mm-dd or dd.mm.yyyy: %s', [$dateString]));
	}

	private function assertAbsenceMutable(Absence $absence): void
	{
		if ($this->monthClosureService === null) {
			return;
		}

		$startDate = $absence->getStartDate();
		$endDate = $absence->getEndDate();
		if ($startDate === null || $endDate === null) {
			throw new \Exception($this->l10n->t('Start date or end date is missing for this absence.'));
		}

		$endDateInclusive = clone $endDate;
		$endDateInclusive->setTime(23, 59, 59);
		$this->monthClosureService->assertDateRangeMutable($absence->getUserId(), $startDate, $endDateInclusive);
	}

	/**
	 * Normalize request day_fraction to 1.0 or 0.5 (SEC-02 / Argus TH-04).
	 *
	 * @param mixed $raw
	 * @throws BusinessRuleException
	 */
	private function normalizeDayFraction(mixed $raw): float
	{
		if ($raw === null || $raw === '' || (is_array($raw) && $raw === [])) {
			return 1.0;
		}
		if (is_array($raw)) {
			$raw = reset($raw);
		}
		if (is_bool($raw) || is_object($raw)) {
			throw new BusinessRuleException(
				$this->l10n->t('Invalid day fraction. Use full day or half day.'),
				Constants::VAC_HALF_DAY_INVALID
			);
		}
		$s = trim((string)$raw);
		if ($s === '') {
			return 1.0;
		}
		$s = str_replace(',', '.', $s);
		if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
			throw new BusinessRuleException(
				$this->l10n->t('Invalid day fraction. Use full day or half day.'),
				Constants::VAC_HALF_DAY_INVALID
			);
		}
		$v = (float)$s;
		if (!is_finite($v)) {
			throw new BusinessRuleException(
				$this->l10n->t('Invalid day fraction. Use full day or half day.'),
				Constants::VAC_HALF_DAY_INVALID
			);
		}
		// Strict membership on the parsed float (rejects 0.5000001 / 0.25).
		if (abs($v - 1.0) < 1e-9) {
			return 1.0;
		}
		if (abs($v - 0.5) < 1e-9) {
			return 0.5;
		}
		throw new BusinessRuleException(
			$this->l10n->t('Invalid day fraction. Use full day or half day.'),
			Constants::VAC_HALF_DAY_INVALID
		);
	}

	/**
	 * Single write-path authority for vacation days debit (ADR-06).
	 * Hours mode ignores day_fraction and returns calendar working-day weight.
	 * Never trusts client `days` / `working_days` (SEC-02).
	 *
	 * @param array<string, mixed> $data
	 * @throws BusinessRuleException|\Exception
	 */
	private function resolveVacationDaysDebit(
		string $userId,
		\DateTime $start,
		\DateTime $end,
		array $data,
	): float {
		$w = $this->computeWorkingDaysForUser($userId, $start, $end);

		// Hours path remains authoritative for duration_hours; days column stays calendar weight.
		if ($this->vacationUnitService?->isHoursMode() === true) {
			return $w;
		}

		$fraction = $this->normalizeDayFraction($data['day_fraction'] ?? null);

		if (abs($fraction - 0.5) < 1e-9) {
			if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
				throw new BusinessRuleException(
					$this->l10n->t('Half-day vacation is only allowed when start and end are the same day. For mixed half and full days, submit separate requests.'),
					Constants::VAC_HALF_DAY_RANGE_FORBIDDEN
				);
			}
			if ($w < 0.999) {
				throw new BusinessRuleException(
					$this->l10n->t('This day is not a full working day; half-day vacation cannot be booked.'),
					Constants::VAC_HALF_DAY_NON_WORKING
				);
			}
			$debit = 0.5;
		} else {
			if ($w < 0.01) {
				throw new \Exception($this->l10n->t('Vacation must include at least one working day. The selected period contains only weekends or public holidays.'));
			}
			$debit = $w;
		}

		// Defensive write-path integrity (should always hold when only this helper sets days).
		$probe = new Absence();
		$probe->setStartDate(clone $start);
		$probe->setEndDate(clone $end);
		$probe->setDays($debit);
		if (!VacationAllocationService::isTrustedStoredVacationDays($probe, $debit, $w)) {
			throw new BusinessRuleException(
				$this->l10n->t('Vacation day amount is inconsistent with the selected dates.'),
				Constants::VAC_HALF_DAY_INTEGRITY
			);
		}

		return $debit;
	}

	/**
	 * Compute working days per year for a date range (excludes weekends and German public holidays)
	 *
	 * @param \DateTime $start
	 * @param \DateTime $end
	 * @return array<int, float> year => working days
	 */
	private function computeWorkingDaysPerYear(\DateTime $start, \DateTime $end, string $userId): array
	{
		return $this->holidayCalendarService->computeWorkingDaysPerYearForUser($userId, $start, $end);
	}

	/**
	 * Compute working days for a user absence, taking into account
	 * company-wide holidays (full and half days).
	 */
	private function computeWorkingDaysForUser(string $userId, \DateTime $start, \DateTime $end): float
	{
		return $this->holidayCalendarService->computeWorkingDaysForUser($userId, $start, $end);
	}

	/**
	 * Build notification payload with a reliable working-day count for display.
	 *
	 * @return array{id: int|null, absence_id: int|null, type: string, start_date: string|null, end_date: string|null, days: float}
	 */
	private function buildAbsenceNotificationData(Absence $absence): array
	{
		return AbsenceNotificationPayload::fromAbsence(
			$absence,
			$this->getWorkingDaysForDisplay($absence)
		);
	}

	/**
	 * Get working days for display (state-aware).
	 * Uses stored days when set; otherwise computes via HolidayService
	 * for consistency with vacation stats and company/state holidays.
	 */
	public function getWorkingDaysForDisplay(Absence $absence): float
	{
		if ($absence->getDays() !== null) {
			return (float)$absence->getDays();
		}
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		if (!$start || !$end) {
			return 0.0;
		}
		return $this->holidayCalendarService->computeWorkingDaysForUser(
			$absence->getUserId(),
			$start,
			$end
		);
	}

	/**
	 * Build a map of additional holiday weights (full/half Firmenfeiertage)
	 * for the given date range and user.
	 *
	 * NOTE:
	 * - Aktuell sind Firmenfeiertage organisationsweit konfiguriert
	 *   (ohne Bundeslandspezifik). Pro-User-Bundesland wirkt sich daher
	 *   nur auf spätere, state-spezifische Erweiterungen aus.
	 *
	 * @return array<string,float> date (Y-m-d) => weight
	 */
	private function buildExtraHolidayWeights(\DateTime $start, \DateTime $end, string $userId): array
	{
		// Legacy helper is kept for backward compatibility with Absence::calculateWorkingDays()
		// and will internally delegate to HolidayService in future iterations if needed.
		unset($start, $end, $userId);
		return [];
	}
}