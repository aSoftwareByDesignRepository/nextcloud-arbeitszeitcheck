<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class UserBusinessTripProfileMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_btrip_profile', UserBusinessTripProfile::class);
	}

	/** Latest profile row effective on the date, or null. */
	public function findEffectiveForUser(string $userId, string $asOfDate): ?UserBusinessTripProfile
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($asOfDate)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('effective_to'),
				$qb->expr()->gte('effective_to', $qb->createNamedParameter($asOfDate))
			))
			->orderBy('effective_from', 'DESC')
			->addOrderBy('id', 'DESC')
			->setMaxResults(1);
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/** @return list<UserBusinessTripProfile> */
	public function findForUser(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('effective_from', 'DESC');
		return $this->findEntities($qb);
	}
}
