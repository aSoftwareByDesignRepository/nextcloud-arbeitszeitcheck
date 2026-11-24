<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCA\ArbeitszeitCheck\Support\QueryInChunker;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class MonthClosureMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_month_closure', MonthClosure::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByUserAndMonth(string $userId, int $year, int $month): MonthClosure
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findByUserAndMonthOptional(string $userId, int $year, int $month): ?MonthClosure
	{
		try {
			return $this->findByUserAndMonth($userId, $year, $month);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * All finalized month closures for a user, newest first.
	 *
	 * @return MonthClosure[]
	 */
	public function findFinalizedByUserId(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(MonthClosure::STATUS_FINALIZED)))
			->orderBy('year', 'DESC')
			->addOrderBy('month', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Distinct calendar months that have at least one finalized closure for the given users (newest first).
	 *
	 * @param list<string> $userIds
	 * @return list<array{year: int, month: int}>
	 */
	public function findDistinctFinalizedYearMonthsForUserIds(array $userIds): array
	{
		if ($userIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('year', 'month')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(MonthClosure::STATUS_FINALIZED)))
			->andWhere(QueryInChunker::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
			->groupBy('year')
			->addGroupBy('month')
			->orderBy('year', 'DESC')
			->addOrderBy('month', 'DESC');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = ['year' => (int)$row['year'], 'month' => (int)$row['month']];
		}
		$result->closeCursor();

		return $out;
	}

	/**
	 * All distinct finalized (year, month) pairs in the table (newest first).
	 *
	 * @return list<array{year: int, month: int}>
	 */
	public function findDistinctFinalizedYearMonths(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('year', 'month')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(MonthClosure::STATUS_FINALIZED)))
			->groupBy('year')
			->addGroupBy('month')
			->orderBy('year', 'DESC')
			->addOrderBy('month', 'DESC');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = ['year' => (int)$row['year'], 'month' => (int)$row['month']];
		}
		$result->closeCursor();

		return $out;
	}

	/**
	 * User ids with a finalized closure for the given calendar month.
	 *
	 * @param list<string>|null $restrictUserIds If null, any user; if empty array, none.
	 * @return list<string>
	 */
	public function findUserIdsWithFinalizedMonth(int $year, int $month, ?array $restrictUserIds): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(MonthClosure::STATUS_FINALIZED)));
		if ($restrictUserIds !== null) {
			if ($restrictUserIds === []) {
				return [];
			}
			$qb->andWhere(QueryInChunker::in($qb, 'user_id', $restrictUserIds, IQueryBuilder::PARAM_STR_ARRAY));
		}
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[] = (string)$row['user_id'];
		}
		$result->closeCursor();

		return $out;
	}

	public function findLatestFinalizedBefore(string $userId, int $year, int $month): ?MonthClosure
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(MonthClosure::STATUS_FINALIZED)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->lt('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)),
				$qb->expr()->andX(
					$qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)),
					$qb->expr()->lt('month', $qb->createNamedParameter($month, IQueryBuilder::PARAM_INT))
				)
			))
			->orderBy('year', 'DESC')
			->addOrderBy('month', 'DESC')
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/** @param MonthClosure $entity */
	public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		$saved = parent::insert($entity);
		// §9.6: a finalized month is a balance-affecting write for its year
		// and every later closing year.
		$this->revisionService()->bumpForWriteRange(
			$entity->getUserId(),
			(int)$entity->getYear(),
			(int)(new \DateTime('now'))->format('Y'),
		);
		return $saved;
	}

	private function revisionService(): \OCA\ArbeitszeitCheck\Service\FinancialRevisionService
	{
		return \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\FinancialRevisionService::class);
	}
}
