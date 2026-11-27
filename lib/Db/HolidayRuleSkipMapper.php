<?php

declare(strict_types=1);

/**
 * HolidayRuleSkipMapper for the arbeitszeitcheck app
 *
 * Per-year exceptions on recurring holiday rules. Stateless query methods;
 * serialisation against rule mutations happens via the named
 * policyCatalog('holiday') lock in HolidayRuleService.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class HolidayRuleSkipMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_holiday_rule_skips', HolidayRuleSkip::class);
	}

	/**
	 * All skips for one rule (all years).
	 *
	 * @return HolidayRuleSkip[]
	 */
	public function findByRuleId(int $ruleId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
			->orderBy('year', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Skipped years for a set of rules — one query per state listing, not N.
	 *
	 * @param int[] $ruleIds
	 * @return array<int,int[]> rule_id => list of skipped years
	 */
	public function yearsForRuleIds(array $ruleIds): array
	{
		if ($ruleIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('rule_id', 'year')
			->from($this->getTableName())
			->where($qb->expr()->in('rule_id', $qb->createNamedParameter($ruleIds, IQueryBuilder::PARAM_INT_ARRAY)));
		$cursor = $qb->executeQuery();
		$result = [];
		while (($row = $cursor->fetch()) !== false) {
			$result[(int)$row['rule_id']][] = (int)$row['year'];
		}
		$cursor->closeCursor();
		foreach ($result as &$years) {
			sort($years);
		}
		return $result;
	}

	public function existsForRuleAndYear(int $ruleId, int $year): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('1'))
			->from($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);
		$cursor = $qb->executeQuery();
		$row = $cursor->fetchOne();
		$cursor->closeCursor();
		return $row !== false && $row !== null;
	}

	public function deleteByRuleAndYear(int $ruleId, int $year): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('year', $qb->createNamedParameter($year, IQueryBuilder::PARAM_INT)));
		$affected = $qb->executeStatement();
		if ($affected > 0) {
			$this->bumpEpoch();
		}
		return $affected > 0;
	}

	/**
	 * Cascade-delete every skip of a rule — called inside the named lock by
	 * the service before the rule row goes away (EC-B5).
	 */
	public function deleteByRuleId(int $ruleId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
		$this->bumpEpoch();
	}

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
}
