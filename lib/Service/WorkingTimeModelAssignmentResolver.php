<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Support\AssignmentSegment;
use OCA\ArbeitszeitCheck\Support\ResolvedWorkingTimeContract;
use OCP\IConfig;

/**
 * Single resolution authority for "which working-time contract applies on day X".
 *
 * Assignments are effective-dated rows in `at_user_models`. Every consumer of
 * contract/Soll data resolves through this service — never through ad-hoc
 * `findCurrentByUser`/`findByUserAndDate` chains — so a mid-period model
 * change can no longer retroactively stretch today's model over the whole
 * range.
 *
 * Two modes:
 *  - strict (money path): a day with no covering assignment resolves to the
 *    org gap policy — `strict` ⇒ Soll 0 (`unassigned_gap`), `lenient` ⇒ the
 *    pre-feature fallback chain (assignment active today, else legacy).
 *  - lenient (assist/UI): uncovered days fall back to the assignment active
 *    today, then legacy defaults; `lenientFallback` marks the provenance.
 */
final class WorkingTimeModelAssignmentResolver
{
	public const BASIS_WEEKDAY_SCHEDULE = 'weekday_schedule';
	public const BASIS_WEEKLY_CONTRACT = 'weekly_contract';
	public const BASIS_ON_DEMAND = 'on_demand';
	public const BASIS_UNASSIGNED_GAP = 'unassigned_gap';
	public const BASIS_DEGRADED = 'degraded';
	public const BASIS_SEGMENTED = 'segmented';

	public const GAP_POLICY_LENIENT = 'lenient';
	public const GAP_POLICY_STRICT = 'strict';
	public const CONFIG_GAP_POLICY = 'assignments_gap_policy';

	/** Legacy fallback contract (pre-feature behaviour, kept byte-identical). */
	private const LEGACY_DAILY_HOURS = 8.0;
	private const LEGACY_WEEKLY_HOURS = 40.0;

	/** @var array<string, list<UserWorkingTimeModel>|null> userId => rows */
	private array $assignmentsByUser = [];

	/** @var array<int, WorkingTimeModel|null> model id => model (null = missing) */
	private array $modelsById = [];

	/** @var array<int, ResolvedWorkingTimeContract|null> model id => contract */
	private array $contractsByModelId = [];

	private ?bool $orgHasWorkingTimeModels = null;
	private ?bool $gapPolicyStrict = null;

	public function __construct(
		private UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private WorkingTimeModelMapper $workingTimeModelMapper,
		private ?IConfig $config = null,
	) {
	}

	public function isGapPolicyStrict(): bool
	{
		if ($this->gapPolicyStrict === null) {
			$value = $this->config !== null
				? (string)$this->config->getAppValue(
					'arbeitszeitcheck',
					self::CONFIG_GAP_POLICY,
					self::GAP_POLICY_LENIENT
				)
				: self::GAP_POLICY_LENIENT;
			$this->gapPolicyStrict = $value === self::GAP_POLICY_STRICT;
		}
		return $this->gapPolicyStrict;
	}

	/**
	 * Drop the request-local memoization for one user. Call after assignment
	 * writes inside the same request (write service + tests).
	 */
	public function invalidateUser(string $userId): void
	{
		unset($this->assignmentsByUser[$userId]);
	}

	/**
	 * Money path resolution for a single day.
	 *
	 * covering row → its model contract; no covering row ∧ ≥1 row exists →
	 * policy branch (`strict` ⇒ `unassigned_gap`, `lenient` ⇒ lenient chain);
	 * zero rows → legacy defaults (`degraded` when the org configures models).
	 */
	public function resolveStrict(string $userId, \DateTime $day): ResolvedWorkingTimeContract
	{
		$covering = $this->coveringAssignment($userId, $day);
		if ($covering !== null) {
			return $this->contractFromAssignment($covering);
		}
		if (!$this->hasAnyRows($userId)) {
			return $this->legacyContract();
		}
		if ($this->isGapPolicyStrict()) {
			return new ResolvedWorkingTimeContract(
				null, null, 0.0, 0.0,
				self::BASIS_UNASSIGNED_GAP, null, false
			);
		}
		return $this->lenientFallbackContract($userId);
	}

