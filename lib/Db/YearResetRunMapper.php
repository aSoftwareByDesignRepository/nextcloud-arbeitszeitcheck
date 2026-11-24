<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<YearResetRun> */
class YearResetRunMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_year_reset_run', YearResetRun::class);
	}

	public function findByOperationKey(string $key): YearResetRun
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('operation_key', $qb->createNamedParameter($key)));
		return $this->findEntity($qb);
	}

	public function findById(int $id): YearResetRun
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $this->findEntity($qb);
	}

	public function findByPreviewKeyHash(string $hash): YearResetRun
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('preview_key_hash', $qb->createNamedParameter($hash)));
		return $this->findEntity($qb);
	}

	/**
	 * Open execution: at most one per (origin, closing_year) — the run-level
	 * execution singleton (NN-06). Returns null when none is active.
	 */
	public function findActiveRun(string $origin, int $closingYear): ?YearResetRun
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('origin', $qb->createNamedParameter($origin)))
			->andWhere($qb->expr()->eq('closing_year', $qb->createNamedParameter($closingYear)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(
				[YearResetRun::STATE_SELECTING, YearResetRun::STATE_READY, YearResetRun::STATE_EXECUTING],
				\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY
			)))
			->setMaxResults(1);
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return YearResetRun[] */
	public function findExpiredPreviews(\DateTimeInterface $now): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter(YearResetRun::STATE_PREVIEWED)))
			->andWhere($qb->expr()->lt('preview_expires_at', $qb->createNamedParameter(
				\DateTime::createFromInterface($now), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_DATE
			)));
		return $this->findEntities($qb);
	}

	/** @return YearResetRun[] newest first */
	public function listRecent(int $limit, int $offset): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/** @return YearResetRun[] runs still mid-execution (crash/catch-up ticks) */
	public function findExecuting(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter(YearResetRun::STATE_EXECUTING)));
		return $this->findEntities($qb);
	}

	public function countAll(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectAlias($qb->func()->count('id'), 'cnt')->from($this->getTableName());
		return (int)$qb->executeQuery()->fetchOne();
	}
}
