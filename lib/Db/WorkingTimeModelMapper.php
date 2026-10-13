<?php

declare(strict_types=1);

/**
 * WorkingTimeModelMapper for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * WorkingTimeModelMapper
 */
class WorkingTimeModelMapper extends QBMapper
{
	/**
	 * WorkingTimeModelMapper constructor
	 *
	 * @param IDBConnection $db
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_models', WorkingTimeModel::class);
	}

	/**
	 * Find all organisation-wide working time models.
	 *
	 * Personal (employee-scoped, user_id IS NOT NULL) models are excluded —
	 * they belong to a single user and must not appear in model pickers,
	 * settings lists, or vacation-default layers.
	 *
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return WorkingTimeModel[]
	 */
	public function findAll(?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('user_id'))
			->orderBy('name', 'ASC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find working time model by ID
	 *
	 * @param int $id
	 * @return WorkingTimeModel
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): WorkingTimeModel
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		return $this->findEntity($qb);
	}

	/**
	 * Find default working time model
	 *
	 * @return WorkingTimeModel|null
	 */
	public function findDefault(): ?WorkingTimeModel
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('user_id'))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Clear the default flag on every model (optionally keeping one id).
	 *
	 * Prefer this over read-modify-write so concurrent "set default" requests
	 * cannot leave two rows with is_default=1.
	 */
	public function clearDefaults(?int $exceptId = null): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('is_default', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('is_default', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->isNull('user_id'));

		if ($exceptId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($exceptId, IQueryBuilder::PARAM_INT)));
		}

		$qb->executeStatement();
	}

	/**
	 * Find working time models by type
	 *
	 * @param string $type
	 * @return WorkingTimeModel[]
	 */
	public function findByType(string $type): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->isNull('user_id'))
			->orderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Search working time models by name
	 *
	 * @param string $query
	 * @return WorkingTimeModel[]
	 */
	public function searchByName(string $query): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->like('name', $qb->createNamedParameter('%' . $this->db->escapeLikeParameter($query) . '%')))
			->andWhere($qb->expr()->isNull('user_id'))
			->orderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Count organisation-wide working time models
	 *
	 * @return int
	 */
	public function count(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'));

		$qb->from($this->getTableName())
			->where($qb->expr()->isNull('user_id'));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Find all personal (employee-scoped) models owned by a user.
	 *
	 * A user can accumulate several over time: rows that are still referenced
	 * by a past assignment stay for history, unreferenced ones get pruned on
	 * the next profile save.
	 *
	 * @param string $userId
	 * @return WorkingTimeModel[]
	 */
	public function findPersonalByUser(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Delete all personal models owned by a user (used on user deletion).
	 * Assignments must be removed first so no dangling model ids remain.
	 *
	 * @param string $userId
	 * @return int Number of deleted rows
	 */
	public function deletePersonalByUser(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}
}