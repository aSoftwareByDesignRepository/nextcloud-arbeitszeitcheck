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

/** @extends QBMapper<YearResetItem> */
class YearResetItemMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_year_reset_item', YearResetItem::class);
	}

	public function findById(int $id): YearResetItem
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));
		return $this->findEntity($qb);
	}

	public function findByUserAndYear(string $userId, int $closingYear): ?YearResetItem
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('closing_year', $qb->createNamedParameter($closingYear)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return YearResetItem[] items of one run, user-ordered */
	public function findByRun(int $runId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('last_execution_run_id', $qb->createNamedParameter($runId)))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Claim the next lease-eligible item of a run: pending, failed-retryable
	 * with an expired lease. Rows are claimed with a lease CAS on
	 * (lease_token, lease_until) so two ticks can never process one row.
	 */
	public function claimNext(int $runId, string $leaseToken, \DateTime $leaseUntil, \DateTime $now): ?YearResetItem
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('last_execution_run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->in('state', $qb->createNamedParameter(
				[YearResetItem::STATE_PENDING, YearResetItem::STATE_IN_PROGRESS],
				IQueryBuilder::PARAM_STR_ARRAY
			)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('lease_until'),
				$qb->expr()->lt('lease_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			))
			->orderBy('id', 'ASC')
			->setMaxResults(1);
		$candidates = $this->findEntities($qb);
		foreach ($candidates as $item) {
			if ($this->tryClaim($item, $leaseToken, $leaseUntil)) {
				return $item;
			}
		}
		return null;
	}

	/** CAS claim — returns true only if this caller owns the row now. */
	public function tryClaim(YearResetItem $item, string $leaseToken, \DateTime $leaseUntil): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('lease_token', $qb->createNamedParameter($leaseToken))
			->set('lease_until', $qb->createNamedParameter($leaseUntil, IQueryBuilder::PARAM_DATE))
			->set('state', $qb->createNamedParameter(YearResetItem::STATE_IN_PROGRESS))
			->set('revision', $qb->createNamedParameter($item->getRevision() + 1))
			->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($item->getId())))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($item->getRevision())));
		if ($qb->executeStatement() !== 1) {
			return false;
		}
		$item->setLeaseToken($leaseToken);
		$item->setLeaseUntil($leaseUntil);
		$item->setState(YearResetItem::STATE_IN_PROGRESS);
		$item->setRevision($item->getRevision() + 1);
		return true;
	}

	/** Terminal transition guarded by lease ownership + revision CAS. */
	public function finishItem(YearResetItem $item, string $leaseToken, string $state, array $fields): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter($state))
			->set('revision', $qb->createNamedParameter($item->getRevision() + 1))
			->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->set('lease_token', $qb->createNamedParameter(null))
			->set('lease_until', $qb->createNamedParameter(null, IQueryBuilder::PARAM_DATE))
			->set('completed_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($item->getId())))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($item->getRevision())))
			->andWhere($qb->expr()->eq('lease_token', $qb->createNamedParameter($leaseToken)));
		foreach ($fields as $col => $value) {
			$qb->set($col, $qb->createNamedParameter($value));
		}
		if ($qb->executeStatement() !== 1) {
			return false;
		}
		$item->setState($state);
		$item->setRevision($item->getRevision() + 1);
		return true;
	}

	/**
	 * Flag every not-terminal item of the run whose source revision no
	 * longer matches — late source changes surface as needs_review (§9.6).
	 */
	public function flagStaleSourceRevisions(int $runId, string $userId, string $currentRevision): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('review_state', $qb->createNamedParameter(YearResetItem::REVIEW_NEEDS_REVIEW))
			->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('last_execution_run_id', $qb->createNamedParameter($runId)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->neq('state', $qb->createNamedParameter(YearResetItem::STATE_DONE)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('source_revision'),
				$qb->expr()->neq('source_revision', $qb->createNamedParameter($currentRevision))
			));
		$qb->executeStatement();
	}

	/**
	 * §9.6: a committed financial write flags every reset item for that
	 * user/year whose stored source token no longer matches — including
	 * completed items, whose review_state surfaces in the run views.
	 * Called inside the writer's own transaction by FinancialRevisionService.
	 */
	public function flagStaleForUser(string $userId, int $closingYear, string $currentRevision): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('review_state', $qb->createNamedParameter(YearResetItem::REVIEW_NEEDS_REVIEW))
			->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('closing_year', $qb->createNamedParameter($closingYear)))
			->andWhere($qb->expr()->neq('review_state', $qb->createNamedParameter(YearResetItem::REVIEW_NEEDS_REVIEW)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('source_revision'),
				$qb->expr()->neq('source_revision', $qb->createNamedParameter($currentRevision))
			));
		$qb->executeStatement();
	}

	/** @return array<string,int> state => count for one run */
	public function countByState(int $runId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('state')->selectAlias($qb->func()->count('id'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('last_execution_run_id', $qb->createNamedParameter($runId)))
			->groupBy('state');
		$out = [];
		$res = $qb->executeQuery();
		while ($row = $res->fetch()) {
			$out[(string)$row['state']] = (int)$row['cnt'];
		}
		$res->closeCursor();
		return $out;
	}
}
