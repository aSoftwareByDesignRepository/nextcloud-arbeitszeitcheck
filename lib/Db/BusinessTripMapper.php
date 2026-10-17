<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class BusinessTripMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_btrip', BusinessTrip::class);
	}

	public function find(int $id): BusinessTrip
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findByRequestKey(string $requestKey): ?BusinessTrip
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('request_key', $qb->createNamedParameter($requestKey)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @return list<BusinessTrip> */
	public function findForUser(string $userId, int $limit = 100, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('starts_at_utc', 'DESC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findEntities($qb);
	}

	/** @return list<BusinessTrip> trips of the user overlapping the window (for TRIP_OVERLAP). */
	public function findOverlapping(string $userId, \DateTimeInterface $start, \DateTimeInterface $end, ?int $excludeId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lte('starts_at_utc', $qb->createNamedParameter($end->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->gte('ends_at_utc', $qb->createNamedParameter($start->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter([
				BusinessTrip::STATUS_DRAFT,
				BusinessTrip::STATUS_SUBMITTED,
				BusinessTrip::STATUS_APPROVED,
			], IQueryBuilder::PARAM_STR_ARRAY)));
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}

	/** @return list<BusinessTrip> trips overlapping a UTC window (any status). */
	public function findForUserBetween(string $userId, \DateTimeInterface $windowStart, \DateTimeInterface $windowEnd): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lt('starts_at_utc', $qb->createNamedParameter($windowEnd->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->gte('ends_at_utc', $qb->createNamedParameter($windowStart->format('Y-m-d H:i:s'))))
			->orderBy('starts_at_utc', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @return list<BusinessTrip> submitted trips awaiting a decision,
	 * oldest first. Bounded for manager-queue enumeration — callers
	 * still filter by scope per row.
	 */
	public function findSubmitted(int $limit = 200): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(BusinessTrip::STATUS_SUBMITTED)))
			->orderBy('updated_at', 'ASC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return list<BusinessTrip> trips in a state that blocks month closure inside the window. */
	public function findClosureBlockers(string $userId, \DateTimeInterface $monthStart, \DateTimeInterface $monthEnd): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lt('starts_at_utc', $qb->createNamedParameter($monthEnd->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->gte('ends_at_utc', $qb->createNamedParameter($monthStart->format('Y-m-d H:i:s'))))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter([
				BusinessTrip::STATUS_SUBMITTED,
			], IQueryBuilder::PARAM_STR_ARRAY)));
		return $this->findEntities($qb);
	}

	/**
	 * CAS update of draft-mutable content fields (window, timezones,
	 * purpose). Atomic at the DB level: a stale revision writes nothing.
	 */
	public function updateContentIfRevision(BusinessTrip $trip): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('starts_at_utc', $qb->createNamedParameter($trip->getStartsAtUtc()?->format('Y-m-d H:i:s')))
			->set('ends_at_utc', $qb->createNamedParameter($trip->getEndsAtUtc()?->format('Y-m-d H:i:s')))
			->set('origin_timezone', $qb->createNamedParameter($trip->getOriginTimezone()))
			->set('destination_timezone', $qb->createNamedParameter($trip->getDestinationTimezone()))
			->set('evaluation_timezone', $qb->createNamedParameter($trip->getEvaluationTimezone()))
			->set('purpose', $qb->createNamedParameter($trip->getPurpose()))
			->set('updated_by', $qb->createNamedParameter($trip->getUpdatedBy()))
			->set('updated_at', $qb->createNamedParameter($trip->getUpdatedAt()?->format('Y-m-d H:i:s')))
			->set('revision', $qb->createNamedParameter((int)$trip->getRevision() + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($trip->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($trip->getRevision(), IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) {
			return false;
		}
		$trip->setRevision((int)$trip->getRevision() + 1);
		return true;
	}

	/**
	 * Advisory snapshot-pointer write for previews. Predicated on the exact
	 * revision AND draft status, never bumps revision — a preview is a read
	 * surface, not a mutation. A lost race simply skips the pointer update;
	 * the frozen snapshot row itself is already persisted and approval never
	 * trusts the pointer anyway (it recalculates under lock).
	 */
	public function setSnapshotPointer(int $id, int $snapshotId, int $expectedRevision): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('current_snapshot_id', $qb->createNamedParameter($snapshotId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(BusinessTrip::STATUS_DRAFT)));
		$qb->executeStatement();
	}

	/** CAS transition; false when the revision token is stale. */
	public function updateIfRevision(BusinessTrip $trip): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($trip->getStatus()))
			->set('current_snapshot_id', $qb->createNamedParameter($trip->getCurrentSnapshotId()))
			->set('updated_by', $qb->createNamedParameter($trip->getUpdatedBy()))
			->set('updated_at', $qb->createNamedParameter($trip->getUpdatedAt()?->format('Y-m-d H:i:s')))
			->set('revision', $qb->createNamedParameter((int)$trip->getRevision() + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($trip->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($trip->getRevision(), IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) {
			return false;
		}
		$trip->setRevision((int)$trip->getRevision() + 1);
		return true;
	}
}
