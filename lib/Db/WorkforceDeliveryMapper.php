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

/** @extends QBMapper<WorkforceDelivery> */
	// unique-conflict:ok — read-only mapper today — no code path writes at_workforce_delivery; a future writer must handle at_wfd_uq conflicts.
class WorkforceDeliveryMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_workforce_delivery', WorkforceDelivery::class);
	}

	/**
	 * Claim up to $limit due rows with a lease CAS (lease_token unique per
	 * tick). At-least-once delivery survives worker crashes via lease expiry.
	 *
	 * @return WorkforceDelivery[]
	 */
	public function claimDue(\DateTime $now, \DateTime $leaseUntil, string $leaseToken, int $limit = 25): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter(WorkforceDelivery::STATE_PENDING)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('next_attempt_at'),
				$qb->expr()->lte('next_attempt_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE))
			))
			->orderBy('id', 'ASC')
			->setMaxResults($limit);
		$out = [];
		foreach ($this->findEntities($qb) as $row) {
			$upd = $this->db->getQueryBuilder();
			$upd->update($this->getTableName())
				->set('state', $upd->createNamedParameter(WorkforceDelivery::STATE_CLAIMED))
				->set('lease_token', $upd->createNamedParameter($leaseToken))
				->set('lease_until', $upd->createNamedParameter($leaseUntil, IQueryBuilder::PARAM_DATE))
				->set('updated_at', $upd->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
				->where($upd->expr()->eq('id', $upd->createNamedParameter($row->getId())))
				->andWhere($upd->expr()->eq('state', $upd->createNamedParameter(WorkforceDelivery::STATE_PENDING)));
			if ($upd->executeStatement() === 1) {
				$row->setState(WorkforceDelivery::STATE_CLAIMED);
				$out[] = $row;
			}
		}
		return $out;
	}

	public function markDelivered(WorkforceDelivery $row): void
	{
		$row->setState(WorkforceDelivery::STATE_DELIVERED);
		$row->setLeaseToken(null);
		$row->setLeaseUntil(null);
		$row->setUpdatedAt(new \DateTime());
		$this->update($row);
	}

	public function markRetry(WorkforceDelivery $row, \DateTime $nextAttempt): void
	{
		$row->setAttempts($row->getAttempts() + 1);
		$row->setState($row->getAttempts() >= WorkforceDelivery::MAX_ATTEMPTS
			? WorkforceDelivery::STATE_DEAD
			: WorkforceDelivery::STATE_PENDING);
		$row->setNextAttemptAt($nextAttempt);
		$row->setLeaseToken(null);
		$row->setLeaseUntil(null);
		$row->setUpdatedAt(new \DateTime());
		$this->update($row);
	}

	/** Requeue rows whose worker lease expired without a result. */
	public function requeueExpiredLeases(\DateTime $now): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('state', $qb->createNamedParameter(WorkforceDelivery::STATE_PENDING))
			->set('lease_token', $qb->createNamedParameter(null))
			->set('lease_until', $qb->createNamedParameter(null, IQueryBuilder::PARAM_DATE))
			->set('updated_at', $qb->createNamedParameter(new \DateTime(), IQueryBuilder::PARAM_DATE))
			->where($qb->expr()->eq('state', $qb->createNamedParameter(WorkforceDelivery::STATE_CLAIMED)))
			->andWhere($qb->expr()->lt('lease_until', $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATE)));
		return $qb->executeStatement();
	}
}
