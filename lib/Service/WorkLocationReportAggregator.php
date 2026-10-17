<?php

declare(strict_types=1);

/**
 * Work-location reporting aggregation (Workforce Policy Suite, Phase 3).
 *
 * Produces the machine-stable `workLocation` report structure (spec §9.10):
 *
 *   byCode     — per catalog code: label, category, distinct days, netMinutes
 *   byCategory — per reporting category + `unrecorded` for NULL locations
 *   mixedDays  — local days carrying more than one concrete code
 *
 * Rules:
 * - Days are distinct local days in the organisation timezone; mixed days may
 *   appear under multiple codes/categories.
 * - Entries spanning local midnight are split per segment; `netMinutes` count
 *   each time segment exactly once.
 * - Breaks keep their recorded timestamps — a break interval subtracts only
 *   from the segment it actually overlaps (existing entry/break allocation;
 *   never proportional re-invention). Breaks below the countable floor follow
 *   the entry's own break-duration semantics.
 * - Open entries (no end) count until $asOf (live view); finalized closures
 *   pass only completed rows upstream.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;

final class WorkLocationReportAggregator
{
	public const UNRECORDED = 'unrecorded';

	public function __construct(
		private readonly WorkLocationCatalog $catalog,
	) {
	}

	/**
	 * @param iterable<TimeEntry> $entries
	 * @return array{
	 *   byCode: array<string, array{label:string,category:string,days:int,netMinutes:int}>,
	 *   byCategory: array<string, array{days:int,netMinutes:int}>,
	 *   mixedDays: int
	 * }
	 */
	public function aggregate(iterable $entries, \DateTimeZone $tz, ?\DateTimeInterface $asOf = null, ?string $locale = null): array
	{
		// day => code => netSeconds ; day set per bucket for distinct counts
		$codeDayMinutes = []; // code => [day => netSeconds]
		$codeMinutes = [];    // code => netSeconds
		$categoryDayMinutes = []; // category => [day => netSeconds]
		$categoryMinutes = [];    // category => netSeconds
		$dayCodes = [];       // day => set of concrete codes

		foreach ($entries as $entry) {
			$perDay = self::splitNetSecondsByDay($entry, $tz, $asOf);
			if ($perDay === []) {
				continue;
			}
			$code = $entry->getWorkLocation();
			$code = ($code !== null && $code !== '') ? (string)$code : self::UNRECORDED;
			$category = $code === self::UNRECORDED
				? self::UNRECORDED
				: ($this->catalog->categoryOf($code) ?? 'other');

			foreach ($perDay as $day => $net) {
				$codeDayMinutes[$code][$day] = ($codeDayMinutes[$code][$day] ?? 0) + $net;
				$codeMinutes[$code] = ($codeMinutes[$code] ?? 0) + $net;
				$categoryDayMinutes[$category][$day] = ($categoryDayMinutes[$category][$day] ?? 0) + $net;
				$categoryMinutes[$category] = ($categoryMinutes[$category] ?? 0) + $net;
				if ($code !== self::UNRECORDED) {
					$dayCodes[$day][$code] = true;
				}
			}
		}

		$byCode = [];
		foreach ($codeDayMinutes as $code => $perDay) {
			if ($code === self::UNRECORDED) {
				continue; // unrecorded appears only at category granularity
			}
			$byCode[$code] = [
				'label' => $this->catalog->labelFor($code, $locale),
				'category' => $this->catalog->categoryOf($code) ?? 'other',
				'days' => count($perDay),
				'netMinutes' => (int)round(($codeMinutes[$code] ?? 0) / 60),
			];
		}
		ksort($byCode);

		$byCategory = [];
		foreach ($categoryDayMinutes as $category => $perDay) {
			$byCategory[$category] = [
				'days' => count($perDay),
				'netMinutes' => (int)round(($categoryMinutes[$category] ?? 0) / 60),
			];
		}
		ksort($byCategory);

		$mixedDays = 0;
		foreach ($dayCodes as $codes) {
			if (count($codes) > 1) {
				$mixedDays++;
			}
		}

		return ['byCode' => $byCode, 'byCategory' => $byCategory, 'mixedDays' => $mixedDays];
	}

	/**
	 * Net seconds per local day for one entry, splitting at every local
	 * midnight in $tz and subtracting each recorded break interval from the
	 * segment it overlaps. Static and catalog-free so the legacy
	 * {@see \OCA\ArbeitszeitCheck\Support\WorkLocationDayCounter} uses the
	 * identical day-attribution.
	 *
	 * @return array<string, int> localDay (Y-m-d) => netSeconds
	 */
	public static function splitNetSecondsByDay(TimeEntry $entry, \DateTimeZone $tz, ?\DateTimeInterface $asOf = null): array
	{
		$start = $entry->getStartTime();
		if ($start === null) {
			return [];
		}
		$end = $entry->getEndTime();
		if ($end === null) {
			if ($asOf === null) {
				return []; // open entry without a live horizon — skip
			}
			$endTs = $asOf->getTimestamp();
		} else {
			$endTs = $end->getTimestamp();
		}
		$startTs = $start->getTimestamp();
		if ($endTs <= $startTs) {
			return [];
		}

		$breaks = self::breakIntervals($entry);
		$perDay = [];

		$cursor = $startTs;
		while ($cursor < $endTs) {
			$local = (new \DateTimeImmutable('@' . $cursor))->setTimezone($tz);
			$day = $local->format('Y-m-d');
			$nextMidnight = $local->setTime(0, 0)->modify('+1 day')->getTimestamp();
			$segmentEnd = min($endTs, $nextMidnight);
			$gross = $segmentEnd - $cursor;

			$breakOverlap = 0;
			foreach ($breaks as [$bStart, $bEnd]) {
				$overlap = min($segmentEnd, $bEnd) - max($cursor, $bStart);
				if ($overlap > 0) {
					$breakOverlap += $overlap;
				}
			}
			$perDay[$day] = ($perDay[$day] ?? 0) + max(0, $gross - $breakOverlap);
			$cursor = $segmentEnd;
		}
		return $perDay;
	}

	/**
	 * Recorded break intervals as [startTs, endTs] pairs, matching the
	 * entry's own break-duration semantics (stored `breaks` JSON plus an
	 * in-flight legacy break window). Malformed rows are skipped.
	 *
	 * @return list<array{0:int,1:int}>
	 */
	private static function breakIntervals(TimeEntry $entry): array
	{
		$intervals = [];
		$raw = $entry->getBreaks();
		if (is_string($raw) && $raw !== '') {
			foreach (json_decode($raw, true) ?? [] as $break) {
				if (!is_array($break) || !isset($break['start'], $break['end'])) {
					continue;
				}
				try {
					$s = (new \DateTime((string)$break['start']))->getTimestamp();
					$e = (new \DateTime((string)$break['end']))->getTimestamp();
				} catch (\Throwable) {
					continue;
				}
				if ($e > $s) {
					$intervals[] = [$s, $e];
				}
			}
		}
		$breakStart = $entry->getBreakStartTime();
		if ($breakStart !== null) {
			$end = $entry->getBreakEndTime();
			$s = $breakStart->getTimestamp();
			$e = $end !== null ? $end->getTimestamp() : time();
			if ($e > $s) {
				$intervals[] = [$s, $e];
			}
		}
		return $intervals;
	}
}
