<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
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
		$e->setAmount(max(0.0, min(366.0, $amount)));
		$e->setCreatedAt(new \DateTime());
		return $this->insert($e);
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