	/**
	 * Assist/UI path: uncovered days fall back to the assignment active today
	 * (matching legacy `findCurrentByUser` semantics), then to legacy
	 * defaults. `lenientFallback` flags when no covering row produced the
	 * result.
	 */
	public function resolveLenient(string $userId, \DateTime $day): ResolvedWorkingTimeContract
	{
		$covering = $this->coveringAssignment($userId, $day);
		if ($covering !== null) {
			return $this->contractFromAssignment($covering);
		}
		if (!$this->hasAnyRows($userId)) {
			return $this->legacyContract();
		}
		return $this->lenientFallbackContract($userId);
	}

	/**
	 * Resolve a whole range into contiguous segments of constant contract.
	 * Segments are merged by resolved-contract equality, so two adjacent rows
	 * pointing at the same model produce one segment while a real model
	 * change produces a boundary.
	 *
	 * @return list<AssignmentSegment>
	 */
	public function resolveRange(string $userId, \DateTime $start, \DateTime $end): array
	{
		$cursor = (clone $start)->setTime(0, 0, 0);
		$last = (clone $end)->setTime(0, 0, 0);
		if ($last < $cursor) {
			return [];
		}

		$segments = [];
		$segStart = clone $cursor;
		$prevContract = $this->resolveStrict($userId, $cursor);
		while ($cursor < $last) {
			$cursor->modify('+1 day');
			$contract = $this->resolveStrict($userId, $cursor);
			if (!$contract->isEquivalentTo($prevContract)) {
				$segments[] = new AssignmentSegment(
					clone $segStart,
					(clone $cursor)->modify('-1 day'),
					$prevContract
				);
				$segStart = clone $cursor;
				$prevContract = $contract;
			}
		}
		$segments[] = new AssignmentSegment($segStart, clone $last, $prevContract);

		return $segments;
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * All assignment rows for the user, memoized per request. Malformed rows
	 * (null start, end before start) are kept — they still count as "a row
	 * exists" for the gap-policy branch, they simply cannot cover a day.
	 *
	 * @return list<UserWorkingTimeModel>
	 */
	private function assignmentsFor(string $userId): array
	{
		if (!array_key_exists($userId, $this->assignmentsByUser)) {
			try {
				$this->assignmentsByUser[$userId] = $this->userWorkingTimeModelMapper->findByUser($userId);
			} catch (\Throwable) {
				$this->assignmentsByUser[$userId] = [];
			}
		}
		return $this->assignmentsByUser[$userId] ?? [];
	}

	private function hasAnyRows(string $userId): bool
	{
		return $this->assignmentsFor($userId) !== [];
	}

	/**
	 * Winning covering row for a day. E-13: several rows covering the same
	 * day → the one with the latest start_date wins, ties by highest id.
	 */
	private function coveringAssignment(string $userId, \DateTime $day): ?UserWorkingTimeModel
	{
		$dayYmd = $day->format('Y-m-d');
		$winner = null;
		$winnerStart = '';
		$winnerId = -1;
		foreach ($this->assignmentsFor($userId) as $row) {
			$start = $row->getStartDate();
			if ($start === null) {
				continue; // malformed — cannot cover
			}
			$startYmd = $start->format('Y-m-d');
			if ($startYmd > $dayYmd) {
				continue;
			}
			$end = $row->getEndDate();
			if ($end !== null && $end->format('Y-m-d') < $dayYmd) {
				continue;
			}
			$rowId = (int)$row->getId();
			if ($winner === null
				|| $startYmd > $winnerStart
				|| ($startYmd === $winnerStart && $rowId > $winnerId)) {
				$winner = $row;
				$winnerStart = $startYmd;
				$winnerId = $rowId;
			}
		}
		return $winner;
	}

	/**
	 * Lenient fallback: the assignment active today (legacy
	 * `findCurrentByUser` equivalent — same covering-tiebreak, which matches
	 * "latest start wins"), else legacy defaults. Always flagged as fallback.
	 */
	private function lenientFallbackContract(string $userId): ResolvedWorkingTimeContract
	{
		$today = new \DateTime('today');
		$current = $this->coveringAssignment($userId, $today);
		if ($current !== null) {
			$contract = $this->contractFromAssignment($current);
			return new ResolvedWorkingTimeContract(
				$contract->model, $contract->schedule, $contract->dailyHours,
				$contract->weeklyHours, $contract->basis, $contract->assignmentId, true,
				$contract->vacationDaysPerYear
			);
		}
		$legacy = $this->legacyContract();
		return new ResolvedWorkingTimeContract(
			$legacy->model, $legacy->schedule, $legacy->dailyHours,
			$legacy->weeklyHours, $legacy->basis, null, true
		);
	}

	private function contractFromAssignment(UserWorkingTimeModel $assignment): ResolvedWorkingTimeContract
	{
		$model = $this->modelById($assignment->getWorkingTimeModelId());
		if ($model === null) {
			// Assigned but model row missing/gone: keep legacy defaults,
			// mark degraded (same contract the pre-feature code emitted).
			return new ResolvedWorkingTimeContract(
				null, null, self::LEGACY_DAILY_HOURS, self::LEGACY_WEEKLY_HOURS,
				self::BASIS_DEGRADED, $assignment->getId()
			);
		}
		$contract = $this->contractFromModel($model, $assignment->getId());
		// vacationDaysPerYear lives on the assignment row, not the model —
		// carry it so entitlement resolution keeps per-window pro-ration.
		return new ResolvedWorkingTimeContract(
			$contract->model, $contract->schedule, $contract->dailyHours,
			$contract->weeklyHours, $contract->basis, $contract->assignmentId,
			$contract->lenientFallback,
			$assignment->getVacationDaysPerYear() === null
				? null
				: (float)$assignment->getVacationDaysPerYear()
		);
	}

	private function contractFromModel(WorkingTimeModel $model, ?int $assignmentId): ResolvedWorkingTimeContract
	{
		$modelId = (int)$model->getId();
		if (!array_key_exists($modelId, $this->contractsByModelId)) {
			$this->contractsByModelId[$modelId] = $this->buildContract($model);
		}
		$base = $this->contractsByModelId[$modelId];
		return new ResolvedWorkingTimeContract(
			$base->model, $base->schedule, $base->dailyHours,
			$base->weeklyHours, $base->basis, $assignmentId
		);
	}

	private function buildContract(WorkingTimeModel $model): ResolvedWorkingTimeContract
	{
		if ($model->isOnDemand()) {
			// Arbeit auf Abruf: no contractual Soll — only worked time counts.
			return new ResolvedWorkingTimeContract(
				$model, null, 0.0, 0.0, self::BASIS_ON_DEMAND, null
			);
		}
		$schedule = $model->getWeekdaySchedule();
		if ($schedule !== null) {
			return new ResolvedWorkingTimeContract(
				$model, $schedule,
				$schedule->averageDailyNetHours(), $schedule->weeklyNetHours(),
				self::BASIS_WEEKDAY_SCHEDULE, null
			);
		}
		$daily = (float)$model->getDailyHours();
		$weekly = (float)$model->getWeeklyHours();
		if ($daily <= 0.0 || $weekly <= 0.0 || !is_finite($daily) || !is_finite($weekly)) {
			// Corrupt scalar contract: Soll would silently collapse to 0 like
			// on_demand — mark degraded so bad data stays distinguishable.
			return new ResolvedWorkingTimeContract(
				$model, null, $daily, $weekly, self::BASIS_DEGRADED, null
			);
		}
		return new ResolvedWorkingTimeContract(
			$model, null, $daily, $weekly, self::BASIS_WEEKLY_CONTRACT, null
		);
	}

	private function legacyContract(): ResolvedWorkingTimeContract
	{
		// No assignment rows at all: identical numbers to the pre-feature
		// fallback; basis flags when the org does configure models (E-02).
		$basis = $this->orgHasWorkingTimeModels() ? self::BASIS_DEGRADED : self::BASIS_WEEKLY_CONTRACT;
		return new ResolvedWorkingTimeContract(
			null, null, self::LEGACY_DAILY_HOURS, self::LEGACY_WEEKLY_HOURS, $basis, null
		);
	}

	private function modelById(?int $modelId): ?WorkingTimeModel
	{
		if ($modelId === null || $modelId <= 0) {
			return null;
		}
		if (!array_key_exists($modelId, $this->modelsById)) {
			try {
				$this->modelsById[$modelId] = $this->workingTimeModelMapper->find($modelId);
			} catch (\Throwable) {
				$this->modelsById[$modelId] = null;
			}
		}
		return $this->modelsById[$modelId];
	}

	private function orgHasWorkingTimeModels(): bool
	{
		if ($this->orgHasWorkingTimeModels === null) {
			try {
				$this->orgHasWorkingTimeModels = $this->workingTimeModelMapper->findAll(1) !== [];
			} catch (\Throwable) {
				$this->orgHasWorkingTimeModels = false;
			}
		}
		return $this->orgHasWorkingTimeModels;
	}
}
