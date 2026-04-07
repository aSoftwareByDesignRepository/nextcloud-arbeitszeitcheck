<?php

declare(strict_types=1);

/**
 * Overtime service for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCP\IL10N;

/**
 * OvertimeService for calculating overtime based on working time models
 */
class OvertimeService
{
	private TimeEntryMapper $timeEntryMapper;
	private WorkingTimeModelMapper $workingTimeModelMapper;
	private UserWorkingTimeModelMapper $userWorkingTimeModelMapper;
	private IL10N $l10n;
	private HolidayService $holidayCalendarService;

	public function __construct(
		TimeEntryMapper $timeEntryMapper,
		WorkingTimeModelMapper $workingTimeModelMapper,
		UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		IL10N $l10n,
		HolidayService $holidayCalendarService
	) {
		$this->timeEntryMapper = $timeEntryMapper;
		$this->workingTimeModelMapper = $workingTimeModelMapper;
		$this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
		$this->l10n = $l10n;
		$this->holidayCalendarService = $holidayCalendarService;
	}

	/**
	 * Calculate overtime for a user for a specific period
	 *
	 * @param string $userId User ID
	 * @param \DateTime $startDate Start date
	 * @param \DateTime $endDate End date
	 * @param bool $calculateCumulative Whether to calculate cumulative balance
	 * @return array Overtime data including total hours, required hours, overtime hours, and balance
	 */
	public function calculateOvertime(string $userId, \DateTime $startDate, \DateTime $endDate, bool $calculateCumulative = true): array
	{
		// Get user's working time model
		$userModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
		
		// Default to 8 hours/day, 40 hours/week if no model assigned
		$dailyHours = 8.0;
		$weeklyHours = 40.0;
		
		if ($userModel) {
			try {
				$model = $this->workingTimeModelMapper->find($userModel->getWorkingTimeModelId());
				$dailyHours = $model->getDailyHours();
				$weeklyHours = $model->getWeeklyHours();
			} catch (\Throwable $e) {
				// Model not found, use defaults
			}
		}

		// Get all time entries for the period
		$timeEntries = $this->timeEntryMapper->findByUserAndDateRange($userId, $startDate, $endDate);
		
		// Calculate total hours worked (only completed entries)
		$totalHoursWorked = 0.0;
		foreach ($timeEntries as $entry) {
			if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED && $entry->getEndTime() !== null) {
				$totalHoursWorked += $entry->getWorkingDurationHours();
			}
		}

		// Calculate required hours based on working days (Mon–Fri minus holidays for this user)
		$requiredHours = $this->calculateRequiredHours($userId, $startDate, $endDate, $dailyHours, $weeklyHours);

		// Calculate overtime (positive = overtime, negative = undertime)
		$overtimeHours = $totalHoursWorked - $requiredHours;

		// Get cumulative overtime balance (from previous periods)
		$cumulativeBalance = 0.0;
		if ($calculateCumulative) {
			try {
				$cumulativeBalance = $this->getCumulativeOvertimeBalance($userId, $startDate);
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error calculating cumulative overtime balance: ' . $e->getMessage());
			}
		}

		// Calculate new balance
		$newBalance = $cumulativeBalance + $overtimeHours;

		// Contract norm spread over a Mon–Fri week (matches required_hours = workingDays × weeklyHours ÷ 5).
		$impliedDailyHours = $weeklyHours > 0 ? round($weeklyHours / 5, 2) : 0.0;

