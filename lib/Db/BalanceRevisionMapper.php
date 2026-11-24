<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @extends QBMapper<BalanceRevision> */
class BalanceRevisionMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_balance_rev', BalanceRevision::class);
	}

	/**
	 * Bump-and-return inside the caller's transaction. Insert-on-conflict
	 * keeps exactly one row per (user, year); the counter is monotonic.
	 * Callers MUST hold their ledger write in the same atomic unit.
	 */
	public function bump(string $userId, int $calendarYear): int
	{
		// Portable upsert: try update first, insert on miss, retry update on
		// race — the unique key makes concurrent first-writes serialize.
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('revision', $qb->createFunction('revision + 1'))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($calendarYear)));
		if ($qb->executeStatement() === 0) {
			try {
				$row = new BalanceRevision();
				$row->setUserId($userId);
				$row->setCalendarYear($calendarYear);
				$row->setRevision(1);
				$this->insert($row);
				return 1;
			} catch (\Exception) {
				$qb2 = $this->db->getQueryBuilder();
				$qb2->update($this->getTableName())
					->set('revision', $qb2->createFunction('revision + 1'))
					->where($qb2->expr()->eq('user_id', $qb2->createNamedParameter($userId)))
					->andWhere($qb2->expr()->eq('calendar_year', $qb2->createNamedParameter($calendarYear)));
				$qb2->executeStatement();
			}
		}
		return $this->current($userId, $calendarYear);
	}

	public function current(string $userId, int $calendarYear): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('revision')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('calendar_year', $qb->createNamedParameter($calendarYear)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return $row === false ? 0 : (int)$row['revision'];
	}
}
