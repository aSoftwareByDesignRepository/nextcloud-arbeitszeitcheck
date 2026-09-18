<?php

declare(strict_types=1);

/**
 * TimeEntryMapper for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Db;

use OCA\ArbeitszeitCheck\Service\AppLocalNaiveDateTimeNormalizer;
use OCA\ArbeitszeitCheck\Support\QueryInChunker;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IConfig;

/**
 * TimeEntryMapper
 */
class TimeEntryMapper extends QBMapper
{
	private IConfig $config;

	/**
	 * TimeEntryMapper constructor
	 *
	 * @param IDBConnection $db
	 */
	public function __construct(IDBConnection $db, IConfig $config)
	{
		parent::__construct($db, 'at_entries', TimeEntry::class);
		$this->config = $config;
	}

	/**
	 * @param array<string, mixed> $row
	 * @psalm-return TimeEntry
	 */
	protected function mapRowToEntity(array $row): Entity
	{
		$entity = parent::mapRowToEntity($row);
		if (!$entity instanceof TimeEntry) {
			return $entity;
		}
		$this->reinterpretNaiveDatabaseTimestamps($entity);
		$entity->resetUpdatedFields();
		return $entity;
	}

	/**
	 * at_entries.* datetime columns are stored without timezone; values are wall clocks in
	 * {@see AppLocalNaiveDateTimeNormalizer::appStorageTimeZoneFromConfig()}.
	 */
	private function reinterpretNaiveDatabaseTimestamps(TimeEntry $entry): void
	{
		$tz = AppLocalNaiveDateTimeNormalizer::appStorageTimeZoneFromConfig($this->config);

		$entry->setStartTime(AppLocalNaiveDateTimeNormalizer::interpretSqlNaiveAsAppTimezone($entry->getStartTime(), $tz));

		if ($entry->getEndTime() !== null) {
			$entry->setEndTime(AppLocalNaiveDateTimeNormalizer::interpretSqlNaiveAsAppTimezone($entry->getEndTime(), $tz));
		}
		if ($entry->getBreakStartTime() !== null) {
			$entry->setBreakStartTime(AppLocalNaiveDateTimeNormalizer::interpretSqlNaiveAsAppTimezone($entry->getBreakStartTime(), $tz));
		}
		if ($entry->getBreakEndTime() !== null) {
			$entry->setBreakEndTime(AppLocalNaiveDateTimeNormalizer::interpretSqlNaiveAsAppTimezone($entry->getBreakEndTime(), $tz));
		}
		if ($entry->getCreatedAt() !== null) {
			$entry->setCreatedAt(AppLocalNaiveDateTimeNormalizer::interpretSqlNaiveAsAppTimezone($entry->getCreatedAt(), $tz));
		}
		if ($entry->getUpdatedAt() !== null) {
			$entry->setUpdatedAt(AppLocalNaiveDateTimeNormalizer::interpretSqlNaiveAsAppTimezone($entry->getUpdatedAt(), $tz));
		}
		if ($entry->getApprovedAt() !== null) {
			$entry->setApprovedAt(AppLocalNaiveDateTimeNormalizer::interpretSqlNaiveAsAppTimezone($entry->getApprovedAt(), $tz));
		}
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
	 * Whether the user has at least one time entry whose start_time falls in the given calendar month.
	 */
	public function userHasTimeEntryInCalendarMonth(string $userId, int $year, int $month): bool
	{
		$start = new \DateTime(sprintf('%04d-%02d-01', $year, $month));
		$start->setTime(0, 0, 0);
		$endExclusive = clone $start;
		$endExclusive->modify('first day of next month');

		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('start_time', $qb->createNamedParameter($start->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('start_time', $qb->createNamedParameter($endExclusive->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		return $qb->executeQuery()->fetchOne() !== false;
	}

	/**
	 * Distinct calendar months (from start_time) that have at least one time entry for this user.
	 * Returns YYYY-MM strings, newest first.
	 *
	 * @return list<string>
	 */
	public function findDistinctYearMonthStringsForUser(string $userId): array
	{
		$expr = $this->yearMonthFromStartTimeSql();
		$qb = $this->db->getQueryBuilder();
		$fn = $qb->createFunction($expr);
		$qb->selectAlias($fn, 'ym')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->groupBy($fn)
			->orderBy($fn, 'DESC');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll(\PDO::FETCH_COLUMN);
		if (!is_array($rows)) {
			return [];
		}
		$out = [];
		foreach ($rows as $row) {
			if ($row !== null && $row !== false && $row !== '') {
				$out[] = (string)$row;
			}
		}
		return $out;
	}

	private function yearMonthFromStartTimeSql(): string
	{
		return match ($this->db->getDatabaseProvider()) {
			IDBConnection::PLATFORM_POSTGRES => "TO_CHAR(start_time, 'YYYY-MM')",
			IDBConnection::PLATFORM_ORACLE => "TO_CHAR(start_time, 'YYYY-MM')",
			default => 'SUBSTR(start_time, 1, 7)',
		};
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
	 * Live punch status for many users in one query (admin/manager desklet summaries).
	 *
	 * Does not run getStatus side-effects. Priority when a user has multiple open
	 * rows: active, then break, then paused. Users with no open row are omitted
	 * (callers treat them as clocked_out).
	 *
	 * @param list<string> $userIds
	 * @return array<string, 'active'|'break'|'paused'>
	 */
	public function findLiveStatusByUserIds(array $userIds): array
	{
		$userIds = QueryInChunker::normalizeValues($userIds);
		if ($userIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id', 'status')
			->from($this->getTableName())
			->where(QueryInChunker::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($qb->expr()->in(
				'status',
				$qb->createNamedParameter(
					[
						TimeEntry::STATUS_ACTIVE,
						TimeEntry::STATUS_BREAK,
						TimeEntry::STATUS_PAUSED,
					],
					IQueryBuilder::PARAM_STR_ARRAY
				)
			));

		$rank = [
			TimeEntry::STATUS_ACTIVE => 3,
			TimeEntry::STATUS_BREAK => 2,
			TimeEntry::STATUS_PAUSED => 1,
		];
		$map = [];
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$uid = (string)($row['user_id'] ?? '');
			$status = (string)($row['status'] ?? '');
			if ($uid === '' || !isset($rank[$status])) {
				continue;
			}
			if (!isset($map[$uid]) || $rank[$status] > $rank[$map[$uid]]) {
				$map[$uid] = $status;
			}
		}
		$result->closeCursor();

		return $map;
	}

	/**
	 * Find paused or unfinished time entry for “today” for a user.
	 *
	 * Calendar-day bounds must match {@see \OCA\ArbeitszeitCheck\Constants::CONFIG_APP_TIMEZONE}
	 * (pass $dayStart / $dayEndExclusive from {@see TimeTrackingService}).
	 *
	 * @param \DateTime|null $dayStart Inclusive start of the local calendar day (00:00)
	 * @param \DateTime|null $dayEndExclusive Exclusive end (midnight next day)
	 */
	public function findPausedOrUnfinishedTodayByUser(string $userId, ?\DateTime $dayStart = null, ?\DateTime $dayEndExclusive = null): ?TimeEntry
	{
		if ($dayStart === null || $dayEndExclusive === null) {
			$tz = AppLocalNaiveDateTimeNormalizer::appStorageTimeZoneFromConfig($this->config);
			$today = new \DateTime('today', $tz);
			$tomorrow = (clone $today)->modify('+1 day');
		} else {
			$today = clone $dayStart;
			$tomorrow = clone $dayEndExclusive;
		}

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
	 * Pending correction of an unfinished session (no end_time).
	 * These rows must block a second clock-in until cancelled or decided.
	 */
	public function findPendingOpenSessionByUser(string $userId): ?TimeEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_PENDING_APPROVAL)))
			->andWhere($qb->expr()->isNull('end_time'))
			->orderBy('start_time', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Paused automatic entries without end_time whose session started before the given local-day boundary.
	 *
	 * Used to heal rows left by legacy clock-out bugs or timezone mismatches so they no longer block UX.
	 *
	 * @return TimeEntry[]
	 */
	public function findStalePausedAutomaticEntries(string $userId, \DateTimeInterface $localTodayStart): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_PAUSED)))
			->andWhere($qb->expr()->isNull('end_time'))
			->andWhere($qb->expr()->eq('is_manual_entry', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('start_time', $qb->createNamedParameter($localTodayStart->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->orderBy('start_time', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * All paused entries (auto + manual, with or without end_time) for a user.
	 *
	 * Used by {@see \OCA\ArbeitszeitCheck\Repair\RepairOrphanedPausedEntries} and the
	 * defense-in-depth healer in {@see \OCA\ArbeitszeitCheck\Service\TimeTrackingService}
	 * to close any leftover `paused` rows regardless of how they were created. Manual
	 * paused rows should never occur in the current code path, but we sweep them anyway
	 * so the user can never get stuck if a future regression slips one through.
	 *
	 * @return TimeEntry[]
	 */
	public function findAllPausedByUser(string $userId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_PAUSED)))
			->orderBy('start_time', 'ASC');

		return $this->findEntities($qb);
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
	 * Get total hours for a user in a date range (entries whose **start_time** falls in the window).
	 *
	 * @deprecated For ArbZG §3 daily limits use {@see \OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator}.
	 *             This method does not clip overnight spans at midnight and must not drive auto clock-out.
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
			// Only completed entries count toward worked hours. Pending four-eyes
			// manual creates / corrections must not inflate totals before approval.
			if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED) {
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
			if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED) {
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
			->leftJoin('te', 'pc_projects', 'pcp', $qb->expr()->eq('te.project_check_project_id', $qb->expr()->castColumn('pcp.id', IQueryBuilder::PARAM_STR)))
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

		$rows = $qb->executeQuery()->fetchAll();
		$tz = AppLocalNaiveDateTimeNormalizer::appStorageTimeZoneFromConfig($this->config);
		$out = [];
		foreach ($rows as $row) {
			if (!\is_array($row)) {
				continue;
			}
			$out[] = AppLocalNaiveDateTimeNormalizer::normalizeAtEntryDatetimeStringsInRow($row, $tz);
		}
		return $out;
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
	 * Whether the user has any time entries on the given date
	 *
	 * @param string $userId
	 * @param \DateTime $date
	 * @return bool
	 */
	public function hasEntriesOnDate(string $userId, \DateTime $date): bool
	{
		$startOfDay = clone $date;
		$startOfDay->setTime(0, 0, 0);
		$endOfDay = clone $date;
		$endOfDay->modify('+1 day');

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('1'))
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('start_time', $qb->createNamedParameter($startOfDay->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('start_time', $qb->createNamedParameter($endOfDay->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		return $qb->executeQuery()->fetchOne() !== false;
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
		// Exclusive upper bound: start of next calendar day (midnight).
		// Do NOT use setTime(23,59,59) then +1 day — that gives next-day 23:59:59, not midnight.
		$endOfDay = clone $startOfDay;
		$endOfDay->modify('+1 day');

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(DISTINCT user_id)'))
			->from($this->getTableName())
			->where($qb->expr()->gte('start_time', $qb->createNamedParameter($startOfDay->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('start_time', $qb->createNamedParameter($endOfDay->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)));

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * User IDs with at least one time entry on the given calendar day (server-local midnight window).
	 *
	 * @return list<string>
	 */
	public function findDistinctUserIdsByDate(\DateTime $date): array
	{
		$startOfDay = clone $date;
		$startOfDay->setTime(0, 0, 0);
		$endOfDay = clone $startOfDay;
		$endOfDay->modify('+1 day');

		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('user_id')
			->from($this->getTableName())
			->where($qb->expr()->gte('start_time', $qb->createNamedParameter($startOfDay->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('start_time', $qb->createNamedParameter($endOfDay->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->orderBy('user_id', 'ASC');

		$result = $qb->executeQuery();
		$userIds = [];
		while (($row = $result->fetch()) !== false) {
			$uid = (string)($row['user_id'] ?? '');
			if ($uid !== '') {
				$userIds[] = $uid;
			}
		}
		$result->closeCursor();

		return $userIds;
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
		if ($userIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_PENDING_APPROVAL)))
			->andWhere(QueryInChunker::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
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
	 * Find entries for a set of users in a date range (start inclusive, end exclusive).
	 *
	 * @param list<string> $userIds
	 * @param \DateTimeInterface $startDate
	 * @param \DateTimeInterface $endDateExclusive
	 * @param string|null $status
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return TimeEntry[]
	 */
	public function findByUsersAndDateRange(
		array $userIds,
		\DateTimeInterface $startDate,
		\DateTimeInterface $endDateExclusive,
		?string $status = null,
		?int $limit = null,
		?int $offset = null
	): array {
		if (empty($userIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where(QueryInChunker::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($qb->expr()->gte('start_time', $qb->createNamedParameter($startDate->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('start_time', $qb->createNamedParameter($endDateExclusive->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->orderBy('start_time', 'DESC')
			->addOrderBy('id', 'DESC');

		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR)));
		}
		if ($limit !== null) {
			$qb->setMaxResults($limit);
		}
		if ($offset !== null) {
			$qb->setFirstResult($offset);
		}

		return $this->findEntities($qb);
	}

	/**
	 * Count entries for a set of users in a date range (start inclusive, end exclusive).
	 *
	 * @param list<string> $userIds
	 */
	public function countByUsersAndDateRange(
		array $userIds,
		\DateTimeInterface $startDate,
		\DateTimeInterface $endDateExclusive,
		?string $status = null
	): int {
		if (empty($userIds)) {
			return 0;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where(QueryInChunker::in($qb, 'user_id', $userIds, IQueryBuilder::PARAM_STR_ARRAY))
			->andWhere($qb->expr()->gte('start_time', $qb->createNamedParameter($startDate->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->lt('start_time', $qb->createNamedParameter($endDateExclusive->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)));

		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status, IQueryBuilder::PARAM_STR)));
		}

		return (int)$qb->executeQuery()->fetchOne();
	}

	/**
	 * Find time entries that overlap with the given time range for a user
	 * Two entries overlap if they have any time in common.
	 *
	 * Active and on-break entries (which have a start_time but no end_time yet)
	 * are considered to extend up to "now" so manual entries cannot accidentally
	 * be inserted on top of an in-progress live session. Legacy paused entries
	 * are treated the same way using their last update timestamp as the implicit
	 * end. Pure draft rows without any start_time are skipped.
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

		// Active/break entries can also start outside the day-bucket window (e.g.
		// a session that started yesterday and is still running). Make sure they
		// are evaluated regardless of where their start_time falls.
		$active = $this->findActiveByUser($userId);
		$break = $this->findOnBreakByUser($userId);
		$pendingOpen = $this->findPendingOpenSessionByUser($userId);
		$seenIds = [];
		foreach ($allEntries as $entry) {
			$seenIds[(int)$entry->getId()] = true;
		}
		foreach ([$active, $break, $pendingOpen] as $live) {
			if ($live !== null && !isset($seenIds[(int)$live->getId()])) {
				$allEntries[] = $live;
				$seenIds[(int)$live->getId()] = true;
			}
		}

		$overlapping = [];
		$newStartTs = $startTime->getTimestamp();
		$newEndTs = $endTime->getTimestamp();
		$nowTs = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config)->getTimestamp();

		foreach ($allEntries as $entry) {
			// Exclude the entry being updated if provided
			if ($excludeId !== null && $entry->getId() === $excludeId) {
				continue;
			}

			$status = $entry->getStatus();
			$entryStart = $entry->getStartTime();

			// A row with no start time cannot overlap with anything.
			if (!$entryStart) {
				continue;
			}

			// Occupancy end: live sessions, paused rows, and pending
			// corrections of open (no end_time) sessions. See TimeEntry::occupancyEnd.
			$now = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
			$effectiveEnd = $entry->occupancyEnd($now);
			if ($effectiveEnd === null) {
				continue;
			}

			// Guard against malformed rows where an end is somehow before the start.
			$entryStartTs = $entryStart->getTimestamp();
			$entryEndTs = max($entryStartTs, $effectiveEnd->getTimestamp());

			// For live (active/break) sessions we never want to consider an end
			// in the past – clamp to now so a clock skew on an old row cannot
			// silently disable overlap detection.
			if (in_array($status, [TimeEntry::STATUS_ACTIVE, TimeEntry::STATUS_BREAK], true)) {
				$entryEndTs = max($entryEndTs, $nowTs);
			}

			// Entries overlap if: new_start < entry_end AND new_end > entry_start
			if ($newStartTs < $entryEndTs && $newEndTs > $entryStartTs) {
				$overlapping[] = $entry;
			}
		}

		return $overlapping;
	}

	/**
	 * Find the most-recent completed entry whose end_time is strictly before $beforeTime.
	 * Used for manual-entry rest-period validation; much cheaper than findByUser().
	 *
	 * @param string        $userId
	 * @param \DateTime     $beforeTime Only entries ending before this instant are considered.
	 * @param int|null      $excludeId  Optional entry ID to exclude (for update scenarios).
	 * @return TimeEntry|null
	 */
	public function findLastCompletedBeforeTime(string $userId, \DateTime $beforeTime, ?int $excludeId = null): ?TimeEntry
	{
		// Compare against storage-TZ wall digits — never PHP's default zone.
		// Using raw format() on a UTC DateTime would shift the bound by the
		// organisation offset and can pick the wrong "last" shift (classic
		// ArbZG §5 false positive / false negative near day boundaries).
		$tz = AppLocalNaiveDateTimeNormalizer::appStorageTimeZoneFromConfig($this->config);
		$beforeInStorage = clone $beforeTime;
		$beforeInStorage->setTimezone($tz);
		$beforeNaive = $beforeInStorage->format('Y-m-d H:i:s');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_COMPLETED)))
			->andWhere($qb->expr()->isNotNull('end_time'))
			->andWhere($qb->expr()->lt('end_time', $qb->createNamedParameter($beforeNaive, IQueryBuilder::PARAM_STR)))
			->orderBy('end_time', 'DESC')
			->setMaxResults(1);

		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find the most-recent completed time entry (with an end_time) for a user.
	 *
	 * Note: this includes rows whose end_time is still in the future (e.g. a
	 * manual "planned day" saved early). ArbZG §5 clock-in checks must use
	 * {@see findLastCompletedBeforeTime()} with storage "now" instead, so
	 * future-dated ends cannot block stamping.
	 *
	 * @param string $userId
	 * @return TimeEntry|null
	 */
	public function findLastCompletedByUser(string $userId): ?TimeEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_COMPLETED)))
			->andWhere($qb->expr()->isNotNull('end_time'))
			->orderBy('end_time', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Find the most-recent paused time entry whose updatedAt falls within $sinceHours of now.
	 * Used as a fallback rest-period reference when no completed entry exists recently.
	 *
	 * @param string    $userId
	 * @param int       $sinceHours Look-back window in hours (default 48 h).
	 * @return TimeEntry|null
	 */
	public function findLastPausedWithinHours(string $userId, int $sinceHours = 48): ?TimeEntry
	{
		$cutoff = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
		$cutoff->modify('-' . $sinceHours . ' hours');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(TimeEntry::STATUS_PAUSED)))
			->andWhere($qb->expr()->isNotNull('updated_at'))
			->andWhere($qb->expr()->gte('updated_at', $qb->createNamedParameter($cutoff->format('Y-m-d H:i:s'), IQueryBuilder::PARAM_STR)))
			->orderBy('updated_at', 'DESC')
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Persist a pending-approval decision only while the row is still pending.
	 *
	 * Same shape as {@see QBMapper::update()}, plus `AND status = pending_approval`.
	 * Returns false when another writer already left pending (0 rows) — callers must
	 * treat that as a lost concurrency race, never overwrite completed/rejected rows.
	 */
	public function updateIfPendingApproval(TimeEntry $entry): bool
	{
		$properties = $entry->getUpdatedFields();
		if (\count($properties) === 0) {
			return true;
		}

		$id = $entry->getId();
		if ($id === null) {
			throw new \InvalidArgumentException('Entity which should be updated has no id');
		}

		unset($properties['id']);

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName);

		foreach ($properties as $property => $_updated) {
			$column = $entry->propertyToColumn($property);
			$getter = 'get' . ucfirst($property);
			$value = $entry->$getter();
			$type = $this->getParameterTypeForProperty($entry, $property);
			$qb->set($column, $qb->createNamedParameter($value, $type));
		}

		$idType = $this->getParameterTypeForProperty($entry, 'id');
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, $idType)))
			->andWhere($qb->expr()->eq(
				'status',
				$qb->createNamedParameter(TimeEntry::STATUS_PENDING_APPROVAL, IQueryBuilder::PARAM_STR)
			));

		return $qb->executeStatement() === 1;
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