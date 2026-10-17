<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class WorkLocationDayOverrideMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_work_location_day', WorkLocationDayOverride::class);
	}

	public function findForUserDate(string $userId, string $localDate): ?WorkLocationDayOverride
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('local_date', $qb->createNamedParameter($localDate, IQueryBuilder::PARAM_DATE)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/**
	 * @return list<WorkLocationDayOverride>
	 */
	public function findForUserRange(string $userId, string $fromDate, string $toDate): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('local_date', $qb->createNamedParameter($fromDate, IQueryBuilder::PARAM_DATE)))
			->andWhere($qb->expr()->lte('local_date', $qb->createNamedParameter($toDate, IQueryBuilder::PARAM_DATE)))
			->orderBy('local_date', 'ASC');
		return $this->findEntities($qb);
	}

	public function deleteForUserDate(string $userId, string $localDate): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('local_date', $qb->createNamedParameter($localDate, IQueryBuilder::PARAM_DATE)));
		$qb->executeStatement();
	}
}
