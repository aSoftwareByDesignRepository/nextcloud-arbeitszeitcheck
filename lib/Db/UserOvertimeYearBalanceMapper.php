<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class UserOvertimeYearBalanceMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_user_ot_year_bal', UserOvertimeYearBalance::class);
	}

	public function findByUserAndYear(string $userId, int $year): UserOvertimeYearBalance
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));

		return $this->findEntity($qb);
	}

	public function findByUserAndYearOptional(string $userId, int $year): ?UserOvertimeYearBalance
	{
		try {
			return $this->findByUserAndYear($userId, $year);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	public function getOpeningBalanceHours(string $userId, int $year): float
	{
		$row = $this->findByUserAndYearOptional($userId, $year);
		return $row !== null ? (float)$row->getOpeningBalanceHours() : 0.0;
	}

	public function upsert(string $userId, int $year, float $openingBalanceHours): UserOvertimeYearBalance
	{
		$now = new \DateTime();
		$normalized = max(-9999.0, min(9999.0, round($openingBalanceHours, 2)));

		// unique-conflict:ok — update() mutates non-key columns only on a row
		// re-read by (user_id, year); it cannot hit at_uotb_user_year_uq.
		try {
			$entity = $this->findByUserAndYear($userId, $year);
			$entity->setOpeningBalanceHours($normalized);
			$entity->setUpdatedAt($now);
			return $this->update($entity);
		} catch (DoesNotExistException $e) {
			$entity = new UserOvertimeYearBalance();
			$entity->setUserId($userId);
			$entity->setYear($year);
			$entity->setOpeningBalanceHours($normalized);
			$entity->setCreatedAt($now);
			$entity->setUpdatedAt($now);
			try {
				return $this->insert($entity);
			} catch (DbException $e) {
				// NC wraps DBAL errors in DbalException — reason check, not the
				// Doctrine subtype (thrown as DbalException, never the original).
				if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
				$existing = $this->findByUserAndYear($userId, $year);
				$existing->setOpeningBalanceHours($normalized);
				$existing->setUpdatedAt($now);
				return $this->update($existing);
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
}