		return [
			'period_start' => $startDate->format('Y-m-d'),
			'period_end' => $endDate->format('Y-m-d'),
			'total_hours_worked' => round($totalHoursWorked, 2),
			'required_hours' => round($requiredHours, 2),
			'overtime_hours' => round($overtimeHours, 2),
			'cumulative_balance_before' => round($cumulativeBalance, 2),
			'cumulative_balance_after' => round($newBalance, 2),
			'cumulative_balance' => round($newBalance, 2),
			'daily_hours' => $dailyHours,
			'weekly_hours' => $weeklyHours,
			'implied_daily_hours' => $impliedDailyHours,
			'required_hours_basis' => 'weekly_contract',
			'working_days' => $this->countWorkingDays($userId, $startDate, $endDate)
		];
	}

	/**
	 * Calculate overtime for current month
	 *
	 * @param string $userId User ID
	 * @return array Overtime data for current month
	 */
	public function calculateMonthlyOvertime(string $userId): array
	{
		$now = new \DateTime();
		$startDate = new \DateTime($now->format('Y-m-01'));
		$startDate->setTime(0, 0, 0);
		$endDate = clone $now;
		$endDate->setTime(23, 59, 59);

		return $this->calculateOvertime($userId, $startDate, $endDate);
	}

	/**
	 * Calculate overtime for current year
	 *
	 * @param string $userId User ID
	 * @return array Overtime data for current year
	 */
	public function calculateYearlyOvertime(string $userId): array
	{
		$now = new \DateTime();
		$startDate = new \DateTime($now->format('Y-01-01'));
		$startDate->setTime(0, 0, 0);
		$endDate = clone $now;
		$endDate->setTime(23, 59, 59);

		return $this->calculateOvertime($userId, $startDate, $endDate);
	}

	/**
	 * Get cumulative overtime balance up to a specific date
	 *
	 * @param string $userId User ID
	 * @param \DateTime $beforeDate Calculate balance before this date
	 * @return float Cumulative overtime balance in hours
	 */
	public function getCumulativeOvertimeBalance(string $userId, \DateTime $beforeDate): float
	{
		// Get user's working time model
		$userModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
		
		$dailyHours = 8.0;
		$weeklyHours = 40.0;
		
		if ($userModel) {
			try {
				$model = $this->workingTimeModelMapper->find($userModel->getWorkingTimeModelId());
				$dailyHours = $model->getDailyHours();
				$weeklyHours = $model->getWeeklyHours();
			} catch (\Throwable $e) {
				// Model not found, use defaults
			}
		}

		// Calculate balance from beginning of year to beforeDate
		$yearStart = new \DateTime($beforeDate->format('Y-01-01'));
		$yearStart->setTime(0, 0, 0);
		
		$beforeDateCopy = clone $beforeDate;
		$beforeDateCopy->setTime(0, 0, 0);

		$overtimeData = $this->calculateOvertime($userId, $yearStart, $beforeDateCopy, false);
		
		return $overtimeData['overtime_hours'];
	}

	/**
	 * Calculate required hours for a date range based on working time model.
	 *
	 * Uses the weekly contract hours only: required = workingDays × (weeklyHours ÷ 5).
	 * The model's dailyHours field is kept for display and defaults; it does not
	 * change this calculation so tariff weeks (e.g. 38.7 h) stay consistent.
	 *
	 * @param float $dailyHours Stored norm per day (informational; not multiplied here)
	 */
	private function calculateRequiredHours(string $userId, \DateTime $startDate, \DateTime $endDate, float $dailyHours, float $weeklyHours): float
	{
		$workingDays = $this->countWorkingDays($userId, $startDate, $endDate);
		$weeks = $workingDays / 5.0;

		return $weeks * $weeklyHours;
	}

	/**
	 * Count working days in a date range (excluding weekends)
	 *
	 * @param string $userId
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @return float Number of working days (Mon–Fri minus holidays, can include half days)
	 */
	private function countWorkingDays(string $userId, \DateTime $startDate, \DateTime $endDate): float
	{
		// Delegate to HolidayService so that statutory and company holidays
		// (including half days) are treated consistently across the app.
		return $this->holidayCalendarService->computeWorkingDaysForUser($userId, $startDate, $endDate);
	}

	/**
	 * Get overtime balance for a user (current cumulative balance)
	 *
	 * @param string $userId User ID
	 * @return float Current overtime balance in hours (positive = overtime, negative = undertime)
	 */
	public function getOvertimeBalance(string $userId): float
	{
		$now = new \DateTime();
		$yearStart = new \DateTime($now->format('Y-01-01'));
		$yearStart->setTime(0, 0, 0);
		$now->setTime(23, 59, 59);

		$overtimeData = $this->calculateOvertime($userId, $yearStart, $now);
		
		return $overtimeData['cumulative_balance_after'];
	}

	/**
	 * Get daily overtime for a user
	 *
	 * @param string $userId User ID
	 * @param \DateTime|null $date Date to check (defaults to today)
	 * @return array Daily overtime data
	 */
	public function getDailyOvertime(string $userId, ?\DateTime $date = null): array
	{
		if ($date === null) {
			$date = new \DateTime();
		}

		$startDate = clone $date;
		$startDate->setTime(0, 0, 0);
		$endDate = clone $date;
		$endDate->setTime(23, 59, 59);

		return $this->calculateOvertime($userId, $startDate, $endDate);
	}

	/**
	 * Get weekly overtime for a user
	 *
	 * @param string $userId User ID
	 * @param \DateTime|null $weekStart Start of week (defaults to current week)
	 * @return array Weekly overtime data
	 */
	public function getWeeklyOvertime(string $userId, ?\DateTime $weekStart = null): array
	{
		if ($weekStart === null) {
			$weekStart = new \DateTime();
			$dayOfWeek = (int)$weekStart->format('w');
			$weekStart->modify('-' . $dayOfWeek . ' days');
		}

		$weekStart->setTime(0, 0, 0);
		$weekEnd = clone $weekStart;
		$weekEnd->modify('+6 days');
		$weekEnd->setTime(23, 59, 59);

		return $this->calculateOvertime($userId, $weekStart, $weekEnd);
	}
}
