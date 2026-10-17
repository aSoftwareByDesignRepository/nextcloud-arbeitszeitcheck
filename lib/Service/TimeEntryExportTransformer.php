<?php

declare(strict_types=1);

/**
 * Converts TimeEntry entities to export row arrays (optional midnight split, multi-day spans).
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCP\IConfig;

/**
 * Shared logic for CSV/JSON time exports (personal and team detailed).
 */
class TimeEntryExportTransformer
{
	private const HOUR_ROUND_PRECISION = 4;
	private string $timezone;

	/**
	 * Max Von/Bis pairs per row in wide daily layout (overflow uses extra rows).
	 */
	public const WIDE_LAYOUT_MAX_PAIRS_PER_ROW = 6;

	public function __construct(IConfig $config, private readonly ?WorkLocationCatalog $workLocationCatalog = null)
	{
		$configured = $config->getAppValue('arbeitszeitcheck', 'app_timezone', '');
		if ($configured !== '' && timezone_open($configured) !== false) {
			$this->timezone = $configured;
			return;
		}

		$phpTimezone = date_default_timezone_get();
		$this->timezone = ($phpTimezone !== '' && $phpTimezone !== 'UTC') ? $phpTimezone : 'Europe/Berlin';
	}

	/**
	 * Localized label + reporting category for a stored work-location code.
	 * Unknown/custom codes degrade to (code, 'other'); NULL stays empty.
	 * Without an injected catalog (legacy constructions) columns stay empty —
	 * the code column always remains authoritative.
	 *
	 * @return array{0:string,1:string}
	 */
	private function workLocationMeta(?string $code): array
	{
		if ($code === null || $code === '' || $this->workLocationCatalog === null) {
			return ['', ''];
		}
		return [
			$this->workLocationCatalog->labelFor($code),
			$this->workLocationCatalog->categoryOf($code) ?? 'other',
		];
	}

	public function getTimezone(): string
	{
		return $this->timezone;
	}

