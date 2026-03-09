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
	 * All team IDs that are the given team or any descendant (recursive).
	 *
	 * @return int[]
	 */
	public function getIdsWithDescendants(int $teamId): array
	{
		$ids = [$teamId];
		$toProcess = [$teamId];
		while (!empty($toProcess)) {
			$parentIds = $toProcess;
			$toProcess = [];
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from($this->getTableName())
				->where($qb->expr()->in('parent_id', $qb->createParameter('parents')));
			foreach (array_chunk($parentIds, 500) as $chunk) {
				$qb->setParameter('parents', $chunk, IQueryBuilder::PARAM_INT_ARRAY);
				$result = $qb->executeQuery();
				while ($row = $result->fetch()) {
					$id = (int) $row['id'];
					$ids[] = $id;
					$toProcess[] = $id;
				}
				$result->closeCursor();
			}
		}
		return array_values(array_unique($ids));
	}
}
