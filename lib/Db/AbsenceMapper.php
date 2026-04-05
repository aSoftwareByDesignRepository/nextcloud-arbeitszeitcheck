<?php

declare(strict_types=1);

/**
 * AbsenceMapper for the arbeitszeitcheck app
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
 * AbsenceMapper
 */
class AbsenceMapper extends QBMapper
{
	/**
	 * AbsenceMapper constructor
	 *
	 * @param IDBConnection $db
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_absences', Absence::class);
	}

	/**
	 * Find absence by ID
	 *
	 * @param int $id
	 * @return Absence
	 * @throws DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): Absence
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Find all absences for a user
	 *
	 * @param string $userId
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return Absence[]
	 */
	public function findByUser(string $userId, ?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('start_date', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find absences by user and date range
	 *
	 * @param string $userId
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @return Absence[]
	 */
	public function findByUserAndDateRange(string $userId, \DateTime $startDate, \DateTime $endDate): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($endDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($startDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->orderBy('start_date', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find absences by date range (all users)
	 *
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @return Absence[]
	 */
	public function findByDateRange(\DateTime $startDate, \DateTime $endDate): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->lte('start_date', $qb->createNamedParameter($endDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($startDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->orderBy('start_date', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find absences where days is NULL (legacy records before working-days backfill).
	 * Used by repair step to backfill working days using state-aware holiday logic.
	 *
	 * @return Absence[]
	 */
	public function findWithNullDays(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('days'))
			->orderBy('id', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * Find absences by status
	 *
	 * @param string $status
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return Absence[]
	 */
	public function findByStatus(string $status, ?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('start_date', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find absences pending approval for specific users (team members)
	 *
	 * @param array $userIds
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return Absence[]
	 */
	public function findPendingForUsers(array $userIds, ?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Absence::STATUS_PENDING)))
			->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('start_date', 'ASC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find absences where the given user is substitute and approval is pending
	 *
	 * @param string $substituteUserId User ID of the substitute
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return Absence[]
	 */
	public function findSubstitutePendingForUser(string $substituteUserId, ?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('substitute_user_id', $qb->createNamedParameter($substituteUserId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Absence::STATUS_SUBSTITUTE_PENDING)))
			->orderBy('start_date', 'ASC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find all absences where the given user is configured as substitute.
	 * Used for cleanup and notifications when a substitute account is deleted.
	 *
	 * @param string $substituteUserId
	 * @return Absence[]
	 */
	public function findBySubstituteUser(string $substituteUserId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('substitute_user_id', $qb->createNamedParameter($substituteUserId)))
			->orderBy('start_date', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find active absences (currently ongoing)
	 *
	 * @param string|null $userId Optional user filter
	 * @return Absence[]
	 */
	public function findActive(?string $userId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$today = new \DateTime();
		$today->setTime(0, 0, 0);

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Absence::STATUS_APPROVED)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($today->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($today->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->orderBy('start_date', 'ASC');

		if ($userId !== null) {
			$qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * @return Absence[]
	 */
	public function findApprovedBatch(int $limit, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(Absence::STATUS_APPROVED)))
			->orderBy('id', 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);

		return $this->findEntities($qb);
	}

	/**
	 * Delete all absences for a user (used on user deletion)
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

	/**
	 * Clear substitute_user_id when the substitute user is deleted
	 *
	 * @param string $substituteUserId
	 * @return int Number of updated rows
	 */
	public function clearSubstituteForUser(string $substituteUserId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('substitute_user_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('substitute_user_id', $qb->createNamedParameter($substituteUserId)));
		return $qb->executeStatement();
	}

	/**
	 * Get total vacation days used by a user in a year.
	 * - Counts absences entirely within the year (start >= Jan 1 and end <= Dec 31) via SUM(days).
	 * - For absences spanning year boundaries, the service layer allocates days per year via
	 *   findVacationApprovedSpanningYearBoundary and computeWorkingDaysPerYearForUser.
	 *
	 * @param string $userId
	 * @param int $year
	 * @return float
	 */
	public function getVacationDaysUsed(string $userId, int $year): float
	{
		try {
			$startDate = new \DateTime("$year-01-01");
			$endDate = new \DateTime("$year-12-31");

			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->createFunction('SUM(COALESCE(days, 0))'))
				->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(Absence::TYPE_VACATION)))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Absence::STATUS_APPROVED)))
				->andWhere($qb->expr()->gte('start_date', $qb->createNamedParameter($startDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->lte('end_date', $qb->createNamedParameter($endDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)));

			$result = $qb->executeQuery()->fetchOne();
			return (float)($result ?: 0);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting vacation days used: ' . $e->getMessage(), ['exception' => $e]);
			return 0.0;
		}
	}

	/**
	 * Find approved vacation absences that span the year boundary (start before year or end after year).
	 * Used to allocate working days to the correct year for accurate vacation stats.
	 *
	 * @param string $userId
	 * @param int $year
	 * @return Absence[]
	 */
	public function findVacationApprovedSpanningYearBoundary(string $userId, int $year): array
	{
		$yearStart = new \DateTime("$year-01-01");
		$yearEnd = new \DateTime("$year-12-31");

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(Absence::TYPE_VACATION)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Absence::STATUS_APPROVED)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($yearEnd->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($yearStart->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->lt('start_date', $qb->createNamedParameter($yearStart->format('Y-m-d'), IQueryBuilder::PARAM_STR)),
				$qb->expr()->gt('end_date', $qb->createNamedParameter($yearEnd->format('Y-m-d'), IQueryBuilder::PARAM_STR))
			))
			->orderBy('start_date', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * All approved vacation absences that overlap a calendar year (inclusive of fully inside and spanning boundaries).
	 * Ordered by start_date ASC, id ASC for FIFO carryover allocation.
	 *
	 * @return Absence[]
	 */
	public function findVacationApprovedOverlappingYear(string $userId, int $year): array
	{
		$yearStart = new \DateTime("$year-01-01");
		$yearEnd = new \DateTime("$year-12-31");

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(Absence::TYPE_VACATION)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Absence::STATUS_APPROVED)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($yearEnd->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($yearStart->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->orderBy('start_date', 'ASC')
			->addOrderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Get total sick leave days for a user in a year
	 *
	 * @param string $userId
	 * @param int $year
	 * @return float
	 */
	public function getSickLeaveDays(string $userId, int $year): float
	{
		try {
			$startDate = new \DateTime("$year-01-01");
			$endDate = new \DateTime("$year-12-31");

			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->createFunction('SUM(COALESCE(days, 0))'))
				->from($this->getTableName())
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(Absence::TYPE_SICK_LEAVE)))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Absence::STATUS_APPROVED)))
				->andWhere($qb->expr()->gte('start_date', $qb->createNamedParameter($startDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
				->andWhere($qb->expr()->lte('end_date', $qb->createNamedParameter($endDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)));

			$result = $qb->executeQuery()->fetchOne();
			return (float)($result ?: 0);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting sick leave days: ' . $e->getMessage(), ['exception' => $e]);
			return 0.0;
		}
	}

	/**
	 * Check for overlapping absences
	 *
	 * @param string $userId
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @param int|null $excludeId ID to exclude from check (for updates)
	 * @return Absence[]
	 */
	public function findOverlapping(string $userId, \DateTime $startDate, \DateTime $endDate, ?int $excludeId = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->in('status', $qb->createNamedParameter([
				Absence::STATUS_PENDING,
				Absence::STATUS_SUBSTITUTE_PENDING,
				Absence::STATUS_APPROVED
			], IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->lte('start_date', $qb->createNamedParameter($endDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->gte('end_date', $qb->createNamedParameter($startDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)));

		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Count all absences for a user
	 *
	 * @param string $userId
	 * @return int
	 */
	public function countByUser(string $userId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Count absences for a user by type and year
	 *
	 * @param string $userId
	 * @param string $type
	 * @param int $year
	 * @return int
	 */
	public function countByUserTypeAndYear(string $userId, string $type, int $year): int
	{
		$startDate = new \DateTime("$year-01-01");
		$endDate = new \DateTime("$year-12-31");

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Absence::STATUS_APPROVED)))
			->andWhere($qb->expr()->gte('start_date', $qb->createNamedParameter($startDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lte('end_date', $qb->createNamedParameter($endDate->format('Y-m-d'), IQueryBuilder::PARAM_STR)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Get absence statistics for a user
	 *
	 * @param string $userId
	 * @param int $year
	 * @return array
	 */
	public function getUserStats(string $userId, int $year): array
	{
		$vacationDays = $this->getVacationDaysUsed($userId, $year);
		$sickLeaveDays = $this->getSickLeaveDays($userId, $year);

		$otherAbsences = [];
		$absenceTypes = [
			Absence::TYPE_PERSONAL_LEAVE,
			Absence::TYPE_PARENTAL_LEAVE,
			Absence::TYPE_SPECIAL_LEAVE,
			Absence::TYPE_UNPAID_LEAVE,
			Absence::TYPE_HOME_OFFICE,
			Absence::TYPE_BUSINESS_TRIP
		];

		foreach ($absenceTypes as $type) {
			$count = $this->countByUserTypeAndYear($userId, $type, $year);
			if ($count > 0) {
				$otherAbsences[$type] = $count;
			}
		}

		return [
			'vacation_days_used' => $vacationDays,
			'sick_leave_days' => $sickLeaveDays,
			'other_absences' => $otherAbsences,
			'total_absences' => array_sum($otherAbsences)
		];
	}
}