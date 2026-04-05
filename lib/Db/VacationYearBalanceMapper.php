<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class VacationYearBalanceMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_vacation_year_balance', VacationYearBalance::class);
	}

	public function findByUserAndYear(string $userId, int $year): VacationYearBalance
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return float Carryover days or 0 if no row
	 */
	public function getCarryoverDays(string $userId, int $year): float
	{
		try {
			return $this->findByUserAndYear($userId, $year)->getCarryoverDays();
		} catch (DoesNotExistException $e) {
			return 0.0;
		}
	}

	public function upsert(string $userId, int $year, float $carryoverDays): VacationYearBalance
	{
		$now = new \DateTime();
		try {
			$entity = $this->findByUserAndYear($userId, $year);
			$entity->setCarryoverDays(max(0.0, min(366.0, $carryoverDays)));
			$entity->setUpdatedAt($now);
			return $this->update($entity);
		} catch (DoesNotExistException $e) {
			$entity = new VacationYearBalance();
			$entity->setUserId($userId);
			$entity->setYear($year);
			$entity->setCarryoverDays(max(0.0, min(366.0, $carryoverDays)));
			$entity->setCreatedAt($now);
			$entity->setUpdatedAt($now);
			return $this->insert($entity);
		}
	}

	/**
	 * Delete all vacation year balance rows for a user (e.g. on account deletion).
	 */
	public function deleteByUserId(string $userId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
	}
}
