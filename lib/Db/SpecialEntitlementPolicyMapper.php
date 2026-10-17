<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class SpecialEntitlementPolicyMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_se_policy', SpecialEntitlementPolicy::class);
	}

	public function find(int $id): SpecialEntitlementPolicy
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/** @return list<SpecialEntitlementPolicy> */
	public function findActiveForType(int $typeId, string $asOfDate): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('entitlement_type_id', $qb->createNamedParameter($typeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($asOfDate)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('effective_to'),
				$qb->expr()->gte('effective_to', $qb->createNamedParameter($asOfDate))
			))
			->orderBy('effective_from', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<SpecialEntitlementPolicy> */
	public function findByType(int $typeId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('entitlement_type_id', $qb->createNamedParameter($typeId, IQueryBuilder::PARAM_INT)))
			->orderBy('scope_type', 'ASC')
			->addOrderBy('scope_id', 'ASC')
			->addOrderBy('effective_from', 'ASC')
			->addOrderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/** @return list<SpecialEntitlementPolicy> */
	public function findOverlapping(
		int $typeId,
		string $scopeType,
		string $scopeId,
		int $priority,
		\DateTimeInterface $effectiveFrom,
		?\DateTimeInterface $effectiveTo,
		?int $excludeId = null,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('entitlement_type_id', $qb->createNamedParameter($typeId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('scope_type', $qb->createNamedParameter($scopeType)))
			->andWhere($qb->expr()->eq('scope_id', $qb->createNamedParameter($scopeId)))
			->andWhere($qb->expr()->eq('priority', $qb->createNamedParameter($priority, IQueryBuilder::PARAM_INT)))
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

	/** CAS update; false means the version token no longer matches. */
	public function updateIfVersion(SpecialEntitlementPolicy $policy): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('effective_to', $qb->createNamedParameter($policy->getEffectiveTo()?->format('Y-m-d')))
			->set('priority', $qb->createNamedParameter($policy->getPriority(), IQueryBuilder::PARAM_INT))
			->set('reason', $qb->createNamedParameter($policy->getReason()))
			->set('updated_by', $qb->createNamedParameter($policy->getUpdatedBy()))
			->set('updated_at', $qb->createNamedParameter($policy->getUpdatedAt()?->format('Y-m-d H:i:s')))
			->set('version', $qb->createNamedParameter((int)$policy->getVersion() + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($policy->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($policy->getVersion(), IQueryBuilder::PARAM_INT)));
		if ($qb->executeStatement() !== 1) {
			return false;
		}
		$policy->setVersion((int)$policy->getVersion() + 1);
		return true;
	}
}
