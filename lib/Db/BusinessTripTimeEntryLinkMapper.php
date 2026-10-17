<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class BusinessTripTimeEntryLinkMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_btrip_link', BusinessTripTimeEntryLink::class);
	}

	/** @return list<BusinessTripTimeEntryLink> */
	public function findForTrip(int $tripId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('trip_id', $qb->createNamedParameter($tripId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/** @return list<int> linked time entry ids */
	public function findTimeEntryIdsForTrip(int $tripId): array
	{
		return array_map(
			static fn (BusinessTripTimeEntryLink $l): int => (int)$l->getTimeEntryId(),
			$this->findForTrip($tripId),
		);
	}

	/** Trip that already links this entry, or null. */
	public function findByTimeEntryId(int $timeEntryId): ?BusinessTripTimeEntryLink
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('time_entry_id', $qb->createNamedParameter($timeEntryId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function deleteForTrip(int $tripId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('trip_id', $qb->createNamedParameter($tripId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
