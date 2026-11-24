<?php

declare(strict_types=1);

/**
 * Resolve vacation debit hours from the employee work model (not a blind 8 h/day).
 *
 * Priority:
 * 1. Weekday schedule net Sollzeit (holiday-aware) — BANSS Mo–Thu / Fri short
 * 2. Model daily_hours × holiday-aware working days
 * 3. Org vacation_hours_per_day (migration / conversion fallback only)
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\AppFramework\Db\DoesNotExistException;

class VacationHoursDebitService
{
	public function __construct(
		private UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private WorkingTimeModelMapper $workingTimeModelMapper,
		private HolidayService $holidayService,
		private VacationUnitService $vacationUnitService,
		private ?DutyRotationSollProvider $dutyRotationSollProvider = null,
	) {
	}

	/**
	 * Holiday-aware hours to debit for a full-day vacation range.
	 *
	 * @return array{
	 *   hours: float,
	 *   basis: 'weekday_schedule'|'model_daily'|'org_hours_per_day'|'rotation_pattern'|'published_roster'|'open_roster'|'segmented'|'unassigned_gap',
	 *   average_daily: float,
	 *   weekday_nets: array<string, float>|null,
	 *   one_day_hours: float
	 * }
	 */
	public function estimateForUserRange(
		string $userId,
		\DateTimeInterface $start,
		\DateTimeInterface $end,
	): array {
		$startDt = $this->asMutableDay($start);
		$endDt = $this->asMutableDay($end);
		if ($endDt < $startDt) {
			$tmp = $startDt;
			$startDt = $endDt;
			$endDt = $tmp;
		}

		$rotation = $this->tryRotationEstimate($userId, $startDt, $endDt);
		if ($rotation !== null) {
			return $rotation;
		}

		$orgHpd = $this->vacationUnitService->getHoursPerDay();

		// Segmented contract resolution: each assignment window debits with
		// its own daily Soll — a mid-range model change no longer applies the
		// *current* contract to the whole vacation.
		$segments = $this->assignmentResolver()->resolveRange($userId, $startDt, $endDt);
		$endContract = $this->assignmentResolver()->resolveStrict($userId, $endDt);
		$uniformBasis = count($segments) === 1 ? $segments[0]->contract->basis : null;

		$total = 0.0;
		foreach ($segments as $seg) {
			$c = $seg->contract;
			if ($c->basis === WorkingTimeModelAssignmentResolver::BASIS_UNASSIGNED_GAP) {
				// Strict gap: no contract, no Soll — nothing to debit.
				continue;
			}
			if ($c->schedule !== null) {
				$total += $c->schedule->requiredHoursForDateRange(
					$seg->start,
					$seg->end,
					fn (\DateTime $d): float => $this->holidayService->getHolidayWeightForUser($userId, $d)
				);
				continue;
			}
			$daily = ($c->dailyHours >= 0.25 && $c->dailyHours <= 24.0 && is_finite($c->dailyHours))
				? round($c->dailyHours, 2, PHP_ROUND_HALF_UP)
				: $orgHpd;
			// Never invent a working day for weekend/holiday-only ranges.
			$wd = max(0.0, $this->holidayService->computeWorkingDaysForUser($userId, $seg->start, $seg->end));
			$total += $wd * $daily;
		}

		// Summary fields mirror the contract covering the range end.
		$endDaily = ($endContract->dailyHours >= 0.25 && $endContract->dailyHours <= 24.0 && is_finite($endContract->dailyHours))
			? round($endContract->dailyHours, 2, PHP_ROUND_HALF_UP)
			: $orgHpd;
		if ($endContract->schedule !== null) {
			$avg = $endContract->schedule->averageDailyNetHours();
			$oneDay = $this->oneDayHoursFromSchedule($endContract->schedule, $endDt);
			$nets = $this->weekdayNetsMap($endContract->schedule);
			$avgOut = max(0.25, $avg);
			$oneDayOut = max(0.25, $oneDay);
		} else {
			$nets = null;
			$avgOut = max(0.25, $endDaily);
			$oneDayOut = max(0.25, $endDaily);
		}

		$basis = match (true) {
			$uniformBasis === 'weekday_schedule' => 'weekday_schedule',
			$uniformBasis === WorkingTimeModelAssignmentResolver::BASIS_UNASSIGNED_GAP => 'unassigned_gap',
			$uniformBasis !== null => ($endContract->dailyHours >= 0.25 && $endContract->dailyHours <= 24.0 && is_finite($endContract->dailyHours))
				? 'model_daily'
				: 'org_hours_per_day',
			default => 'segmented',
		};

		return [
			'hours' => $this->vacationUnitService->roundAmount(max(0.0, $total)),
			'basis' => $basis,
			'average_daily' => $this->vacationUnitService->roundAmount($avgOut),
			'weekday_nets' => $nets,
			'one_day_hours' => $this->vacationUnitService->roundAmount($oneDayOut),
		];
	}

	/**
	 * Snapshot for UI / mobile companions (additive; old clients ignore).
	 *
	 * @return array{
	 *   basis: 'weekday_schedule'|'model_daily'|'org_hours_per_day'|'rotation_pattern'|'published_roster'|'open_roster'|'segmented'|'unassigned_gap',
	 *   average_daily: float,
	 *   weekday_nets: array<string, float>|null,
	 *   one_day_hours: float
	 * }
	 */
	public function snapshotForUser(string $userId): array
	{
		$today = new \DateTime('today');
		$est = $this->estimateForUserRange($userId, $today, $today);

		return [
			'basis' => $est['basis'],
			'average_daily' => $est['average_daily'],
			'weekday_nets' => $est['weekday_nets'],
			'one_day_hours' => $est['one_day_hours'],
		];
	}

	/**
	 * @return array{
	 *   hours: float,
	 *   basis: 'rotation_pattern'|'published_roster'|'open_roster',
	 *   average_daily: float,
	 *   weekday_nets: null,
	 *   one_day_hours: float
	 * }|null
	 */
	private function tryRotationEstimate(string $userId, \DateTime $startDt, \DateTime $endDt): ?array
	{
		if ($this->dutyRotationSollProvider === null || !$this->dutyRotationSollProvider->isEnabledForOrg()) {
			return null;
		}
		try {
			$sum = 0.0;
			$days = 0;
			$oneDay = null;
			$basis = null;
			$cursor = clone $startDt;
			while ($cursor <= $endDt) {
				$h = $this->dutyRotationSollProvider->dayNetHoursForUser($userId, $cursor);
				if ($h === null) {
					return null;
				}
				$sum += $h;
				$days++;
				if ($oneDay === null) {
					$oneDay = $h;
					$basis = $this->dutyRotationSollProvider->getWeekTargetBasis($userId, $cursor) ?? 'rotation_pattern';
					if (!in_array($basis, ['rotation_pattern', 'published_roster', 'open_roster'], true)) {
						$basis = 'rotation_pattern';
					}
				}
				$cursor = (clone $cursor)->modify('+1 day');
			}
			if ($days < 1 || $oneDay === null) {
				return null;
			}
			$avg = $sum / $days;
			return [
				'hours' => $this->vacationUnitService->roundAmount(max(0.0, $sum)),
				'basis' => $basis,
				'average_daily' => $this->vacationUnitService->roundAmount(max(0.25, $avg)),
				'weekday_nets' => null,
				'one_day_hours' => $this->vacationUnitService->roundAmount(max(0.25, $oneDay)),
			];
		} catch (\Throwable) {
			return null;
		}
	}

	private ?WorkingTimeModelAssignmentResolver $lazyAssignmentResolver = null;

	private function assignmentResolver(): WorkingTimeModelAssignmentResolver
	{
		if ($this->lazyAssignmentResolver === null) {
			try {
				$this->lazyAssignmentResolver = \OCP\Server::get(WorkingTimeModelAssignmentResolver::class);
			} catch (\Throwable) {
				$this->lazyAssignmentResolver = new WorkingTimeModelAssignmentResolver(
					$this->userWorkingTimeModelMapper,
					$this->workingTimeModelMapper
				);
			}
		}
		return $this->lazyAssignmentResolver;
	}

	/**
	 * @return array<string, float>
	 */
	private function weekdayNetsMap(WeekdaySchedule $schedule): array
	{
		$out = [];
		foreach (WeekdaySchedule::DAYS as $day) {
			$out[$day] = $this->vacationUnitService->roundAmount($schedule->netHoursForWeekday($day));
		}

		return $out;
	}

	private function oneDayHoursFromSchedule(WeekdaySchedule $schedule, \DateTime $day): float
	{
		$map = [
			1 => 'mon',
			2 => 'tue',
			3 => 'wed',
			4 => 'thu',
			5 => 'fri',
			6 => 'sat',
			7 => 'sun',
		];
		$key = $map[(int)$day->format('N')] ?? 'mon';
		$net = $schedule->netHoursForWeekday($key);
		if ($net >= 0.25) {
			return $net;
		}
		$avg = $schedule->averageDailyNetHours();

		return $avg >= 0.25 ? $avg : $this->vacationUnitService->getHoursPerDay();
	}

	private function asMutableDay(\DateTimeInterface $d): \DateTime
	{
		$dt = $d instanceof \DateTime ? clone $d : new \DateTime($d->format('Y-m-d'));
		$dt->setTime(0, 0, 0);

		return $dt;
	}
}
