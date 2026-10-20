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
			$period = $this->buildClippedPeriod($liveEntry, $dayStartTs, $dayEndTs, $now);
			if ($period !== null) {
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
			$period = $this->buildClippedPeriod($entry, $dayStartTs, $dayEndTs, $now);
			if ($period !== null) {
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
		$period = $this->buildClippedPeriod(
			$entry,
			$dayStart->getTimestamp(),
			$dayEnd->getTimestamp(),
			$effectiveEnd ?? $this->timeZoneService->nowInStorage(),
		);
		if ($period === null) {
			return 0.0;
		}
		return $this->periodWorkingHours($period);
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
	 * @return array{start: int, end: int, breakHours: float}|null
	 */
	private function buildClippedPeriod(TimeEntry $entry, int $dayStartTs, int $dayEndTs, \DateTime $now): ?array
	{
		$entryStart = $entry->getStartTime();
		if ($entryStart === null) {
			return null;
		}

		$entryStartTs = $entryStart->getTimestamp();
		$status = $entry->getStatus();
		$entryEnd = $entry->getEndTime();

		if ($entryEnd !== null) {
			// Never attribute work that is still in the future relative to the
			// evaluation instant. Planned/mistaken completed rows with end_time
			// later today must not inflate "hours today" or ArbZG §3 totals.
			// When validating a proposed entry, callers pass that entry's end as
			// $now so the full proposed span is still counted.
			$effectiveEndTs = min($entryEnd->getTimestamp(), $now->getTimestamp());
		} elseif (in_array($status, [TimeEntry::STATUS_ACTIVE, TimeEntry::STATUS_BREAK], true)) {
			$effectiveEndTs = max($entryStartTs, $now->getTimestamp());
		} elseif ($status === TimeEntry::STATUS_PAUSED) {
			$pausedEnd = $entry->getUpdatedAt() ?? $now;
			$effectiveEndTs = max($entryStartTs, min($pausedEnd->getTimestamp(), $now->getTimestamp()));
		} else {
			return null;
		}

		$clipStart = max($entryStartTs, $dayStartTs);
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
	 * Sum merged break intervals that fall inside [windowStart, windowEnd).
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
