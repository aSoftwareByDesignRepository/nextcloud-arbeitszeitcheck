<?php

declare(strict_types=1);

/**
 * Calendar-day working hours (ArbZG §3).
 *
 * Legal daily limits apply per calendar day, not per clock-in date. Overnight
 * shifts must be clipped at midnight so a 22:00–08:00 block becomes 2 h on day
 * one and 8 h on day two — never 10 h lumped onto the start day.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;

/**
 * Overlap-safe working hours inside a single calendar-day window.
 */
class DailyWorkingHoursCalculator
{
	public function __construct(
		private readonly TimeEntryMapper $timeEntryMapper,
		private readonly TimeZoneService $timeZoneService,
	) {
	}

	/**
	 * Working hours for the calendar day containing $reference (storage TZ).
	 */
	public function getWorkingHoursForToday(string $userId, ?\DateTime $reference = null): float
	{
		$ref = $reference ?? $this->timeZoneService->nowInStorage();
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($ref);
		return $this->getWorkingHoursForCalendarDay($userId, $dayStart, $dayEnd, null, $ref);
	}

	/**
	 * Working hours inside [dayStart, dayEnd) for all entries that overlap the window.
	 *
	 * @param TimeEntry|null $liveEntry When set, this entry is evaluated with $liveEnd (typically "now").
	 * @param int|null $excludeEntryId Skip this entry id (e.g. when computing "other" hours on the same day).
	 */
	public function getWorkingHoursForCalendarDay(
		string $userId,
		\DateTime $dayStart,
		\DateTime $dayEnd,
		?TimeEntry $liveEntry = null,
		?\DateTime $liveEnd = null,
		?int $excludeEntryId = null,
	): float {
		$dayStartTs = $dayStart->getTimestamp();
		$dayEndTs = $dayEnd->getTimestamp();
		if ($dayEndTs <= $dayStartTs) {
			return 0.0;
		}

		$now = $liveEnd ?? $this->timeZoneService->nowInStorage();

		$overlapping = $this->timeEntryMapper->findOverlapping($userId, $dayStart, $dayEnd) ?? [];
		$periods = $this->buildClippedPeriods($overlapping, $dayStartTs, $dayEndTs, $now, $excludeEntryId, $liveEntry?->getId());

		if ($liveEntry !== null) {
			foreach ($this->buildClippedWorkPeriods($liveEntry, $dayStartTs, $dayEndTs, $now) as $period) {
				$periods[] = $period;
			}
		}

		return $this->mergeNonOverlappingWorkingHours($periods);
	}

	/**
	 * Clip a set of candidate entries to [dayStartTs, dayEndTs) — the shared
	 * per-day inner loop for the single-day and batched range paths.
	 *
	 * @param list<TimeEntry> $entries
	 * @return list<array{start: int, end: int, breakHours: float}>
	 */
	private function buildClippedPeriods(
		array $entries,
		int $dayStartTs,
		int $dayEndTs,
		\DateTime $now,
		?int $excludeEntryId = null,
		?int $skipEntryId = null,
	): array {
		$periods = [];
		foreach ($entries as $entry) {
			if ($excludeEntryId !== null && $entry->getId() === $excludeEntryId) {
				continue;
			}
			if ($skipEntryId !== null && $entry->getId() === $skipEntryId) {
				continue;
			}
			foreach ($this->buildClippedWorkPeriods($entry, $dayStartTs, $dayEndTs, $now) as $period) {
				$periods[] = $period;
			}
		}
		return $periods;
	}

	/**
	 * Working hours of one entry clipped to a calendar-day window.
	 */
	public function getEntryWorkingHoursOnCalendarDay(
		TimeEntry $entry,
		\DateTime $dayStart,
		\DateTime $dayEnd,
		?\DateTime $effectiveEnd = null,
	): float {
		$periods = $this->buildClippedWorkPeriods(
			$entry,
			$dayStart->getTimestamp(),
			$dayEnd->getTimestamp(),
			$effectiveEnd ?? $this->timeZoneService->nowInStorage(),
		);
		// Same-entry segment periods never overlap (segments are the maximal
		// intervals of R \ X), so summing part measures is exact.
		$total = 0.0;
		foreach ($periods as $period) {
			$total += $this->periodWorkingHours($period);
		}
		return $total;
	}

