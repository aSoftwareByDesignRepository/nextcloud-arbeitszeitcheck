<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class WorkLocationDefaultPolicyMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_work_location_policy', WorkLocationDefaultPolicy::class);
	}

	public function find(int $id): WorkLocationDefaultPolicy
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * All policies effective on $date for the given scopes (inclusive bounds,
	 * matching the shared resolver contract).
	 *
	 * @param list<array{0:string,1:string}> $scopes [scopeType, scopeId] pairs
	 * @return list<WorkLocationDefaultPolicy>
	 */
	public function findEffectiveForScopes(array $scopes, string $date): array
	{
		if ($scopes === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$or = $qb->expr()->orX();
		foreach ($scopes as [$scopeType, $scopeId]) {
			$or->add($qb->expr()->andX(
				$qb->expr()->eq('scope_type', $qb->createNamedParameter($scopeType)),
				$qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId)),
			));
		}
		$qb->select('*')->from($this->getTableName())
			->where($or)
			->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($date)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('effective_to'),
				$qb->expr()->gte('effective_to', $qb->createNamedParameter($date)),
			))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Rows of the same scope whose effective range overlaps [from, to].
	 * Open-ended `effective_to` overlaps everything from `from` onward.
	 *
	 * @return list<WorkLocationDefaultPolicy>
	 */
	public function findOverlapping(string $scopeType, string $scopeId, string $from, ?string $to, ?int $excludeId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('scope_type', $qb->createNamedParameter($scopeType)))
			->andWhere($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('effective_to'),
				$qb->expr()->gte('effective_to', $qb->createNamedParameter($from)),
			));
		if ($to !== null) {
			$qb->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($to)));
		}
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Active (currently effective or future) policies referencing a location
	 * code — used to block deactivation of a still-referenced catalog entry.
	 *
	 * @return list<WorkLocationDefaultPolicy>
	 */
	public function findActiveByLocationCode(string $code, string $today): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('location_code', $qb->createNamedParameter($code)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('effective_to'),
				$qb->expr()->gte('effective_to', $qb->createNamedParameter($today)),
			));
		return $this->findEntities($qb);
	}

	/**
	 * Every policy referencing a location code regardless of effective range —
	 * a referenced code must never be hard-deleted even when the policy is
	 * historical (resolution history stays reconstructible).
	 *
	 * @return list<WorkLocationDefaultPolicy>
	 */
	public function findByLocationCode(string $code): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('location_code', $qb->createNamedParameter($code)));
		return $this->findEntities($qb);
	}

	/**
	 * @return list<WorkLocationDefaultPolicy>
	 */
	public function findByScope(string $scopeType, string $scopeId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('scope_type', $qb->createNamedParameter($scopeType)))
			->andWhere($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId)))
			->orderBy('effective_from', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}
}
