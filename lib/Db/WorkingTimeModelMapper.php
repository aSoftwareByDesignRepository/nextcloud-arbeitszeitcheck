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
	 * Find all working time models
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
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
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
			->where($qb->expr()->like('name', $qb->createNamedParameter('%' . $query . '%')))
			->orderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Count working time models
	 *
	 * @return int
	 */
	public function count(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'));

		$qb->from($this->getTableName());

		return (int)$qb->executeQuery()->fetchOne();
	}
}