	/**
	 * Highest calendar-day working total while $entry is counted on each day it touches.
	 *
	 * Used for ArbZG §4 break rules when a single row spans midnight.
	 */
	public function getPeakWorkingHoursForEntrySpan(
		string $userId,
		TimeEntry $entry,
		?\DateTime $effectiveEnd = null,
		?int $excludeEntryId = null,
	): float {
		return $this->describePeakWorkingDay($userId, $entry, $effectiveEnd, $excludeEntryId)['hours'];
	}

	/**
	 * Peak calendar-day net working hours plus that day's window (storage TZ).
	 *
	 * @return array{hours: float, dayStart: \DateTime, dayEnd: \DateTime}
	 */
	public function describePeakWorkingDay(
		string $userId,
		TimeEntry $entry,
		?\DateTime $effectiveEnd = null,
		?int $excludeEntryId = null,
	): array {
		$start = $entry->getStartTime();
		$end = $entry->getEndTime() ?? $effectiveEnd;
		if ($start === null || $end === null) {
			[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($this->timeZoneService->nowInStorage());

			return ['hours' => 0.0, 'dayStart' => $dayStart, 'dayEnd' => $dayEnd];
		}

		$peak = -1.0;
		$peakStart = null;
		$peakEnd = null;
		$dayCursor = $this->timeZoneService->dayWindowInStorage($start)[0];
		$lastDay = $this->timeZoneService->dayWindowInStorage($end)[0];

		while ($dayCursor <= $lastDay) {
			[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($dayCursor);
			$hours = $this->getWorkingHoursForCalendarDay(
				$userId,
				$dayStart,
				$dayEnd,
				$entry,
				$effectiveEnd ?? $end,
				$excludeEntryId,
			);
			if ($hours > $peak) {
				$peak = $hours;
				$peakStart = $dayStart;
				$peakEnd = $dayEnd;
			}
			$dayCursor = (clone $dayCursor)->modify('+1 day');
		}

		if ($peakStart === null || $peakEnd === null) {
			[$peakStart, $peakEnd] = $this->timeZoneService->dayWindowInStorage($start);
			$peak = 0.0;
		}

		return ['hours' => $peak, 'dayStart' => $peakStart, 'dayEnd' => $peakEnd];
	}

	/**
	 * Countable break portions (whole minutes) on a calendar day, including $liveEntry.
	 *
	 * Portions shorter than $minBreakMinutes do not count (DE/CH: 15; AT: 10).
	 *
	 * @return list<int>
	 */
	public function countableBreakPortionsOnCalendarDay(
		string $userId,
		\DateTime $dayStart,
		\DateTime $dayEnd,
		TimeEntry $liveEntry,
		int $minBreakMinutes,
		?int $excludeEntryId = null,
	): array {
		$dayStartTs = $dayStart->getTimestamp();
		$dayEndTs = $dayEnd->getTimestamp();
		if ($dayEndTs <= $dayStartTs) {
			return [];
		}

		$now = $liveEntry->getEndTime() ?? $this->timeZoneService->nowInStorage();
		$liveId = $liveEntry->getId();
		$intervals = [];
		$overlapping = $this->timeEntryMapper->findOverlapping($userId, $dayStart, $dayEnd) ?? [];
		foreach ($overlapping as $entry) {
			if ($excludeEntryId !== null && $entry->getId() === $excludeEntryId) {
				continue;
			}
			if ($liveId !== null && $entry->getId() === $liveId) {
				continue;
			}
			$intervals = array_merge($intervals, $this->breakIntervalsInWindow($entry, $dayStartTs, $dayEndTs, $now));
		}
		$intervals = array_merge($intervals, $this->breakIntervalsInWindow($liveEntry, $dayStartTs, $dayEndTs, $now));

		$merged = $this->mergeTimeIntervals($intervals);
		$minSeconds = max(0, $minBreakMinutes) * 60;
		$portions = [];
		foreach ($merged as $interval) {
			$seconds = $interval['end'] - $interval['start'];
			if ($seconds >= $minSeconds) {
				$portions[] = (int)round($seconds / 60);
			}
		}

		return $portions;
	}

	/**
	 * First calendar day on which $entry would push totals above $maxDailyHours, or null if legal.
	 *
	 * @return array{date: string, hours: float}|null
	 */
	public function findCalendarDayExceedingMaximum(
		string $userId,
		TimeEntry $entry,
		float $maxDailyHours,
		?int $excludeEntryId = null,
	): ?array {
		$start = $entry->getStartTime();
		$end = $entry->getEndTime();
		if ($start === null || $end === null) {
			return null;
		}

		$dayCursor = $this->timeZoneService->dayWindowInStorage($start)[0];
		$lastDay = $this->timeZoneService->dayWindowInStorage($end)[0];

		while ($dayCursor <= $lastDay) {
			[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($dayCursor);
			// Count $entry via $liveEntry only once (overlap query may return a stale row).
			$hours = $this->getWorkingHoursForCalendarDay(
				$userId,
				$dayStart,
				$dayEnd,
				$entry,
				$end,
				$entry->getId(),
			);
			if ($hours > $maxDailyHours + 0.001) {
				return [
					'date' => $dayStart->format('Y-m-d'),
					'hours' => round($hours, 2),
				];
			}
			$dayCursor = (clone $dayCursor)->modify('+1 day');
		}

		return null;
	}

	/**
	 * Every calendar day in $entry's span whose total working hours exceed $maxDailyHours.
	 *
	 * Uses overlap totals for the user (entry must already be persisted for accurate counts).
	 *
	 * @return list<array{date: string, hours: float}>
	 */
	public function findAllCalendarDaysExceedingMaximum(
		string $userId,
		TimeEntry $entry,
		float $maxDailyHours,
	): array {
		$start = $entry->getStartTime();
		$end = $entry->getEndTime();
		if ($start === null || $end === null) {
			return [];
		}

		$violations = [];
		$dayCursor = $this->timeZoneService->dayWindowInStorage($start)[0];
		$lastDay = $this->timeZoneService->dayWindowInStorage($end)[0];

		while ($dayCursor <= $lastDay) {
			[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($dayCursor);
			$hours = $this->getWorkingHoursForCalendarDay(
				$userId,
				$dayStart,
				$dayEnd,
				null,
				$end,
			);
			if ($hours > $maxDailyHours + 0.001) {
				$violations[] = [
					'date' => $dayStart->format('Y-m-d'),
					'hours' => round($hours, 2),
				];
			}
			$dayCursor = (clone $dayCursor)->modify('+1 day');
		}

		return $violations;
	}

	/**
	 * Sum working hours for every calendar day that intersects [rangeStart, rangeEndExclusive).
	 *
	 * Uses the same midnight-clipped overlap math as {@see getWorkingHoursForToday()}.
	 * Suitable for week/month manager summaries and date-range stats (not ArbZG §3 enforcement
	 * alone — each day is still capped only by its own calendar-day total).
	 */
	public function sumWorkingHoursForCalendarDaysInRange(
		string $userId,
		\DateTime $rangeStart,
		\DateTime $rangeEndExclusive,
	): float {
		if ($rangeEndExclusive <= $rangeStart) {
			return 0.0;
		}

		$total = 0.0;
		$dayCursor = $this->timeZoneService->dayWindowInStorage($rangeStart)[0];
		$lastInstant = (clone $rangeEndExclusive)->modify('-1 second');
		if ($lastInstant < $rangeStart) {
			return 0.0;
		}
		$lastDay = $this->timeZoneService->dayWindowInStorage($lastInstant)[0];

		while ($dayCursor <= $lastDay) {
			[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($dayCursor);
			if ($dayEnd > $rangeStart && $dayStart < $rangeEndExclusive) {
				$total += $this->getWorkingHoursForCalendarDay($userId, $dayStart, $dayEnd);
			}
			$dayCursor = (clone $dayCursor)->modify('+1 day');
		}

		return $total;
	}

	/**
	 * Working hours summed per calendar day for a set of users — one batched
	 * entry fetch instead of a per-user-per-day query loop. The per-day clip
	 * and merge semantics are identical to getWorkingHoursForCalendarDay().
	 *
	 * @param list<string> $userIds
	 * @return array<string, float> userId => hours (every requested user present)
	 */
	public function sumWorkingHoursForUsersInRange(
		array $userIds,
		\DateTime $rangeStart,
		\DateTime $rangeEndExclusive,
	): array {
		$userIds = array_values(array_unique($userIds));
		$totals = array_fill_keys($userIds, 0.0);
		if (empty($userIds) || $rangeEndExclusive <= $rangeStart) {
			return $totals;
		}

		$now = $this->timeZoneService->nowInStorage();
		$entriesByUser = $this->timeEntryMapper->findOverlappingForUsers($userIds, $rangeStart, $rangeEndExclusive);

		$dayCursor = $this->timeZoneService->dayWindowInStorage($rangeStart)[0];
		$lastInstant = (clone $rangeEndExclusive)->modify('-1 second');
		if ($lastInstant < $rangeStart) {
			return $totals;
		}
		$lastDay = $this->timeZoneService->dayWindowInStorage($lastInstant)[0];

		while ($dayCursor <= $lastDay) {
			[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($dayCursor);
			if ($dayEnd > $rangeStart && $dayStart < $rangeEndExclusive) {
				$dayStartTs = $dayStart->getTimestamp();
				$dayEndTs = $dayEnd->getTimestamp();
				foreach ($userIds as $userId) {
					$entries = $entriesByUser[$userId] ?? null;
					if ($entries === null) {
						continue;
					}
					$periods = $this->buildClippedPeriods($entries, $dayStartTs, $dayEndTs, $now);
					if ($periods !== []) {
						$totals[$userId] += $this->mergeNonOverlappingWorkingHours($periods);
					}
				}
			}
			$dayCursor = (clone $dayCursor)->modify('+1 day');
		}

		return $totals;
	}

	/**
	 * Break minutes recorded inside [dayStart, dayEnd) across all overlapping entries.
	 */
	public function getBreakMinutesForCalendarDay(
		string $userId,
		\DateTime $dayStart,
		\DateTime $dayEnd,
		?\DateTime $reference = null,
	): float {
		$ref = $reference ?? $this->timeZoneService->nowInStorage();
		$dayStartTs = $dayStart->getTimestamp();
		$dayEndTs = $dayEnd->getTimestamp();
		if ($dayEndTs <= $dayStartTs) {
			return 0.0;
		}

		$totalSeconds = 0;
		foreach ($this->timeEntryMapper->findOverlapping($userId, $dayStart, $dayEnd) as $entry) {
			$totalSeconds += $this->breakSecondsInWindow($entry, $dayStartTs, $dayEndTs, $ref);
		}

		return round($totalSeconds / 60, 1);
	}

	/**
	 * Active/break session duration (seconds) clipped to the calendar day containing $reference.
	 */
	public function getLiveSessionSecondsOnCalendarDay(TimeEntry $entry, ?\DateTime $reference = null): int
	{
		$ref = $reference ?? $this->timeZoneService->nowInStorage();
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($ref);
		$period = $this->buildClippedPeriod(
			$entry,
			$dayStart->getTimestamp(),
			$dayEnd->getTimestamp(),
			$ref,
		);
		if ($period === null) {
			return 0;
		}
		return max(0, $period['end'] - $period['start']);
	}

	/**
	 * Clipped accounting periods for one entry inside [dayStartTs, dayEndTs).
	 *
	 * Entries without a frozen split-service context produce at most one
	 * period — the raw [start, effectiveEnd) span clipped to the day window
	 * (byte-identical legacy behaviour). Entries carrying a valid frozen
	 * context produce one period per `result.segments` interval, each clipped
	 * to the entry span and the day window; a segment inside the excluded
	 * interruption X simply does not exist here, so X is never counted as
	 * work. Recorded breaks are counted only outside X (NN-02) — that part is
	 * enforced inside {@see breakIntervalsInWindow()}.
	 *
	 * @return list<array{start: int, end: int, breakHours: float}>
	 */
	private function buildClippedWorkPeriods(TimeEntry $entry, int $dayStartTs, int $dayEndTs, \DateTime $now): array
	{
		$split = $this->splitContextIntervals($entry);
		if ($split === null) {
			$period = $this->buildClippedPeriod($entry, $dayStartTs, $dayEndTs, $now);
			return $period === null ? [] : [$period];
		}

		$entryStart = $entry->getStartTime();
		if ($entryStart === null) {
			return [];
		}
		$effectiveEndTs = $this->resolveEffectiveEndTs($entry, $now);
		if ($effectiveEndTs === null) {
			return [];
		}
		$entryStartTs = $entryStart->getTimestamp();

		$periods = [];
		foreach ($split['segments'] as $segment) {
			$clipStart = max($segment['start'], $entryStartTs, $dayStartTs);
			$clipEnd = min($segment['end'], $effectiveEndTs, $dayEndTs);
			if ($clipEnd <= $clipStart) {
				continue;
			}
			$breakSeconds = $this->breakSecondsInWindow($entry, $clipStart, $clipEnd, $now);
			$periods[] = [
				'start' => $clipStart,
				'end' => $clipEnd,
				'breakHours' => $breakSeconds / 3600.0,
			];
		}

		return $periods;
	}

	/**
	 * @return array{start: int, end: int, breakHours: float}|null
	 */
	private function buildClippedPeriod(TimeEntry $entry, int $dayStartTs, int $dayEndTs, \DateTime $now): ?array
	{
		$entryStart = $entry->getStartTime();
		if ($entryStart === null) {
			return null;
		}

		$effectiveEndTs = $this->resolveEffectiveEndTs($entry, $now);
		if ($effectiveEndTs === null) {
			return null;
		}

		$clipStart = max($entryStart->getTimestamp(), $dayStartTs);
		$clipEnd = min($effectiveEndTs, $dayEndTs);
		if ($clipEnd <= $clipStart) {
			return null;
		}

		$breakSeconds = $this->breakSecondsInWindow($entry, $clipStart, $clipEnd, $now);

		return [
			'start' => $clipStart,
			'end' => $clipEnd,
			'breakHours' => $breakSeconds / 3600.0,
		];
	}

	/**
	 * Effective end timestamp of an entry for day accounting, or null when the
	 * entry state contributes nothing (e.g. pending/rejected without end).
	 *
	 * Never attributes work that is still in the future relative to the
	 * evaluation instant. Planned/mistaken completed rows with end_time later
	 * today must not inflate "hours today" or ArbZG §3 totals. When validating
	 * a proposed entry, callers pass that entry's end as $now so the full
	 * proposed span is still counted.
	 */
	private function resolveEffectiveEndTs(TimeEntry $entry, \DateTime $now): ?int
	{
		$entryStart = $entry->getStartTime();
		if ($entryStart === null) {
			return null;
		}
		$entryStartTs = $entryStart->getTimestamp();
		$status = $entry->getStatus();
		$entryEnd = $entry->getEndTime();

		if ($entryEnd !== null) {
			return min($entryEnd->getTimestamp(), $now->getTimestamp());
		}
		if (in_array($status, [TimeEntry::STATUS_ACTIVE, TimeEntry::STATUS_BREAK], true)) {
			return max($entryStartTs, $now->getTimestamp());
		}
		if ($status === TimeEntry::STATUS_PAUSED) {
			$pausedEnd = $entry->getUpdatedAt() ?? $now;
			return max($entryStartTs, min($pausedEnd->getTimestamp(), $now->getTimestamp()));
		}
		return null;
	}

	/**
	 * Frozen split-service context intervals for one entry, or null when the
	 * entry must keep legacy [start, end) day accounting.
	 *
	 * Defensive and log-free by contract: NULL context, an entity-flagged
	 * invalid context, a missing/unknown `result.state`, absent
	 * `result.segments`, or any malformed interval pair all mean "legacy
	 * path for this entry" — this aggregator never throws on context data.
	 *
	 * @return array{segments: list<array{start: int, end: int}>, excluded: list<array{start: int, end: int}>}|null
	 */
	private function splitContextIntervals(TimeEntry $entry): ?array
	{
		$context = $this->readWorkContext($entry);
		if ($context === null) {
			return null;
		}

		$result = $context['result'] ?? null;
		if (!is_array($result)) {
			return null;
		}
		$state = $result['state'] ?? null;
		if (!is_string($state) || !in_array($state, ['applied', 'review', 'active'], true)) {
			return null;
		}
		$segments = $this->parseIntervalList($result['segments'] ?? null);
		if ($segments === null || $segments === []) {
			return null;
		}
		// `excluded` absent means "empty union"; present but malformed makes the
		// whole context untrustworthy for accounting → legacy for this entry.
		if (array_key_exists('excluded', $result)) {
			$excluded = $this->parseIntervalList($result['excluded']);
			if ($excluded === null) {
				return null;
			}
		} else {
			$excluded = [];
		}

		return ['segments' => $segments, 'excluded' => $excluded];
	}

	/**
	 * Decoded work context for an entry, or null when absent/invalid.
	 *
	 * Prefers the typed entity accessors (getWorkContext / hasInvalidWorkContext).
	 * Until those land, falls back to decoding the raw `work_context_json`
	 * column property so this consumer is not coupled to entity timing.
	 */
	private function readWorkContext(TimeEntry $entry): ?array
	{
		try {
			if (method_exists($entry, 'hasInvalidWorkContext') && $entry->hasInvalidWorkContext()) {
				return null;
			}
			if (method_exists($entry, 'getWorkContext')) {
				$context = $entry->getWorkContext();
				return is_array($context) ? $context : null;
			}
			if (property_exists($entry, 'workContextJson') || method_exists($entry, 'getWorkContextJson')) {
				$raw = $entry->getWorkContextJson();
				if (is_array($raw)) {
					return $raw;
				}
				if (is_string($raw) && $raw !== '') {
					$decoded = json_decode($raw, true);
					return is_array($decoded) ? $decoded : null;
				}
			}
		} catch (\Throwable $e) {
			return null;
		}

		return null;
	}

	/**
	 * Parse a list of [start, end] pairs (ISO-8601 strings or epoch numbers)
	 * into epoch-second intervals. Strict: any malformed pair invalidates the
	 * whole list rather than silently dropping a boundary.
	 *
	 * @return list<array{start: int, end: int}>|null
	 */
	private function parseIntervalList(mixed $list): ?array
	{
		if (!is_array($list) || !array_is_list($list)) {
			return null;
		}
		$intervals = [];
		foreach ($list as $pair) {
			if (!is_array($pair) || count($pair) < 2) {
				return null;
			}
			$values = array_values($pair);
			$start = $this->intervalPointToTimestamp($values[0]);
			$end = $this->intervalPointToTimestamp($values[1]);
			if ($start === null || $end === null || $end <= $start) {
				return null;
			}
			$intervals[] = ['start' => $start, 'end' => $end];
		}
		return $intervals;
	}

	private function intervalPointToTimestamp(mixed $point): ?int
	{
		if (is_int($point)) {
			return $point;
		}
		if (is_float($point)) {
			return (int)$point;
		}
		if (is_string($point)) {
			if (preg_match('/^-?\d+$/', $point) === 1) {
				return (int)$point;
			}
			try {
				return (new \DateTime($point))->getTimestamp();
			} catch (\Throwable $e) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Remove the union of $holes from each interval of $intervals. Both inputs
	 * must be sorted, non-overlapping interval lists (see mergeTimeIntervals).
	 *
	 * @param list<array{start: int, end: int}> $intervals
	 * @param list<array{start: int, end: int}> $holes
	 * @return list<array{start: int, end: int}>
	 */
	private function subtractIntervals(array $intervals, array $holes): array
	{
		$result = [];
		foreach ($intervals as $interval) {
			$cursor = $interval['start'];
			foreach ($holes as $hole) {
				if ($hole['end'] <= $cursor) {
					continue;
				}
				if ($hole['start'] >= $interval['end']) {
					break;
				}
				if ($hole['start'] > $cursor) {
					$result[] = ['start' => $cursor, 'end' => min($hole['start'], $interval['end'])];
				}
				$cursor = max($cursor, $hole['end']);
				if ($cursor >= $interval['end']) {
					break;
				}
			}
			if ($cursor < $interval['end']) {
				$result[] = ['start' => $cursor, 'end' => $interval['end']];
			}
		}
		return $result;
	}

	/**
	 * Sum merged break intervals that fall inside [windowStart, windowEnd).
	 *
	 * For entries with a frozen split-service context these are the effective
	 * breaks B \ X: portions inside the excluded interruption are already
	 * non-work and must never be deducted twice (NN-02).
	 */
	private function breakSecondsInWindow(TimeEntry $entry, int $windowStart, int $windowEnd, \DateTime $now): int
	{
		$total = 0;
		foreach ($this->breakIntervalsInWindow($entry, $windowStart, $windowEnd, $now) as $interval) {
			$total += $interval['end'] - $interval['start'];
		}

		return $total;
	}

	/**
	 * Recorded break intervals clipped to [windowStart, windowEnd). For
	 * split-service entries the excluded interruption X is subtracted so only
	 * effective breaks B \ X remain (NN-02: never deduct the same second twice).
	 *
	 * @return list<array{start: int, end: int}>
	 */
	private function breakIntervalsInWindow(TimeEntry $entry, int $windowStart, int $windowEnd, \DateTime $now): array
	{
		$intervals = [];

		$breaksJson = $entry->getBreaks();
		if ($breaksJson !== null && $breaksJson !== '') {
			foreach (json_decode($breaksJson, true) ?? [] as $break) {
				if (!isset($break['start'], $break['end'])) {
					continue;
				}
				try {
					$bStart = (new \DateTime((string)$break['start']))->getTimestamp();
					$bEnd = (new \DateTime((string)$break['end']))->getTimestamp();
				} catch (\Throwable $e) {
					continue;
				}
				if ($bEnd > $bStart) {
					$intervals[] = ['start' => $bStart, 'end' => $bEnd];
				}
			}
		}

		if ($entry->getBreakStartTime() !== null) {
			$bStart = $entry->getBreakStartTime()->getTimestamp();
			$bEnd = $entry->getBreakEndTime() !== null
				? $entry->getBreakEndTime()->getTimestamp()
				: $now->getTimestamp();
			if ($bEnd > $bStart) {
				$intervals[] = ['start' => $bStart, 'end' => $bEnd];
			}
		}

		$clipped = [];
		foreach ($this->mergeTimeIntervals($intervals) as $interval) {
			$clipStart = max($interval['start'], $windowStart);
			$clipEnd = min($interval['end'], $windowEnd);
			if ($clipEnd > $clipStart) {
				$clipped[] = ['start' => $clipStart, 'end' => $clipEnd];
			}
		}

		// Split-service entries: a recorded break inside the excluded
		// interruption X is already excluded work — remove those portions so
		// every break consumer (working hours, day break minutes, countable
		// break portions) sees only B \ X.
		$split = $this->splitContextIntervals($entry);
		if ($split !== null && $split['excluded'] !== []) {
			$clipped = $this->subtractIntervals($clipped, $this->mergeTimeIntervals($split['excluded']));
		}

		return $clipped;
	}

	/**
	 * @param list<array{start: int, end: int}> $intervals
	 * @return list<array{start: int, end: int}>
	 */
	private function mergeTimeIntervals(array $intervals): array
	{
		if ($intervals === []) {
			return [];
		}

		usort($intervals, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

		$merged = [];
		$current = $intervals[0];
		for ($i = 1, $n = count($intervals); $i < $n; $i++) {
			$next = $intervals[$i];
			if ($next['start'] <= $current['end']) {
				$current['end'] = max($current['end'], $next['end']);
			} else {
				$merged[] = $current;
				$current = $next;
			}
		}
		$merged[] = $current;

		return $merged;
	}

	/**
	 * @param list<array{start: int, end: int, breakHours: float}> $periods
	 */
	private function mergeNonOverlappingWorkingHours(array $periods): float
	{
		if ($periods === []) {
			return 0.0;
		}

		usort($periods, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);

		$merged = [];
		$current = $periods[0];
		for ($i = 1, $n = count($periods); $i < $n; $i++) {
			$next = $periods[$i];
			if ($next['start'] <= $current['end']) {
				$current['end'] = max($current['end'], $next['end']);
				$current['breakHours'] += $next['breakHours'];
			} else {
				$merged[] = $current;
				$current = $next;
			}
		}
		$merged[] = $current;

		$total = 0.0;
		foreach ($merged as $period) {
			$total += $this->periodWorkingHours($period);
		}

		return $total;
	}

	/**
	 * @param array{start: int, end: int, breakHours: float} $period
	 */
	private function periodWorkingHours(array $period): float
	{
		$durationHours = ($period['end'] - $period['start']) / 3600;
		return max(0.0, $durationHours - $period['breakHours']);
	}
}
