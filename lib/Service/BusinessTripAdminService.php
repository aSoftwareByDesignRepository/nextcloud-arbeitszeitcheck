<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignment;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripMapper;
use OCA\ArbeitszeitCheck\Db\UserBusinessTripProfile;
use OCA\ArbeitszeitCheck\Db\UserBusinessTripProfileMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use Psr\Log\LoggerInterface;

/**
 * Admin-side business-trip domain service: versioned rule sets,
 * effective-dated scope assignments, and user trip profiles (only the
 * normal-commute minutes — never addresses or routes).
 */
final class BusinessTripAdminService
{
	public function __construct(
		private readonly WorkforceSuiteConfig $config,
		private readonly IDBConnection $db,
		private readonly ILockingProvider $lockingProvider,
		private readonly BusinessTripRuleSetMapper $ruleSets,
		private readonly BusinessTripRuleAssignmentMapper $assignments,
		private readonly UserBusinessTripProfileMapper $profiles,
		private readonly BusinessTripMapper $trips,
		private readonly BusinessTripService $tripService,
		private readonly BusinessTripRuleCatalog $catalog,
		private readonly BusinessTripRuleConfigValidator $ruleValidator,
		private readonly IUserManager $userManager,
		private readonly BusinessTripRolloutReadinessService $readiness,
		private readonly LoggerInterface $logger,
	) {
	}

	// ---------- rule sets ----------

	/** @return list<array<string,mixed>> */
	public function listRuleSets(): array
	{
		$this->assertEnabled();
		return array_map([$this, 'serializeRuleSet'], $this->ruleSets->findAll());
	}

