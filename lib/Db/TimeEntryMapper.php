<?php

declare(strict_types=1);

/**
 * TimeEntryMapper for the arbeitszeitcheck app
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
 * TimeEntryMapper
 */
class TimeEntryMapper extends QBMapper
{
	/**
	 * TimeEntryMapper constructor
	 *
	 * @param IDBConnection $db
	 */
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'at_entries', TimeEntry::class);
	}

	/**
	 * Find time entry by ID
	 *
	 * @param int $id
	 * @return TimeEntry
	 * @throws DoesNotExistException
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function find(int $id): TimeEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Find all time entries for a user
	 *
	 * @param string $userId
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return TimeEntry[]
	 */
	public function findByUser(string $userId, ?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('start_time', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find time entries by user and date range
	 *
	 * @param string $userId
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @return TimeEntry[]
	 */
	public function findByUserAndDateRange(string $userId, \DateTime $startDate, \DateTime $endDate): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('start_time', $qb->createNamedParameter($startDate->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('start_time', $qb->createNamedParameter($endDate->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->orderBy('start_time', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find active time entry for a user (currently clocked in)
	 *
	 * @param string $userId
	 * @return TimeEntry|null
	 */
	public function findActiveByUser(string $userId): ?TimeEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_ACTIVE)))
			->orderBy('start_time', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find paused or unfinished time entry for today for a user
	 *
	 * @param string $userId
	 * @return TimeEntry|null
	 */
	public function findPausedOrUnfinishedTodayByUser(string $userId): ?TimeEntry
	{
		$today = new \DateTime();
		$today->setTime(0, 0, 0);
		$tomorrow = clone $today;
		$tomorrow->modify('+1 day');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('start_time', $qb->createNamedParameter($today->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('start_time', $qb->createNamedParameter($tomorrow->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_PAUSED)),
				$qb->expr()->andX(
					$qb->expr()->isNull('end_time'),
					$qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_ACTIVE))
				)
			))
			->orderBy('start_time', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find time entries by user and status
	 *
	 * @param string $userId
	 * @param string $status
	 * @return TimeEntry[]
	 */
	public function findByUserAndStatus(string $userId, string $status): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)))
			->orderBy('start_time', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find time entries on break for a user
	 *
	 * @param string $userId
	 * @return TimeEntry|null
	 */
	public function findOnBreakByUser(string $userId): ?TimeEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_BREAK)))
			->orderBy('start_time', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Get total hours for a user in a date range
	 *
	 * @param string $userId
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @return float
	 */
	public function getTotalHoursByUserAndDateRange(string $userId, \DateTime $startDate, \DateTime $endDate): float
	{
		$entries = $this->findByUserAndDateRange($userId, $startDate, $endDate);
		$totalHours = 0.0;
		foreach ($entries as $entry) {
			if (in_array($entry->getStatus(), [TimeEntry::STATUS_COMPLETED, TimeEntry::STATUS_PENDING_APPROVAL])) {
				$totalHours += $entry->getWorkingDurationHours() ?? 0.0;
			}
		}
		return $totalHours;
	}

	/**
	 * Get total break hours for a user in a date range
	 *
	 * @param string $userId
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @return float
	 */
	public function getTotalBreakHoursByUserAndDateRange(string $userId, \DateTime $startDate, \DateTime $endDate): float
	{
		$entries = $this->findByUserAndDateRange($userId, $startDate, $endDate);
		$totalBreakHours = 0.0;
		foreach ($entries as $entry) {
			if (in_array($entry->getStatus(), [TimeEntry::STATUS_COMPLETED, TimeEntry::STATUS_PENDING_APPROVAL])) {
				$totalBreakHours += $entry->getBreakDurationHours();
			}
		}
		return $totalBreakHours;
	}

	/**
	 * Count time entries for a user
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
	 * Get time entries with project information (for integration with ProjectCheck)
	 *
	 * @param array $filters
	 * @return array
	 */
	public function getTimeEntriesWithProjectInfo(array $filters = []): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('te.*')
			->from($this->getTableName(), 'te')
			->leftJoin('te', 'projectcheck_projects', 'pcp', $qb->expr()->eq('te.project_check_project_id', 'pcp.id'))
			->addSelect('pcp.name as project_name')
			->addSelect('pcp.customer_id as customer_id');

		// Apply filters
		if (isset($filters['user_id'])) {
			$qb->andWhere($qb->expr()->eq('te.user_id', $qb->createNamedParameter($filters['user_id'])));
		}

		if (isset($filters['project_id'])) {
			$qb->andWhere($qb->expr()->eq('te.project_check_project_id', $qb->createNamedParameter($filters['project_id'])));
		}

		if (isset($filters['start_date'])) {
			$start = $filters['start_date'] instanceof \DateTime ? $filters['start_date']->format('Y-m-d H:i:s') : $filters['start_date'];
			$qb->andWhere($qb->expr()->gte('te.start_time', $qb->createNamedParameter($start, IQueryBuilder::PARAM_STR)));
		}

		if (isset($filters['end_date'])) {
			$end = $filters['end_date'] instanceof \DateTime ? $filters['end_date']->format('Y-m-d H:i:s') : $filters['end_date'];
			$qb->andWhere($qb->expr()->lt('te.start_time', $qb->createNamedParameter($end, IQueryBuilder::PARAM_STR)));
		}

		if (isset($filters['status'])) {
			$qb->andWhere($qb->expr()->eq('te.status', $qb->createNamedParameter($filters['status'])));
		}

		$qb->orderBy('te.start_time', 'DESC');

		if (isset($filters['limit'])) {
			$qb->setMaxResults((int)$filters['limit']);
		}

		if (isset($filters['offset'])) {
			$qb->setFirstResult((int)$filters['offset']);
		}

		return $qb->executeQuery()->fetchAll();
	}

	/**
	 * Count time entries with filters
	 *
	 * @param array $filters
	 * @return int
	 */
	public function count(array $filters = []): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName(), 'te');

		// Apply same filters as getTimeEntriesWithProjectInfo
		if (isset($filters['user_id'])) {
			$qb->andWhere($qb->expr()->eq('te.user_id', $qb->createNamedParameter($filters['user_id'])));
		}

		if (isset($filters['project_id'])) {
			$qb->andWhere($qb->expr()->eq('te.project_check_project_id', $qb->createNamedParameter($filters['project_id'])));
		}

		if (isset($filters['start_date'])) {
			$start = $filters['start_date'] instanceof \DateTime ? $filters['start_date']->format('Y-m-d H:i:s') : $filters['start_date'];
			$qb->andWhere($qb->expr()->gte('te.start_time', $qb->createNamedParameter($start, IQueryBuilder::PARAM_STR)));
		}

		if (isset($filters['end_date'])) {
			$end = $filters['end_date'] instanceof \DateTime ? $filters['end_date']->format('Y-m-d H:i:s') : $filters['end_date'];
			$qb->andWhere($qb->expr()->lt('te.start_time', $qb->createNamedParameter($end, IQueryBuilder::PARAM_STR)));
		}

		if (isset($filters['status'])) {
			$qb->andWhere($qb->expr()->eq('te.status', $qb->createNamedParameter($filters['status'])));
		}

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Get time entries pending approval
	 *
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return TimeEntry[]
	 */
	public function findPendingApproval(?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_PENDING_APPROVAL)))
			->orderBy('start_time', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Count distinct users with time entries on a specific date
	 *
	 * @param \DateTime $date
	 * @return int
	 */
	public function countDistinctUsersByDate(\DateTime $date): int
	{
		$startOfDay = clone $date;
		$startOfDay->setTime(0, 0, 0);
		$endOfDay = clone $date;
		$endOfDay->setTime(23, 59, 59);
		$endOfDay->modify('+1 day'); // Make exclusive

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(DISTINCT user_id)'))
			->from($this->getTableName())
			->where($qb->expr()->gte('start_time', $qb->createNamedParameter($startOfDay->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('start_time', $qb->createNamedParameter($endOfDay->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Get time entries for manager approval (team members)
	 *
	 * @param array $userIds Team member user IDs
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return TimeEntry[]
	 */
	public function findPendingApprovalForUsers(array $userIds, ?int $limit = null, ?int $offset = null): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_PENDING_APPROVAL)))
			->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->orderBy('start_time', 'DESC');

		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Find time entries that overlap with the given time range for a user
	 * Two entries overlap if they have any time in common
	 *
	 * @param string $userId
	 * @param \DateTime $startTime
	 * @param \DateTime $endTime
	 * @param int|null $excludeId Optional entry ID to exclude from results (useful for updates)
	 * @return TimeEntry[]
	 */
	public function findOverlapping(string $userId, \DateTime $startTime, \DateTime $endTime, ?int $excludeId = null): array
	{
		// Get all entries for the same day (or overlapping days) for this user
		$entryDateStart = clone $startTime;
		$entryDateStart->setTime(0, 0, 0);
		$entryDateEnd = clone $endTime;
		$entryDateEnd->setTime(23, 59, 59);
		
		// Extend date range to catch entries that might span across day boundaries
		$entryDateStart->modify('-1 day');
		$entryDateEnd->modify('+1 day');
		
		$allEntries = $this->findByUserAndDateRange($userId, $entryDateStart, $entryDateEnd);
		
		// Filter and check for overlaps
		$overlapping = [];
		$newStartTs = $startTime->getTimestamp();
		$newEndTs = $endTime->getTimestamp();
		
		foreach ($allEntries as $entry) {
			// Exclude the entry being updated if provided
			if ($excludeId !== null && $entry->getId() === $excludeId) {
				continue;
			}
			
			// Only check completed or pending entries with end times
			if (!in_array($entry->getStatus(), [TimeEntry::STATUS_COMPLETED, TimeEntry::STATUS_PENDING_APPROVAL])) {
				continue;
			}
			
			if (!$entry->getStartTime() || !$entry->getEndTime()) {
				continue;
			}
			
			$entryStartTs = $entry->getStartTime()->getTimestamp();
			$entryEndTs = $entry->getEndTime()->getTimestamp();
			
			// Entries overlap if: new_start < entry_end AND new_end > entry_start
			if ($newStartTs < $entryEndTs && $newEndTs > $entryStartTs) {
				$overlapping[] = $entry;
			}
		}

		return $overlapping;
	}

	/**
	 * Delete all time entries for a user (used on user deletion)
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