<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class BusinessTripCreditLedgerMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_btrip_ledger', BusinessTripCreditLedgerEntry::class);
	}

	public function findByEventKey(string $eventKey): ?BusinessTripCreditLedgerEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('event_key', $qb->createNamedParameter($eventKey)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<BusinessTripCreditLedgerEntry> */
	public function findForTrip(int $tripId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('trip_id', $qb->createNamedParameter($tripId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<BusinessTripCreditLedgerEntry> */
	public function findForUserBetween(string $userId, string $fromDate, string $toDate): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('local_date', $qb->createNamedParameter($fromDate)))
			->andWhere($qb->expr()->lte('local_date', $qb->createNamedParameter($toDate)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** Sum of signed minutes for a user/day — reversal + correction net out. */
	public function balanceForUserDate(string $userId, string $localDate): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('minutes'))->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('local_date', $qb->createNamedParameter($localDate)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
