<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class HolidaySuppressionMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_holiday_suppress', HolidaySuppression::class);
	}

	public function isSuppressed(string $state, string $dateYmd): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('1'))
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere($qb->expr()->eq('date', $qb->createNamedParameter($dateYmd)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetchOne();
		$cursor->closeCursor();

		return $row !== false && $row !== null;
	}

	/**
	 * @return string[] Y-m-d dates suppressed for state/year
	 */
	public function findSuppressedDatesForStateAndYear(string $state, int $year): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('date')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere(
				$qb->expr()->andX(
					$qb->expr()->gte('date', $qb->createNamedParameter(sprintf('%04d-01-01', $year))),
					$qb->expr()->lte('date', $qb->createNamedParameter(sprintf('%04d-12-31', $year)))
				)
			)
			->orderBy('date', 'ASC');
		$cursor = $qb->executeQuery();
		$dates = [];
		while (($row = $cursor->fetch()) !== false) {
			$dates[] = (string)$row['date'];
		}
		$cursor->closeCursor();

		return $dates;
	}

	public function addSuppression(string $state, string $dateYmd, ?string $suppressedBy = null): void
	{
		if ($this->isSuppressed($state, $dateYmd)) {
			return;
		}

		$entity = new HolidaySuppression();
		$entity->setState($state);
		$entity->setDate(new \DateTime($dateYmd));
		$entity->setCreatedAt(new \DateTime());
		$entity->setSuppressedBy($suppressedBy);

		try {
			$this->insert($entity);
		} catch (DbException $e) {
			// Lost the isSuppressed()→insert race: the unique (state, date) row
			// now exists, which is the state this call wanted — idempotent.
			if ($e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
		}
	}

	public function removeSuppression(string $state, string $dateYmd): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere($qb->expr()->eq('date', $qb->createNamedParameter($dateYmd)));
		$qb->executeStatement();
	}
}
