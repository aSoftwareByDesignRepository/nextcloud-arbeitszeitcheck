<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class BusinessTripDayCalculationMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_btrip_daycalc', BusinessTripDayCalculation::class);
	}

	/** @return list<BusinessTripDayCalculation> */
	public function findForTrip(int $tripId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('trip_id', $qb->createNamedParameter($tripId, IQueryBuilder::PARAM_INT)))
			->orderBy('local_date', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForTrip(int $tripId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('trip_id', $qb->createNamedParameter($tripId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
