<?php

declare(strict_types=1);

/**
 * Opt-in Entgeltausfall / Ausfallprinzip: credit planned hours for paid absences.
 *
 * Default off. When enabled, approved sick leave on a planned day (weekday schedule,
 * DutyCheck rotation incl. Sat/Sun, or legacy Mon–Fri daily hours) adds those planned
 * net hours to overtime Ist so illness does not create flextime Minusstunden.
 *
 * Non-goals (v1): vacation / special leave matrix, payroll money, inventing weekend
 * Soll where nothing was planned.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

class PaidAbsencePlannedHoursCreditService
{
	/** @var list<string> */
	public const CREDITABLE_TYPES = [
		Absence::TYPE_SICK_LEAVE,
	];

	public function __construct(
		private readonly IConfig $config,
		private readonly AbsenceMapper $absenceMapper,
		private readonly UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private readonly WorkingTimeModelMapper $workingTimeModelMapper,
		private readonly HolidayService $holidayService,
		private readonly ?DutyRotationSollProvider $dutyRotationSollProvider = null,
	) {
	}

	public function isEnabled(): bool
	{
		return $this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT,
			Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT_DEFAULT,
		) === '1';
	}

	/**
	 * Planned-hours credit for approved paid absences overlapping the period.
	 *
	 * Iterates calendar days in the absence span (not Mon–Fri working-day counts),
	 * so planned weekend shifts are included when the roster/schedule planned them.
	 *
	 * @param array<string, float> $workedHoursByDate Y-m-d => completed worked hours that day
	 * @return array{hours: float, days_credited: int}
	 */
	public function creditHoursForRange(
		string $userId,
		\DateTimeInterface $periodStart,
		\DateTimeInterface $periodEnd,
		array $workedHoursByDate = [],
	): array {
		if (!$this->isEnabled() || $userId === '') {
			return ['hours' => 0.0, 'days_credited' => 0];
		}

		$rangeStart = $this->asDay($periodStart);
		$rangeEnd = $this->asDay($periodEnd);
		if ($rangeEnd < $rangeStart) {
			return ['hours' => 0.0, 'days_credited' => 0];
		}

		$absences = $this->absenceMapper->findByUserAndDateRange($userId, $rangeStart, $rangeEnd);
		if ($absences === []) {
			return ['hours' => 0.0, 'days_credited' => 0];
		}

		[$schedule, $dailyHours] = $this->resolveContract($userId);
		/** @var array<string, float> $bestCreditByDay */
		$bestCreditByDay = [];

		foreach ($absences as $absence) {
			if (!$absence instanceof Absence) {
				continue;
			}
			if ($absence->getStatus() !== Absence::STATUS_APPROVED) {
				continue;
			}
			if (!in_array($absence->getType(), self::CREDITABLE_TYPES, true)) {
				continue;
			}

			$absStart = $this->asDay($absence->getStartDate());
			$absEnd = $this->asDay($absence->getEndDate());
			$dayStart = $absStart > $rangeStart ? clone $absStart : clone $rangeStart;
			$dayEnd = $absEnd < $rangeEnd ? clone $absEnd : clone $rangeEnd;
			if ($dayEnd < $dayStart) {
				continue;
			}

			$fraction = $this->dayFraction($absence);
			$cursor = clone $dayStart;
			while ($cursor <= $dayEnd) {
				$key = $cursor->format('Y-m-d');
				$planned = $this->plannedNetHoursForDay($userId, $cursor, $schedule, $dailyHours);
				if ($planned <= 0.0001) {
					$cursor->modify('+1 day');
					continue;
				}
				$target = $planned * $fraction;
				$worked = max(0.0, (float)($workedHoursByDate[$key] ?? 0.0));
				$credit = max(0.0, $target - $worked);
				if ($credit <= 0.0001) {
					$cursor->modify('+1 day');
					continue;
				}
				$bestCreditByDay[$key] = max($bestCreditByDay[$key] ?? 0.0, $credit);
				$cursor->modify('+1 day');
			}
		}

		$total = 0.0;
		foreach ($bestCreditByDay as $hours) {
			$total += $hours;
		}

		return [
			'hours' => round($total, 4),
			'days_credited' => count($bestCreditByDay),
		];
	}

	/**
	 * Planned net hours for one calendar day (0 when free / full holiday).
	 */
	public function plannedNetHoursForDay(
		string $userId,
		\DateTimeInterface $date,
		?WeekdaySchedule $schedule = null,
		?float $dailyHours = null,
	): float {
		$day = $this->asDay($date);

		if ($this->dutyRotationSollProvider !== null && $this->dutyRotationSollProvider->isEnabledForOrg()) {
			try {
				$rotation = $this->dutyRotationSollProvider->dayNetHoursForUser($userId, $day);
				if ($rotation !== null) {
					return max(0.0, round($rotation, 4));
				}
			} catch (\Throwable) {
				// Fall through to schedule / legacy.
			}
		}

		if ($schedule === null || $dailyHours === null) {
			[$schedule, $dailyHours] = $this->resolveContract($userId);
		}

		$weight = 0.0;
		try {
			$weight = (float)$this->holidayService->getHolidayWeightForUser($userId, $day);
		} catch (\Throwable) {
			$weight = 0.0;
		}
		if ($weight >= 1.0) {
			return 0.0;
		}
		$holidayFraction = $weight > 0.0 ? max(0.0, 1.0 - $weight) : 1.0;

		if ($schedule !== null) {
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

			return max(0.0, round($net * $holidayFraction, 4));
		}

		// Legacy weekly_hours/5 model: Mon–Fri only — free weekends stay 0.
		$n = (int)$day->format('N');
		if ($n >= 6) {
			return 0.0;
		}
		$daily = ($dailyHours !== null && is_finite($dailyHours) && $dailyHours > 0.0)
			? $dailyHours
			: 8.0;

		return max(0.0, round($daily * $holidayFraction, 4));
	}

	/**
	 * Half-day absences (single calendar day, days ≈ 0.5) credit half the planned target.
	 */
	private function dayFraction(Absence $absence): float
	{
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		if ($start === null || $end === null) {
			return 1.0;
		}
		if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
			return 1.0;
		}
		$days = $absence->getDays();
		if ($days !== null && abs((float)$days - 0.5) < 0.011) {
			return 0.5;
		}

		return 1.0;
	}

	/**
	 * @return array{0: ?WeekdaySchedule, 1: float}
	 */
	private function resolveContract(string $userId): array
	{
		$dailyHours = 8.0;
		try {
			$userModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
			if ($userModel === null) {
				return [null, $dailyHours];
			}
			$model = $this->workingTimeModelMapper->find($userModel->getWorkingTimeModelId());
			$schedule = $model->getWeekdaySchedule();
			if ($schedule !== null) {
				return [$schedule, $schedule->averageDailyNetHours()];
			}
			$modelDaily = (float)$model->getDailyHours();
			if (is_finite($modelDaily) && $modelDaily > 0.0) {
				$dailyHours = $modelDaily;
			}
		} catch (DoesNotExistException) {
			// defaults
		} catch (\Throwable) {
			// defaults
		}

		return [null, $dailyHours];
	}

	private function asDay(\DateTimeInterface $d): \DateTime
	{
		$dt = $d instanceof \DateTime ? clone $d : new \DateTime($d->format('Y-m-d'));
		$dt->setTime(0, 0, 0);

		return $dt;
	}
}
