<?php

declare(strict_types=1);

/**
 * TeamMemberMapper for app team membership.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class TeamMemberMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_team_members', TeamMember::class);
	}

	/**
	 * User IDs that are members of any of the given teams.
	 *
	 * @param int[] $teamIds
	 * @return list<string>
	 */
	public function getMemberUserIdsByTeamIds(array $teamIds): array
	{
		if (empty($teamIds)) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')
			->from($this->getTableName())
			->where($qb->expr()->in('team_id', $qb->createParameter('team_ids')));
		$qb->setParameter('team_ids', $teamIds, IQueryBuilder::PARAM_INT_ARRAY);
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = $row['user_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * @return TeamMember[]
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
	 * @return TeamMember[]
	 */
	public function findByUserId(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->findEntities($qb);
	}

	public function addMember(int $teamId, string $userId): TeamMember
	{
		$entity = new TeamMember();
		$entity->setTeamId($teamId);
		$entity->setUserId($userId);
		return $this->insert($entity);
	}

	public function removeMember(int $teamId, string $userId): void
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
