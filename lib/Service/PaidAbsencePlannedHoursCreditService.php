<?php

declare(strict_types=1);

/**
 * Absence Soll effects for the flextime balance (Entgeltausfall / Ausfallprinzip).
 *
 * Two gates, one matrix ({@see AbsenceTypeCatalog::sollEffect}):
 *
 * - `paid_absence_planned_hours_credit` (opt-in, default off): approved
 *   `sick_leave` / `child_sick` on a planned day (weekday schedule, DutyCheck
 *   rotation incl. Sat/Sun, or legacy Mon–Fri daily hours) add those planned
 *   net hours to overtime Ist.
 * - `leave_soll_effects` (default on): approved `vacation` / `personal_leave` /
 *   `special_leave` credit the same way — leave pay continuation is not an org
 *   policy choice — while `unpaid_leave` / `parental_leave` *reduce* the day's
 *   Soll instead of crediting Ist (contract suspended: neutral, never paid).
 * - Custom types with `soll_credit` credit when EITHER gate is on (OR): the
 *   flag is an explicit "paid absence" intent that belongs to the leave
 *   family, so `leave_soll_effects` covers them and the sick opt-in keeps
 *   working for orgs that enabled it before the split.
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
	 * opt-in. Custom types credit under an OR of both gates — their
	 * `soll_credit` flag is explicit admin intent for paid leave (A-R1).
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
	 * Explainability breakdown: decomposes the credited/reduced hours into
	 * per-absence (and per-day) contributions plus the reasons absences did
	 * NOT count. Totals are computed from the same merged day maps as
	 * {@see creditHoursForRange()} / {@see sollReductionHoursForRange()} with
	 * the identical formula and rounding, so `credit_hours` / `reduced_hours`
	 * / `days_*` reconcile exactly with the dashboard aggregate — the
	 * breakdown can never disagree with the number the tile shows.
	 *
	 * `not_counted` reason codes (stable wire contract for web + companion):
	 *  - not_approved     — pending/declined/cancelled absences never count
	 *  - effect_none      — type is worked/neutral by design (business trip,
	 *                       home office, overtime compensation)
	 *  - type_not_paid    — custom type without the "counts as paid absence"
	 *                       flag; it is worked/neutral by admin choice
	 *  - gate_disabled    — type would count but the org policy gate is off;
	 *                       carries an extra `gate` field with the config key
	 *                       of the recommended setting
	 *  - no_planned_hours — creditable, but no planned work time on those
	 *                       days (weekend, holiday, on-demand contract)
	 *  - fully_worked     — creditable days, but clocked time already
	 *                       covered the planned target everywhere
	 *  - overlap          — another absence won the per-day merge for every
	 *                       produced day (legacy/imported overlaps)
	 *
	 * @param array<string, float> $workedHoursByDate Y-m-d => completed worked hours that day
	 * @return array{
	 *   credit_hours: float,
	 *   days_credited: int,
	 *   reduced_hours: float,
	 *   days_reduced: int,
	 *   credited: list<array{absence_id: int|null, type: string, status: string, start: string, end: string, credit_hours: float, overlap_days: int, unplanned_days: int, days: list<array{date: string, planned: float, absent: float, worked: float, credited: float}>}>,
	 *   reduced: list<array{absence_id: int|null, type: string, status: string, start: string, end: string, reduced_hours: float, overlap_days: int, unplanned_days: int, days: list<array{date: string, planned: float, absent: float, reduced: float}>}>,
	 *   not_counted: list<array{absence_id: int|null, type: string, status: string, start: string, end: string, reason: string, overlap_days: int, unplanned_days?: int}>
	 * }
	 */
	public function creditBreakdownForRange(
		string $userId,
		\DateTimeInterface $periodStart,
		\DateTimeInterface $periodEnd,
		array $workedHoursByDate = [],
	): array {
		$result = [
			'credit_hours' => 0.0,
			'days_credited' => 0,
			'reduced_hours' => 0.0,
			'days_reduced' => 0,
			'credited' => [],
			'reduced' => [],
			'not_counted' => [],
		];
		if ($userId === '') {
			return $result;
		}

		$rangeStart = $this->asDay($periodStart);
		$rangeEnd = $this->asDay($periodEnd);
		if ($rangeEnd < $rangeStart) {
			return $result;
		}

		$absences = $this->absenceMapper->findByUserAndDateRange($userId, $rangeStart, $rangeEnd);
		$leaveEffectsOn = $this->isLeaveEffectsEnabled();
		/** @var array<string, array<int, array{absence: Absence, produced: array<string, array{0: float, 1: float}>}>> */
		$candidates = [
			AbsenceTypeCatalog::SOLL_EFFECT_CREDIT_IST => [],
			AbsenceTypeCatalog::SOLL_EFFECT_REDUCE_SOLL => [],
		];

		foreach ($absences as $absence) {
			if (!$absence instanceof Absence) {
				continue;
			}
			if ($absence->getStatus() !== Absence::STATUS_APPROVED) {
				$result['not_counted'][] = $this->breakdownNotCounted($absence, 'not_approved');
				continue;
			}
			$effect = AbsenceTypeCatalog::sollEffect($absence->getType());
			if ($effect === AbsenceTypeCatalog::SOLL_EFFECT_NONE) {
				$reason = AbsenceTypeCatalog::isCustom($absence->getType())
					? 'type_not_paid'
					: 'effect_none';
				$result['not_counted'][] = $this->breakdownNotCounted($absence, $reason);
				continue;
			}
			if ($effect === AbsenceTypeCatalog::SOLL_EFFECT_CREDIT_IST && !$this->creditGateAllows($absence->getType())) {
				$item = $this->breakdownNotCounted($absence, 'gate_disabled');
				$item['gate'] = $this->creditGateRecommendation($absence->getType());
				$result['not_counted'][] = $item;
				continue;
			}
			if ($effect === AbsenceTypeCatalog::SOLL_EFFECT_REDUCE_SOLL && !$leaveEffectsOn) {
				$item = $this->breakdownNotCounted($absence, 'gate_disabled');
				$item['gate'] = Constants::CONFIG_LEAVE_SOLL_EFFECTS;
				$result['not_counted'][] = $item;
				continue;
			}

			$dayStart = $this->asDay($absence->getStartDate());
			$dayEnd = $this->asDay($absence->getEndDate());
			$dayStart = $dayStart > $rangeStart ? $dayStart : clone $rangeStart;
			$dayEnd = $dayEnd < $rangeEnd ? $dayEnd : clone $rangeEnd;
			if ($dayEnd < $dayStart) {
				continue;
			}
			$produced = $this->absenceDayTargets($userId, $absence, $dayStart, $dayEnd);
			if ($produced === []) {
				$item = $this->breakdownNotCounted($absence, 'no_planned_hours');
				$item['unplanned_days'] = $this->daysInSpan($dayStart, $dayEnd);
				$result['not_counted'][] = $item;
				continue;
			}
			$candidates[$effect][$this->breakdownKey($absence)] = [
				'absence' => $absence,
				'produced' => $produced,
				'span_days' => $this->daysInSpan($dayStart, $dayEnd),
			];
		}

		// Merge the candidates' own produced day maps — identical rule as the
		// aggregate (per-day max wins) but single-fetch and single-compute:
		// no second DB round-trip can make the headline drift from the items,
		// and winning rows carry the *same* Absence instance as the candidate,
		// so attribution cannot drift either.
		$creditWin = $this->mergeDayTargets($candidates[AbsenceTypeCatalog::SOLL_EFFECT_CREDIT_IST]);
		$reduceWin = $this->mergeDayTargets($candidates[AbsenceTypeCatalog::SOLL_EFFECT_REDUCE_SOLL]);

		/** @var array<int|string, array<string, array{target: float, planned: float, absence: Absence}>> */
		$wonCredit = [];
		foreach ($creditWin as $key => $row) {
			$wonCredit[$this->breakdownKey($row['absence'])][$key] = $row;
			$worked = max(0.0, (float)($workedHoursByDate[$key] ?? 0.0));
			$credit = max(0.0, min($row['target'], $row['planned'] - $worked));
			if ($credit > 0.0001) {
				// Sub-epsilon dust is excluded exactly like the aggregate —
				// identical filter, identical headline.
				$result['credit_hours'] += $credit;
				$result['days_credited']++;
			}
		}
		$result['credit_hours'] = round($result['credit_hours'], 4);

		/** @var array<int|string, array<string, array{target: float, planned: float, absence: Absence}>> */
		$wonReduce = [];
		foreach ($reduceWin as $key => $row) {
			$wonReduce[$this->breakdownKey($row['absence'])][$key] = $row;
			$result['reduced_hours'] += $row['target'];
		}
		$result['reduced_hours'] = round($result['reduced_hours'], 4);
		$result['days_reduced'] = count($reduceWin);

		foreach ($candidates[AbsenceTypeCatalog::SOLL_EFFECT_CREDIT_IST] as $oid => $cand) {
			$won = $wonCredit[$oid] ?? [];
			$unplanned = $cand['span_days'] - count($cand['produced']);
			if ($won === []) {
				$item = $this->breakdownNotCounted($cand['absence'], 'overlap');
				$item['overlap_days'] = count($cand['produced']);
				$item['unplanned_days'] = $unplanned;
				$result['not_counted'][] = $item;
				continue;
			}
			$rows = [];
			$itemTotal = 0.0;
			foreach ($won as $key => $row) {
				$worked = max(0.0, (float)($workedHoursByDate[$key] ?? 0.0));
				$credit = max(0.0, min($row['target'], $row['planned'] - $worked));
				if ($credit > 0.0001) {
					// Same sub-epsilon filter as the headline — sum(items)
					// must equal credit_hours, day rows stay for context.
					$itemTotal += $credit;
				}
				$rows[] = [
					'date' => $key,
					'planned' => round($row['planned'], 4),
					'absent' => round($row['target'], 4),
					'worked' => round($worked, 4),
					'credited' => round($credit, 4),
				];
			}
			usort($rows, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
			if ($itemTotal <= 0.0001) {
				$item = $this->breakdownNotCounted($cand['absence'], 'fully_worked');
				$item['overlap_days'] = count($cand['produced']) - count($won);
				$item['unplanned_days'] = $unplanned;
				$result['not_counted'][] = $item;
				continue;
			}
			$item = $this->breakdownItemBase($cand['absence']);
			$item['credit_hours'] = round($itemTotal, 4);
			$item['overlap_days'] = count($cand['produced']) - count($won);
			$item['unplanned_days'] = $unplanned;
			$item['days'] = $rows;
			$result['credited'][] = $item;
		}

		foreach ($candidates[AbsenceTypeCatalog::SOLL_EFFECT_REDUCE_SOLL] as $oid => $cand) {
			$won = $wonReduce[$oid] ?? [];
			$unplanned = $cand['span_days'] - count($cand['produced']);
			if ($won === []) {
				$item = $this->breakdownNotCounted($cand['absence'], 'overlap');
				$item['overlap_days'] = count($cand['produced']);
				$item['unplanned_days'] = $unplanned;
				$result['not_counted'][] = $item;
				continue;
			}
			$rows = [];
			$itemTotal = 0.0;
			foreach ($won as $key => $row) {
				$itemTotal += $row['target'];
				$rows[] = [
					'date' => $key,
					'planned' => round($row['planned'], 4),
					'absent' => round($row['target'], 4),
					'reduced' => round($row['target'], 4),
				];
			}
			usort($rows, static fn (array $a, array $b): int => strcmp($a['date'], $b['date']));
			$item = $this->breakdownItemBase($cand['absence']);
			$item['reduced_hours'] = round($itemTotal, 4);
			$item['overlap_days'] = count($cand['produced']) - count($won);
			$item['unplanned_days'] = $unplanned;
			$item['days'] = $rows;
			$result['reduced'][] = $item;
		}

		return $result;
	}

	/**
	 * Attribution key stable across separate hydration rounds: the merge map
	 * re-fetches absences from the mapper, so the winning entity is a
	 * different PHP instance than the candidate's — identity
	 * (spl_object_id) never matches across two DB round-trips. The row id
	 * does. Unpersisted entities (unit fixtures) fall back to object
	 * identity, which is stable within one request.
	 *
	 * @return int|string
	 */
	private function breakdownKey(Absence $absence): int|string
	{
		return $absence->getId() ?? spl_object_id($absence);
	}

	/**
	 * Whole calendar days an in-range absence span covers — the denominator
	 * for `unplanned_days` (span days that produced no planned target).
	 */
	private function daysInSpan(\DateTime $dayStart, \DateTime $dayEnd): int
	{
		return $dayEnd->diff($dayStart)->days + 1;
	}

	/**
	 * Per-day max merge over already-produced day maps — THE merge rule,
	 * shared by the aggregate ({@see detailedTargetsForEffect}) and the
	 * breakdown so totals can never drift apart.
	 *
	 * @param array<int|string, array{absence: Absence, produced: array<string, array{0: float, 1: float}>}> $perAbsence
	 * @return array<string, array{target: float, planned: float, absence: Absence}>
	 */
	private function mergeDayTargets(array $perAbsence): array
	{
		$targetByDay = [];
		foreach ($perAbsence as $cand) {
			foreach ($cand['produced'] as $key => $dayPlan) {
				if (!isset($targetByDay[$key]) || $dayPlan[0] > $targetByDay[$key]['target']) {
					$targetByDay[$key] = [
						'target' => $dayPlan[0],
						'planned' => $dayPlan[1],
						'absence' => $cand['absence'],
					];
				}
			}
		}
		return $targetByDay;
	}

	/**
	 * Shared item shape for breakdown rows. Dates are the absence's own
	 * span — the `days` list shows which in-range days actually counted.
	 *
	 * @return array{absence_id: int|null, type: string, status: string, start: string, end: string}
	 */
	private function breakdownItemBase(Absence $absence): array
	{
		return [
			'absence_id' => $absence->getId(),
			'type' => (string)$absence->getType(),
			'status' => (string)$absence->getStatus(),
			'start' => $this->asDay($absence->getStartDate())->format('Y-m-d'),
			'end' => $this->asDay($absence->getEndDate())->format('Y-m-d'),
		];
	}

	/**
	 * @return array{absence_id: int|null, type: string, status: string, start: string, end: string, reason: string, overlap_days: int}
	 */
	private function breakdownNotCounted(Absence $absence, string $reason): array
	{
		return $this->breakdownItemBase($absence) + ['reason' => $reason, 'overlap_days' => 0];
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
		foreach ($this->detailedTargetsForEffect($userId, $rangeStart, $rangeEnd, $effect, $respectGates) as $key => $row) {
			$targetByDay[$key] = [$row['target'], $row['planned']];
		}
		return $targetByDay;
	}

	/**
	 * Attribution-preserving variant of {@see plannedTargetsForEffect()}:
	 * same per-day max merge, but each winning day also carries the absence
	 * that produced it — the single source of truth for the balance figure
	 * AND the "where does this number come from" breakdown.
	 *
	 * @return array<string, array{target: float, planned: float, absence: Absence}>
	 */
	private function detailedTargetsForEffect(
		string $userId,
		\DateTime $rangeStart,
		\DateTime $rangeEnd,
		string $effect,
		bool $respectGates = true,
	): array {
		$perAbsence = [];

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

			$perAbsence[] = [
				'absence' => $absence,
				'produced' => $this->absenceDayTargets($userId, $absence, $dayStart, $dayEnd),
			];
		}

		return $this->mergeDayTargets($perAbsence);
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

		// Hour-mode rows (optional workforce spec §9.2): integer minutes are
		// the persisted truth — the absent target is min(M, planned) on the
		// single day, never a days/weight ratio derivation (NN-04).
		if ($absence->getDurationMode() === \OCA\ArbeitszeitCheck\Constants::ABSENCE_DURATION_MODE_HOURS) {
			$minutes = $absence->getDurationMinutes();
			if ($minutes === null || $minutes <= 0) {
				return [];
			}
			$targets = [];
			foreach ($plannedByDay as $key => $planned) {
				$targets[$key] = [min($minutes / 60.0, $planned), $planned];
			}
			return $targets;
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

		// Per-day fraction (AZC-HALF-DAY-SLOTS-BOUNDARIES): explicit stored
		// boundary fractions are the truth; NULL-column legacy rows fall back
		// to the single-day days/weight derivation.
		$legacyFraction = $this->dayFraction($userId, $absence);
		$targets = [];
		foreach ($plannedByDay as $key => $planned) {
			$explicit = \OCA\ArbeitszeitCheck\Support\AbsenceDayShape::fractionForBoundary($absence, new \DateTime($key));
			$fraction = $explicit !== null ? $explicit : $legacyFraction;
			$targets[$key] = [$planned * max(0.0, min(1.0, $fraction)), $planned];
		}
		return $targets;
	}

	/**
	 * Which gate governs a creditable type: leave types follow the
	 * leave-effects flag; sick builtins stay strictly behind the sick
	 * opt-in; custom `soll_credit` types open when EITHER gate is on —
	 * their paid flag is an explicit admin intent (like paid leave), and
	 * both gates disable them only together.
	 */
	private function creditGateAllows(string $type): bool
	{
		if (in_array($type, self::LEAVE_CREDIT_TYPES, true)) {
			return $this->isLeaveEffectsEnabled();
		}
		if (AbsenceTypeCatalog::isCustom($type)) {
			return $this->isEnabled() || $this->isLeaveEffectsEnabled();
		}
		return $this->isEnabled();
	}

	/**
	 * Config key of the setting the org should open to unblock a
	 * gate-disabled credit — surfaced as `gate` on not_counted breakdown
	 * items (A-R5). Custom types recommend the leave gate because their
	 * `soll_credit` flag marks them as paid leave; sick builtins keep
	 * their dedicated opt-in.
	 */
	private function creditGateRecommendation(string $type): string
	{
		if (in_array($type, self::LEAVE_CREDIT_TYPES, true) || AbsenceTypeCatalog::isCustom($type)) {
			return Constants::CONFIG_LEAVE_SOLL_EFFECTS;
		}
		return Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT;
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
			// Strict money-path resolution: covering row → its contract;
			// no covering row + rows exist → gap policy (strict: Soll 0).
			$contract = $this->assignmentResolver()->resolveStrict($userId, $this->asDay($date));
			if ($contract->basis === WorkingTimeModelAssignmentResolver::BASIS_ON_DEMAND
				|| $contract->basis === WorkingTimeModelAssignmentResolver::BASIS_UNASSIGNED_GAP) {
				return [$contract->schedule, 0.0, true];
			}
			if ($contract->schedule !== null) {
				return [$contract->schedule, $contract->schedule->averageDailyNetHours(), false];
			}
			$modelDaily = $contract->dailyHours;
			if (is_finite($modelDaily) && $modelDaily > 0.0 && $modelDaily <= 24.0) {
				$dailyHours = $modelDaily;
			} else {
				// daily_hours > 24 is physically impossible: the column had
				// no upper bound, so legacy rows may carry garbage that
				// would credit fantasy hours per absence day. Derive the
				// day Soll from the weekly contract — the same basis the
				// required-hours formula uses — before the 8 h default.
				$modelWeekly = $contract->weeklyHours;
				$derived = is_finite($modelWeekly) ? $modelWeekly / 5.0 : 0.0;
				if ($derived > 0.0 && $derived <= 24.0) {
					$dailyHours = $derived;
				}
			}
		} catch (\Throwable) {
			// defaults
		}

		return [null, $dailyHours, false];
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

	private function asDay(\DateTimeInterface $d): \DateTime
	{
		$dt = $d instanceof \DateTime ? clone $d : new \DateTime($d->format('Y-m-d'));
		$dt->setTime(0, 0, 0);

		return $dt;
	}
}
