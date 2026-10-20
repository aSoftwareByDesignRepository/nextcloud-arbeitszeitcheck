<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCA\ArbeitszeitCheck\Support\QueryInChunker;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<OvertimeAdjustment>
 */
class OvertimeAdjustmentMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_ot_adj', OvertimeAdjustment::class);
	}

	public function insertAdjustment(OvertimeAdjustment $entity): OvertimeAdjustment
	{
		return $this->insert($entity);
	}

	/**
	 * Sum of signed hours_delta for a calendar year through an inclusive as-of date.
	 */
	public function sumHoursDeltaForYearThroughDate(string $userId, int $year, \DateTimeInterface $throughInclusive): float
	{
		$through = \DateTime::createFromInterface($throughInclusive)->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('hours_delta'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('effective_on', $qb->createNamedParameter($through, IQueryBuilder::PARAM_STR)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return round((float)($row['total'] ?? 0), 2);
	}

	/**
	 * Per-user adjustment sums for a set of users — one grouped query.
	 *
	 * @param list<string> $userIds
	 * @return array<string, float> userId => sum (users without rows are absent)
	 */
	public function sumHoursDeltaForYearThroughDateForUsers(array $userIds, int $year, \DateTimeInterface $throughInclusive): array
	{
		if (empty($userIds)) {
			return [];
		}

		$through = \DateTime::createFromInterface($throughInclusive)->format('Y-m-d');
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->selectAlias($qb->func()->sum('hours_delta'), 'total')
			->from($this->getTableName())
			->where(QueryInChunker::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('effective_on', $qb->createNamedParameter($through, IQueryBuilder::PARAM_STR)))
			->groupBy('user_id');

		$sums = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$sums[(string)$row['user_id']] = round((float)$row['total'], 2);
		}
		$result->closeCursor();
		return $sums;
	}

	public function sumHoursDeltaForYear(string $userId, int $year): float
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('hours_delta'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return round((float)($row['total'] ?? 0), 2);
	}

	/**
	 * @return list<OvertimeAdjustment>
	 */
	public function findByUserAndYear(string $userId, int $year, int $limit = 50, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->orderBy('effective_on', 'DESC')
			->addOrderBy('id', 'DESC')
			->setFirstResult(max(0, $offset))
			->setMaxResults(max(1, min(200, $limit)));

		return $this->findEntities($qb);
	}

	public function countByUserAndYear(string $userId, int $year): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}

	public function deleteByUserId(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}
}
