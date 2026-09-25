<?php

declare(strict_types=1);

/**
 * Admin-side CRUD for L0 / L1 / L2 vacation entitlement defaults.
 *
 * Responsibilities:
 *
 *  - Persist {@see OrgVacationDefault}, {@see ModelVacationDefault},
 *    {@see TeamVacationPolicy} via {@see DBAL} transactions.
 *  - Run pre-flight validation that goes beyond field validators
 *    (referential integrity for `working_time_model_id` and `team_id`,
 *    tariff rule-set activation status, organisation-default
 *    open-ended-row uniqueness).
 *  - Emit audit-log entries with before/after JSON payloads via
 *    {@see AuditLogMapper}, covering REQ-AUD-02.
 *  - Maintain an advisory app lock around writes so two admins editing
 *    the same layer concurrently produce a `409 Conflict` rather than a
 *    silent last-writer-wins (REQ-SEC-04 / EC-07).
 *
 * The service never returns HTTP responses itself; it returns DTO-style
 * arrays or throws domain exceptions that the controller maps to JSON
 * responses. This keeps the service reusable from CLI repair commands and
 * background jobs.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ModelVacationDefault;
use OCA\ArbeitszeitCheck\Db\ModelVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\OrgVacationDefault;
use OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamVacationPolicy;
use OCA\ArbeitszeitCheck\Db\TeamVacationPolicyMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Support\StrictYmdDates;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class LayeredVacationDefaultsService
{
	use TTransactional;

	private const LOCK_NAMESPACE = 'arbeitszeitcheck/layered-vacation';

	public function __construct(
		private readonly OrgVacationDefaultMapper $orgMapper,
		private readonly ModelVacationDefaultMapper $modelMapper,
		private readonly TeamVacationPolicyMapper $teamPolicyMapper,
		private readonly TeamMapper $teamMapper,
		private readonly WorkingTimeModelMapper $workingTimeModelMapper,
		private readonly TariffRuleSetMapper $tariffRuleSetMapper,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly IDBConnection $db,
		private readonly ILockingProvider $lockingProvider,
		private readonly ?TeamMemberMapper $teamMemberMapper = null,
		private readonly ?UserWorkingTimeModelMapper $userWorkingTimeModelMapper = null,
		private readonly ?VacationUnitService $vacationUnitService = null,
	) {
	}

	/* ============================================================ *
	 * L0 — Organisation default
	 * ============================================================ */

	/**
	 * Find the currently active L0 row (today). Returns null when nothing
	 * has been configured yet.
	 */
	public function getActiveOrgDefault(?\DateTimeInterface $asOfDate = null): ?OrgVacationDefault
	{
		return $this->orgMapper->findActiveByDate($asOfDate ?? new \DateTimeImmutable('today'));
	}

	/**
	 * @return OrgVacationDefault[]
	 */
	public function listOrgDefaults(): array
	{
		return $this->orgMapper->findAll();
	}

	/**
	 * Insert a new organisation default. Closes the currently open-ended row
	 * (`effective_to IS NULL`) atomically by setting its `effective_to` to
	 * the new row's `effective_from - 1 day`, so the resolution chain only
	 * ever sees one active L0 row per date.
	 *
	 * @param array{vacationMode: string, manualDays?: float|null, tariffRuleSetId?: int|null, description?: string|null, effectiveFrom: string, effectiveTo?: string|null} $payload
	 * @param string $performedBy
	 */
	public function upsertOrgDefault(array $payload, string $performedBy): OrgVacationDefault
	{
		$lockKey = self::LOCK_NAMESPACE . '/org';
		$this->acquireWriteLock($lockKey, 'Org vacation default write lock');
		try {
			return $this->atomic(function () use ($payload, $performedBy): OrgVacationDefault {
				$payload = $this->normalizeVacationLayerPayload($payload);
				$entity = new OrgVacationDefault();
				$entity->setVacationMode((string)($payload['vacationMode'] ?? Constants::VACATION_MODE_MANUAL_FIXED));
				$entity->setManualDays(isset($payload['manualDays']) ? $this->parseDecimal($payload['manualDays']) : null);
				$entity->setTariffRuleSetId(isset($payload['tariffRuleSetId']) && $payload['tariffRuleSetId'] !== '' ? (int)$payload['tariffRuleSetId'] : null);
				$entity->setDescription(isset($payload['description']) ? trim((string)$payload['description']) : null);
				[$effFrom, $effTo] = $this->parseVacationLayerEffectiveRangeOrThrow($payload);
				$entity->setEffectiveFrom($effFrom);
				$entity->setEffectiveTo($effTo);
				$entity->setVersion(1);
				$entity->setCreatedBy($performedBy);
				$entity->setCreatedAt(new \DateTime());
				$entity->setUpdatedAt(new \DateTime());

				$errors = $entity->validate();
				if ($errors !== []) {
					throw new LayeredVacationValidationException('Validation failed', $errors);
				}
				$this->assertTariffRuleSetUsable($entity->getTariffRuleSetId(), $entity->getEffectiveFrom());

				// REQ-DAT-03 — refuse overlapping *closed* ranges before they
				// land in the DB. We can still safely auto-trim an existing
				// open-ended row (the docs call this out explicitly as
				// "supersede"), but a newly-saved closed range that overlaps
				// an existing closed range is always operator error and
				// should not silently emit a
				// `degraded_org_default_collision` flag at resolution time.
				$overlaps = $this->orgMapper->findOverlappingRanges(
					$entity->getEffectiveFrom(),
					$entity->getEffectiveTo(),
					null,
				);
				$blocking = array_values(array_filter($overlaps, function (array $row) use ($entity): bool {
					// Open-ended pre-existing row will be auto-closed by
					// `closeOverlappingOpenRows`. Anything else is a hard conflict.
					if ($row['effective_to'] === null && $entity->getEffectiveTo() === null) {
						// Two open-ended rows: trim the existing one.
						return false;
					}
					if ($row['effective_to'] === null && $entity->getEffectiveFrom() !== null) {
						// New row is closed but starts on/after the existing
						// open row's `effective_from` — auto-trim handles it.
						return $row['effective_from'] > $entity->getEffectiveFrom()->format('Y-m-d');
					}
					// Pre-existing row has a closed range — overlap is blocking.
					return true;
				}));
				if ($blocking !== []) {
					$first = $blocking[0];
					$range = $first['effective_from'] . ' → ' . ($first['effective_to'] ?? '∞');
					throw new LayeredVacationValidationException('Validation failed', [
						'effectiveFrom' => sprintf(
							'Overlaps existing organisation default #%d (%s). Adjust the date range or delete the existing row.',
							$first['id'],
							$range,
						),
					]);
				}

				// Close currently open-ended overlapping row(s).
				$trimmed = $this->orgMapper->closeOverlappingOpenRows($entity->getEffectiveFrom());
				$saved = $this->orgMapper->insert($entity);

				$this->auditLogMapper->logAction(
					'system',
					'create',
					Constants::AUDIT_ENTITY_ORG_VACATION_DEFAULT,
					(int)$saved->getId(),
					$trimmed === [] ? null : ['trimmed_open_rows' => $trimmed],
					$saved->getSummary(),
					$performedBy
				);
				return $saved;
			}, $this->db);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function deleteOrgDefault(int $id, string $performedBy): void
	{
		$lockKey = self::LOCK_NAMESPACE . '/org';
		$this->acquireWriteLock($lockKey, 'Org vacation default delete lock');
		try {
			try {
				$existing = $this->orgMapper->find($id);
			} catch (DoesNotExistException) {
				throw new LayeredVacationNotFoundException('Organisation vacation default not found');
			}
			$before = $existing->getSummary();
			$this->orgMapper->delete($existing);
			$this->auditLogMapper->logAction(
				'system',
				'delete',
				Constants::AUDIT_ENTITY_ORG_VACATION_DEFAULT,
				$id,
				$before,
				null,
				$performedBy
			);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/* ============================================================ *
	 * L1 — Working-time-model defaults
	 * ============================================================ */

	/**
	 * @return ModelVacationDefault[]
	 */
	public function listModelDefaults(?int $workingTimeModelId = null): array
	{
		if ($workingTimeModelId === null) {
			return $this->modelMapper->findAll();
		}
		return $this->modelMapper->findByModelId($workingTimeModelId);
	}

	/**
	 * @param array{workingTimeModelId: int, vacationMode: string, manualDays?: float|null, tariffRuleSetId?: int|null, description?: string|null, effectiveFrom: string, effectiveTo?: string|null} $payload
	 */
	public function upsertModelDefault(array $payload, string $performedBy): ModelVacationDefault
	{
		$workingTimeModelId = (int)($payload['workingTimeModelId'] ?? 0);
		if ($workingTimeModelId <= 0) {
			throw new LayeredVacationValidationException('Validation failed', ['workingTimeModelId' => 'Working time model is required']);
		}
		try {
			$this->workingTimeModelMapper->find($workingTimeModelId);
		} catch (DoesNotExistException) {
			throw new LayeredVacationNotFoundException('Working time model not found');
		}

		$lockKey = self::LOCK_NAMESPACE . '/model/' . $workingTimeModelId;
		$this->acquireWriteLock($lockKey, 'Model vacation default write lock');
		try {
			return $this->atomic(function () use ($payload, $workingTimeModelId, $performedBy): ModelVacationDefault {
				$payload = $this->normalizeVacationLayerPayload($payload);
				$entity = new ModelVacationDefault();
				$entity->setWorkingTimeModelId($workingTimeModelId);
				$entity->setVacationMode((string)($payload['vacationMode'] ?? Constants::VACATION_MODE_MANUAL_FIXED));
				$entity->setManualDays(isset($payload['manualDays']) ? $this->parseDecimal($payload['manualDays']) : null);
				$entity->setTariffRuleSetId(isset($payload['tariffRuleSetId']) && $payload['tariffRuleSetId'] !== '' ? (int)$payload['tariffRuleSetId'] : null);
				$entity->setDescription(isset($payload['description']) ? trim((string)$payload['description']) : null);
				[$effFrom, $effTo] = $this->parseVacationLayerEffectiveRangeOrThrow($payload);
				$entity->setEffectiveFrom($effFrom);
				$entity->setEffectiveTo($effTo);
				$entity->setVersion(1);
				$entity->setCreatedBy($performedBy);
				$entity->setCreatedAt(new \DateTime());
				$entity->setUpdatedAt(new \DateTime());

				$errors = $entity->validate();
				if ($errors !== []) {
					throw new LayeredVacationValidationException('Validation failed', $errors);
				}
				$this->assertTariffRuleSetUsable($entity->getTariffRuleSetId(), $entity->getEffectiveFrom());

				$saved = $this->modelMapper->insert($entity);
				$this->auditLogMapper->logAction(
					'system',
					'create',
					Constants::AUDIT_ENTITY_MODEL_VACATION_DEFAULT,
					(int)$saved->getId(),
					null,
					$saved->getSummary(),
					$performedBy
				);
				return $saved;
			}, $this->db);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function deleteModelDefault(int $id, string $performedBy): void
	{
		try {
			$existing = $this->modelMapper->find($id);
		} catch (DoesNotExistException) {
			throw new LayeredVacationNotFoundException('Model vacation default not found');
		}
		$lockKey = self::LOCK_NAMESPACE . '/model/' . (int)$existing->getWorkingTimeModelId();
		$this->acquireWriteLock($lockKey, 'Model vacation default delete lock');
		try {
			$before = $existing->getSummary();
			$this->modelMapper->delete($existing);
			$this->auditLogMapper->logAction(
				'system',
				'delete',
				Constants::AUDIT_ENTITY_MODEL_VACATION_DEFAULT,
				$id,
				$before,
				null,
				$performedBy
			);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Purge all L1 model vacation defaults for a working-time model that is
	 * about to be deleted. Caller must own the surrounding DB transaction.
	 * Does not acquire its own lock (nested with WTM delete atomic unit).
	 */
	public function deleteDefaultsForWorkingTimeModel(int $workingTimeModelId): int
	{
		if ($workingTimeModelId <= 0) {
			return 0;
		}
		return $this->modelMapper->deleteByModelId($workingTimeModelId);
	}

	/* ============================================================ *
	 * L2 — Team policies
	 * ============================================================ */

	/**
	 * @return TeamVacationPolicy[]
	 */
	public function listTeamPolicies(?int $teamId = null): array
	{
		if ($teamId === null) {
			return $this->teamPolicyMapper->findAll();
		}
		return $this->teamPolicyMapper->findByTeamId($teamId);
	}

	/**
	 * @param array{teamId: int, vacationMode: string, manualDays?: float|null, tariffRuleSetId?: int|null, description?: string|null, priority?: int, effectiveFrom: string, effectiveTo?: string|null} $payload
	 */
	public function upsertTeamPolicy(array $payload, string $performedBy): TeamVacationPolicy
	{
		$teamId = (int)($payload['teamId'] ?? 0);
		if ($teamId <= 0) {
			throw new LayeredVacationValidationException('Validation failed', ['teamId' => 'Team is required']);
		}
		try {
			$this->teamMapper->find($teamId);
		} catch (DoesNotExistException) {
			throw new LayeredVacationNotFoundException('Team not found');
		}
		$lockKey = self::LOCK_NAMESPACE . '/team/' . $teamId;
		$this->acquireWriteLock($lockKey, 'Team vacation policy write lock');
		try {
			return $this->atomic(function () use ($payload, $teamId, $performedBy): TeamVacationPolicy {
				$payload = $this->normalizeVacationLayerPayload($payload);
				$entity = new TeamVacationPolicy();
				$entity->setTeamId($teamId);
				$entity->setVacationMode((string)($payload['vacationMode'] ?? Constants::VACATION_MODE_MANUAL_FIXED));
				$entity->setManualDays(isset($payload['manualDays']) ? $this->parseDecimal($payload['manualDays']) : null);
				$entity->setTariffRuleSetId(isset($payload['tariffRuleSetId']) && $payload['tariffRuleSetId'] !== '' ? (int)$payload['tariffRuleSetId'] : null);
				$entity->setDescription(isset($payload['description']) ? trim((string)$payload['description']) : null);
				$entity->setPriority(isset($payload['priority']) ? (int)$payload['priority'] : 0);
				[$effFrom, $effTo] = $this->parseVacationLayerEffectiveRangeOrThrow($payload);
				$entity->setEffectiveFrom($effFrom);
				$entity->setEffectiveTo($effTo);
				$entity->setVersion(1);
				$entity->setCreatedBy($performedBy);
				$entity->setCreatedAt(new \DateTime());
				$entity->setUpdatedAt(new \DateTime());

				$errors = $entity->validate();
				if ($errors !== []) {
					throw new LayeredVacationValidationException('Validation failed', $errors);
				}
				$this->assertTariffRuleSetUsable($entity->getTariffRuleSetId(), $entity->getEffectiveFrom());

				$saved = $this->teamPolicyMapper->insert($entity);
				$this->auditLogMapper->logAction(
					'system',
					'create',
					Constants::AUDIT_ENTITY_TEAM_VACATION_POLICY,
					(int)$saved->getId(),
					null,
					$saved->getSummary(),
					$performedBy
				);
				return $saved;
			}, $this->db);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function deleteTeamPolicy(int $id, string $performedBy): void
	{
		try {
			$existing = $this->teamPolicyMapper->find($id);
		} catch (DoesNotExistException) {
			throw new LayeredVacationNotFoundException('Team vacation policy not found');
		}
		$lockKey = self::LOCK_NAMESPACE . '/team/' . (int)$existing->getTeamId();
		$this->acquireWriteLock($lockKey, 'Team vacation policy delete lock');
		try {
			$before = $existing->getSummary();
			$this->teamPolicyMapper->delete($existing);
			$this->auditLogMapper->logAction(
				'system',
				'delete',
				Constants::AUDIT_ENTITY_TEAM_VACATION_POLICY,
				$id,
				$before,
				null,
				$performedBy
			);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/* ============================================================ *
	 * Impact preview (REQ-UX-03)
	 * ============================================================ */

	/**
	 * Estimate the number of users that would be re-resolved when a write
	 * to the given layer happens. Returned numbers are intentionally
	 * *upper bounds* (counts of currently-assigned users) rather than full
	 * re-simulations — the goal is to give the admin a fast "you're about
	 * to change vacation for ~37 people" warning before they click save,
	 * not to compute every single resulting day count.
	 *
	 * The endpoint is read-only and never touches the locking provider,
	 * which means it stays cheap to call from a `change` handler in the JS.
	 *
	 * `$scope` is one of `org`, `model`, `team`, with an optional `targetId`
	 * for `model` / `team`. For `org` the result is "all users that aren't
	 * already overridden by L1/L2/L3" — but because we can't know that
	 * cheaply for every user without running the engine, we return the
	 * raw "in scope" count and let the UI label it "up to N users".
	 *
	 * @return array{scope: string, target_id: int|null, affected_user_count: int, exact: bool, note: string}
	 */
	public function previewImpact(string $scope, ?int $targetId = null): array
	{
		$scope = strtolower(trim($scope));
		switch ($scope) {
			case 'org':
				return [
					'scope' => 'org',
					'target_id' => null,
					'affected_user_count' => $this->countDistinctAssignableUsers(),
					'exact' => false,
					'note' => 'Upper bound: counts users without an L1/L2/L3 override at all. Some users may still be served by a higher layer.',
				];

			case 'model':
				if ($targetId === null || $targetId <= 0) {
					throw new LayeredVacationValidationException('Validation failed', ['targetId' => 'Working time model id is required for scope=model']);
				}
				return [
					'scope' => 'model',
					'target_id' => $targetId,
					'affected_user_count' => $this->countUsersOnWorkingTimeModel($targetId),
					'exact' => false,
					'note' => 'Counts active assignments of this working time model. Users with an L2/L3 override will not be re-resolved by an L1 change.',
				];

			case 'team':
				if ($targetId === null || $targetId <= 0) {
					throw new LayeredVacationValidationException('Validation failed', ['targetId' => 'Team id is required for scope=team']);
				}
				return [
					'scope' => 'team',
					'target_id' => $targetId,
					'affected_user_count' => $this->countUsersInTeamSubtree($targetId),
					'exact' => false,
					'note' => 'Counts members of the team and its descendants. Users with an L3 override will not be re-resolved by an L2 change.',
				];

			default:
				throw new LayeredVacationValidationException('Validation failed', ['scope' => 'Scope must be one of: org, model, team']);
		}
	}

	/**
	 * Best-effort upper bound for "users that may be served by L0". When
	 * the dependency isn't wired (legacy unit tests), returns 0 so the
	 * preview shows "0" rather than crashing.
	 */
	private function countDistinctAssignableUsers(): int
	{
		if ($this->userWorkingTimeModelMapper === null) {
			return 0;
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->createFunction('COUNT(DISTINCT user_id) AS cnt'))
				->from('at_user_working_time_models');
			$cursor = $qb->executeQuery();
			$row = $cursor->fetch();
			$cursor->closeCursor();
			return $row && isset($row['cnt']) ? (int)$row['cnt'] : 0;
		} catch (\Throwable) {
			return 0;
		}
	}

	private function countUsersOnWorkingTimeModel(int $workingTimeModelId): int
	{
		if ($this->userWorkingTimeModelMapper === null) {
			return 0;
		}
		try {
			return count($this->userWorkingTimeModelMapper->findByWorkingTimeModel($workingTimeModelId, true));
		} catch (\Throwable) {
			return 0;
		}
	}

	/**
	 * Walk the team tree downward from `$teamId` and count distinct user
	 * memberships across the subtree. Defensive: a malformed parent chain
	 * is bounded by a max-depth check so we never loop forever.
	 */
	private function countUsersInTeamSubtree(int $teamId): int
	{
		if ($this->teamMemberMapper === null) {
			return 0;
		}
		try {
			$teamIds = $this->collectTeamSubtreeIds($teamId);
			if ($teamIds === []) {
				return 0;
			}
			$userIds = $this->teamMemberMapper->getMemberUserIdsByTeamIds($teamIds);
			return count(array_unique($userIds));
		} catch (\Throwable) {
			return 0;
		}
	}

	/**
	 * @return int[]
	 */
	private function collectTeamSubtreeIds(int $rootId): array
	{
		try {
			$parentMap = $this->teamMapper->getParentMap();
		} catch (\Throwable) {
			return [$rootId];
		}
		$children = [];
		foreach ($parentMap as $childId => $parentId) {
			$childId = (int)$childId;
			$parentId = $parentId === null ? null : (int)$parentId;
			if ($parentId === null) {
				continue;
			}
			$children[$parentId] ??= [];
			$children[$parentId][] = $childId;
		}
		$collected = [];
		$stack = [$rootId];
		$maxIterations = 4096; // hard ceiling against pathological cycles
		while ($stack !== [] && $maxIterations-- > 0) {
			$current = (int)array_pop($stack);
			if (isset($collected[$current])) {
				continue;
			}
			$collected[$current] = true;
			foreach ($children[$current] ?? [] as $child) {
				if (!isset($collected[$child])) {
					$stack[] = $child;
				}
			}
		}
		return array_keys($collected);
	}

	/* ============================================================ *
	 * Helpers
	 * ============================================================ */

	/**
	 * Strict-but-tolerant decimal parser shared with the L3 admin flow:
	 * accepts `27.5`, `27,5`, `"27.50"`, rejects `NaN`, `inf`, negative
	 * specials. Returns null when value is empty string / null.
	 */
	private function parseDecimal(mixed $raw): ?float
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		if (is_int($raw) || is_float($raw)) {
			$value = (float)$raw;
		} else {
			$value = (float)str_replace(',', '.', (string)$raw);
		}
		if (!is_finite($value)) {
			throw new LayeredVacationValidationException('Validation failed', ['manualDays' => 'Value must be a finite number']);
		}
		return $value;
	}

	/**
	 * Acquire the exclusive app lock for one of the layer namespaces and
	 * translate Nextcloud's {@see LockedException} into our typed
	 * {@see LayeredVacationConflictException} so the admin controller can
	 * return HTTP 409 (REQ-SEC-04 / EC-07) instead of a generic 500.
	 *
	 * Intentionally not exposing the raw `LockedException` to the caller:
	 * an HR admin should see "another admin is currently saving this
	 * layer — refresh and try again" in the UI, not the lock path.
	 */
	private function acquireWriteLock(string $lockKey, string $reason): void
	{
		try {
			$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, $reason);
		} catch (LockedException $e) {
			throw new LayeredVacationConflictException(
				'Another administrator is currently editing this layer. Refresh and try again.',
				$e,
			);
		}
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return array{\DateTime, \DateTime|null}
	 */
	private function parseVacationLayerEffectiveRangeOrThrow(array $payload): array
	{
		$from = StrictYmdDates::parseRequired((string)($payload['effectiveFrom'] ?? ''));
		if ($from === null) {
			throw new LayeredVacationValidationException('Validation failed', ['effectiveFrom' => 'Invalid date; use YYYY-MM-DD.']);
		}
		$to = null;
		if (isset($payload['effectiveTo']) && $payload['effectiveTo'] !== null && $payload['effectiveTo'] !== '') {
			if (!is_scalar($payload['effectiveTo'])) {
				throw new LayeredVacationValidationException('Validation failed', ['effectiveTo' => 'Invalid date; use YYYY-MM-DD.']);
			}
			$trimTo = trim((string)$payload['effectiveTo']);
			if ($trimTo !== '') {
				$parsedTo = StrictYmdDates::parseRequired($trimTo);
				if ($parsedTo === null) {
					throw new LayeredVacationValidationException('Validation failed', ['effectiveTo' => 'Invalid date; use YYYY-MM-DD.']);
				}
				$to = $parsedTo;
			}
		}

		return [$from, $to];
	}

	/**
	 * Remove fields that must not apply for the selected vacation mode so
	 * HTTP clients cannot persist contradictory state (defence in depth next
	 * to {@see OrgVacationDefault::validate()} and siblings).
	 *
	 * Bachus: admins always submit calendar days in `manualDays`; when the
	 * organisation unit is hours we convert to stored hours here.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	private function normalizeVacationLayerPayload(array $payload): array
	{
		$mode = (string)($payload['vacationMode'] ?? Constants::VACATION_MODE_MANUAL_FIXED);
		if ($mode === Constants::VACATION_MODE_MANUAL_FIXED) {
			$payload['tariffRuleSetId'] = null;
		} elseif ($mode === Constants::VACATION_MODE_MODEL_BASED_SIMPLE) {
			$payload['manualDays'] = null;
			$payload['tariffRuleSetId'] = null;
		} elseif ($mode === Constants::VACATION_MODE_TARIFF_RULE_BASED) {
			$payload['manualDays'] = null;
		}
		if (array_key_exists('manualDays', $payload) && $payload['manualDays'] !== null && $payload['manualDays'] !== '') {
			$days = $this->parseDecimal($payload['manualDays']);
			if ($days !== null && $this->vacationUnitService !== null) {
				$payload['manualDays'] = $this->vacationUnitService->adminDaysToStoredAmount($days);
			}
		}
		return $payload;
	}

	/**
	 * Present stored manual amount as HR-friendly days (+ hours when in hours mode).
	 *
	 * @return array{manualDays: ?float, manualHours: ?float, hoursPerDay: ?float}
	 */
	public function presentManualAmount(?float $stored): array
	{
		if ($stored === null) {
			return ['manualDays' => null, 'manualHours' => null, 'hoursPerDay' => null];
		}
		$unit = $this->vacationUnitService;
		if ($unit === null) {
			return ['manualDays' => $stored, 'manualHours' => null, 'hoursPerDay' => null];
		}
		$days = $unit->storedAmountToAdminDays($stored);
		if ($unit->isHoursMode()) {
			return [
				'manualDays' => $days,
				'manualHours' => $unit->roundAmount($stored),
				'hoursPerDay' => $unit->getHoursPerDay(),
			];
		}

		return ['manualDays' => $days, 'manualHours' => null, 'hoursPerDay' => null];
	}

	/**
	 * Enrich a layer summary so the admin UI always edits/shows days.
	 *
	 * @param array<string, mixed> $summary
	 * @return array<string, mixed>
	 */
	public function presentLayerSummary(array $summary): array
	{
		// Prefer explicit stored amount so a second present() cannot treat
		// already-converted admin days as hours (25 → 25/8 = 3.125).
		if (array_key_exists('manualAmountStored', $summary) && $summary['manualAmountStored'] !== null) {
			$stored = (float)$summary['manualAmountStored'];
		} else {
			$stored = array_key_exists('manualDays', $summary) && $summary['manualDays'] !== null
				? (float)$summary['manualDays']
				: null;
		}
		$presented = $this->presentManualAmount($stored);
		$summary['manualDays'] = $presented['manualDays'];
		$summary['manualHours'] = $presented['manualHours'];
		$summary['hoursPerDay'] = $presented['hoursPerDay'];
		$summary['manualAmountStored'] = $stored;

		return $summary;
	}

	private function assertTariffRuleSetUsable(?int $ruleSetId, ?\DateTime $effectiveFrom): void
	{
		if ($ruleSetId === null || $effectiveFrom === null) {
			return;
		}
		try {
			$ruleSet = $this->tariffRuleSetMapper->find($ruleSetId);
		} catch (DoesNotExistException) {
			throw new LayeredVacationValidationException('Validation failed', ['tariffRuleSetId' => 'Tariff rule set not found']);
		}
		if ($ruleSet->getStatus() !== Constants::TARIFF_RULE_SET_STATUS_ACTIVE) {
			throw new LayeredVacationValidationException('Validation failed', ['tariffRuleSetId' => 'Only active tariff rule sets can be referenced']);
		}
		if ($ruleSet->getValidFrom() > $effectiveFrom) {
			throw new LayeredVacationValidationException('Validation failed', ['tariffRuleSetId' => 'Tariff rule set starts after the layer effective date']);
		}
		$validTo = $ruleSet->getValidTo();
		if ($validTo !== null && $validTo < $effectiveFrom) {
			throw new LayeredVacationValidationException('Validation failed', ['tariffRuleSetId' => 'Tariff rule set is no longer valid for the selected layer effective date']);
		}
	}
}

/**
 * Domain validation error. Carries a field => message map identical in shape
 * to `Entity::validate()` output so the admin controller can pipe it back to
 * the form unchanged.
 */
class LayeredVacationValidationException extends \RuntimeException
{
	/** @var array<string, string> */
	public array $fieldErrors;

	public function __construct(string $message, array $fieldErrors)
	{
		parent::__construct($message);
		$this->fieldErrors = $fieldErrors;
	}
}

class LayeredVacationNotFoundException extends \RuntimeException
{
}

/**
 * Optimistic-concurrency conflict (REQ-SEC-04, EC-07). Mapped to HTTP 409
 * by the admin controller so the JS layer can prompt "another administrator
 * is editing this layer right now" instead of leaking a 500.
 */
class LayeredVacationConflictException extends \RuntimeException
{
	public function __construct(string $message, ?\Throwable $previous = null)
	{
		parent::__construct($message, 0, $previous);
	}
}
