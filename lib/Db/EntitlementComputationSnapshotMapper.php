<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class EntitlementComputationSnapshotMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'at_entitlement_snapshots', EntitlementComputationSnapshot::class);
	}

	public function find(int $id): EntitlementComputationSnapshot
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findLatestForUserAndPeriod(string $userId, string $periodKey): ?EntitlementComputationSnapshot {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('period_key', $qb->createNamedParameter($periodKey)))
			->orderBy('computed_at', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByUserPeriodAndAsOfDate(string $userId, string $periodKey, \DateTimeInterface $asOfDate): ?EntitlementComputationSnapshot {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('period_key', $qb->createNamedParameter($periodKey)))
			->andWhere($qb->expr()->eq('as_of_date', $qb->createNamedParameter($asOfDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->orderBy('computed_at', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function findByUser(string $userId, ?int $limit = null): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('computed_at', 'DESC');
		if ($limit !== null) {
			$qb->setMaxResults(max(1, $limit));
		}
		return $this->findEntities($qb);
	}

	public function upsertSnapshot(EntitlementComputationSnapshot $snapshot): EntitlementComputationSnapshot {
		$existing = $this->findByUserPeriodAndAsOfDate(
			$snapshot->getUserId(),
			$snapshot->getPeriodKey(),
			$snapshot->getAsOfDate()
		);
		$existingAsOfDate = null;
		$needsLegacyRepair = false;
		if ($existing !== null) {
			try {
				$existingAsOfDate = $existing->getAsOfDate();
			} catch (\Error) {
				// Legacy/inconsistent rows may miss as_of_date hydration.
				$existingAsOfDate = null;
				$needsLegacyRepair = true;
			}
		}
		if ($existing !== null && $needsLegacyRepair) {
			$existing->setAsOfDate($snapshot->getAsOfDate());
			$existing->setEffectiveEntitlementDays($snapshot->getEffectiveEntitlementDays());
			$existing->setSource($snapshot->getSource());
			$existing->setRuleSetId($snapshot->getRuleSetId());
			$existing->setCalculationTraceJson($snapshot->getCalculationTraceJson());
			$existing->setComputedAt($snapshot->getComputedAt());
			$existing->setComputedBy($snapshot->getComputedBy());
			$existing->setPolicyFingerprint($snapshot->getPolicyFingerprint());
			$this->copySpecialEntitlementFields($snapshot, $existing);
			return $this->update($existing);
		}
		if ($existing !== null && $existingAsOfDate !== null) {
			$existing->setEffectiveEntitlementDays($snapshot->getEffectiveEntitlementDays());
			$existing->setSource($snapshot->getSource());
			$existing->setRuleSetId($snapshot->getRuleSetId());
			$existing->setCalculationTraceJson($snapshot->getCalculationTraceJson());
			$existing->setComputedAt($snapshot->getComputedAt());
			$existing->setComputedBy($snapshot->getComputedBy());
			$existing->setPolicyFingerprint($snapshot->getPolicyFingerprint());
			$this->copySpecialEntitlementFields($snapshot, $existing);
			return $this->update($existing);
		}
		try {
			return $this->insert($snapshot);
		} catch (UniqueConstraintViolationException) {
			// Concurrent writer inserted the same (user_id, period_key, as_of_date) row.
			// Re-read and update to keep deterministic latest values.
			$conflict = $this->findByUserPeriodAndAsOfDate(
				$snapshot->getUserId(),
				$snapshot->getPeriodKey(),
				$snapshot->getAsOfDate()
			);
			if ($conflict !== null) {
				$conflict->setEffectiveEntitlementDays($snapshot->getEffectiveEntitlementDays());
				$conflict->setSource($snapshot->getSource());
				$conflict->setRuleSetId($snapshot->getRuleSetId());
				$conflict->setCalculationTraceJson($snapshot->getCalculationTraceJson());
				$conflict->setComputedAt($snapshot->getComputedAt());
				$conflict->setComputedBy($snapshot->getComputedBy());
				$conflict->setPolicyFingerprint($snapshot->getPolicyFingerprint());
				$this->copySpecialEntitlementFields($snapshot, $conflict);
				return $this->update($conflict);
			}
			throw new \RuntimeException('Snapshot upsert conflict could not be resolved');
		}
	}

	private function copySpecialEntitlementFields(EntitlementComputationSnapshot $from, EntitlementComputationSnapshot $to): void
	{
		$to->setEntitlementTypeId($from->getEntitlementTypeId());
		$to->setAlgorithmVersion($from->getAlgorithmVersion());
		$to->setPeriodStart($from->getPeriodStart());
		$to->setPeriodEnd($from->getPeriodEnd());
		$to->setUnit($from->getUnit());
		$to->setEffectiveAmount($from->getEffectiveAmount());
	}

	public function deleteByUser(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}
}

