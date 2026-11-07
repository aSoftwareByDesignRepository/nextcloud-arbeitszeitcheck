<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class VacationRolloverLogMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_vacation_rollover_log', VacationRolloverLog::class);
	}

	public function existsForUserAndYears(string $userId, int $fromYear, int $toYear): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('from_year', $qb->createNamedParameter($fromYear, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('to_year', $qb->createNamedParameter($toYear, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return isset($row['cnt']) && (int)$row['cnt'] > 0;
	}

	public function insertLog(string $userId, int $fromYear, int $toYear, float $amount): VacationRolloverLog
	{
		$e = new VacationRolloverLog();
		$e->setUserId($userId);
		$e->setFromYear($fromYear);
		$e->setToYear($toYear);
		// Hours mode can roll > 366 (e.g. unused annual + carryover). Cap at 4000 to
		// match VacationUnitService::clampCarryover — never truncate real hour amounts to 366.
		$e->setAmount(max(0.0, min(4000.0, $amount)));
		$e->setCreatedAt(new \DateTime());
		try {
			return $this->insert($e);
		} catch (DbException $ex) {
			// A rollover rerun or concurrent writer already logged this
			// (user_id, from_year, to_year) row — return the winner so the
			// ledger write stays idempotent instead of leaking a raw DB error.
			if ($ex->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $ex;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('from_year', $qb->createNamedParameter($fromYear, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('to_year', $qb->createNamedParameter($toYear, IQueryBuilder::PARAM_INT)));
			try {
				return $this->findEntity($qb);
			} catch (DoesNotExistException) {
				throw $ex;
			}
		}
	}

	public function deleteByUserId(string $userId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
	}

	public function deleteByUserAndYears(string $userId, int $fromYear, int $toYear): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('from_year', $qb->createNamedParameter($fromYear, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('to_year', $qb->createNamedParameter($toYear, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
