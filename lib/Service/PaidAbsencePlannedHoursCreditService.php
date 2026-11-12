<?php

declare(strict_types=1);

/**
 * Absence Soll effects for the flextime balance (Entgeltausfall / Ausfallprinzip).
 *
 * Two gates, one matrix ({@see AbsenceTypeCatalog::sollEffect}):
 *
 * - `paid_absence_planned_hours_credit` (opt-in, default off): approved
 *   `sick_leave` / `child_sick` / custom `soll_credit` types on a planned day
 *   (weekday schedule, DutyCheck rotation incl. Sat/Sun, or legacy Mon–Fri
 *   daily hours) add those planned net hours to overtime Ist.
 * - `leave_soll_effects` (default on): approved `vacation` / `personal_leave` /
 *   `special_leave` credit the same way — leave pay continuation is not an org
 *   policy choice — while `unpaid_leave` / `parental_leave` *reduce* the day's
 *   Soll instead of crediting Ist (contract suspended: neutral, never paid).
 *
 * Non-goals: payroll money, inventing weekend Soll where nothing was planned,
 * per-type admin overrides of the built-in matrix (custom types have their own
 * `soll_credit` flag).
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
use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

class PaidAbsencePlannedHoursCreditService
{
	public function __construct(
		private readonly IConfig $config,
		private readonly AbsenceMapper $absenceMapper,
		private readonly UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private readonly WorkingTimeModelMapper $workingTimeModelMapper,
		private readonly HolidayService $holidayService,
		private readonly ?DutyRotationSollProvider $dutyRotationSollProvider = null,
		private readonly ?\OCA\ArbeitszeitCheck\Support\ScheduledWorkdayCounter $workdayCounter = null,
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
	 * Leave-effects gate (default on): paid-leave types credit planned hours,
	 * suspension types reduce Soll. Separate from the sick opt-in so enabling
	 * Entgeltausfall for illness stays an explicit org decision while correct
	 * leave handling ships on by default.
	 */
	public function isLeaveEffectsEnabled(): bool
	{
		return $this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_LEAVE_SOLL_EFFECTS,
			Constants::CONFIG_LEAVE_SOLL_EFFECTS_DEFAULT,
		) === '1';
	}

	/**
	 * Paid-leave types governed by the leave-effects gate rather than the sick
	 * opt-in. Custom types always follow the sick opt-in flag — their
	 * `soll_credit` flag documents the Entgeltausfall family they joined.
	 */
	private const LEAVE_CREDIT_TYPES = [
		Absence::TYPE_VACATION,
		Absence::TYPE_PERSONAL_LEAVE,
		Absence::TYPE_SPECIAL_LEAVE,
	];

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
		$empty = ['hours' => 0.0, 'days_credited' => 0];
		if ($userId === '' || (!$this->isEnabled() && !$this->isLeaveEffectsEnabled())) {
			return $empty;
		}

		$rangeStart = $this->asDay($periodStart);
		$rangeEnd = $this->asDay($periodEnd);
		if ($rangeEnd < $rangeStart) {
			return $empty;
		}

		// Contract is resolved per calendar day inside: an effective-dated model
		// change inside the range must credit each day with its own Soll, not
		// the assignment that happens to be current today.
		$targetByDay = $this->plannedTargetsForEffect(
			$userId,
			$rangeStart,
			$rangeEnd,
			AbsenceTypeCatalog::SOLL_EFFECT_CREDIT_IST,
		);

		/** @var array<string, float> $bestCreditByDay */
		$bestCreditByDay = [];
		foreach ($targetByDay as $key => $dayPlan) {
			[$target, $planned] = $dayPlan;
			$worked = max(0.0, (float)($workedHoursByDate[$key] ?? 0.0));
			// Top the day up to its full planned Soll, capped at the absent
			// fraction: a half-day absence + worked other half must net zero,
			// not half a day's Minusstunden. (Was: target - worked, which
			// undercredited every half-day with worked hours.)
			$credit = max(0.0, min($target, $planned - $worked));
			if ($credit > 0.0001) {
				$bestCreditByDay[$key] = $credit;
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
	 * Soll removed by approved suspension absences (unpaid / parental leave)
	 * overlapping the period — subtracted from required hours so a suspended
	 * contract day can neither earn nor lose balance.
	 *
	 * @return array{hours: float, days_reduced: int}
	 */
	public function sollReductionHoursForRange(
		string $userId,
		\DateTimeInterface $periodStart,
		\DateTimeInterface $periodEnd,
	): array {
		$empty = ['hours' => 0.0, 'days_reduced' => 0];
		if (!$this->isLeaveEffectsEnabled() || $userId === '') {
			return $empty;
		}

		$rangeStart = $this->asDay($periodStart);
		$rangeEnd = $this->asDay($periodEnd);
		if ($rangeEnd < $rangeStart) {
			return $empty;
		}

		$targetByDay = $this->plannedTargetsForEffect(
			$userId,
			$rangeStart,
			$rangeEnd,
			AbsenceTypeCatalog::SOLL_EFFECT_REDUCE_SOLL,
		);

		$total = 0.0;
		foreach ($targetByDay as $dayPlan) {
			$total += $dayPlan[0];
		}

		return [
			'hours' => round($total, 4),
			'days_reduced' => count($targetByDay),
		];
	}

	/**
	 * What the matrix *should* deliver for a range — gate-agnostic expectation
	 * for auditing ({@see \OCA\ArbeitszeitCheck\Command\VerifyAbsenceSollCommand}).
	 * Compare with {@see creditHoursForRange()} / {@see sollReductionHoursForRange()}:
	 * a gate-disabled org shows expected > actual, which is configuration —
	 * an enabled org showing that gap is a bug.
	 *
	 * @param array<string, float> $workedHoursByDate Y-m-d => completed worked hours that day
	 * @return array{credit_hours: float, credit_days: int, reduction_hours: float, reduction_days: int}
	 */
	public function expectedSollEffectHours(
		string $userId,
		\DateTimeInterface $periodStart,
		\DateTimeInterface $periodEnd,
		array $workedHoursByDate = [],
	): array {
		$empty = [
			'credit_hours' => 0.0, 'credit_days' => 0,
			'reduction_hours' => 0.0, 'reduction_days' => 0,
		];
		if ($userId === '') {
			return $empty;
		}
		$rangeStart = $this->asDay($periodStart);
		$rangeEnd = $this->asDay($periodEnd);
		if ($rangeEnd < $rangeStart) {
			return $empty;
		}

		$creditHours = 0.0;
		$creditDays = 0;
		foreach ($this->plannedTargetsForEffect(
			$userId, $rangeStart, $rangeEnd,
			AbsenceTypeCatalog::SOLL_EFFECT_CREDIT_IST, false,
		) as $key => $dayPlan) {
			[$target, $planned] = $dayPlan;
			$worked = max(0.0, (float)($workedHoursByDate[$key] ?? 0.0));
			$credit = max(0.0, min($target, $planned - $worked));
			if ($credit > 0.0001) {
				$creditHours += $credit;
				$creditDays++;
			}
		}

		$reductionTargets = $this->plannedTargetsForEffect(
			$userId, $rangeStart, $rangeEnd,
			AbsenceTypeCatalog::SOLL_EFFECT_REDUCE_SOLL, false,
		);
		$reductionHours = 0.0;
		foreach ($reductionTargets as $dayPlan) {
			$reductionHours += $dayPlan[0];
		}

		return [
			'credit_hours' => round($creditHours, 4),
			'credit_days' => $creditDays,
			'reduction_hours' => round($reductionHours, 4),
			'reduction_days' => count($reductionTargets),
		];
	}

	/**
	 * Per-day planned absence hours (Y-m-d => target) for one Soll effect.
	 *
	 * Only approved absences of the requested effect contribute; for credit
	 * days the type's own gate must additionally be open. Overlapping absences
	 * are rejected at write time, so a day belongs to at most one absence —
	 * the per-day max is a defensive ceiling, not the merge rule.
	 *
	 * @return array<string, array{0: float, 1: float}> Y-m-d => [fraction-adjusted target, full planned net]
	 */
	private function plannedTargetsForEffect(
		string $userId,
		\DateTime $rangeStart,
		\DateTime $rangeEnd,
		string $effect,
		bool $respectGates = true,
	): array {
		$targetByDay = [];

		foreach ($this->absenceMapper->findByUserAndDateRange($userId, $rangeStart, $rangeEnd) as $absence) {
			if (!$absence instanceof Absence) {
				continue;
			}
			if ($absence->getStatus() !== Absence::STATUS_APPROVED) {
				continue;
			}
			$type = $absence->getType();
			if (AbsenceTypeCatalog::sollEffect($type) !== $effect) {
				continue;
			}
			if ($respectGates && $effect === AbsenceTypeCatalog::SOLL_EFFECT_CREDIT_IST && !$this->creditGateAllows($type)) {
				continue;
			}

			$absStart = $this->asDay($absence->getStartDate());
			$absEnd = $this->asDay($absence->getEndDate());
			$dayStart = $absStart > $rangeStart ? clone $absStart : clone $rangeStart;
			$dayEnd = $absEnd < $rangeEnd ? clone $absEnd : clone $rangeEnd;
			if ($dayEnd < $dayStart) {
				continue;
			}

			foreach ($this->absenceDayTargets($userId, $absence, $dayStart, $dayEnd) as $key => $dayPlan) {
				if (!isset($targetByDay[$key]) || $dayPlan[0] > $targetByDay[$key][0]) {
					$targetByDay[$key] = $dayPlan;
				}
			}
		}

		return $targetByDay;
	}

	/**
	 * Per-day absence targets for one absence clipped to the range.
	 *
	 * Returns Y-m-d => [fraction-adjusted absent target, full planned net].
	 * Hours-mode vacations carry `duration_hours` as the authoritative absent
	 * amount (the `days` column is only calendar weight there); it is spread
	 * over planned days by planned weight — the same partial-day ratio the
	 * vacation debit itself uses. Days mode falls back to the 0.5/1.0
	 * day-fraction derived from `days`.
	 *
	 * @return array<string, array{0: float, 1: float}>
	 */
	private function absenceDayTargets(
		string $userId,
		Absence $absence,
		\DateTime $dayStart,
		\DateTime $dayEnd,
	): array {
		/** @var array<string, float> $plannedByDay */
		$plannedByDay = [];
		$cursor = clone $dayStart;
		while ($cursor <= $dayEnd) {
			$planned = $this->plannedNetHoursForDay($userId, $cursor);
			if ($planned > 0.0001) {
				$plannedByDay[$cursor->format('Y-m-d')] = $planned;
			}
			$cursor->modify('+1 day');
		}
		if ($plannedByDay === []) {
			return [];
		}

		$duration = $absence->getDurationHours();
		$plannedTotal = array_sum($plannedByDay);
		if ($duration !== null && is_finite((float)$duration) && (float)$duration > 0.0001) {
			$ratio = min(1.0, (float)$duration / $plannedTotal);
			$targets = [];
			foreach ($plannedByDay as $key => $planned) {
				$targets[$key] = [$planned * $ratio, $planned];
			}
			return $targets;
		}

		$fraction = $this->dayFraction($userId, $absence);
		$targets = [];
		foreach ($plannedByDay as $key => $planned) {
			$targets[$key] = [$planned * $fraction, $planned];
		}
		return $targets;
	}

	/**
	 * Which gate governs a creditable type: leave types follow the
	 * leave-effects flag, the Entgeltausfall family (sick + custom
	 * `soll_credit`) follows the sick opt-in flag.
	 */
	private function creditGateAllows(string $type): bool
	{
		if (in_array($type, self::LEAVE_CREDIT_TYPES, true)) {
			return $this->isLeaveEffectsEnabled();
		}
		return $this->isEnabled();
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

		$onDemand = false;
		if ($schedule === null || $dailyHours === null) {
			[$schedule, $dailyHours, $onDemand] = $this->resolveContractForDate($userId, $day);
		}
		if ($onDemand) {
			// On-demand contract (Arbeit auf Abruf): no contractual Soll —
			// an approved absence must neither credit nor deduct phantom
			// hours. Early-return before the legacy `?: 8.0` substitution.
			return 0.0;
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
	 * Fraction of the planned target a single-day absence covers
	 * (AZC-HALF-DAY-NON-VACATION). Derived as `days / singleDayWeight` — the
	 * same weight authority the write path used — never a literal
	 * `days ≈ 0.5` test. That literal test double-halved on fraction-weighted
	 * days (a 0.25-stored half on a 0.5-weight holiday read as "full") and
	 * missed halves at weight 1.0.
	 *
	 * Guards: non-single-day and zero/near-zero weight stay 1.0 — a free day
	 * credits nothing anyway because planned net is 0. A stored debit above
	 * the recomputed weight (retro schedule change, EC-18) clamps to 1.0.
	 */
	private function dayFraction(string $userId, Absence $absence): float
	{
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		if ($start === null || $end === null) {
			return 1.0;
		}
		if ($start->format('Y-m-d') !== $end->format('Y-m-d')) {
			return 1.0;
		}
		$rawDays = $absence->getDays();
		if ($rawDays === null || !is_finite((float)$rawDays)) {
			return 1.0;
		}
		$weight = $this->workdayCounter !== null
			? $this->workdayCounter->daysForUser($userId, $start, $start)
			: $this->holidayService->computeWorkingDaysForUser($userId, $start, $start);
		if (!is_finite($weight) || $weight <= 0.011) {
			// Weight uncomputable (schedule source unavailable) — fall back
			// to the legacy literal check rather than losing the fraction;
			// a free day credits nothing anyway because planned net is 0.
			return abs((float)$rawDays - 0.5) < 0.011 ? 0.5 : 1.0;
		}
		$fraction = (float)$rawDays / $weight;
		if (!is_finite($fraction) || $fraction >= 1.0 - 0.011) {
			return 1.0;
		}
		return max(0.0, $fraction);
	}

	/**
	 * Contract hours effective on a specific calendar day.
	 *
	 * Resolves via the effective-dated assignment ({@see UserWorkingTimeModelMapper::findByUserAndDate})
	 * so a mid-period model change credits each day with its own Soll. Days
	 * without a covering assignment fall back to the current one, then to the
	 * legacy daily-hours default — identical to the previous behaviour.
	 *
	 * @return array{0: ?WeekdaySchedule, 1: float, 2: bool}
	 *   [schedule, dailyHours, isOnDemand] — the on-demand flag must be checked
	 *   BEFORE any daily-hours default substitution: an intentional zero Soll
	 *   is not "value missing → 8.0".
	 */
	private function resolveContractForDate(string $userId, \DateTimeInterface $date): array
	{
		$dailyHours = 8.0;
		try {
			$userModel = $this->userWorkingTimeModelMapper->findByUserAndDate($userId, $this->asDay($date))
				?? $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
			if ($userModel === null) {
				return [null, $dailyHours, false];
			}
			$model = $this->workingTimeModelMapper->find($userModel->getWorkingTimeModelId());
			if ($model->isOnDemand()) {
				return [$model->getWeekdaySchedule(), 0.0, true];
			}
			$schedule = $model->getWeekdaySchedule();
			if ($schedule !== null) {
				return [$schedule, $schedule->averageDailyNetHours(), false];
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

		return [null, $dailyHours, false];
	}

	private function asDay(\DateTimeInterface $d): \DateTime
	{
		$dt = $d instanceof \DateTime ? clone $d : new \DateTime($d->format('Y-m-d'));
		$dt->setTime(0, 0, 0);

		return $dt;
	}
}
