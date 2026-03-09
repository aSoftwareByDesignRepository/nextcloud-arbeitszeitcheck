<?php

declare(strict_types=1);

/**
 * TeamManagerMapper for app team manager assignments.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TeamManagerMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_team_managers', TeamManager::class);
	}

	/**
	 * Team IDs for which the given user is a manager.
	 *
	 * @return int[]
	 */
	public function getTeamIdsForManager(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('team_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int) $row['team_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * @return TeamManager[]
	 */
	public function findByTeamId(int $teamId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * @return TeamManager[]
	 */
	public function findByUserId(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntities($qb);
	}

	public function addManager(int $teamId, string $userId): TeamManager
	{
		$entity = new TeamManager();
		$entity->setTeamId($teamId);
		$entity->setUserId($userId);
		return $this->insert($entity);
	}

	public function removeManager(int $teamId, string $userId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	public function deleteByUserId(string $userId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	public function deleteByTeamId(int $teamId): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
