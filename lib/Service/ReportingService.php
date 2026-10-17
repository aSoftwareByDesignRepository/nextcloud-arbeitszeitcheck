<?php

declare(strict_types=1);

/**
 * Reporting service for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Support\WorkLocationDayCounter;
use OCP\IUserManager;
use OCP\IL10N;

/**
 * ReportingService for generating various types of reports
 */
class ReportingService
{
	private TimeEntryMapper $timeEntryMapper;
	private AbsenceMapper $absenceMapper;
	private ComplianceViolationMapper $violationMapper;
	private OvertimeService $overtimeService;
	private OvertimeBankService $overtimeBankService;
	private IUserManager $userManager;
	private IL10N $l10n;
	private HolidayService $holidayCalendarService;
	private PermissionService $permissionService;
	private TimeZoneService $timeZoneService;
	private WorkLocationReportAggregator $workLocationAggregator;

	public function __construct(
		TimeEntryMapper $timeEntryMapper,
		AbsenceMapper $absenceMapper,
		ComplianceViolationMapper $violationMapper,
		OvertimeService $overtimeService,
		OvertimeBankService $overtimeBankService,
		IUserManager $userManager,
		IL10N $l10n,
		HolidayService $holidayCalendarService,
		PermissionService $permissionService,
		?TimeZoneService $timeZoneService = null,
		?WorkLocationReportAggregator $workLocationAggregator = null
	) {
		$this->timeEntryMapper = $timeEntryMapper;
		$this->absenceMapper = $absenceMapper;
		$this->violationMapper = $violationMapper;
		$this->overtimeService = $overtimeService;
		$this->overtimeBankService = $overtimeBankService;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->holidayCalendarService = $holidayCalendarService;
		$this->permissionService = $permissionService;
		$this->timeZoneService = $timeZoneService ?? \OCP\Server::get(TimeZoneService::class);
		$this->workLocationAggregator = $workLocationAggregator ?? \OCP\Server::get(WorkLocationReportAggregator::class);
	}

	/**
	 * Machine-stable work-location report structure (spec §9.10):
	 * `workLocation => {byCode, byCategory, mixedDays}`. Live view — open
	 * entries count through "now"; finalized closures only pass completed
	 * rows upstream.
	 *
	 * @param iterable<TimeEntry> $entries
	 * @param \DateTimeInterface|null $openAsOf `now` for live views;
	 *        `null` excludes still-open entries (final closures).
	 * @return array{byCode: array, byCategory: array, mixedDays: int}
	 */
	public function workLocationReport(iterable $entries, ?\DateTimeInterface $openAsOf = null): array
	{
		return $this->workLocationAggregator->aggregate(
			$entries,
			$this->timeZoneService->storageTimeZone(),
			$openAsOf
		);
	}

