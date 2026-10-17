<?php

declare(strict_types=1);

/**
 * TeamMemberMapper for app team membership.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCA\ArbeitszeitCheck\Support\QueryInChunker;
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
			->where(QueryInChunker::in($qb, 'team_id', $teamIds, IQueryBuilder::PARAM_INT_ARRAY));
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (string)$row['user_id'];
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
	 * Bounded member-userId sample for preview surfaces (deterministic order).
	 *
	 * @return list<string>
	 */
	public function findMemberUserIds(int $teamId, int $limit): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)))
			->orderBy('user_id', 'ASC')
			->setMaxResults($limit);
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (string)$row['user_id'];
		}
		$result->closeCursor();
		return $ids;
	}

	public function countMembers(int $teamId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('user_id', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->free();
		return $count;
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
