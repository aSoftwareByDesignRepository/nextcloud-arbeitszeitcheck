<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class PolicyCohortMembershipMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_policy_cohort_member', PolicyCohortMembership::class);
	}

	public function find(int $id): PolicyCohortMembership
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findEffectiveForUser(string $userId, string $date): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($date)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('effective_to'),
				$qb->expr()->gte('effective_to', $qb->createNamedParameter($date)),
			))
			->orderBy('cohort_id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * All memberships of one cohort (any date range), ordered for admin lists.
	 *
	 * @return list<PolicyCohortMembership>
	 */
	public function findForCohort(int $cohortId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('cohort_id', $qb->createNamedParameter($cohortId, IQueryBuilder::PARAM_INT)))
			->orderBy('user_id', 'ASC')
			->addOrderBy('effective_from', 'ASC');
		return $this->findEntities($qb);
	}

	public function countForCohort(int $cohortId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('cohort_id', $qb->createNamedParameter($cohortId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->free();
		return $count;
	}

	/**
	 * Members of one cohort whose membership is effective at $date.
	 *
	 * @return list<PolicyCohortMembership>
	 */
	public function findEffectiveForCohort(int $cohortId, string $date, int $limit = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('cohort_id', $qb->createNamedParameter($cohortId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($date)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('effective_to'),
				$qb->expr()->gte('effective_to', $qb->createNamedParameter($date)),
			))
			->orderBy('user_id', 'ASC');
		if ($limit > 0) {
			$qb->setMaxResults($limit);
		}
		return $this->findEntities($qb);
	}

	public function countEffectiveForCohort(int $cohortId, string $date): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id', 'cnt'))->from($this->getTableName())
			->where($qb->expr()->eq('cohort_id', $qb->createNamedParameter($cohortId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($date)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('effective_to'),
				$qb->expr()->gte('effective_to', $qb->createNamedParameter($date)),
			));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->free();
		return $count;
	}

	public function findOverlapping(int $cohortId, string $userId, string $from, ?string $to, ?int $excludeId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('cohort_id', $qb->createNamedParameter($cohortId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('effective_to'),
				$qb->expr()->gte('effective_to', $qb->createNamedParameter($from)),
			));
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($to)));
		}
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}
}
