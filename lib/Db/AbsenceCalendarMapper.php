<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class AbsenceCalendarMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_absence_calendar', AbsenceCalendar::class);
	}

	public function findByAbsenceId(int $absenceId): AbsenceCalendar
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('absence_id', $qb->createNamedParameter($absenceId, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * @return AbsenceCalendar[]
	 */
	public function findByUserId(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntities($qb);
	}

	public function findByAbsenceIdOrNull(int $absenceId): ?AbsenceCalendar
	{
		try {
			return $this->findByAbsenceId($absenceId);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	public function deleteByAbsenceId(int $absenceId): int
	{
		$qb = $this->db->getQueryBuilder();
		return $qb->delete($this->getTableName())
			->where($qb->expr()->eq('absence_id', $qb->createNamedParameter($absenceId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
