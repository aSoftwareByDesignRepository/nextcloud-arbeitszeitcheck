<?php

declare(strict_types=1);

/**
 * Planned end-of-day resolution for AutoClockOutJob.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Resolves the planned end-of-day instant for an open time entry.
 *
 * Fail-closed by design: whenever a planned end cannot be derived from the
 * user's effective working-time model — no assignment, on-demand contract,
 * non-working day, holiday, approved absence, or a schedule without an end
 * — the resolver returns a skip reason instead of guessing. A guessed end
 * would corrupt payroll, so the job must skip rather than improvise.
 */
final class PlannedEndResolver
{
	public const SKIP_MISSING_START = 'missing_start';
	public const SKIP_NO_MODEL = 'no_model';
	public const SKIP_ON_DEMAND_MODEL = 'on_demand_model';
	public const SKIP_HOLIDAY = 'holiday';
	public const SKIP_ABSENCE_DAY = 'absence_day';
	public const SKIP_NO_PLANNED_END = 'no_planned_end';
	public const SKIP_INVALID_PLANNED_END = 'invalid_planned_end';
	public const SKIP_PLANNED_END_AFTER_CUTOVER = 'planned_end_after_cutover';

	/** ISO-8601 weekday number (1=Mon … 7=Sun) → WeekdaySchedule day key. */
	private const N_TO_WEEKDAY = [
		1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun',
	];

	public function __construct(
		private UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private WorkingTimeModelMapper $workingTimeModelMapper,
		private AbsenceMapper $absenceMapper,
		private HolidayService $holidayService,
		private BreakPolicyResolver $breakPolicyResolver,
		private TimeZoneService $timeZoneService,
	) {
	}

	/**
	 * Resolve the planned end for the calendar day (storage TZ) on which
	 * $entry started.
	 *
	 * @param \DateTimeInterface $cutover Latest instant the job is allowed to
	 *   close sessions at (configured cutover hour of the current run, in
	 *   storage TZ). A planned end later than this is skipped so an early
	 *   planned end is never applied before it is actually reached.
	 * @param array<int, ?\OCA\ArbeitszeitCheck\Db\WorkingTimeModel> $modelCache
	 *   Per-run memo of model id → model entity (null = known-missing). Many
	 *   employees share one model; the job reuses this across all users.
	 * @return array{end: ?\DateTime, skip: ?string} Exactly one key is set.
	 */
	public function resolve(string $userId, TimeEntry $entry, \DateTimeInterface $cutover, array &$modelCache = []): array
	{
		$start = $entry->getStartTime();
		if (!$start instanceof \DateTimeInterface) {
			return $this->skip(self::SKIP_MISSING_START);
		}
		[$dayStart] = $this->timeZoneService->dayWindowInStorage($start);

		$model = $this->effectiveModel($userId, $dayStart, $modelCache);
		if ($model === null) {
			return $this->skip(self::SKIP_NO_MODEL);
		}
		if ($model->isOnDemand()) {
			// On-demand contracts have no planned day shape — nothing to aim at.
			return $this->skip(self::SKIP_ON_DEMAND_MODEL);
		}

		if ($this->holidayService->isHolidayForUser($userId, $dayStart)) {
			return $this->skip(self::SKIP_HOLIDAY);
		}
		// The absence window is the entry's calendar day only — an
		// inclusive date-range query widened to the next midnight would
		// let leave that merely STARTS tomorrow veto today's closure.
		if ($this->hasApprovedAbsence($userId, $dayStart)) {
			return $this->skip(self::SKIP_ABSENCE_DAY);
		}

		$weekdayKey = self::N_TO_WEEKDAY[(int)$dayStart->format('N')] ?? 'mon';
		$end = $this->plannedEndFromModel($model->getWeekdaySchedule(), $model->getDailyHours(), $weekdayKey, $dayStart, $start, $userId);
		if ($end === null) {
			return $this->skip(self::SKIP_NO_PLANNED_END);
		}
		if ($end->getTimestamp() <= $start->getTimestamp()) {
			return $this->skip(self::SKIP_INVALID_PLANNED_END);
		}
		if ($end->getTimestamp() > $cutover->getTimestamp()) {
			// Planned end is still ahead of the cutover — wait for a later run.
			return $this->skip(self::SKIP_PLANNED_END_AFTER_CUTOVER);
		}

		return ['end' => $end, 'skip' => null];
	}

	/**
	 * Effective working-time model on the entry's calendar day. A deleted
	 * model, an expired assignment and a lookup failure all collapse to
	 * "no model" — the resolver may not guess.
	 */
	private function effectiveModel(string $userId, \DateTime $dayStart, array &$modelCache): ?\OCA\ArbeitszeitCheck\Db\WorkingTimeModel
	{
		try {
			// Lenient assist resolution: uncovered days fall back to the
			// assignment active today — a suggestion must never guess, but it
			// also must not collapse just because a window ended.
			$model = $this->assignmentResolver()->resolveLenient($userId, $dayStart)->model;
			if ($model === null) {
				return null;
			}
			$modelId = (int)$model->getId();
			if (array_key_exists($modelId, $modelCache)) {
				return $modelCache[$modelId];
			}
			return $modelCache[$modelId] = $model;
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

	private function hasApprovedAbsence(string $userId, \DateTime $day): bool
	{
		foreach ($this->absenceMapper->findByUserAndDateRange($userId, $day, $day) as $absence) {
			if ($absence->getStatus() === Absence::STATUS_APPROVED) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Derive the planned end instant from the model. A fixed weekday
	 * schedule always wins — its last block end is the wall-clock end the
	 * employee and payroll expect. Only a model without a weekday schedule
	 * falls back to `start + dailyHours + required break`.
	 */
	private function plannedEndFromModel(
		?WeekdaySchedule $schedule,
		float $dailyHours,
		string $weekdayKey,
		\DateTime $dayStart,
		\DateTimeInterface $start,
		string $userId
	): ?\DateTime {
		if ($schedule !== null) {
			$blocks = $schedule->blocksForWeekday($weekdayKey);
			if ($blocks === []) {
				// Weekday/weekend row without scheduled work on this day.
				return null;
			}
			$endMinutes = (int)end($blocks)[1];
			return (clone $dayStart)->modify('+' . $endMinutes . ' minutes');
		}

		$weekday = (int)$dayStart->format('N');
		if ($weekday >= 6 || $dailyHours <= 0.0) {
			return null;
		}
		// Flexible model: net target plus the unpaid break the law/org/model
		// demands for that net duration defines the planned wall-clock end.
		$netSeconds = (int)round($dailyHours * 3600);
		$breakSeconds = $this->breakPolicyResolver->requiredBreakMinutes($netSeconds, $userId) * 60;
		// Mutable \DateTime on purpose: callers hand the instant to mapper
		// APIs typed \DateTime (findOverlapping) — an immutable here would
		// TypeError in the job.
		return (new \DateTime('@' . $start->getTimestamp()))
			->setTimezone($this->timeZoneService->storageTimeZone())
			->modify('+' . ($netSeconds + $breakSeconds) . ' seconds');
	}

	/** @return array{end: null, skip: string} */
	private function skip(string $reason): array
	{
		return ['end' => null, 'skip' => $reason];
	}
}
