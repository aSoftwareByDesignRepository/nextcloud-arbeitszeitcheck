<?php

declare(strict_types=1);

/**
 * HolidayRuleMapper for the arbeitszeitcheck app
 *
 * Query methods for recurring holiday rules. Stateless; business rules
 * (anchor resolution, dedup, locks) live in HolidayRuleService.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class HolidayRuleMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_holiday_rules', HolidayRule::class);
	}

	/**
	 * All rules for a state (active and inactive — the read merge decides).
	 *
	 * @return HolidayRule[]
	 */
	public function findByState(string $state): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return HolidayRule[]
	 */
	public function findActiveByState(string $state): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	public function findById(int $id): HolidayRule
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		/** @var HolidayRule|null $entity */
		$entity = $this->findEntity($qb);
		if ($entity === null) {
			throw new DoesNotExistException('Holiday rule not found');
		}

		return $entity;
	}

	/**
	 * ID of the rule occupying (state, rule_key, scope), or null — dedup
	 * lookup inside the named lock (EC-B10, backed by at_hrules_key_uq).
	 */
	public function findIdForStateRuleKeyScope(string $state, string $ruleKey, string $scope): ?int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere($qb->expr()->eq('rule_key', $qb->createNamedParameter($ruleKey)))
			->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter($scope)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetchOne();
		$cursor->closeCursor();

		return ($row === false || $row === null) ? null : (int)$row;
	}

	/**
	 * Global epoch bump: rule edits change resolved holiday sets for every
	 * year ≥ start_year, so watching source-revision tokens must refresh.
	 * Runs inside the caller's transaction.
	 */
	private function bumpEpoch(): void
	{
		\OCP\Server::get(\OCA\ArbeitszeitCheck\Service\FinancialRevisionService::class)
			->bumpEpoch(\OCA\ArbeitszeitCheck\Service\FinancialRevisionService::EPOCH_HOLIDAYS);
	}

	/** @param \OCP\AppFramework\Db\Entity $entity */
	public function insert(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		$saved = parent::insert($entity);
		$this->bumpEpoch();
		return $saved;
	}

	/** @param \OCP\AppFramework\Db\Entity $entity */
	public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		$saved = parent::update($entity);
		$this->bumpEpoch();
		return $saved;
	}

	/** @param \OCP\AppFramework\Db\Entity $entity */
	public function delete(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity
	{
		$saved = parent::delete($entity);
		$this->bumpEpoch();
		return $saved;
	}
}
