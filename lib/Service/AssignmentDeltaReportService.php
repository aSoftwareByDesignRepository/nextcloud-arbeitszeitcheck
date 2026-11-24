<?php

declare(strict_types=1);

/**
 * MH-18: pre/post-upgrade inventory + expected Soll delta for the
 * effective-dated assignment rollout.
 *
 * Scans the whole `user_working_time_model` table once and reports, per
 * user, which legacy shapes exist (multiple rows, coverage gaps, overlaps,
 * only-ended, future-dated, malformed) plus the expected required-hours
 * delta the segmented resolver produces over a reference range. The
 * "legacy" side reproduces the pre-change uniform semantics (single
 * contract resolved at range end applied to the whole range); the "new"
 * side is the resolver's segmented result — same primitives, no divergent
 * math (NN-01). Suspension reductions are excluded on both sides so the
 * delta isolates the assignment correction (D-03).
 *
 * Also serves MH-19: the set of user_ids needing a one-shot stats-cache
 * invalidation after upgrade and the malformed-row findings.
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;

final class AssignmentDeltaReportService
{
	private const EPSILON = 0.01;

	public function __construct(
		private readonly UserWorkingTimeModelMapper $assignments,
		private readonly WorkingTimeModelMapper $models,
		private readonly WorkingTimeModelAssignmentResolver $resolver,
		private readonly HolidayService $holidays,
		private readonly TimeZoneService $timeZoneService,
	) {
	}

	/**
	 * @return array{
	 *   range: array{from: string, to: string},
	 *   gap_policy: string,
	 *   users: list<array<string,mixed>>,
	 *   summary: array<string,int>,
	 * }
	 */
	public function collect(\DateTime $from, \DateTime $to): array
	{
		$today = $this->todayInStorage();

		$byUser = [];
		foreach ($this->assignments->findAllRows() as $row) {
			$byUser[(string)$row->getUserId()][] = $row;
		}

		$modelIds = [];
		foreach ($byUser as $rows) {
			foreach ($rows as $row) {
				$modelIds[(int)$row->getWorkingTimeModelId()] = true;
			}
		}
		$knownModels = $this->knownModelIds(array_keys($modelIds));

		$users = [];
		$summary = [
			'users_scanned' => 0,
			'users_flagged' => 0,
			'multiple_rows' => 0,
			'coverage_gap' => 0,
			'overlapping_rows' => 0,
			'only_ended' => 0,
			'future_dated' => 0,
			'malformed_rows' => 0,
			'soll_delta' => 0,
		];

		foreach ($byUser as $userId => $rows) {
			$summary['users_scanned']++;
			usort($rows, static fn (UserWorkingTimeModel $a, UserWorkingTimeModel $b): int =>
				[$a->getStartDate()?->format('Y-m-d') ?? '', $a->getId()] <=> [$b->getStartDate()?->format('Y-m-d') ?? '', $b->getId()]);

			$flags = [];
			$malformed = [];
			$coversToday = false;
			foreach ($rows as $row) {
				$start = $row->getStartDate();
				$end = $row->getEndDate();
				if ($start === null) {
					$malformed[] = ['id' => $row->getId(), 'reason' => 'start_null'];
					continue;
				}
				if ($end !== null && $end < $start) {
					$malformed[] = ['id' => $row->getId(), 'reason' => 'end_before_start'];
					continue;
				}
				if (!isset($knownModels[(int)$row->getWorkingTimeModelId()])) {
					$malformed[] = ['id' => $row->getId(), 'reason' => 'dangling_model_id'];
				}
				if ($start > $today) {
					if (!in_array('future_dated', $flags, true)) {
						$flags[] = 'future_dated';
					}
				}
				if ($start <= $today && ($end === null || $end >= $today)) {
					$coversToday = true;
				}
			}
			if (count($rows) > 1) {
				$flags[] = 'multiple_rows';
			}
			if ($this->hasCoverageGap($rows)) {
				$flags[] = 'coverage_gap';
			}
			if ($this->hasOverlap($rows)) {
				$flags[] = 'overlapping_rows';
			}
			if (!$coversToday) {
				$flags[] = 'only_ended';
			}
			if ($malformed !== []) {
				$flags[] = 'malformed_rows';
			}

			$delta = null;
			if (count($rows) > 1 || $this->hasCoverageGap($rows)) {
				$delta = $this->sollDelta($userId, $from, $to);
				if ($delta !== null && abs($delta['delta_hours']) > self::EPSILON) {
					$flags[] = 'soll_delta';
				}
			}

			foreach ($flags as $flag) {
				$summary[$flag] = ($summary[$flag] ?? 0) + 1;
			}
			if ($flags === []) {
				continue;
			}
			$summary['users_flagged']++;
			$users[] = [
				'user_id' => $userId,
				'row_count' => count($rows),
				'flags' => $flags,
				'malformed' => $malformed !== [] ? $malformed : null,
				'soll_delta' => $delta,
			];
		}

		return [
			'range' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
			'gap_policy' => $this->resolver->isGapPolicyStrict() ? 'strict' : 'lenient',
			'users' => $users,
			'summary' => $summary,
		];
	}

	/**
	 * MH-19 input: user_ids whose cached stats may show stale numbers after
	 * upgrade (any shape the segmented resolver treats differently).
	 *
	 * @return list<string>
	 */
	public function userIdsNeedingCacheInvalidation(): array
	{
		$today = $this->todayInStorage();
		$ids = [];
		$byUser = [];
		foreach ($this->assignments->findAllRows() as $row) {
			$byUser[(string)$row->getUserId()][] = $row;
		}
		foreach ($byUser as $userId => $rows) {
			if (count($rows) > 1 || $this->hasCoverageGap($rows) || $this->hasOverlap($rows)) {
				$ids[] = $userId;
				continue;
			}
			foreach ($rows as $row) {
				$end = $row->getEndDate();
				if ($end !== null && $end < $today) {
					$ids[] = $userId;
					break;
				}
			}
		}
		return $ids;
	}

	/**
	 * Expected required-hours change for [from,to]: segmented resolver Soll
	 * minus the legacy uniform Soll (single contract at range end).
	 *
	 * @return array{legacy_hours: float, segmented_hours: float, delta_hours: float}
	 */
	private function sollDelta(string $userId, \DateTime $from, \DateTime $to): ?array
	{
		try {
			$legacy = $this->contractHours($this->resolver->resolveLenient($userId, $to), $userId, $from, $to);
			$segmented = 0.0;
			foreach ($this->resolver->resolveRange($userId, $from, $to) as $seg) {
				$segmented += $this->contractHours($seg->contract, $userId, $seg->start, $seg->end);
			}
			return [
				'legacy_hours' => round($legacy, 2),
				'segmented_hours' => round($segmented, 2),
				'delta_hours' => round($segmented - $legacy, 2),
			];
		} catch (\Throwable) {
			return null;
		}
	}

	private function contractHours(
		\OCA\ArbeitszeitCheck\Support\ResolvedWorkingTimeContract $contract,
		string $userId,
		\DateTime $start,
		\DateTime $end,
	): float {
		if ($contract->schedule !== null) {
			return $contract->schedule->requiredHoursForDateRange(
				$start,
				$end,
				fn (\DateTime $day): float => $this->holidays->getHolidayWeightForUser($userId, $day),
			);
		}
		if ($contract->weeklyHours > 0.0) {
			return ($this->holidays->computeWorkingDaysForUser($userId, $start, $end) / 5.0) * $contract->weeklyHours;
		}
		return 0.0;
	}

	/** Uncovered days between consecutive sorted windows (end-exclusive scan). */
	private function hasCoverageGap(array $rows): bool
	{
		$cursor = null;
		foreach ($rows as $row) {
			$start = $row->getStartDate();
			if ($start === null) {
				continue;
			}
			$end = $row->getEndDate();
			if ($cursor !== null && $start > (clone $cursor)->modify('+1 day')) {
				return true;
			}
			if ($end === null) {
				return false; // open window closes the tail
			}
			if ($cursor === null || $end > $cursor) {
				$cursor = clone $end;
			}
		}
		return false;
	}

	private function hasOverlap(array $rows): bool
	{
		$cursor = null;
		foreach ($rows as $row) {
			$start = $row->getStartDate();
			if ($start === null) {
				continue;
			}
			if ($cursor !== null && $start <= $cursor) {
				return true;
			}
			$end = $row->getEndDate();
			if ($end === null) {
				return false;
			}
			if ($cursor === null || $end > $cursor) {
				$cursor = clone $end;
			}
		}
		return false;
	}

	private function todayInStorage(): \DateTime
	{
		[$start] = $this->timeZoneService->todayWindowInStorage();
		$start->setTime(0, 0, 0);
		return $start;
	}

	/**
	 * @param list<int> $ids
	 * @return array<int, true>
	 */
	private function knownModelIds(array $ids): array
	{
		$known = [];
		foreach ($this->models->findAll() as $model) {
			$known[(int)$model->getId()] = true;
		}
		return $known;
	}
}
