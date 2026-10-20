<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCA\ArbeitszeitCheck\Support\QueryInChunker;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class OvertimePayoutMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_ot_payout', OvertimePayout::class);
	}

	public function existsForUserAndMonth(string $userId, int $year, int $month): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('calendar_month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return isset($row['cnt']) && (int)$row['cnt'] > 0;
	}

	public function findByUserAndMonth(string $userId, int $year, int $month): ?OvertimePayout
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('calendar_month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Sum of hours paid out for a user in a calendar year, optionally before a given month.
	 */
	public function sumHoursPaidForYear(string $userId, int $year, ?int $beforeMonthExclusive = null): float
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('hours_paid'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));

		if ($beforeMonthExclusive !== null && $beforeMonthExclusive > 1) {
			$qb->andWhere($qb->expr()->lt('calendar_month', $qb->createNamedParameter($beforeMonthExclusive, IQueryBuilder::PARAM_INT)));
		}

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return round((float)($row['total'] ?? 0), 2);
	}

	public function sumHoursPaidForYearThroughMonth(string $userId, int $year, int $throughMonth): float
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->sum('hours_paid'), 'total')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('calendar_month', $qb->createNamedParameter($throughMonth, IQueryBuilder::PARAM_INT)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return round((float)($row['total'] ?? 0), 2);
	}

	/**
	 * Per-user payout totals for a set of users — one grouped query.
	 * Used by scoped overview surfaces to avoid N+1 summing.
	 *
	 * @param list<string> $userIds
	 * @return array<string, float> userId => sum (users without payouts are absent)
	 */
	public function sumHoursPaidForYearThroughMonthForUsers(array $userIds, int $year, int $throughMonth): array
	{
		if (empty($userIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->selectAlias($qb->func()->sum('hours_paid'), 'total')
			->from($this->getTableName())
			->where(QueryInChunker::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('calendar_month', $qb->createNamedParameter($throughMonth, IQueryBuilder::PARAM_INT)))
			->groupBy('user_id');

		$sums = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$sums[(string)$row['user_id']] = round((float)$row['total'], 2);
		}
		$result->closeCursor();
		return $sums;
	}

	/**
	 * Latest payout per user for a set of users — one query, PHP-side pick.
	 * Payout rows are bounded (~1/month/user) so the full set is safe to reduce
	 * in memory; ordered newest-first, first row per user wins.
	 *
	 * @param list<string> $userIds
	 * @return array<string, OvertimePayout>
	 */
	public function findLatestForUsersInYear(array $userIds, int $year): array
	{
		if (empty($userIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(QueryInChunker::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->orderBy('calendar_month', 'DESC')
			->addOrderBy('id', 'DESC');

		$latest = [];
		foreach ($this->findEntities($qb) as $payout) {
			$uid = (string)$payout->getUserId();
			if (!isset($latest[$uid])) {
				$latest[$uid] = $payout;
			}
		}
		return $latest;
	}

	/**
	 * @return list<OvertimePayout>
	 */
	public function findFiltered(?int $year, ?int $month, ?string $userId, int $limit = 500, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		if ($year !== null) {
			$qb->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		}
		if ($month !== null) {
			$qb->andWhere($qb->expr()->eq('calendar_month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)));
		}
		if ($userId !== null && $userId !== '') {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
		}

		$qb->orderBy('calendar_year', 'DESC')
			->addOrderBy('calendar_month', 'DESC')
			->addOrderBy('created_at', 'DESC')
			->setMaxResults(max(1, min(1000, $limit)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	public function countFiltered(?int $year, ?int $month, ?string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))->from($this->getTableName());

		if ($year !== null) {
			$qb->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		}
		if ($month !== null) {
			$qb->andWhere($qb->expr()->eq('calendar_month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)));
		}
		if ($userId !== null && $userId !== '') {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
		}

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}

	/**
	 * @return list<OvertimePayout>
	 */
	public function findByYearAndMonth(int $year, int $month): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('calendar_month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)))
			->orderBy('user_id', 'ASC');

		return $this->findEntities($qb);
	}

	public function insertPayout(OvertimePayout $entity): OvertimePayout
	{
		$entity->setCreatedAt(new \DateTime());

		return $this->insert($entity);
	}

	/**
	 * @return list<OvertimePayout>
	 */
	public function findByUser(string $userId, int $limit = 100, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->orderBy('calendar_year', 'DESC')
			->addOrderBy('calendar_month', 'DESC')
			->addOrderBy('created_at', 'DESC')
			->setMaxResults(max(1, min(500, $limit)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	public function countByUser(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}

	public function findLatestForUserInYear(string $userId, int $year): ?OvertimePayout
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->orderBy('calendar_month', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (\OCP\AppFramework\Db\DoesNotExistException) {
			return null;
		}
	}

	public function deleteByUserId(string $userId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
	}
}