	/**
	 * Generate daily summary report
	 *
	 * @param \DateTime $date Date for the report
	 * @param string|null $userId User ID (null for all users)
	 * @return array Report data
	 */
	public function generateDailyReport(\DateTime $date, ?string $userId = null): array
	{
		$start = clone $date;
		$start->setTime(0, 0, 0);
		$end = clone $date;
		$end->setTime(23, 59, 59);

		$report = [
			'type' => 'daily',
			'date' => $date->format('Y-m-d'),
			'period' => [
				'start' => $start->format('Y-m-d H:i:s'),
				'end' => $end->format('Y-m-d H:i:s')
			],
			'total_users' => 0,
			'active_users' => 0,
			'total_hours' => 0.0,
			'total_break_hours' => 0.0,
			'average_hours_per_user' => 0.0,
			'total_overtime' => 0.0,
			'violations_count' => 0,
			'users' => []
		];

		if ($userId) {
			$user = $this->userManager->get($userId);
			if ($user) {
				$userReport = $this->generateUserDailyReport($userId, $date);
				$report['users'][] = $userReport;
				$report['total_users'] = 1;
				$report['active_users'] = $userReport['has_entries'] ? 1 : 0;
				$report['total_hours'] = $userReport['total_hours'];
				$report['total_break_hours'] = $userReport['break_hours'];
				$report['total_overtime'] = $userReport['overtime_hours'];
				$report['violations_count'] = $userReport['violations_count'];
				$report['average_hours_per_user'] = $userReport['total_hours'];
			}
		} else {
			// Generate report for all users
			$userIds = [];
			$this->userManager->callForAllUsers(function ($user) use (&$userIds) {
				if ($user->isEnabled()) {
					if (!$this->permissionService->isUserAllowedByAccessGroups($user->getUID())) {
						return;
					}
					$userIds[] = $user->getUID();
				}
			});

			$report['total_users'] = count($userIds);
			$activeCount = 0;
			$totalHours = 0.0;
			$totalBreakHours = 0.0;
			$totalOvertime = 0.0;
			$totalViolations = 0;

			foreach ($userIds as $uid) {
				$userReport = $this->generateUserDailyReport($uid, $date);
				$report['users'][] = $userReport;

				if ($userReport['has_entries']) {
					$activeCount++;
					$totalHours += $userReport['total_hours'];
					$totalBreakHours += $userReport['break_hours'];
					$totalOvertime += $userReport['overtime_hours'];
					$totalViolations += $userReport['violations_count'];
				}
			}

			$report['active_users'] = $activeCount;
			$report['total_hours'] = round($totalHours, 2);
			$report['total_break_hours'] = round($totalBreakHours, 2);
			$report['total_overtime'] = round($totalOvertime, 2);
			$report['violations_count'] = $totalViolations;
			$report['average_hours_per_user'] = $activeCount > 0 ? round($totalHours / $activeCount, 2) : 0.0;
		}

		return $report;
	}