	public function createRuleSetDraft(
		string $code,
		string $name,
		string $jurisdiction,
		string $configJson,
		?\DateTime $validFrom,
		?\DateTime $validTo,
		string $actor,
	): array {
		$this->assertEnabled();
		$this->assertCode($code);
		if (trim($name) === '' || strlen($name) > 120) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'Name is required.', 400, ['name' => 'invalid']);
		}
		return $this->serializeRuleSet(
			$this->catalog->createDraft($code, trim($name), $jurisdiction, $configJson, $validFrom, $validTo, $actor)
		);
	}

	public function updateRuleSetDraft(int $id, string $configJson, string $name, string $actor): array
	{
		$this->assertEnabled();
		if (trim($name) === '' || strlen($name) > 120) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'Name is required.', 400, ['name' => 'invalid']);
		}
		return $this->serializeRuleSet(
			$this->catalog->updateDraft($this->requireRuleSet($id), $configJson, trim($name), $actor)
		);
	}

	public function activateRuleSet(int $id, string $actor): array
	{
		$this->assertEnabled();
		return $this->serializeRuleSet($this->catalog->activate($id, $actor));
	}

	/**
	 * Instantiate a canonical DACH preset as a DRAFT rule set (spec §10.5).
	 * Presets carry provenance but never skip the legal review gate —
	 * activation still requires markReviewed.
	 */
	public function createPresetDraft(string $jurisdiction, string $actor): array
	{
		$this->assertEnabled();
		$preset = BusinessTripRulePresets::forJurisdiction($jurisdiction);
		$set = $this->catalog->createDraft(
			$preset['code'],
			$preset['name'],
			$jurisdiction,
			json_encode($preset['config'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			null,
			null,
			$actor,
			$preset['provenance'],
		);
		return $this->serializeRuleSet($set);
	}

	/** Record legal review for a draft rule set (spec §10.5 release gate). */
	public function markRuleSetReviewed(int $id, string $actor, string $configHash): array
	{
		$this->assertEnabled();
		return $this->serializeRuleSet($this->catalog->markReviewed($this->requireRuleSet($id), $actor, $configHash));
	}

	/** @return list<array<string,mixed>> preset metadata for the admin UI. */
	public function listPresets(): array
	{
		$this->assertEnabled();
		$out = [];
		foreach (BusinessTripRulePresets::all() as $jurisdiction => $preset) {
			$out[] = [
				'jurisdiction' => $jurisdiction,
				'code' => $preset['code'],
				'name' => $preset['name'],
				'provenance' => $preset['provenance'],
				'config' => $preset['config'],
			];
		}
		return $out;
	}

	// ---------- assignments ----------

	/** @return list<array<string,mixed>> */
	public function listAssignments(): array
	{
		$this->assertEnabled();
		$rows = [];
		foreach ($this->assignments->findAll() as $a) {
			$rows[] = $this->serializeAssignment($a);
		}
		return $rows;
	}

	public function createAssignment(
		int $ruleSetId,
		string $scopeType,
		string $scopeId,
		int $priority,
		\DateTimeInterface $effectiveFrom,
		?\DateTimeInterface $effectiveTo,
		string $actor,
	): array {
		$this->assertEnabled();
		$ruleSet = $this->requireRuleSet($ruleSetId);
		if ($ruleSet->getStatus() !== BusinessTripRuleSet::STATUS_ACTIVE) {
			throw new BusinessTripException('RULE_ASSIGNMENT_INVALID', 'Only active rule sets can be assigned.', 400, ['ruleSetId' => 'not_active']);
		}
		if (!in_array($scopeType, BusinessTripRuleAssignment::SCOPES, true)) {
			throw new BusinessTripException('RULE_ASSIGNMENT_INVALID', 'Unknown scope type.', 400, ['scopeType' => 'invalid']);
		}
		if ($scopeType === BusinessTripRuleAssignment::SCOPE_USER && !$this->userManager->userExists($scopeId)) {
			throw new BusinessTripException('RULE_ASSIGNMENT_INVALID', 'Unknown user.', 400, ['scopeId' => 'invalid']);
		}
		if ($effectiveTo !== null && $effectiveTo < $effectiveFrom) {
			throw new BusinessTripException('RULE_ASSIGNMENT_INVALID', 'effectiveTo must not precede effectiveFrom.', 400, ['effectiveTo' => 'before_from']);
		}
		if ($scopeType === BusinessTripRuleAssignment::SCOPE_ORG) {
			$scopeId = BusinessTripRuleAssignment::ORG_SCOPE_ID;
		}
		if ($scopeId === '' || strlen($scopeId) > 64) {
			throw new BusinessTripException('RULE_ASSIGNMENT_INVALID', 'Invalid scope id.', 400, ['scopeId' => 'invalid']);
		}

		$lock = DbLockKeys::businessTripRuleCatalog();
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Business-trip rule catalog');
		try {
			// One assignment per scope per effective window — overlaps fork
			// precedence silently, so reject them under the catalog lock.
			$this->db->beginTransaction();
			try {
				if ($this->assignments->findOverlapping($scopeType, $scopeId, $effectiveFrom, $effectiveTo) !== []) {
					throw new BusinessTripException('RULE_ASSIGNMENT_OVERLAP', 'An assignment for this scope already covers that period.', 409, ['effectiveFrom' => 'overlap']);
				}
				$assignment = new BusinessTripRuleAssignment();
				$assignment->setRuleSetId($ruleSetId);
				$assignment->setScopeType($scopeType);
				$assignment->setScopeId($scopeId);
				$assignment->setPriority($priority);
				$assignment->setEffectiveFrom(new \DateTime($effectiveFrom->format('Y-m-d')));
				$assignment->setEffectiveTo($effectiveTo !== null ? new \DateTime($effectiveTo->format('Y-m-d')) : null);
				$assignment->setRevision(1);
				$now = new \DateTime();
				$assignment->setCreatedBy($actor);
				$assignment->setUpdatedBy($actor);
				$assignment->setCreatedAt($now);
				$assignment->setUpdatedAt($now);
				$saved = $this->assignments->insert($assignment);
				$this->db->commit();
				return $this->serializeAssignment($saved);
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Effective-end an assignment (CAS on its revision). */
	public function endAssignment(int $id, int $expectedRevision, \DateTimeInterface $effectiveTo, string $actor): array
	{
		$this->assertEnabled();
		$lock = DbLockKeys::businessTripRuleCatalog();
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Business-trip rule catalog');
		try {
			$assignment = $this->requireAssignment($id);
			if ($expectedRevision !== (int)$assignment->getRevision()) {
				throw new BusinessTripException('RULE_ASSIGNMENT_STALE', 'The assignment changed; reload and retry.', 409, ['revision' => 'stale']);
			}
			if ($effectiveTo->format('Y-m-d') < $assignment->getEffectiveFrom()->format('Y-m-d')) {
				throw new BusinessTripException('RULE_ASSIGNMENT_INVALID', 'effectiveTo must not precede effectiveFrom.', 400, ['effectiveTo' => 'before_from']);
			}
			$assignment->setEffectiveTo(new \DateTime($effectiveTo->format('Y-m-d')));
			$assignment->setRevision((int)$assignment->getRevision() + 1);
			$assignment->setUpdatedBy($actor);
			$assignment->setUpdatedAt(new \DateTime());
			return $this->serializeAssignment($this->assignments->update($assignment));
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	// ---------- user profiles ----------

	/**
	 * Set the normal-commute minutes for a user (effective-dated). This is
	 * deliberately the only profile field — private addresses and routes are
	 * never stored.
	 */
	public function setProfile(string $userId, ?int $normalCommuteMinutes, \DateTimeInterface $effectiveFrom, string $actor): array
	{
		$this->assertEnabled();
		if (!$this->userManager->userExists($userId)) {
			throw new BusinessTripException('USER_NOT_FOUND', 'Not found.', 404);
		}
		if ($normalCommuteMinutes !== null && ($normalCommuteMinutes < 0 || $normalCommuteMinutes > 1440)) {
			throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'Normal commute must be 0–1440 minutes.', 400, ['normalCommuteMinutes' => 'range']);
		}
		$profile = new UserBusinessTripProfile();
		$profile->setUserId($userId);
		$profile->setNormalCommuteMinutes($normalCommuteMinutes);
		$profile->setEffectiveFrom(new \DateTime($effectiveFrom->format('Y-m-d')));
		$profile->setEffectiveTo(null);
		$profile->setRevision(1);
		$now = new \DateTime();
		$profile->setCreatedBy($actor);
		$profile->setUpdatedBy($actor);
		$profile->setCreatedAt($now);
		$profile->setUpdatedAt($now);
		$saved = $this->profiles->insert($profile);
		return [
			'id' => (int)$saved->getId(),
			'userId' => (string)$saved->getUserId(),
			'normalCommuteMinutes' => $saved->getNormalCommuteMinutes(),
			'effectiveFrom' => $saved->getEffectiveFrom()?->format('Y-m-d'),
			'revision' => (int)$saved->getRevision(),
		];
	}

	/**
	 * Rule-config dry run (spec §7.4.7): evaluate the account target at
	 * basis-1, basis, and basis+1 so admins see exact threshold boundaries.
	 * Uses the same engine code path as production calculation.
	 *
	 * @return list<array{basis:int,target:int}>
	 */
	public function previewRuleConfig(string $configJson, int $basisMinutes): array
	{
		$this->assertEnabled();
		$config = $this->ruleValidator->validate($configJson);
		$rows = [];
		foreach ([$basisMinutes - 1, $basisMinutes, $basisMinutes + 1] as $basis) {
			if ($basis < 0) {
				continue;
			}
			$rows[] = [
				'basis' => $basis,
				'target' => BusinessTripCalculationEngine::accountTargetForBasis($config, $basis, $basis),
			];
		}
		return $rows;
	}

	// ---------- rollout (Phase 6) ----------

	/**
	 * Rollout card data: the evidence-driven preflight plus the flags this
	 * surface controls. Not feature-gated — the readiness view is precisely
	 * what an admin needs BEFORE enabling or cutting over.
	 */
	public function rolloutStatus(): array
	{
		return [
			'preflight' => $this->readiness->preflight(),
			'flags' => [
				'business_trips_enabled' => $this->config->isEnabled('business_trips_enabled'),
				'business_trip_credit_enabled' => $this->config->isEnabled('business_trip_credit_enabled'),
				'legacy_trip_absence_create_enabled' => $this->config->isEnabled('legacy_trip_absence_create_enabled'),
			],
		];
	}

	/**
	 * Turn `business_trips_enabled` on. The feature is additive, so this is
	 * safe any time — callers still demand an explicit ack (mirroring the
	 * work-location catalog enable). Flag flips are policy mutations, so
	 * the actor is logged.
	 */
	public function enableFeature(string $actor): void
	{
		$this->config->updateFlags(['business_trips_enabled' => true]);
		$this->logger->info('business_trips_enabled set to true', ['actor' => $actor]);
	}

	/**
	 * Phase-6 cutover: stop new legacy `business_trip` absence creation.
	 * Refused while the preflight reports pending legacy requests, undecided
	 * submitted trips, or no reviewed+active rule set — the cutover is
	 * evidence-driven, never hopeful. The feature itself must be on: you
	 * cannot cut over to a disabled replacement.
	 */
	public function disableLegacyTripAbsence(string $actor): array
	{
		$this->assertEnabled();
		$preflight = $this->readiness->preflight();
		if (!$preflight['ready']) {
			throw new BusinessTripException('TRIP_ROLLOUT_BLOCKED', 'The rollout preflight is not green yet.', 409, []);
		}
		$this->config->updateFlags(['legacy_trip_absence_create_enabled' => false]);
		$this->logger->info('legacy_trip_absence_create_enabled set to false', ['actor' => $actor, 'preflight' => $preflight]);
		return [
			'legacy_trip_absence_create_enabled' => false,
			'preflight' => $preflight,
		];
	}

	// ---------- manager decisions ----------

	/**
	 * Approve a submitted trip on behalf of the owner. The snapshot token is
	 * mandatory — the approver signs off on an exact frozen fact set.
	 */
	public function approve(int $tripId, int $expectedRevision, string $snapshotToken, string $actor): array
	{
		$this->assertEnabled();
		$trip = $this->requireTrip($tripId);
		$saved = $this->tripService->approve($trip, $expectedRevision, $snapshotToken, $actor);
		return $this->serializeTrip($saved);
	}

	public function reject(int $tripId, int $expectedRevision, string $actor, ?string $reason = null): array
	{
		$this->assertEnabled();
		$trip = $this->requireTrip($tripId);
		if ($reason !== null && strlen($reason) > 500) {
			throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'Reason is too long.', 400, ['reason' => 'too_long']);
		}
		return $this->serializeTrip($this->tripService->reject($trip, $expectedRevision, $actor, $reason));
	}

	// ------------------------------------------------------------------

	private function requireRuleSet(int $id): BusinessTripRuleSet
	{
		try {
			return $this->ruleSets->find($id);
		} catch (DoesNotExistException) {
			throw new BusinessTripException('RULE_SET_NOT_FOUND', 'Not found.', 404);
		}
	}

	private function requireAssignment(int $id): BusinessTripRuleAssignment
	{
		try {
			return $this->assignments->find($id);
		} catch (DoesNotExistException) {
			throw new BusinessTripException('RULE_ASSIGNMENT_NOT_FOUND', 'Not found.', 404);
		}
	}

	private function requireTrip(int $id): BusinessTrip
	{
		try {
			return $this->trips->find($id);
		} catch (DoesNotExistException) {
			throw new BusinessTripException('TRIP_NOT_FOUND', 'Not found.', 404);
		}
	}

	private function assertEnabled(): void
	{
		if (!$this->config->isEnabled('business_trips_enabled')) {
			throw new BusinessTripException('FEATURE_DISABLED', 'Business trips are not enabled.', 404);
		}
	}

	private function assertCode(string $code): void
	{
		if (preg_match('/^[a-z][a-z0-9_-]{0,62}$/D', $code) !== 1) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'Invalid rule-set code.', 400, ['code' => 'invalid']);
		}
	}

	private function serializeRuleSet(BusinessTripRuleSet $set): array
	{
		return [
			'id' => (int)$set->getId(),
			'code' => (string)$set->getCode(),
			'version' => (int)$set->getVersion(),
			'name' => (string)$set->getName(),
			'jurisdiction' => (string)$set->getJurisdiction(),
			'status' => (string)$set->getStatus(),
			'validFrom' => $set->getValidFrom()?->format('Y-m-d'),
			'validTo' => $set->getValidTo()?->format('Y-m-d'),
			'configHash' => (string)$set->getConfigHash(),
			'configJson' => (string)$set->getConfigJson(),
			'createdBy' => $set->getCreatedBy(),
			'activatedBy' => $set->getActivatedBy(),
			'activatedAt' => $set->getActivatedAt()?->format('c'),
			'createdAt' => $set->getCreatedAt()?->format('c'),
			// §10.5 provenance + legal review gate
			'sourceTitle' => $set->getSourceTitle(),
			'sourceReference' => $set->getSourceReference(),
			'classifierSchemaVersion' => $set->getClassifierSchemaVersion() !== null ? (int)$set->getClassifierSchemaVersion() : null,
			'reviewedAt' => $set->getReviewedAt()?->format('c'),
			'reviewedBy' => $set->getReviewedBy(),
		];
	}

	private function serializeAssignment(BusinessTripRuleAssignment $a): array
	{
		return [
			'id' => (int)$a->getId(),
			'ruleSetId' => (int)$a->getRuleSetId(),
			'scopeType' => (string)$a->getScopeType(),
			'scopeId' => (string)$a->getScopeId(),
			'priority' => (int)$a->getPriority(),
			'effectiveFrom' => $a->getEffectiveFrom()?->format('Y-m-d'),
			'effectiveTo' => $a->getEffectiveTo()?->format('Y-m-d'),
			'revision' => (int)$a->getRevision(),
		];
	}

	private function serializeTrip(BusinessTrip $trip): array
	{
		return [
			'id' => (int)$trip->getId(),
			'userId' => (string)$trip->getUserId(),
			'status' => (string)$trip->getStatus(),
			'startsAtUtc' => $trip->getStartsAtUtc()?->format('c'),
			'endsAtUtc' => $trip->getEndsAtUtc()?->format('c'),
			'evaluationTimezone' => (string)$trip->getEvaluationTimezone(),
			'revision' => (int)$trip->getRevision(),
			'currentSnapshotId' => $trip->getCurrentSnapshotId(),
		];
	}
}
