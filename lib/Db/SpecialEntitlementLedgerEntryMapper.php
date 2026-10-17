<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class SpecialEntitlementLedgerEntryMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_se_ledger', SpecialEntitlementLedgerEntry::class);
	}

	public function findByEventKey(string $eventKey): ?SpecialEntitlementLedgerEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('event_key', $qb->createNamedParameter($eventKey)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** Posted balance: credits positive, debits negative, corrections signed by amount. */
	public function balance(int $periodId): float
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction("COALESCE(SUM(CASE WHEN kind = 'debit' THEN -amount ELSE amount END), 0)"))
			->from($this->getTableName())
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		return (float)$qb->executeQuery()->fetchOne();
	}

	public function balanceForPeriod(int $periodId): float
	{
		return $this->balance($periodId);
	}

	public function usedAmountForPeriod(int $periodId): float
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction(
			"COALESCE(SUM(CASE WHEN kind = 'debit' THEN amount WHEN kind = 'correction' AND (reverses_ledger_id IS NOT NULL OR amount < 0) THEN -amount ELSE 0 END), 0)"
		))
			->from($this->getTableName())
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)));
		return (float)$qb->executeQuery()->fetchOne();
	}

	/** @return list<SpecialEntitlementLedgerEntry> */
	public function findForPeriod(int $periodId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @param list<int> $periodIds @return list<SpecialEntitlementLedgerEntry> */
	public function findCreatedBetween(array $periodIds, string $start, string $end): array
	{
		if ($periodIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('period_id', $qb->createNamedParameter($periodIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($start . ' 00:00:00')))
			->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($end . ' 23:59:59')))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<SpecialEntitlementLedgerEntry> */
	public function findForPeriodPage(int $periodId, int $limit, int $offset): array
	{
		$limit = max(1, min(200, $limit));
		$offset = max(0, $offset);
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('period_id', $qb->createNamedParameter($periodId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}
}