	/**
	 * @param iterable<TimeEntry> $entries
	 * @return list<array<string, mixed>>
	 */
	public function entriesToExportRows(iterable $entries, bool $enableMidnightSplit): array
	{
		$rows = [];
		foreach ($entries as $entry) {
			foreach ($this->entryToExportRows($entry, $enableMidnightSplit) as $row) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	/**
	 * @param list<array<string, mixed>> $longRows Rows from entriesToExportRows with optional user_id, display_name
	 * @return list<array<string, string>>
	 */
	public function longExportRowsToWideDaily(
		array $longRows,
		callable $weekdayFormatter
	): array {
		/** @var array<string, list<array{start: string, end: string}>> $grouped */
		$grouped = [];
		foreach ($longRows as $row) {
			$dateStr = (string)($row['date'] ?? '');
			if ($dateStr === '') {
				continue;
			}
			$uid = (string)($row['user_id'] ?? '');
			$key = $uid . '|' . $dateStr;
			if (!isset($grouped[$key])) {
				$grouped[$key] = [];
			}
			$grouped[$key][] = [
				'start' => (string)($row['start_time'] ?? ''),
				'end' => (string)($row['end_time'] ?? ''),
			];
		}
		ksort($grouped);

		$out = [];
		foreach ($grouped as $key => $pairs) {
			[$uid, $dateStr] = explode('|', $key, 2);
			usort($pairs, static function ($a, $b) {
				return strcmp($a['start'], $b['start']);
			});
			$weekday = $weekdayFormatter($dateStr);
			$displayName = '';
			foreach ($longRows as $r) {
				if (($r['date'] ?? '') === $dateStr && (string)($r['user_id'] ?? '') === $uid) {
					$displayName = (string)($r['display_name'] ?? '');
					break;
				}
			}
			$chunks = array_chunk($pairs, self::WIDE_LAYOUT_MAX_PAIRS_PER_ROW);
			$part = 0;
			foreach ($chunks as $chunk) {
				$dObj = \DateTime::createFromFormat('Y-m-d', $dateStr);
				$dateDisplay = $dObj ? $dObj->format('d.m.Y') : $dateStr;
				$flat = [
					'date' => $dateDisplay,
					'weekday' => $part === 0 ? $weekday : '',
					'row_part' => (string)($part + 1),
					'user_id' => $uid,
					'display_name' => $displayName,
				];
				$n = 1;
				foreach ($chunk as $seg) {
					$flat['von_' . $n] = $this->formatTimeForWide($seg['start']);
					$flat['bis_' . $n] = $this->formatTimeForWide($seg['end']);
					$n++;
				}
				for (; $n <= self::WIDE_LAYOUT_MAX_PAIRS_PER_ROW; $n++) {
					$flat['von_' . $n] = '';
					$flat['bis_' . $n] = '';
				}
				$out[] = $flat;
				$part++;
			}
		}
		return $out;
	}

	private function formatTimeForWide(string $hms): string
	{
		if ($hms === '') {
			return '';
		}
		// Payroll-style: segment ending at end-of-day before midnight shows as 0:00 (not 23:59)
		if (str_starts_with($hms, '23:59:')) {
			return '0:00';
		}
		$parts = explode(':', $hms);
		if (count($parts) >= 2) {
			$h = (int)$parts[0];
			$m = (int)$parts[1];
			return sprintf('%d:%02d', $h, $m);
		}
		return $hms;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function entryToExportRows(TimeEntry $entry, bool $enableMidnightSplit): array
	{
		$startTime = $entry->getStartTime();
		if (!$startTime) {
			return [];
		}
		$endTime = $entry->getEndTime();
		$startForExport = (clone $startTime)->setTimezone(new \DateTimeZone($this->timezone));
		$endForExport = $endTime ? (clone $endTime)->setTimezone(new \DateTimeZone($this->timezone)) : null;

		if (!$enableMidnightSplit || $endForExport === null) {
			return [$this->singleRowFromEntry($entry, $startForExport, $endForExport)];
		}

		if ($startForExport->format('Y-m-d') === $endForExport->format('Y-m-d')) {
			return [$this->singleRowFromEntry($entry, $startForExport, $endForExport)];
		}

		$startTs = $startForExport->getTimestamp();
		$endTs = $endForExport->getTimestamp();
		if ($endTs <= $startTs) {
			return [$this->singleRowFromEntry($entry, $startForExport, $endForExport)];
		}

		$segments = $this->splitIntoCalendarDaySegments($startForExport, $endForExport);
		if (count($segments) <= 1) {
			return [$this->singleRowFromEntry($entry, $startForExport, $endForExport)];
		}

		$totalDurationSeconds = $endTs - $startTs;
		$totalDurationHours = $entry->getTotalDurationHours();
		$totalWorkingHours = $entry->getWorkingDurationHours();
		$totalBreakHours = $entry->getBreakDurationHours();

		if ($totalDurationHours === null || $totalDurationHours <= 0) {
			return [$this->singleRowFromEntry($entry, $startForExport, $endForExport)];
		}

		$rows = [];
		foreach ($segments as [$segStart, $segEnd]) {
			$segSeconds = $segEnd->getTimestamp() - $segStart->getTimestamp();
			if ($segSeconds <= 0) {
				continue;
			}
			$ratio = $segSeconds / $totalDurationSeconds;
			$durationHours = $segSeconds / 3600.0;
			$workingPart = $totalWorkingHours !== null ? $totalWorkingHours * $ratio : null;
			$breakPart = $totalBreakHours !== null ? $totalBreakHours * $ratio : null;

			$rows[] = [
				'id' => $entry->getId(),
				'date' => $segStart->format('Y-m-d'),
				'start_time' => $segStart->format('H:i:s'),
				'end_time' => $segEnd->format('H:i:s'),
				'break_start' => '',
				'break_end' => '',
				'break_periods' => '',
				'duration_hours' => round($durationHours, self::HOUR_ROUND_PRECISION),
				'break_duration_hours' => $breakPart !== null ? round($breakPart, self::HOUR_ROUND_PRECISION) : null,
				'working_hours' => $workingPart !== null ? round($workingPart, self::HOUR_ROUND_PRECISION) : null,
				'description' => $entry->getDescription() ?? '',
				'work_location' => $entry->getWorkLocation() ?? '',
				'work_location_label' => $this->workLocationMeta($entry->getWorkLocation())[0],
				'work_location_category' => $this->workLocationMeta($entry->getWorkLocation())[1],
				'status' => $entry->getStatus(),
				'ended_reason' => $entry->getEndedReason() ?? '',
				'policy_applied' => $entry->getPolicyApplied() ?? '',
				'is_manual_entry' => $entry->getIsManualEntry() ? 'Yes' : 'No',
				'project_id' => $entry->getProjectCheckProjectId() ?? '',
			];
		}

		return $rows !== [] ? $rows : [$this->singleRowFromEntry($entry, $startForExport, $endForExport)];
	}

	/**
	 * Split [start, end] into contiguous segments that each lie within a single calendar day.
	 *
	 * @return list<array{\DateTime, \DateTime}>
	 */
	private function splitIntoCalendarDaySegments(\DateTime $startTime, \DateTime $endTime): array
	{
		$segments = [];
		$cur = clone $startTime;
		$end = clone $endTime;
		while ($cur < $end) {
			$nextMidnight = clone $cur;
			$nextMidnight->setTime(0, 0, 0);
			if ($nextMidnight->getTimestamp() <= $cur->getTimestamp()) {
				$nextMidnight->modify('+1 day');
			}
			if ($end < $nextMidnight) {
				$segments[] = [clone $cur, clone $end];
				break;
			}
			$segEnd = clone $nextMidnight;
			$segEnd->modify('-1 second');
			$segments[] = [clone $cur, $segEnd];
			$cur = clone $nextMidnight;
		}
		return $segments;
	}

	private function singleRowFromEntry(TimeEntry $entry, \DateTime $startTime, ?\DateTime $endTime): array
	{
		$breaks = $this->formatBreaksForExport($entry);
		$breakPeriods = implode('; ', array_map(
			static fn(array $period): string => $period['start'] . ' - ' . $period['end'],
			$breaks
		));

		return [
			'id' => $entry->getId(),
			'date' => $startTime->format('Y-m-d'),
			'start_time' => $startTime->format('H:i:s'),
			'end_time' => $endTime ? $endTime->format('H:i:s') : '',
			'break_start' => $breaks[0]['start'] ?? '',
			'break_end' => $breaks[0]['end'] ?? '',
			'break_periods' => $breakPeriods,
			'duration_hours' => $entry->getTotalDurationHours(),
			'break_duration_hours' => $entry->getBreakDurationHours(),
			'working_hours' => $entry->getWorkingDurationHours(),
			'description' => $entry->getDescription() ?? '',
			'work_location' => $entry->getWorkLocation() ?? '',
			'work_location_label' => $this->workLocationMeta($entry->getWorkLocation())[0],
			'work_location_category' => $this->workLocationMeta($entry->getWorkLocation())[1],
			'status' => $entry->getStatus(),
			'ended_reason' => $entry->getEndedReason() ?? '',
			'policy_applied' => $entry->getPolicyApplied() ?? '',
			'is_manual_entry' => $entry->getIsManualEntry() ? 'Yes' : 'No',
			'project_id' => $entry->getProjectCheckProjectId() ?? '',
		];
	}

	/**
	 * @return list<array{start: string, end: string}>
	 */
	private function formatBreaksForExport(TimeEntry $entry): array
	{
		$tz = new \DateTimeZone($this->timezone);
		$periods = [];
		$breaksJson = $entry->getBreaks();
		if ($breaksJson !== null && $breaksJson !== '') {
			foreach (json_decode($breaksJson, true) ?? [] as $break) {
				if (!isset($break['start'], $break['end'])) {
					continue;
				}
				try {
					$start = (new \DateTime((string)$break['start']))->setTimezone($tz);
					$end = (new \DateTime((string)$break['end']))->setTimezone($tz);
				} catch (\Throwable $e) {
					continue;
				}
				$periods[] = ['start' => $start->format('H:i:s'), 'end' => $end->format('H:i:s')];
			}
		}

		if ($entry->getBreakStartTime() !== null && $entry->getBreakEndTime() !== null) {
			$start = (clone $entry->getBreakStartTime())->setTimezone($tz);
			$end = (clone $entry->getBreakEndTime())->setTimezone($tz);
			$periods[] = ['start' => $start->format('H:i:s'), 'end' => $end->format('H:i:s')];
		}

		return $periods;
	}
}
