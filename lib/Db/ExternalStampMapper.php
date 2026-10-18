<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\IDBConnection;

/**
 * @extends QBMapper<ExternalStamp>
 */
class ExternalStampMapper extends QBMapper
{
	public const TABLE = 'at_ext_stamp';

	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, self::TABLE, ExternalStamp::class);
	}

	public function findBySourceRef(string $sourceApp, string $sourceRef): ?ExternalStamp
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('source_app', $qb->createNamedParameter($sourceApp)))
			->andWhere($qb->expr()->eq('source_ref', $qb->createNamedParameter($sourceRef)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Own-scope lookup: a stamp of another member is indistinguishable from
	 * a stamp that does not exist (no existence oracle).
	 */
	public function findForUser(int $id, string $uid): ?ExternalStamp
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * Row lock for the confirm/dismiss decision — two parallel decisions on
	 * the same stamp must serialize, not double-promote.
	 */
	public function findForUserLocked(int $id, string $uid): ?ExternalStamp
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->forUpdate();
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<ExternalStamp> */
	public function findPendingForUser(string $uid, int $limit = 50): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ExternalStamp::STATUS_PENDING)))
			->orderBy('start_ts', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	public function countPendingForUser(string $uid): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ExternalStamp::STATUS_PENDING)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Returns the inserted entity, or null when the unique
	 * (source_app, source_ref) index rejected a concurrent/replay insert.
	 */
	public function tryInsert(ExternalStamp $stamp): ?ExternalStamp
	{
		try {
			return $this->insert($stamp);
		} catch (DbException $e) {
			if ($e->getReason() === DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				return null;
			}
			throw $e;
		}
	}
}
