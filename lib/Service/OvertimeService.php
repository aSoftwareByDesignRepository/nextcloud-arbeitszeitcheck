<?php

declare(strict_types=1);

/**
 * Overtime service for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
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
	private UserOvertimeSettingsService $overtimeSettingsService;
	private ?DutyRotationSollProvider $dutyRotationSollProvider;
	private ?PaidAbsencePlannedHoursCreditService $paidAbsenceCreditService;
	private ?WorkingTimeModelAssignmentResolver $assignmentResolver;

	public function __construct(
		TimeEntryMapper $timeEntryMapper,
		WorkingTimeModelMapper $workingTimeModelMapper,
		UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		IL10N $l10n,
		HolidayService $holidayCalendarService,
		UserOvertimeSettingsService $overtimeSettingsService,
		?DutyRotationSollProvider $dutyRotationSollProvider = null,
		?PaidAbsencePlannedHoursCreditService $paidAbsenceCreditService = null,
		?WorkingTimeModelAssignmentResolver $assignmentResolver = null,
	) {
		$this->timeEntryMapper = $timeEntryMapper;
		$this->workingTimeModelMapper = $workingTimeModelMapper;
		$this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
		$this->l10n = $l10n;
		$this->holidayCalendarService = $holidayCalendarService;
		$this->overtimeSettingsService = $overtimeSettingsService;
		$this->dutyRotationSollProvider = $dutyRotationSollProvider;
		$this->paidAbsenceCreditService = $paidAbsenceCreditService;
		$this->assignmentResolver = $assignmentResolver;
	}

	/**
	 * The single resolution authority (lazily resolved so legacy manual
	 * constructions in tests keep working; DI always injects it).
	 */
	private function assignmentResolver(): WorkingTimeModelAssignmentResolver
	{
		if ($this->assignmentResolver === null) {
			$this->assignmentResolver = \OCP\Server::get(WorkingTimeModelAssignmentResolver::class);
		}
		return $this->assignmentResolver;
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
		$startDate = clone $startDate;
		$endDate = clone $endDate;
		$startDate->setTime(0, 0, 0);
		$endDate->setTime(23, 59, 59);

		$yearOfStart = (int)$startDate->format('Y');
		$effectiveYTDStart = $this->overtimeSettingsService->resolveEffectiveYearStart($userId, $yearOfStart);
		$opening = $this->overtimeSettingsService->getOpeningBalanceHours($userId, $yearOfStart);
		$trackingFrom = $this->overtimeSettingsService->getTrackingFrom($userId);

		$rotationWeekLabel = null;

		$periodStart = clone $startDate;
		if ($periodStart < $effectiveYTDStart) {
			$periodStart = clone $effectiveYTDStart;
		}

		// Future tracking-from (or any clamp that moves period start past the range end):
		// no target hours and no worked hours in-window; opening balance / carry-in still apply.
		$periodStartDay = clone $periodStart;
		$periodStartDay->setTime(0, 0, 0);
		$periodEndDay = clone $endDate;
		$periodEndDay->setTime(0, 0, 0);

		// Scalar summary fields mirror the contract covering period_end (R-07) —
		// a mid-period model change shows the *current* contract's numbers,
		// while soll_segments carry the full history.
		$endContract = $this->assignmentResolver()->resolveStrict($userId, $periodEndDay);
		$dailyHours = $endContract->dailyHours;
		$weeklyHours = $endContract->weeklyHours;
		$schedule = $endContract->schedule;
		$requiredHoursBasis = $endContract->basis;

		$rotationHours = null;
		if ($periodStartDay <= $periodEndDay) {
			$rotationHours = $this->tryRotationRequiredHours($userId, $periodStart, $endDate);
			if ($rotationHours !== null) {
				$basis = $this->dutyRotationSollProvider?->getWeekTargetBasis($userId, $periodStart);
				$requiredHoursBasis = $basis ?? 'rotation_pattern';
				$rotationWeekLabel = $this->dutyRotationSollProvider?->getWeekTargetLabel($userId, $periodStart);
			}
		}

		if ($periodStartDay > $periodEndDay) {
			$carryInDelta = 0.0;
			if ($calculateCumulative) {
				try {
					$carryInDelta = $this->getCumulativeWorkDelta($userId, $effectiveYTDStart, $startDate);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error calculating cumulative overtime balance: ' . $e->getMessage());
				}
			}
			$balanceBefore = $opening + $carryInDelta;
			$impliedDailyHours = $schedule !== null
				? $schedule->averageDailyNetHours()
				: ($weeklyHours > 0 ? round($weeklyHours / 5, 2) : 0.0);

			return [
				'period_start' => $periodStart->format('Y-m-d'),
				'period_end' => $endDate->format('Y-m-d'),
				'total_hours_worked' => 0.0,
				'absence_credit_hours' => 0.0,
				'soll_reduction_hours' => 0.0,
				'required_hours' => 0.0,
				'overtime_hours' => 0.0,
				'cumulative_balance_before' => round($balanceBefore, 2),
				'cumulative_balance_after' => round($balanceBefore, 2),
				'cumulative_balance' => round($balanceBefore, 2),
				'daily_hours' => $dailyHours,
				'weekly_hours' => $weeklyHours,
				'implied_daily_hours' => $impliedDailyHours,
				'required_hours_basis' => $requiredHoursBasis,
				'rotation_week_label' => $rotationWeekLabel,
				'soll_segments' => [],
				'working_days' => 0.0,
				'effective_tracking_from' => $trackingFrom !== null ? $trackingFrom->format('Y-m-d') : null,
				'opening_balance_hours' => round($opening, 2),
				'algorithm_version' => Constants::OVERTIME_ALGORITHM_VERSION,
			];
		}

		[$totalHoursWorked, $workedByDay] = $this->workedHoursByDayRange($userId, $periodStart, $endDate);

		$absenceCreditHours = 0.0;
		if ($this->paidAbsenceCreditService !== null) {
			try {
				$credit = $this->paidAbsenceCreditService->creditHoursForRange(
					$userId,
					$periodStart,
					$endDate,
					$workedByDay,
				);
				$absenceCreditHours = max(0.0, (float)($credit['hours'] ?? 0.0));
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error(
					'Paid absence planned-hours credit failed: ' . $e->getMessage()
				);
				$absenceCreditHours = 0.0;
			}
		}

		$sollSegments = [];
		// Segmented Soll: every assignment window in the range contributes its
		// own contract — a mid-period model change no longer stretches today's
		// model retroactively over the whole range (the core feature).
		if ($rotationHours !== null) {
			$suspensionReduction = $this->suspensionReductionHours($userId, $periodStart, $endDate);
			$requiredHours = max(0.0, $rotationHours - $suspensionReduction);
		} else {
			[$requiredHours, $resolvedSegments, $sollSegments, $suspensionReduction] = $this->segmentedRequiredHours(
				$userId,
				$periodStartDay,
				$periodEndDay
			);
			$requiredHoursBasis = count($resolvedSegments) === 1
				? $resolvedSegments[0]->contract->basis
				: WorkingTimeModelAssignmentResolver::BASIS_SEGMENTED;
		}
		// Overtime tracking "Stichtag": do not apply the full daily/weekly target to the
		// anchor calendar day itself. Otherwise a new employee sees a full day of
		// undertime at login before any work is recorded (confusing UX). From the next
		// calendar day onward, targets apply normally. Opening balance still applies.
		if ($trackingFrom !== null) {
			$anchor = \DateTime::createFromImmutable($trackingFrom);
			$anchor->setTime(0, 0, 0);
			$periodStartDay = clone $periodStart;
			$periodStartDay->setTime(0, 0, 0);
			$periodEndDay = clone $endDate;
			$periodEndDay->setTime(0, 0, 0);
			if ($anchor >= $periodStartDay && $anchor <= $periodEndDay) {
				$anchorEnd = clone $anchor;
				$anchorEnd->setTime(23, 59, 59);
				$anchorRotation = $this->tryRotationRequiredHours($userId, $anchor, $anchorEnd);
				if ($anchorRotation !== null) {
					$anchorReduction = $this->suspensionReductionHours($userId, $anchor, $anchorEnd);
					$requiredHours -= max(0.0, $anchorRotation - $anchorReduction);
				} else {
					[$anchorSoll] = $this->segmentedRequiredHours($userId, $anchor, $anchorEnd);
					$requiredHours -= $anchorSoll;
				}
				if ($requiredHours < 0) {
					$requiredHours = 0.0;
				}
			}
		}
		// Ist for Saldo = clocked hours + optional paid-absence planned-hours credit.
		// total_hours_worked stays clocked-only so reports do not invent worked time.
		$overtimeHours = ($totalHoursWorked + $absenceCreditHours) - $requiredHours;

		$carryInDelta = 0.0;
		if ($calculateCumulative) {
			try {
				$carryInDelta = $this->getCumulativeWorkDelta($userId, $effectiveYTDStart, $startDate);
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Error calculating cumulative overtime balance: ' . $e->getMessage());
			}
		}

		$balanceBefore = $opening + $carryInDelta;
		$balanceAfter = $balanceBefore + $overtimeHours;

		$impliedDailyHours = $schedule !== null
			? round($schedule->averageDailyNetHours(), 2)
			: ($weeklyHours > 0 ? round($weeklyHours / 5, 2) : 0.0);

		return [
			'period_start' => $startDate->format('Y-m-d'),
			'period_end' => $endDate->format('Y-m-d'),
			'total_hours_worked' => round($totalHoursWorked, 2),
			'absence_credit_hours' => round($absenceCreditHours, 2),
			'soll_reduction_hours' => round($suspensionReduction, 2),
			'required_hours' => round($requiredHours, 2),
			'overtime_hours' => round($overtimeHours, 2),
			'cumulative_balance_before' => round($balanceBefore, 2),
			'cumulative_balance_after' => round($balanceAfter, 2),
			'cumulative_balance' => round($balanceAfter, 2),
			'daily_hours' => $dailyHours,
			'weekly_hours' => $weeklyHours,
			'implied_daily_hours' => $impliedDailyHours,
			'required_hours_basis' => $requiredHoursBasis,
			'rotation_week_label' => $rotationWeekLabel,
			'soll_segments' => $sollSegments,
			'working_days' => $this->countWorkingDays($userId, $periodStart, $endDate),
			'effective_tracking_from' => $trackingFrom !== null ? $trackingFrom->format('Y-m-d') : null,
			'opening_balance_hours' => round($opening, 2),
			'algorithm_version' => Constants::OVERTIME_ALGORITHM_VERSION,
		];
	}

	/**
	 * Calculate overtime for current month
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
	 * Completed paid work hours for a range, bucketed by calendar day.
	 *
	 * Single source of truth for the `workedHoursByDate` input the paid
	 * absence credit consumes ({@see PaidAbsencePlannedHoursCreditService}) —
	 * {@see calculateOvertime()} and the overtime-breakdown endpoint both go
	 * through here so the breakdown explains the exact aggregate.
	 *
	 * @return array{0: float, 1: array<string, float>}
	 *   [total paid hours in range (incl. unbucketed rows), Y-m-d => paid hours]
	 */
	public function workedHoursByDayRange(string $userId, \DateTime $periodStart, \DateTime $endDate): array
	{
		// Normalize to day bounds: callers pass "the period covers these
		// days", but the mapper query is an inclusive datetime range — a
		// 00:00:00 end would silently drop the last day's entries.
		$from = clone $periodStart;
		$from->setTime(0, 0, 0);
		$to = clone $endDate;
		$to->setTime(23, 59, 59);
		$timeEntries = $this->timeEntryMapper->findByUserAndDateRange($userId, $from, $to);

		$totalHoursWorked = 0.0;
		/** @var array<string, float> $workedByDay */
		$workedByDay = [];
		foreach ($timeEntries as $entry) {
			if ($entry->getStatus() === TimeEntry::STATUS_COMPLETED && $entry->getEndTime() !== null) {
				// Overtime is tariff-facing: paid basis = net + paid-break snapshot.
				$hours = (float)($entry->getCountedPaidDurationHours() ?? 0.0);
				$totalHoursWorked += $hours;
				$start = $entry->getStartTime();
				if ($start !== null) {
					$key = $start->format('Y-m-d');
					$workedByDay[$key] = ($workedByDay[$key] ?? 0.0) + $hours;
				}
			}
		}

		return [$totalHoursWorked, $workedByDay];
	}

	/**
	 * Work delta from $from (inclusive) to $to (exclusive at day boundary).
	 */
	public function getCumulativeWorkDelta(string $userId, \DateTime $from, \DateTime $to): float
	{
		$fromCopy = clone $from;
		$fromCopy->setTime(0, 0, 0);
		$toCopy = clone $to;
		$toCopy->setTime(0, 0, 0);

		if ($fromCopy >= $toCopy) {
			return 0.0;
		}

		$overtimeData = $this->calculateOvertime($userId, $fromCopy, $toCopy, false);

		return $overtimeData['overtime_hours'];
	}

	/**
	 * Get cumulative overtime balance up to a specific date (legacy name: returns work delta only).
	 */
	public function getCumulativeOvertimeBalance(string $userId, \DateTime $beforeDate): float
	{
		$yearOfBefore = (int)$beforeDate->format('Y');
		$effectiveStart = $this->overtimeSettingsService->resolveEffectiveYearStart($userId, $yearOfBefore);

		return $this->getCumulativeWorkDelta($userId, $effectiveStart, $beforeDate);
	}

	/**
	 * Segment-wise Soll for a range: each contiguous contract window
	 * contributes its own required hours, and suspension absences reduce the
	 * segment they fall into (never leaking into a neighbouring window or an
	 * unassigned gap, whose Soll is already 0).
	 *
	 * @return array{0: float, 1: list<\OCA\ArbeitszeitCheck\Support\AssignmentSegment>, 2: list<array<string, mixed>>, 3: float}
	 *   [total required hours, resolved segments, wire-shaped soll_segments,
	 *   total suspension reduction]
	 */
	private function segmentedRequiredHours(string $userId, \DateTime $startDay, \DateTime $endDay): array
	{
		$segments = $this->assignmentResolver()->resolveRange($userId, $startDay, $endDay);
		$total = 0.0;
		$reduction = 0.0;
		$wire = [];
		foreach ($segments as $seg) {
			$contract = $seg->contract;
			if ($contract->schedule !== null) {
				$base = $contract->schedule->requiredHoursForDateRange(
					$seg->start,
					$seg->end,
					fn (\DateTime $day): float => $this->holidayCalendarService->getHolidayWeightForUser($userId, $day),
				);
			} elseif ($contract->weeklyHours > 0.0) {
				$workingDays = $this->countWorkingDays($userId, $seg->start, $seg->end);
				$base = ($workingDays / 5.0) * $contract->weeklyHours;
			} else {
				// on_demand / unassigned_gap: contractually no Soll.
				$base = 0.0;
			}
			$segReduction = $this->suspensionReductionHours($userId, $seg->start, $seg->end);
			$reduction += $segReduction;
			$segSoll = max(0.0, $base - $segReduction);
			$total += $segSoll;
			$model = $contract->model;
			$wire[] = [
				'start' => $seg->start->format('Y-m-d'),
				'end' => $seg->end->format('Y-m-d'),
				'model_id' => $model?->getId(),
				'model_name' => $model !== null
					? ($model->isPersonal() ? $this->l10n->t('Individual contract') : $model->getName())
					: null,
				'basis' => $contract->basis,
				'hours' => round($segSoll, 2),
			];
		}
		return [$total, $segments, $wire, $reduction];
	}

	/**
	 * Planned Soll removed by approved suspension absences in the range.
	 * Fails soft to 0 — a reduction outage must never inflate required hours
	 * past what the employee would have owed before the matrix shipped.
	 */
	private function suspensionReductionHours(string $userId, \DateTime $startDate, \DateTime $endDate): float
	{
		if ($this->paidAbsenceCreditService === null) {
			return 0.0;
		}
		try {
			$result = $this->paidAbsenceCreditService->sollReductionHoursForRange($userId, $startDate, $endDate);
			return max(0.0, (float)($result['hours'] ?? 0.0));
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Absence Soll reduction failed: ' . $e->getMessage());
			return 0.0;
		}
	}

	private function tryRotationRequiredHours(string $userId, \DateTime $startDate, \DateTime $endDate): ?float
	{
		if ($this->dutyRotationSollProvider === null || !$this->dutyRotationSollProvider->isEnabledForOrg()) {
			return null;
		}
		try {
			return $this->dutyRotationSollProvider->requiredHoursForDateRange($userId, $startDate, $endDate);
		} catch (\Throwable) {
			return null;
		}
	}

	private function countWorkingDays(string $userId, \DateTime $startDate, \DateTime $endDate): float
	{
		return $this->holidayCalendarService->computeWorkingDaysForUser($userId, $startDate, $endDate);
	}

	/**
	 * @return array{0: float, 1: float, 2: ?\OCA\ArbeitszeitCheck\Support\WeekdaySchedule, 3: string}
	 *   [dailyHours, weeklyHours, schedule, required_hours_basis]
	 */
	private function resolveContractHours(string $userId): array
	{
		// "Current contract" == strict resolution at today: covering row → its
		// contract; org-gap-policy branch applies to non-covering days.
		$contract = $this->assignmentResolver()->resolveStrict($userId, new \DateTime());
		return [$contract->dailyHours, $contract->weeklyHours, $contract->schedule, $contract->basis];
	}

	/**
	 * Average planned hours per working day for comp-day ("Abbummeltag")
	 * estimates. Schedule-aware (weekday schedule → its daily average; plain
	 * contract → daily/weekly÷5). `basis` mirrors resolveContractHours so
	 * callers can distinguish on_demand/degraded from a real contract —
	 * on_demand must NOT produce an estimate (no contractual Soll exists).
	 *
	 * @return array{daily_hours: float, basis: string}
	 */
	public function getAverageDailyPlannedHours(string $userId): array
	{
		// Rotation (G2): the contract model is only the fallback Soll — the
		// roster is the authority. The comp-day divisor must be the roster's
		// average *scheduled* day net, the same quantity an overtime_comp
		// day actually debits. Every other Soll consumer consults DutyCheck
		// first; the estimate must too.
		if ($this->dutyRotationSollProvider !== null && $this->dutyRotationSollProvider->isEnabledForOrg()) {
			$rotation = $this->rotationAverageDailyPlannedHours($userId);
			if ($rotation !== null) {
				return $rotation;
			}
		}

		[$dailyHours, $weeklyHours, $schedule, $basis] = $this->resolveContractHours($userId);
		// daily_hours is only loosely trusted elsewhere: required-hours math
		// derives Soll from weekly/5 and the vacation debit clamps the
		// column to ≤ 24 h. A value outside (0, 24] is corrupt — derive the
		// day value from the weekly contract instead of letting it poison
		// the estimate.
		if (!is_finite($dailyHours) || $dailyHours <= 0.0 || $dailyHours > 24.0) {
			$dailyHours = $weeklyHours > 0.0 ? $weeklyHours / 5.0 : 0.0;
		}
		$daily = $dailyHours > 0.0
			? $dailyHours
			: ($weeklyHours > 0.0 ? round($weeklyHours / 5, 2) : 0.0);
		return ['daily_hours' => round($daily, 2), 'basis' => $basis];
	}

	/**
	 * Average planned net per *scheduled* roster day for rotation users —
	 * the same value an overtime_comp day debits. Samples the current ISO
	 * week (the anchor {@see DutyRotationSollProvider::getWeekTargetBasis}
	 * uses). A null day — roster miss, sealed month, facade down, or
	 * rotation not enabled for this user — falls back to the contract
	 * path, matching requiredHoursForDateRange's all-or-null semantics.
	 * A week with no scheduled days also falls back: zero tells the
	 * estimate nothing about day value.
	 *
	 * @return array{daily_hours: float, basis: string}|null
	 */
	private function rotationAverageDailyPlannedHours(string $userId): ?array
	{
		$monday = new \DateTime('monday this week');
		$sum = 0.0;
		$scheduledDays = 0;
		for ($i = 0; $i < 7; $i++) {
			$net = $this->dutyRotationSollProvider->dayNetHoursForUser(
				$userId,
				(clone $monday)->modify("+{$i} days")
			);
			if ($net === null) {
				return null;
			}
			if ($net > 0.0001) {
				$sum += $net;
				$scheduledDays++;
			}
		}
		if ($scheduledDays === 0) {
			return null;
		}

		return [
			'daily_hours' => round($sum / $scheduledDays, 2),
			'basis' => $this->dutyRotationSollProvider->getWeekTargetBasis($userId, $monday)
				?? 'rotation_pattern',
		];
	}

	/**
	 * True when the organisation has at least one org-level working time
	 * model configured. Memoized per request — one cheap EXISTS-style query
	 * total, not per user.
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

	public function getWeeklyOvertime(string $userId, ?\DateTime $weekStart = null): array
	{
		if ($weekStart === null) {
			// ISO-style calendar week (Monday–Sunday), aligned with compliance checks and DE practice.
			$weekStart = new \DateTime('monday this week');
		}

		$weekStart = clone $weekStart;
		$weekStart->setTime(0, 0, 0);
		$weekEnd = clone $weekStart;
		$weekEnd->modify('+6 days');
		$weekEnd->setTime(23, 59, 59);

		return $this->calculateOvertime($userId, $weekStart, $weekEnd);
	}
}
