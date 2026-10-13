<?php

declare(strict_types=1);

/**
 * CustomAbsenceTypeMapper for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

class CustomAbsenceTypeMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_custom_absence', CustomAbsenceType::class);
	}

	/**
	 * All custom types including inactive ones (admin list / history labels).
	 *
	 * @return CustomAbsenceType[]
	 */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->orderBy('code', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @return CustomAbsenceType[]
	 */
	public function findAllActive(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)))
			->orderBy('code', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id): CustomAbsenceType
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function findByCode(string $code): CustomAbsenceType
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		return $this->findEntity($qb);
	}

	public function codeExists(string $code): bool
	{
		try {
			$this->findByCode($code);
			return true;
		} catch (DoesNotExistException $e) {
			return false;
		}
	}

	/**
	 * Number of absence rows still referencing a type code — used to decide
	 * hard-delete (0) vs soft-delete (>0) in the admin flow.
	 */
	public function countAbsenceUsage(string $code): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('at_absences')
			->where($qb->expr()->eq('type', $qb->createNamedParameter($code)));
		return (int)$qb->executeQuery()->fetchOne();
	}
}
