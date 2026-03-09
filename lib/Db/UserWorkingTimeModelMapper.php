<?php

declare(strict_types=1);

/**
 * UserWorkingTimeModelMapper for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * UserWorkingTimeModelMapper
 */
class UserWorkingTimeModelMapper extends QBMapper
{
	/**
	 * UserWorkingTimeModelMapper constructor
	 *
	 * @param IDBConnection $db
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_user_models', UserWorkingTimeModel::class);
	}

	/**
	 * Find current working time model for a user
	 *
	 * @param string $userId
	 * @return UserWorkingTimeModel|null
	 */
	public function findCurrentByUser(string $userId): ?UserWorkingTimeModel
	{
		$qb = $this->db->getQueryBuilder();
		$now = new \DateTime();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($now->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('end_date'),
				$qb->expr()->gte('end_date', $qb->createNamedParameter($now->format('Y-m-d'), IQueryBuilder::PARAM_STR))
			))
			->orderBy('start_date', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find working time model assignment active on a specific date
	 *
	 * @param string $userId
	 * @param \DateTime $date
	 * @return UserWorkingTimeModel|null
	 */
	public function findByUserAndDate(string $userId, \DateTime $date): ?UserWorkingTimeModel
	{
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($date->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('end_date'),
				$qb->expr()->gte('end_date', $qb->createNamedParameter($date->format('Y-m-d'), IQueryBuilder::PARAM_STR))
			))
			->orderBy('start_date', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find all assignments for a user
	 *
	 * @param string $userId
	 * @return UserWorkingTimeModel[]
	 */
	public function findByUser(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('start_date', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find users with a specific working time model
	 *
	 * @param int $workingTimeModelId
	 * @param bool $onlyActive
	 * @return UserWorkingTimeModel[]
	 */
	public function findByWorkingTimeModel(int $workingTimeModelId, bool $onlyActive = true): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('working_time_model_id', $qb->createNamedParameter($workingTimeModelId)));

		if ($onlyActive) {
			$now = new \DateTime();
			$qb->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($now->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->orX(
					$qb->expr()->isNull('end_date'),
					$qb->expr()->gte('end_date', $qb->createNamedParameter($now->format('Y-m-d'), IQueryBuilder::PARAM_STR))
				));
		}

		$qb->orderBy('user_id', 'ASC')
			->addOrderBy('start_date', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Check if a user has overlapping working time model assignments
	 *
	 * @param string $userId
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @param int|null $excludeId
	 * @return UserWorkingTimeModel[]
	 */
	public function findOverlapping(string $userId, \DateTime $startDate, \DateTime $endDate, ?int $excludeId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($endDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('end_date'),
				$qb->expr()->gte('end_date', $qb->createNamedParameter($startDate->format('Y-m-d'), IQueryBuilder::PARAM_STR))
			));

		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * End current working time model assignment for a user
	 *
	 * @param string $userId
	 * @param \DateTime|null $endDate
	 * @return UserWorkingTimeModel|null
	 */
	public function endCurrentAssignment(string $userId, ?\DateTime $endDate = null): ?UserWorkingTimeModel
	{
		$current = $this->findCurrentByUser($userId);
		if (!$current) {
			return null;
		}

		$current->setEndDate($endDate ?: new \DateTime());
		$current->setUpdatedAt(new \DateTime());

		return $this->update($current);
	}

	/**
	 * Delete all working time model assignments for a user (used on user deletion)
	 *
	 * @param string $userId
	 * @return int Number of deleted rows
	 */
	public function deleteByUser(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}
}