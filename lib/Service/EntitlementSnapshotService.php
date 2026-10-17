<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshot;
use OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshotMapper;
use OCP\Lock\ILockingProvider;

class EntitlementSnapshotService {
	public function __construct(
		private EntitlementComputationSnapshotMapper $snapshotMapper,
		private ILockingProvider $lockingProvider,
	) {
	}

	public function store(
		string $userId,
		int $year,
		\DateTimeInterface $asOfDate,
		float $effectiveDays,
		string $source,
		?int $ruleSetId,
		array $trace,
		string $computedBy,
		?string $policyFingerprint = null
	): EntitlementComputationSnapshot {
		$asOfDateOnly = new \DateTime($asOfDate->format('Y-m-d'));
		$lockKey = DbLockKeys::entitlementSnapshot($userId, $year, $asOfDateOnly->format('Y-m-d'));
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, 'Entitlement snapshot lock ' . $userId . ' ' . $year);
		try {
			$snapshot = new EntitlementComputationSnapshot();
			$snapshot->setUserId($userId);
			$snapshot->setPeriodKey((string)$year);
			$snapshot->setAsOfDate($asOfDateOnly);
			$snapshot->setEffectiveEntitlementDays(round($effectiveDays, 2, PHP_ROUND_HALF_UP));
			$snapshot->setSource($source);
			$snapshot->setRuleSetId($ruleSetId);
			// Ensure trace v1 envelope so payroll auditors can replay the
			// snapshot deterministically. If the engine already supplied
			// `algorithm_version`, keep the engine's value untouched; only
			// fill defaults for legacy callers that pre-date the trace v1
			// contract (e.g. background jobs that hand-constructed traces).
			$trace = $this->ensureTraceEnvelope($trace, $asOfDateOnly, $source, $ruleSetId, $effectiveDays);
			$snapshot->setCalculationTrace($trace);
			$snapshot->setComputedAt(new \DateTime('now'));
			$snapshot->setComputedBy($computedBy);
			$snapshot->setPolicyFingerprint($policyFingerprint);
			return $this->snapshotMapper->upsertSnapshot($snapshot);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function storeSpecial(
		string $userId,
		int $entitlementTypeId,
		\DateTimeInterface $periodStart,
		\DateTimeInterface $periodEnd,
		string $unit,
		\DateTimeInterface $asOfDate,
		float $effectiveAmount,
		string $algorithmVersion,
		array $trace,
		string $computedBy,
	): EntitlementComputationSnapshot {
		$periodStartDate = new \DateTime($periodStart->format('Y-m-d'));
		$periodEndDate = new \DateTime($periodEnd->format('Y-m-d'));
		$asOfDateOnly = new \DateTime($asOfDate->format('Y-m-d'));
		$periodKey = 'se' . substr(md5($entitlementTypeId . '|' . $periodStartDate->format('Y-m-d')), 0, 14);
		$lockKey = DbLockKeys::entitlementSnapshot($userId, (int)$periodStartDate->format('Y'), $asOfDateOnly->format('Y-m-d'));
		$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, 'Special entitlement snapshot lock ' . $userId);
		try {
			$snapshot = new EntitlementComputationSnapshot();
			$snapshot->setUserId($userId);
			$snapshot->setPeriodKey($periodKey);
			$snapshot->setAsOfDate($asOfDateOnly);
			$snapshot->setEffectiveEntitlementDays($unit === 'days' ? $effectiveAmount : 0.0);
			$snapshot->setSource('special_entitlement');
			$snapshot->setRuleSetId(null);
			$trace = $this->ensureTraceEnvelope($trace, $asOfDateOnly, 'special_entitlement', null, $effectiveAmount);
			$snapshot->setCalculationTrace($trace);
			$snapshot->setComputedAt(new \DateTime('now'));
			$snapshot->setComputedBy($computedBy);
			$snapshot->setPolicyFingerprint(hash('sha256', MonthClosureCanonical::encode($trace), false));
			$snapshot->setEntitlementTypeId($entitlementTypeId);
			$snapshot->setAlgorithmVersion($algorithmVersion);
			$snapshot->setPeriodStart($periodStartDate);
			$snapshot->setPeriodEnd($periodEndDate);
			$snapshot->setUnit($unit);
			$snapshot->setEffectiveAmount(round($effectiveAmount, 2, PHP_ROUND_HALF_UP));
			return $this->snapshotMapper->upsertSnapshot($snapshot);
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Make sure every persisted snapshot trace carries the v1 envelope keys
	 * required by `REQ-AUD-01`:
	 *
	 *   - `algorithm_version` (int)
	 *   - `as_of_date`        (Y-m-d)
	 *   - `matched_layer`     (L0|L1|L2|L3|legacy)
	 *   - `layers_evaluated`  (list)
	 *   - `winner`            (assoc)
	 *   - `inputs_redacted`   (bool, always false at persist time — the
	 *                          redacted form lives in API responses only)
	 *
	 * Legacy traces that lack these keys are upgraded in-place. Engine
	 * traces already produced by {@see VacationEntitlementEngine::computeForDate()}
	 * pass through unchanged.
	 *
	 * @param array<string, mixed> $trace
	 * @return array<string, mixed>
	 */
	private function ensureTraceEnvelope(array $trace, \DateTime $asOfDate, string $source, ?int $ruleSetId, float $effectiveDays): array
	{
		$trace['algorithm_version'] = $trace['algorithm_version'] ?? Constants::ENTITLEMENT_ALGORITHM_VERSION;
		$trace['as_of_date'] = $trace['as_of_date'] ?? $asOfDate->format('Y-m-d');
		$trace['inputs_redacted'] = $trace['inputs_redacted'] ?? false;
		if (!isset($trace['matched_layer'])) {
			$trace['matched_layer'] = $source === 'layered' ? 'L0' : 'legacy';
		}
		if (!isset($trace['winner'])) {
			$trace['winner'] = [
				'layer' => $trace['matched_layer'],
				'mode' => $source,
				'days' => round($effectiveDays, 2, PHP_ROUND_HALF_UP),
				'rule_set_id' => $ruleSetId,
			];
		}
		if (!isset($trace['layers_evaluated']) || !is_array($trace['layers_evaluated'])) {
			$trace['layers_evaluated'] = [
				['layer' => $trace['matched_layer'], 'matched' => true, 'mode' => $source, 'days' => round($effectiveDays, 2, PHP_ROUND_HALF_UP)],
			];
		}
		return $trace;
	}
}