	/**
	 * Generate weekly summary report
	 *
	 * @param \DateTime $weekStart Start of week
	 * @param string|null $userId User ID (null for all users)
	 * @return array Report data
	 */
	public function generateWeeklyReport(\DateTime $weekStart, ?string $userId = null): array
	{
		$start = clone $weekStart;
		$start->setTime(0, 0, 0);
		$end = clone $weekStart;
		$end->modify('+6 days');
		$end->setTime(23, 59, 59);

		$report = [
			'type' => 'weekly',
			'week_start' => $start->format('Y-m-d'),
			'week_end' => $end->format('Y-m-d'),
			'period' => [
				'start' => $start->format('Y-m-d H:i:s'),
				'end' => $end->format('Y-m-d H:i:s')
			],
			'total_users' => 0,
			'active_users' => 0,
			'total_hours' => 0.0,
			'total_break_hours' => 0.0,
			'average_hours_per_user' => 0.0,
			'total_overtime' => 0.0,
			'violations_count' => 0,
			'daily_breakdown' => [],
			'users' => []
		];

		// Generate daily breakdown
		$current = clone $start;
		while ($current <= $end) {
			$dayReport = $this->generateDailyReport($current, $userId);
			$report['daily_breakdown'][$current->format('Y-m-d')] = [
				'date' => $current->format('Y-m-d'),
				'day_name' => $current->format('l'),
				'total_hours' => $dayReport['total_hours'],
				'active_users' => $dayReport['active_users'],
				'violations' => $dayReport['violations_count']
			];
			$current->modify('+1 day');
		}

		// Aggregate totals
		$report['total_hours'] = array_sum(array_column($report['daily_breakdown'], 'total_hours'));
		$report['violations_count'] = array_sum(array_column($report['daily_breakdown'], 'violations'));
		$report['active_users'] = max(array_column($report['daily_breakdown'], 'active_users'));

		if ($userId) {
			$user = $this->userManager->get($userId);
			if ($user) {
				$overtimeData = $this->overtimeService->getWeeklyOvertime($userId, $weekStart);
				$entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $start, $end);
				$violations = $this->violationMapper->findByDateRange($start, $end, $userId);

				$totalBreakHours = 0.0;
				foreach ($entries as $entry) {
					if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED) {
						$totalBreakHours += $entry->getBreakDurationHours();
					}
				}

				$report['total_users'] = 1;
				$report['total_hours'] = $overtimeData['total_hours_worked'];
				$report['total_break_hours'] = round($totalBreakHours, 2);
				$report['total_overtime'] = $overtimeData['overtime_hours'];
				$report['violations_count'] = count($violations);
				$report['average_hours_per_user'] = $overtimeData['total_hours_worked'];
			}
		} else {
			$userIds = [];
			$this->userManager->callForAllUsers(function ($user) use (&$userIds) {
				if ($user->isEnabled()) {
					if (!$this->permissionService->isUserAllowedByAccessGroups($user->getUID())) {
						return;
					}
					$userIds[] = $user->getUID();
				}
			});

			$report['total_users'] = count($userIds);
			$activeCount = 0;
			$totalHours = 0.0;
			$totalBreakHours = 0.0;
			$totalOvertime = 0.0;

			foreach ($userIds as $uid) {
				$overtimeData = $this->overtimeService->getWeeklyOvertime($uid, $weekStart);
				$entries = $this->timeEntryMapper->findByUserAndDateRange($uid, $start, $end);

				if (!empty($entries)) {
					$activeCount++;
					$totalHours += $overtimeData['total_hours_worked'];
					$totalOvertime += $overtimeData['overtime_hours'];

					foreach ($entries as $entry) {
						if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED) {
							$totalBreakHours += $entry->getBreakDurationHours();
						}
					}
				}
			}

			$report['active_users'] = $activeCount;
			$report['total_hours'] = round($totalHours, 2);
			$report['total_break_hours'] = round($totalBreakHours, 2);
			$report['total_overtime'] = round($totalOvertime, 2);
			$report['average_hours_per_user'] = $activeCount > 0 ? round($totalHours / $activeCount, 2) : 0.0;
		}

		return $report;
	}

	/**
	 * Generate monthly summary report
	 *
	 * @param \DateTime $month Month to report (any day in the month); used when no period override is given
	 * @param string|null $userId User ID (null for all users)
	 * @param \DateTime|null $periodStartOverride If set with $periodEndOverride, use this inclusive date range instead of the full calendar month
	 * @param \DateTime|null $periodEndOverride
	 * @return array Report data
	 */
	public function generateMonthlyReport(\DateTime $month, ?string $userId = null, ?\DateTime $periodStartOverride = null, ?\DateTime $periodEndOverride = null): array
	{
		if ($periodStartOverride !== null && $periodEndOverride !== null) {
			$start = clone $periodStartOverride;
			$start->setTime(0, 0, 0);
			$end = clone $periodEndOverride;
			$end->setTime(23, 59, 59);
		} else {
			$start = new \DateTime($month->format('Y-m-01'));
			$start->setTime(0, 0, 0);
			$end = clone $start;
			$end->modify('last day of this month');
			$end->setTime(23, 59, 59);
		}

		$report = [
			'type' => 'monthly',
			'month' => $start->format('Y-m'),
			'month_name' => $start->format('F Y'),
			'period' => [
				'start' => $start->format('Y-m-d'),
				'end' => $end->format('Y-m-d')
			],
			'total_users' => 0,
			'active_users' => 0,
			'total_hours' => 0.0,
			'total_break_hours' => 0.0,
			'average_hours_per_user' => 0.0,
			'total_overtime' => 0.0,
			'violations_count' => 0,
			'working_days' => $this->countWorkingDays($start, $end),
			'holiday_summary' => [
				'holiday_days' => 0,
				'holiday_work_hours' => 0.0,
			],
			'users' => []
		];

		if ($userId) {
			$user = $this->userManager->get($userId);
			if ($user) {
				$overtimeData = $this->overtimeService->calculateOvertime($userId, $start, $end);
				$entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $start, $end);
				$violations = $this->violationMapper->findByDateRange($start, $end, $userId);

				$totalBreakHours = 0.0;
				foreach ($entries as $entry) {
					if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED) {
						$totalBreakHours += $entry->getBreakDurationHours();
					}
				}

				$holidaySummary = $this->calculateHolidaySummaryForUser($userId, $start, $end, $entries);

				$report['total_users'] = 1;
				$report['active_users'] = !empty($entries) ? 1 : 0;
				$report['total_hours'] = $overtimeData['total_hours_worked'];
				$report['total_break_hours'] = round($totalBreakHours, 2);
				$report['total_overtime'] = $overtimeData['overtime_hours'];
				$report['violations_count'] = count($violations);
				$report['average_hours_per_user'] = $overtimeData['total_hours_worked'];
				$report['holiday_summary'] = $holidaySummary;
				// GH #43: distinct days with ≥1 entry at that location (mixed days
				// count under both — org timezone, same frame as the period bounds).
				$report['work_location_days'] = WorkLocationDayCounter::countDays(
					$entries,
					$this->timeZoneService->storageTimeZone(),
					new \DateTimeImmutable('now')
				);
				$report['workLocation'] = $this->workLocationReport($entries, new \DateTimeImmutable('now'));
			}
		} else {
			$userIds = [];
			$this->userManager->callForAllUsers(function ($user) use (&$userIds) {
				if ($user->isEnabled()) {
					if (!$this->permissionService->isUserAllowedByAccessGroups($user->getUID())) {
						return;
					}
					$userIds[] = $user->getUID();
				}
			});

			$report['total_users'] = count($userIds);
			$activeCount = 0;
			$totalHours = 0.0;
			$totalBreakHours = 0.0;
			$totalOvertime = 0.0;
			$totalViolations = 0;

			$totalHolidayDays = 0;
			$totalHolidayWorkHours = 0.0;

			foreach ($userIds as $uid) {
				$overtimeData = $this->overtimeService->calculateOvertime($uid, $start, $end);
				$entries = $this->timeEntryMapper->findByUserAndDateRange($uid, $start, $end);
				$violations = $this->violationMapper->findByDateRange($start, $end, $uid);

				if (!empty($entries)) {
					$activeCount++;
					$totalHours += $overtimeData['total_hours_worked'];
					$totalOvertime += $overtimeData['overtime_hours'];
					$totalViolations += count($violations);
					$holidaySummary = $this->calculateHolidaySummaryForUser($uid, $start, $end, $entries);
					$totalHolidayDays += $holidaySummary['holiday_days'];
					$totalHolidayWorkHours += $holidaySummary['holiday_work_hours'];

					foreach ($entries as $entry) {
						if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED) {
							$totalBreakHours += $entry->getBreakDurationHours();
						}
					}

					$report['users'][] = [
						'user_id' => $uid,
						'display_name' => $this->getDisplayName($uid),
						'total_hours' => round($overtimeData['total_hours_worked'], 2),
						'overtime_hours' => round($overtimeData['overtime_hours'], 2),
						'violations_count' => count($violations),
						'work_location_days' => WorkLocationDayCounter::countDays(
							$entries,
							$this->timeZoneService->storageTimeZone(),
							new \DateTimeImmutable('now')
						),
						'workLocation' => $this->workLocationReport($entries, new \DateTimeImmutable('now')),
					];
				}
			}

			$report['active_users'] = $activeCount;
			$report['total_hours'] = round($totalHours, 2);
			$report['total_break_hours'] = round($totalBreakHours, 2);
			$report['total_overtime'] = round($totalOvertime, 2);
			$report['violations_count'] = $totalViolations;
			$report['average_hours_per_user'] = $activeCount > 0 ? round($totalHours / $activeCount, 2) : 0.0;
			$report['holiday_summary'] = [
				'holiday_days' => $totalHolidayDays,
				'holiday_work_hours' => round($totalHolidayWorkHours, 2),
			];
		}

		return $report;
	}

	/**
	 * Generate overtime report
	 *
	 * @param \DateTime $startDate Start date
	 * @param \DateTime $endDate End date
	 * @param string|null $userId User ID (null for all users)
	 * @return array Report data
	 */
	public function generateOvertimeReport(\DateTime $startDate, \DateTime $endDate, ?string $userId = null): array
	{
		$report = [
			'type' => 'overtime',
			'period' => [
				'start' => $startDate->format('Y-m-d'),
				'end' => $endDate->format('Y-m-d')
			],
			'bank_enabled' => $this->overtimeBankService->isEnabled(),
			'total_users' => 0,
			'users_with_overtime' => 0,
			'users_with_undertime' => 0,
			'total_overtime' => 0.0,
			'total_undertime' => 0.0,
			'average_overtime' => 0.0,
			'users' => []
		];

		if ($userId) {
			$user = $this->userManager->get($userId);
			if ($user) {
				$overtimeData = $this->overtimeService->calculateOvertime($userId, $startDate, $endDate);
				$report['total_users'] = 1;
				$userRow = $this->buildOvertimeReportUserRow($userId, $overtimeData);
				$report['users'][] = $userRow;

				if ($userRow['period_overtime_hours'] > 0) {
					$report['users_with_overtime'] = 1;
					$report['total_overtime'] = $userRow['period_overtime_hours'];
				} elseif ($userRow['period_undertime_hours'] > 0) {
					$report['users_with_undertime'] = 1;
					$report['total_undertime'] = $userRow['period_undertime_hours'];
				}
				$report['average_overtime'] = $userRow['overtime_hours'];
			}
		} else {
			$userIds = [];
			$this->userManager->callForAllUsers(function ($user) use (&$userIds) {
				if ($user->isEnabled()) {
					if (!$this->permissionService->isUserAllowedByAccessGroups($user->getUID())) {
						return;
					}
					$userIds[] = $user->getUID();
				}
			});

			$report['total_users'] = count($userIds);
			$overtimeSum = 0.0;
			$overtimeCount = 0;
			$undertimeSum = 0.0;
			$undertimeCount = 0;

			foreach ($userIds as $uid) {
				$overtimeData = $this->overtimeService->calculateOvertime($uid, $startDate, $endDate);
				$userRow = $this->buildOvertimeReportUserRow($uid, $overtimeData);
				$report['users'][] = $userRow;

				if ($userRow['period_overtime_hours'] > 0) {
					$report['users_with_overtime']++;
					$overtimeSum += $userRow['period_overtime_hours'];
					$overtimeCount++;
				} elseif ($userRow['period_undertime_hours'] > 0) {
					$report['users_with_undertime']++;
					$undertimeSum += $userRow['period_undertime_hours'];
					$undertimeCount++;
				}
			}

			$report['total_overtime'] = round($overtimeSum, 2);
			$report['total_undertime'] = round($undertimeSum, 2);
			$report['average_overtime'] = $overtimeCount > 0 ? round($overtimeSum / $overtimeCount, 2) : 0.0;
		}

		return $report;
	}

	/**
	 * Generate absence report
	 *
	 * @param \DateTime $startDate Start date
	 * @param \DateTime $endDate End date
	 * @param string|null $userId User ID (null for all users)
	 * @param string $sort Row order inside each user block: `date` (default, chronological)
	 *                    or `type` (absence type, then start date) for payroll handoff.
	 * @return array Report data
	 */
	public function generateAbsenceReport(
		\DateTime $startDate,
		\DateTime $endDate,
		?string $userId = null,
		string $sort = 'date'
	): array {
		$absences = $userId
			? $this->absenceMapper->findByUserAndDateRange($userId, $startDate, $endDate)
			: $this->absenceMapper->findByDateRange($startDate, $endDate);

		$sortMode = strtolower(trim($sort));
		if ($sortMode !== 'type') {
			$sortMode = 'date';
		}

		$report = [
			'type' => 'absence',
			'period' => [
				'start' => $startDate->format('Y-m-d'),
				'end' => $endDate->format('Y-m-d')
			],
			'sort' => $sortMode,
			'total_absences' => count($absences),
			'absences_by_type' => [],
			'absences_by_status' => [],
			'total_days' => 0,
			'users' => []
		];

		$userAbsences = [];
		$totalDays = 0;

		foreach ($absences as $absence) {
			$type = $absence->getType();
			$status = $absence->getStatus();
			$uid = $absence->getUserId();

			// Group by type
			if (!isset($report['absences_by_type'][$type])) {
				$report['absences_by_type'][$type] = 0;
			}
			$report['absences_by_type'][$type]++;

			// Group by status
			if (!isset($report['absences_by_status'][$status])) {
				$report['absences_by_status'][$status] = 0;
			}
			$report['absences_by_status'][$status]++;

			// Group by user
			if (!isset($userAbsences[$uid])) {
				$userAbsences[$uid] = [
					'user_id' => $uid,
					'display_name' => $this->getDisplayName($uid),
					'absences' => [],
					'total_days' => 0
				];
			}

			$startDateRow = $absence->getStartDate();
			$endDateRow = $absence->getEndDate();
			// Use stored days; fallback to HolidayService (state-aware) for legacy null
			if ($absence->getDays() !== null) {
				$days = (float)$absence->getDays();
			} elseif ($startDateRow && $endDateRow) {
				$days = $this->holidayCalendarService->computeWorkingDaysForUser($uid, $startDateRow, $endDateRow);
			} else {
				$days = 0.0;
			}

			$userAbsences[$uid]['absences'][] = [
				'id' => $absence->getId(),
				'type' => $type,
				'start_date' => $startDateRow ? $startDateRow->format('Y-m-d') : null,
				'end_date' => $endDateRow ? $endDateRow->format('Y-m-d') : null,
				'days' => $days,
				'status' => $status
			];

			$userAbsences[$uid]['total_days'] += $days;
			$totalDays += $days;
		}

		foreach ($userAbsences as &$userBlock) {
			usort($userBlock['absences'], static function (array $a, array $b) use ($sortMode): int {
				if ($sortMode === 'type') {
					$typeCmp = strcasecmp((string)($a['type'] ?? ''), (string)($b['type'] ?? ''));
					if ($typeCmp !== 0) {
						return $typeCmp;
					}
				}
				$startCmp = strcmp((string)($a['start_date'] ?? ''), (string)($b['start_date'] ?? ''));
				if ($startCmp !== 0) {
					return $startCmp;
				}
				return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
			});
		}
		unset($userBlock);

		// Summary maps stay alphabetically keyed for stable JSON (counts only).
		ksort($report['absences_by_type'], SORT_STRING);
		ksort($report['absences_by_status'], SORT_STRING);

		$report['users'] = array_values($userAbsences);
		$report['total_days'] = $totalDays;

		return $report;
	}

	/**
	 * Generate team report
	 *
	 * @param array $userIds Array of user IDs (team members)
	 * @param \DateTime $startDate Start date
	 * @param \DateTime $endDate End date
	 * @return array Report data
	 */
	public function generateTeamReport(array $userIds, \DateTime $startDate, \DateTime $endDate): array
	{
		$report = [
			'type' => 'team',
			'period' => [
				'start' => $startDate->format('Y-m-d'),
				'end' => $endDate->format('Y-m-d')
			],
			'team_size' => count($userIds),
			'active_members' => 0,
			'total_hours' => 0.0,
			'total_overtime' => 0.0,
			'total_violations' => 0,
			'average_hours_per_member' => 0.0,
			'members' => []
		];

		$activeCount = 0;
		$totalHours = 0.0;
		$totalOvertime = 0.0;
		$totalViolations = 0;

		foreach ($userIds as $uid) {
			$user = $this->userManager->get($uid);
			if (!$user || !$user->isEnabled()) {
				continue;
			}

			$overtimeData = $this->overtimeService->calculateOvertime($uid, $startDate, $endDate);
			$entries = $this->timeEntryMapper->findByUserAndDateRange($uid, $startDate, $endDate);
			$violations = $this->violationMapper->findByDateRange($startDate, $endDate, $uid);
			$absences = $this->absenceMapper->findByUserAndDateRange($uid, $startDate, $endDate);

			$totalBreakHours = 0.0;
			foreach ($entries as $entry) {
				if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED) {
					$totalBreakHours += $entry->getBreakDurationHours();
				}
			}

			$absenceDays = 0;
			foreach ($absences as $absence) {
				if ($absence->getStatus() === 'approved') {
					$absenceDays += $absence->getDays();
				}
			}

			if (!empty($entries)) {
				$activeCount++;
				$totalHours += $overtimeData['total_hours_worked'];
				$totalOvertime += $overtimeData['overtime_hours'];
				$totalViolations += count($violations);
			}

			$workLocationDays = WorkLocationDayCounter::countDays(
				$entries,
				$this->timeZoneService->storageTimeZone(),
				new \DateTimeImmutable('now')
			);
			$report['members'][] = [
				'user_id' => $uid,
				'display_name' => $this->getDisplayName($uid),
				'total_hours' => round($overtimeData['total_hours_worked'], 2),
				'required_hours' => $overtimeData['required_hours'],
				'overtime_hours' => round($overtimeData['overtime_hours'], 2),
				'break_hours' => round($totalBreakHours, 2),
				'violations_count' => count($violations),
				'absence_days' => $absenceDays,
				'entries_count' => count($entries),
				'home_office_days' => $workLocationDays['home'],
				'external_appointment_days' => $workLocationDays['external'],
				'workLocation' => $this->workLocationReport($entries, new \DateTimeImmutable('now')),
			];
		}

		$report['active_members'] = $activeCount;
		$report['total_hours'] = round($totalHours, 2);
		$report['total_overtime'] = round($totalOvertime, 2);
		$report['total_violations'] = $totalViolations;
		$report['average_hours_per_member'] = $activeCount > 0 ? round($totalHours / $activeCount, 2) : 0.0;

		return $report;
	}

	/**
	 * Generate user daily report
	 *
	 * @param string $userId User ID
	 * @param \DateTime $date Date
	 * @return array User report data
	 */
	private function generateUserDailyReport(string $userId, \DateTime $date): array
	{
		$start = clone $date;
		$start->setTime(0, 0, 0);
		$end = clone $date;
		$end->setTime(23, 59, 59);

		$entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $start, $end);
		$overtimeData = $this->overtimeService->getDailyOvertime($userId, $date);
		$violations = $this->violationMapper->findByDateRange($start, $end, $userId);

		$totalHours = 0.0;
		$breakHours = 0.0;
		foreach ($entries as $entry) {
			if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED) {
				$totalHours += $entry->getWorkingDurationHours();
				$breakHours += $entry->getBreakDurationHours();
			}
		}

		return [
			'user_id' => $userId,
			'display_name' => $this->getDisplayName($userId),
			'date' => $date->format('Y-m-d'),
			'has_entries' => !empty($entries),
			'total_hours' => round($totalHours, 2),
			'break_hours' => round($breakHours, 2),
			'overtime_hours' => $overtimeData['overtime_hours'],
			'violations_count' => count($violations),
			'entries_count' => count($entries)
		];
	}

	/**
	 * Count working days between two dates (excluding weekends)
	 *
	 * @param \DateTime $start
	 * @param \DateTime $end
	 * @return int
	 */
	private function countWorkingDays(\DateTime $start, \DateTime $end): int
	{
		$workingDays = 0;
		$current = clone $start;

		while ($current <= $end) {
			$dayOfWeek = (int)$current->format('w'); // 0 = Sunday, 6 = Saturday
			if ($dayOfWeek !== 0 && $dayOfWeek !== 6) {
				$workingDays++;
			}
			$current->modify('+1 day');
		}

		return $workingDays;
	}

	/**
	 * Calculate holiday days and hours worked on holidays for a given user and period.
	 *
	 * @param string $userId
	 * @param \DateTime $start
	 * @param \DateTime $end
	 * @param TimeEntry[] $entries
	 * @return array{holiday_days:int,holiday_work_hours:float}
	 */
	private function calculateHolidaySummaryForUser(string $userId, \DateTime $start, \DateTime $end, array $entries): array
	{
		$holidayDays = 0;
		$holidayWorkHours = 0.0;

		$cursor = (clone $start)->setTime(0, 0, 0);
		$endDay = (clone $end)->setTime(0, 0, 0);

		while ($cursor <= $endDay) {
			if ($this->holidayCalendarService->isHolidayForUser($userId, $cursor)) {
				$holidayDays++;
			}
			$cursor->modify('+1 day');
		}

		foreach ($entries as $entry) {
			if ($entry->getStatus() !== TimeEntry::STATUS_COMPLETED || $entry->getEndTime() === null) {
				continue;
			}
			$startTime = $entry->getStartTime();
			if ($startTime === null) {
				continue;
			}
			$entryDate = (clone $startTime)->setTime(0, 0, 0);
			if ($this->holidayCalendarService->isHolidayForUser($userId, $entryDate)) {
				$hours = $entry->getWorkingDurationHours();
				if ($hours !== null) {
					$holidayWorkHours += $hours;
				}
			}
		}

		return [
			'holiday_days' => $holidayDays,
			'holiday_work_hours' => $holidayWorkHours,
		];
	}

	/**
	 * @param array<string, mixed> $overtimeData
	 * @return array<string, mixed>
	 */
	private function buildOvertimeReportUserRow(string $userId, array $overtimeData): array
	{
		$periodHours = (float)($overtimeData['overtime_hours'] ?? 0.0);
		$bank = $this->overtimeBankService->getBankStatus($userId);

		return [
			'user_id' => $userId,
			'display_name' => $this->getDisplayName($userId),
			'total_hours_worked' => round((float)($overtimeData['total_hours_worked'] ?? 0), 2),
			'required_hours' => round((float)($overtimeData['required_hours'] ?? 0), 2),
			'overtime_hours' => round($periodHours, 2),
			'period_overtime_hours' => round(max(0.0, $periodHours), 2),
			'period_undertime_hours' => round(max(0.0, -$periodHours), 2),
			'cumulative_balance' => round((float)($overtimeData['cumulative_balance_after'] ?? 0), 2),
			'bank_enabled' => (bool)($bank['enabled'] ?? false),
			'effective_balance' => round(
				$bank['enabled']
					? (float)($bank['effective_balance'] ?? 0)
					: (float)($overtimeData['cumulative_balance_after'] ?? 0),
				2
			),
			'banked_hours' => $bank['enabled'] ? round((float)($bank['banked_hours'] ?? 0), 2) : null,
			'payout_eligible_hours' => $bank['enabled'] ? round((float)($bank['payout_eligible_hours'] ?? 0), 2) : null,
		];
	}

	/**
	 * Get display name for a user
	 *
	 * @param string $userId
	 * @return string
	 */
	private function getDisplayName(string $userId): string
	{
		$user = $this->userManager->get($userId);
		return $user ? $user->getDisplayName() : $userId;
	}
}
