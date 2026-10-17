<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class BusinessTripRuleAssignmentMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_btrip_rule_asn', BusinessTripRuleAssignment::class);
	}

	public function find(int $id): BusinessTripRuleAssignment
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<BusinessTripRuleAssignment> */
	public function findAll(int $limit = 500): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('id', 'DESC')
			->setMaxResults($limit);
		return $this->findEntities($qb);
	}

	/** @return list<BusinessTripRuleAssignment> all assignments effective on the date. */
	public function findEffective(string $asOfDate): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->lte('effective_from', $qb->createNamedParameter($asOfDate)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('effective_to'),
				$qb->expr()->gte('effective_to', $qb->createNamedParameter($asOfDate))
			))
			->orderBy('priority', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<BusinessTripRuleAssignment> */
	public function findForRuleSet(int $ruleSetId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('rule_set_id', $qb->createNamedParameter($ruleSetId, IQueryBuilder::PARAM_INT)))
			->orderBy('effective_from', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<BusinessTripRuleAssignment> */
	public function findOverlapping(
		string $scopeType,
		string $scopeId,
		\DateTimeInterface $effectiveFrom,
		?\DateTimeInterface $effectiveTo,
		?int $excludeId = null,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('scope_type', $qb->createNamedParameter($scopeType)))
			->andWhere($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId)))
			->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter(($effectiveTo ?? new \DateTimeImmutable('9999-12-31'))->format('Y-m-d'))))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('effective_to'),
				$qb->expr()->gte('effective_to', $qb->createNamedParameter($effectiveFrom->format('Y-m-d')))
			))
			->orderBy('id', 'ASC');
		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}
}
