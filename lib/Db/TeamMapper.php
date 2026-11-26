<?php

declare(strict_types=1);

/**
 * TeamMapper for app-owned teams.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TeamMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_teams', Team::class);
	}

	/**
	 * Find all teams, ordered by sort_order then name.
	 *
	 * @return Team[]
	 */
	public function findAll(?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('sort_order', 'ASC')
			->addOrderBy('name', 'ASC');
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}
		return $this->findEntities($qb);
	}

	public function find(int $id): Team
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Teams that are direct children of the given parent (null = root).
	 *
	 * @return Team[]
	 */
	public function findByParentId(?int $parentId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('sort_order', 'ASC')
			->addOrderBy('name', 'ASC');
		if ($parentId === null) {
			$qb->where($qb->expr()->isNull('parent_id'));
		} else {
			$qb->where($qb->expr()->eq('parent_id', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT)));
		}
		return $this->findEntities($qb);
	}

	/**
	 * Build an in-memory tree of `[id => parent_id|null]` for the full team
	 * universe. Used by depth-aware tie-breakers (layered vacation
	 * entitlement, reporting). One round-trip — small teams universes (≪10k
	 * rows) keep this cheap; for very large universes the engine can be
	 * adjusted to walk parents lazily.
	 *
	 * @return array<int, int|null>
	 */
	public function getParentMap(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'parent_id')
			->from($this->getTableName());
		$result = $qb->executeQuery();
		$map = [];
		while ($row = $result->fetch()) {
			$id = (int)$row['id'];
			$parent = $row['parent_id'] !== null && $row['parent_id'] !== '' ? (int)$row['parent_id'] : null;
			$map[$id] = $parent;
		}
		$result->closeCursor();
		return $map;
	}

	/**
	 * Compute the depth of a team in the tree (root = 0). Returns `-1` for
	 * unknown / circular teams; the layered entitlement engine treats `-1`
	 * as "not in tree" and falls back to id-based ordering. Hard caps the
	 * walk at 64 levels to prevent infinite loops if the table accidentally
	 * contains a cycle (validator rejects cycles at write time; this is a
	 * defence-in-depth read-side guard).
	 */
	public function computeDepth(int $teamId, array $parentMap): int
	{
		if (!isset($parentMap[$teamId])) {
			return -1;
		}
		$depth = 0;
		$current = $teamId;
		$seen = [$current => true];
		while (($parent = $parentMap[$current] ?? null) !== null) {
			if (isset($seen[$parent])) {
				return -1;
			}
			$depth++;
			if ($depth > 64) {
				return -1;
			}
			$seen[$parent] = true;
			$current = $parent;
		}
		return $depth;
	}

	/**
	 * All team IDs that are the given team or any descendant (recursive).
	 *
	 * Defence in depth (FM-C1/C-R7): a visited set guards against `parent_id`
	 * cycles (write validation already rejects them, but a corrupted tree
	 * must not loop a report request forever) and the walk is capped at
	 * {@see \OCA\ArbeitszeitCheck\Constants::TEAM_HIERARCHY_MAX_DEPTH}.
	 *
	 * @return int[]
	 */
	public function getIdsWithDescendants(int $teamId): array
	{
		$seen = [$teamId => true];
		$toProcess = [$teamId];
		$depth = 0;
		$tableName = $this->getTableName();
		while (!empty($toProcess) && $depth < \OCA\ArbeitszeitCheck\Constants::TEAM_HIERARCHY_MAX_DEPTH) {
			$depth++;
			$parentIds = $toProcess;
			$toProcess = [];
			foreach (array_chunk($parentIds, \OCA\ArbeitszeitCheck\Constants::BATCH_CHUNK_SIZE) as $chunk) {
				$qb = $this->db->getQueryBuilder();
				$qb->select('id')
					->from($tableName)
					->where($qb->expr()->in('parent_id', $qb->createParameter('parents')));
				$qb->setParameter('parents', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
				$result = $qb->executeQuery();
				while ($row = $result->fetch()) {
					$id = (int) $row['id'];
					if (!isset($seen[$id])) {
						$seen[$id] = true;
						$toProcess[] = $id;
					}
				}
				$result->closeCursor();
			}
		}
		return array_map('intval', array_keys($seen));
	}
}
