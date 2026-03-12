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
	private IUserManager $userManager;
	private IL10N $l10n;
	private HolidayCalendarService $holidayCalendarService;

	public function __construct(
		TimeEntryMapper $timeEntryMapper,
		AbsenceMapper $absenceMapper,
		ComplianceViolationMapper $violationMapper,
		OvertimeService $overtimeService,
		IUserManager $userManager,
		IL10N $l10n,
		HolidayCalendarService $holidayCalendarService
	) {
		$this->timeEntryMapper = $timeEntryMapper;
		$this->absenceMapper = $absenceMapper;
		$this->violationMapper = $violationMapper;
		$this->overtimeService = $overtimeService;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->holidayCalendarService = $holidayCalendarService;
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
	 * @param \DateTime $month Month to report (any day in the month)
	 * @param string|null $userId User ID (null for all users)
	 * @return array Report data
	 */
	public function generateMonthlyReport(\DateTime $month, ?string $userId = null): array
	{
		$start = new \DateTime($month->format('Y-m-01'));
		$start->setTime(0, 0, 0);
		$end = clone $start;
		$end->modify('last day of this month');
		$end->setTime(23, 59, 59);

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
				$overtimeData = $this->overtimeService->calculateMonthlyOvertime($userId);
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
			}
		} else {
			$userIds = [];
			$this->userManager->callForAllUsers(function ($user) use (&$userIds) {
				if ($user->isEnabled()) {
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
						'violations_count' => count($violations)
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
				$report['users'][] = [
					'user_id' => $userId,
					'display_name' => $this->getDisplayName($userId),
					'total_hours_worked' => $overtimeData['total_hours_worked'],
					'required_hours' => $overtimeData['required_hours'],
					'overtime_hours' => $overtimeData['overtime_hours'],
					'cumulative_balance' => $overtimeData['cumulative_balance_after']
				];

				if ($overtimeData['overtime_hours'] > 0) {
					$report['users_with_overtime'] = 1;
					$report['total_overtime'] = $overtimeData['overtime_hours'];
				} elseif ($overtimeData['overtime_hours'] < 0) {
					$report['users_with_undertime'] = 1;
					$report['total_undertime'] = abs($overtimeData['overtime_hours']);
				}
				$report['average_overtime'] = $overtimeData['overtime_hours'];
			}
		} else {
			$userIds = [];
			$this->userManager->callForAllUsers(function ($user) use (&$userIds) {
				if ($user->isEnabled()) {
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
				$overtimeHours = $overtimeData['overtime_hours'];

				$report['users'][] = [
					'user_id' => $uid,
					'display_name' => $this->getDisplayName($uid),
					'total_hours_worked' => $overtimeData['total_hours_worked'],
					'required_hours' => $overtimeData['required_hours'],
					'overtime_hours' => $overtimeHours,
					'cumulative_balance' => $overtimeData['cumulative_balance_after']
				];

				if ($overtimeHours > 0) {
					$report['users_with_overtime']++;
					$overtimeSum += $overtimeHours;
					$overtimeCount++;
				} elseif ($overtimeHours < 0) {
					$report['users_with_undertime']++;
					$undertimeSum += abs($overtimeHours);
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
	 * @return array Report data
	 */
	public function generateAbsenceReport(\DateTime $startDate, \DateTime $endDate, ?string $userId = null): array
	{
		$absences = $userId
			? $this->absenceMapper->findByUserAndDateRange($userId, $startDate, $endDate)
			: $this->absenceMapper->findByDateRange($startDate, $endDate);

		$report = [
			'type' => 'absence',
			'period' => [
				'start' => $startDate->format('Y-m-d'),
				'end' => $endDate->format('Y-m-d')
			],
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

			$startDate = $absence->getStartDate();
			$endDate = $absence->getEndDate();
			$userAbsences[$uid]['absences'][] = [
				'id' => $absence->getId(),
				'type' => $type,
				'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
				'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
				'days' => $absence->getDays(),
				'status' => $status
			];

			$userAbsences[$uid]['total_days'] += $absence->getDays();
			$totalDays += $absence->getDays();
		}

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

			$report['members'][] = [
				'user_id' => $uid,
				'display_name' => $this->getDisplayName($uid),
				'total_hours' => round($overtimeData['total_hours_worked'], 2),
				'required_hours' => $overtimeData['required_hours'],
				'overtime_hours' => round($overtimeData['overtime_hours'], 2),
				'break_hours' => round($totalBreakHours, 2),
				'violations_count' => count($violations),
				'absence_days' => $absenceDays,
				'entries_count' => count($entries)
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
