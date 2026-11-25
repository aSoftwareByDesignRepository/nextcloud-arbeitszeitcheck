<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @extends QBMapper<YearResetRef> */
	// unique-conflict:ok — linkItem/markOutcome update non-key columns on rows addressed by the (run_id,user_id) unique key; ref inserts run inside YearEndResetService transactions with REASON_UNIQUE handling.
class YearResetRefMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_year_reset_ref', YearResetRef::class);
	}

	/** @return YearResetRef[] */
	public function findByRun(int $runId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	public function linkItem(int $runId, string $userId, int $itemId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('item_id', $qb->createNamedParameter($itemId))
			->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	public function markOutcome(int $runId, string $userId, string $outcome): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('outcome', $qb->createNamedParameter($outcome))
			->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}
}
