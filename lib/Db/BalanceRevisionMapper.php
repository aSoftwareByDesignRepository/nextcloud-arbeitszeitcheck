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
	// unique-conflict:ok — bump()'s update sites only increment the non-key
	// `revision` counter on the unique-keyed row; the first-write insert
	// carries a REASON_UNIQUE check and falls back to the retry update.
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
			} catch (\OCP\DB\Exception $e) {
				// Only a (user,year) collision means "concurrent first write
				// won" — any other failure must surface, not retry blindly.
				if ($e->getReason() !== \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
					throw $e;
				}
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
