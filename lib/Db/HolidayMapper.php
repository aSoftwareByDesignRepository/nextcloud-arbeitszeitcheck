<?php

declare(strict_types=1);

/**
 * HolidayMapper for the arbeitszeitcheck app
 *
 * Provides convenient query methods to retrieve holidays by state,
 * year and date ranges. This mapper is intentionally minimal and
 * stateless; higher-level business rules live in HolidayService.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class HolidayMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_holidays', Holiday::class);
	}

	/**
	 * Delete a holiday by ID.
	 *
	 * @param int $id
	 */
	public function deleteById(int $id): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * Find all holidays for a given state and year.
	 *
	 * @param string $state
	 * @param int $year
	 * @return Holiday[]
	 */
	public function findByStateAndYear(string $state, int $year): array
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere(
				$qb->expr()->andX(
					$qb->expr()->gte('date', $qb->createNamedParameter(sprintf('%04d-01-01', $year))),
					$qb->expr()->lte('date', $qb->createNamedParameter(sprintf('%04d-12-31', $year)))
				)
			)
			->orderBy('date', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find holidays for a given state and arbitrary date range.
	 *
	 * @param string $state
	 * @param \DateTime $start
	 * @param \DateTime $end
	 * @return Holiday[]
	 */
	public function findByStateAndRange(string $state, \DateTime $start, \DateTime $end): array
	{
		$startStr = $start->format('Y-m-d');
		$endStr = $end->format('Y-m-d');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere(
				$qb->expr()->andX(
					$qb->expr()->gte('date', $qb->createNamedParameter($startStr)),
					$qb->expr()->lte('date', $qb->createNamedParameter($endStr))
				)
			)
			->orderBy('date', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Check whether a holiday exists for the given state, date and scope
	 * (avoids duplicate inserts when seeding; used for idempotent statutory seed).
	 */
	public function existsForStateDateScope(string $state, string $dateYmd, string $scope): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('1'))
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere($qb->expr()->eq('date', $qb->createNamedParameter($dateYmd)))
			->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetchOne();
		$cursor->closeCursor();
		return $row !== false && $row !== null;
	}

	/**
	 * Check whether at least one statutory holiday exists for a state/year
	 * (used to decide if statutory seeding is needed; company holidays alone
	 * do not imply statutory holidays have been seeded).
	 */
	public function hasStatutoryHolidaysForStateAndYear(string $state, int $year): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('1'))
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter(Holiday::SCOPE_STATUTORY)))
			->andWhere(
				$qb->expr()->andX(
					$qb->expr()->gte('date', $qb->createNamedParameter(sprintf('%04d-01-01', $year))),
					$qb->expr()->lte('date', $qb->createNamedParameter(sprintf('%04d-12-31', $year)))
				)
			)
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetchOne();
		$cursor->closeCursor();
		return $row !== false && $row !== null;
	}

	/**
	 * Check whether at least one holiday definition exists for a state/year
	 * combination (kept for backward compatibility).
	 */
	public function hasHolidaysForStateAndYear(string $state, int $year): bool
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select($qb->createFunction('1'))
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere(
				$qb->expr()->andX(
					$qb->expr()->gte('date', $qb->createNamedParameter(sprintf('%04d-01-01', $year))),
					$qb->expr()->lte('date', $qb->createNamedParameter(sprintf('%04d-12-31', $year)))
				)
			)
			->setMaxResults(1);

		$cursor = $qb->executeQuery();
		$row = $cursor->fetchOne();
		$cursor->closeCursor();

		return $row !== false && $row !== null;
	}

	/**
	 * Find a holiday by its primary ID.
	 *
	 * Implemented explicitly with QueryBuilder so that static analysis
	 * does not need to know about QBMapper::find().
	 */
	public function findById(int $id): Holiday
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(
				$qb->expr()->eq(
					'id',
					$qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)
				)
			)
			->setMaxResults(1);

		/** @var Holiday|null $entity */
		$entity = $this->findEntity($qb);
		if ($entity === null) {
			throw new \OCP\AppFramework\Db\DoesNotExistException('Holiday not found');
		}

		return $entity;
	}
}

