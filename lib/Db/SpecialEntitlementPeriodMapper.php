<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class SpecialEntitlementPeriodMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_se_period', SpecialEntitlementPeriod::class);
	}

	public function find(int $id): SpecialEntitlementPeriod
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findForPeriod(int $typeId, string $userId, string $periodStart): ?SpecialEntitlementPeriod
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('entitlement_type_id', $qb->createNamedParameter($typeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('period_start', $qb->createNamedParameter($periodStart)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<SpecialEntitlementPeriod> */
	public function findForUserAndType(string $userId, int $typeId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('entitlement_type_id', $qb->createNamedParameter($typeId, IQueryBuilder::PARAM_INT)))
			->orderBy('period_start', 'DESC');
		return $this->findEntities($qb);
	}

	/** @return list<SpecialEntitlementPeriod> */
	public function findOverlappingRangeForUser(string $userId, string $rangeStart, string $rangeEnd): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lte('period_start', $qb->createNamedParameter($rangeEnd)))
			->andWhere($qb->expr()->gte('period_end', $qb->createNamedParameter($rangeStart)))
			->orderBy('period_start', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<SpecialEntitlementPeriod> */
	public function findOpenForUser(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SpecialEntitlementPeriod::STATUS_OPEN)))
			->orderBy('period_start', 'DESC');
		return $this->findEntities($qb);
	}

	/** CAS update; false means the revision token no longer matches. */
	public function updateIfRevision(SpecialEntitlementPeriod $period): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('computed_amount', $qb->createNamedParameter($period->getComputedAmount()))
			->set('computation_snapshot_id', $qb->createNamedParameter($period->getComputationSnapshotId(), IQueryBuilder::PARAM_INT))
			->set('status', $qb->createNamedParameter($period->getStatus()))
			->set('revision', $qb->createNamedParameter((int)$period->getRevision() + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($period->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($period->getRevision(), IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) {
			return false;
		}
		$period->setRevision((int)$period->getRevision() + 1);
		return true;
	}
}
