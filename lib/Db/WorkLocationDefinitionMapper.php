<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class WorkLocationDefinitionMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_work_location_def', WorkLocationDefinition::class);
	}

	public function find(int $id): WorkLocationDefinition
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	public function findByCode(string $code): WorkLocationDefinition
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)));
		return $this->findEntity($qb);
	}

	public function codeExists(string $code): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from($this->getTableName())
			->where($qb->expr()->eq('code', $qb->createNamedParameter($code)))
			->setMaxResults(1);
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/**
	 * @return array<string, WorkLocationDefinition> code => row, ordered by code.
	 */
	public function findAllIndexed(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())->orderBy('code', 'ASC');
		$indexed = [];
		foreach ($this->findEntities($qb) as $row) {
			$indexed[$row->getCode()] = $row;
		}
		return $indexed;
	}

	/**
	 * @return list<WorkLocationDefinition>
	 */
	public function findActive(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('code', 'ASC');
		return $this->findEntities($qb);
	}

	public function countReferencesByCode(string $code): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('at_entries')
			->where($qb->expr()->eq('work_location', $qb->createNamedParameter($code)));
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}
}
