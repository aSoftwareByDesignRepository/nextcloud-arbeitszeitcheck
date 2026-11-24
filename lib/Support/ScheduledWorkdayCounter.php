<?php

declare(strict_types=1);

/**
 * Single authority for absence day counting.
 *
 * spec: planning/features/arbeitszeitcheck/absence-day-accuracy-pack-2026-11
 * ADR-01: the employee's working-time model weekday_schedule wins; the
 * legacy Mon–Fri holiday-weight count is the documented fallback when no
 * schedule exists (Personio parity — absence days are counted in scheduled
 * work days, never in hours).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;

final class ScheduledWorkdayCounter
{
	/** @var array<int, string> DateTime N (1=Mon) → day key */
	private const N_TO_DAY = [
		1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu',
		5 => 'fri', 6 => 'sat', 7 => 'sun',
	];

	public function __construct(
		private UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private WorkingTimeModelMapper $workingTimeModelMapper,
		private HolidayService $holidayService,
	) {
	}

	/**
	 * The user's active weekday schedule, or null when the model carries
	 * only scalar weekly/daily hours (or no model is assigned).
	 */
	public function scheduleForUser(string $userId): ?WeekdaySchedule
	{
		try {
			return $this->assignmentResolver()->resolveStrict($userId, new \DateTime())->schedule;
		} catch (\Throwable) {
			return null;
		}
	}

	private ?\OCA\ArbeitszeitCheck\Service\WorkingTimeModelAssignmentResolver $lazyAssignmentResolver = null;

	private function assignmentResolver(): \OCA\ArbeitszeitCheck\Service\WorkingTimeModelAssignmentResolver
	{
		if ($this->lazyAssignmentResolver === null) {
			try {
				$this->lazyAssignmentResolver = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\WorkingTimeModelAssignmentResolver::class);
			} catch (\Throwable) {
				$this->lazyAssignmentResolver = new \OCA\ArbeitszeitCheck\Service\WorkingTimeModelAssignmentResolver(
					$this->userWorkingTimeModelMapper,
					$this->workingTimeModelMapper
				);
			}
		}
		return $this->lazyAssignmentResolver;
	}

	/**
	 * Counting basis identifier for display (estimate endpoint).
	 */
	public function basisForUser(string $userId): string
	{
		return $this->scheduleForUser($userId) !== null
			? 'weekday_schedule'
			: 'weekdays_mon_fri';
	}

	/**
	 * Absence working days for a user over [start, end] inclusive.
	 *
	 * With a weekday_schedule: each day with work=true counts, reduced by
	 * the company/state holiday weight (full holiday → 0, half → 0.5).
	 * Weekend work days count — that is deliberate (Entgeltausfall
	 * reading; the schedule, not the calendar, defines the work week).
	 * Without a schedule: identical output to the legacy Mon–Fri count.
	 */
	public function daysForUser(string $userId, \DateTime $start, \DateTime $end): float
	{
		if ($end < $start) {
			[$start, $end] = [$end, $start];
		}
		// Per-segment strict resolution: each assignment window counts with
		// its own schedule; unassigned-gap days (strict policy) have no
		// contract and count 0. on_demand keeps the legacy Mon–Fri count.
		try {
			$segments = $this->assignmentResolver()->resolveRange($userId, $start, $end);
		} catch (\Throwable) {
			return $this->daysForSchedule($this->scheduleForUser($userId), $userId, $start, $end);
		}
		$total = 0.0;
		foreach ($segments as $seg) {
			if ($seg->contract->basis === \OCA\ArbeitszeitCheck\Service\WorkingTimeModelAssignmentResolver::BASIS_UNASSIGNED_GAP) {
				continue;
			}
			$total += $this->daysForSchedule($seg->contract->schedule, $userId, $seg->start, $seg->end);
		}
		return $total;
	}

	/**
	 * Same count as {@see daysForUser()} with an already-resolved schedule —
	 * lets batch callers (repair step) memoize the model per user instead of
	 * re-querying it for every row. Pass null for the Mon–Fri fallback.
	 */
	public function daysForSchedule(?WeekdaySchedule $schedule, string $userId, \DateTime $start, \DateTime $end): float
	{
		if ($schedule === null) {
			return $this->holidayService->computeWorkingDaysForUser($userId, $start, $end);
		}

		$start = (clone $start)->setTime(0, 0, 0);
		$end = (clone $end)->setTime(0, 0, 0);
		if ($end < $start) {
			[$start, $end] = [$end, $start];
		}

		$weights = $this->holidayService->getHolidayWeightMapForUser($userId, clone $start, clone $end);

		$days = 0.0;
		$cursor = clone $start;
		while ($cursor <= $end) {
			$dayKey = self::N_TO_DAY[(int)$cursor->format('N')] ?? 'mon';
			if ($schedule->isWorkDay($dayKey)) {
				$extra = (float)($weights[$cursor->format('Y-m-d')] ?? 0.0);
				if ($extra >= 1.0) {
					$weight = 0.0;
				} elseif ($extra > 0.0) {
					$weight = max(0.0, 1.0 - $extra);
				} else {
					$weight = 1.0;
				}
				$days += $weight;
			}
			$cursor->modify('+1 day');
		}

		return $days;
	}

	/**
	 * @return array<int,float> year => schedule-aware working days
	 */
	public function daysPerYearForUser(string $userId, \DateTime $start, \DateTime $end): array
	{
		if ($end < $start) {
			[$start, $end] = [$end, $start];
		}
		$startY = (int)$start->format('Y');
		$endY = (int)$end->format('Y');
		$out = [];
		for ($y = $startY; $y <= $endY; $y++) {
			$segStart = new \DateTime($y . '-01-01');
			$segEnd = new \DateTime($y . '-12-31');
			if ($segStart < $start) { $segStart = clone $start; }
			if ($segEnd > $end) { $segEnd = clone $end; }
			$out[$y] = $this->daysForUser($userId, $segStart, $segEnd);
		}
		return $out;
	}
}
