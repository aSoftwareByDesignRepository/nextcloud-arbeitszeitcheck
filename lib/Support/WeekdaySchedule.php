<?php

declare(strict_types=1);

/**
 * Optional per-weekday work schedule + fixed unpaid break windows.
 *
 * Stored under WorkingTimeModel break_rules['weekday_schedule']. When absent or
 * invalid, OvertimeService keeps the legacy weekly_hours / 5 algorithm.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

/**
 * Immutable weekday schedule (Bachus Phase A / BANSS).
 */
final class WeekdaySchedule
{
	public const VERSION = 1;

	public const KEY = 'weekday_schedule';

	/** @var list<string> */
	public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

	/** @var array<int, string> DateTime N (1=Mon) → day key */
	private const N_TO_DAY = [
		1 => 'mon',
		2 => 'tue',
		3 => 'wed',
		4 => 'thu',
		5 => 'fri',
		6 => 'sat',
		7 => 'sun',
	];

	/**
	 * @param array<string, array{
	 *   work: bool,
	 *   start?: string,
	 *   end?: string,
	 *   breaks?: list<array{start: string, end: string, paid?: bool}>
	 * }> $days
	 */
	private function __construct(
		private readonly array $days,
	) {
	}

	/**
	 * @param array<string, mixed>|null $breakRules
	 */
	public static function tryFromBreakRules(?array $breakRules): ?self
	{
		if ($breakRules === null || $breakRules === []) {
			return null;
		}
		// Legacy accidental list encoding
		if (array_is_list($breakRules)) {
			return null;
		}
		$raw = $breakRules[self::KEY] ?? null;
		if (!is_array($raw)) {
			return null;
		}
		if (self::validate($raw) !== []) {
			return null;
		}

		return self::fromValidated($raw);
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return list<string> error codes
	 */
	public static function validate(array $raw): array
	{
		$errors = [];
		$days = $raw['days'] ?? null;
		if (!is_array($days)) {
			return ['SCHEDULE_MISSING_DAYS'];
		}

		foreach (self::DAYS as $day) {
			if (!isset($days[$day]) || !is_array($days[$day])) {
				$errors[] = 'SCHEDULE_MISSING_DAY_' . strtoupper($day);
				continue;
			}
			$row = $days[$day];
			$work = !empty($row['work']);
			if (!$work) {
				continue;
			}
			$start = isset($row['start']) ? trim((string)$row['start']) : '';
			$end = isset($row['end']) ? trim((string)$row['end']) : '';
			if ($start === '' || $end === '') {
				$errors[] = 'SCHEDULE_EMPTY_WORKDAY';
				continue;
			}
			$startMin = self::parseHm($start);
			$endMin = self::parseHm($end);
			if ($startMin === null || $endMin === null) {
				$errors[] = 'SCHEDULE_INVALID_TIME';
				continue;
			}
			if ($endMin <= $startMin) {
				$errors[] = 'SCHEDULE_END_BEFORE_START';
				continue;
			}
			$breaks = $row['breaks'] ?? [];
			if ($breaks !== null && !is_array($breaks)) {
				$errors[] = 'SCHEDULE_INVALID_BREAK';
				continue;
			}
			foreach ((array)$breaks as $break) {
				if (!is_array($break)) {
					$errors[] = 'SCHEDULE_INVALID_BREAK';
					continue;
				}
				$bStart = self::parseHm(isset($break['start']) ? (string)$break['start'] : '');
				$bEnd = self::parseHm(isset($break['end']) ? (string)$break['end'] : '');
				if ($bStart === null || $bEnd === null || $bEnd <= $bStart) {
					$errors[] = 'SCHEDULE_INVALID_BREAK';
					continue;
				}
				if ($bStart < $startMin || $bEnd > $endMin) {
					$errors[] = 'SCHEDULE_INVALID_BREAK';
				}
			}
		}

		return array_values(array_unique($errors));
	}

	/**
	 * @param array<string, mixed> $raw validated
	 */
	public static function fromValidated(array $raw): self
	{
		$daysIn = is_array($raw['days'] ?? null) ? $raw['days'] : [];
		$normalized = [];
		foreach (self::DAYS as $day) {
			$row = is_array($daysIn[$day] ?? null) ? $daysIn[$day] : ['work' => false];
			$work = !empty($row['work']);
			if (!$work) {
				$normalized[$day] = ['work' => false];
				continue;
			}
			$breaks = [];
			foreach ((array)($row['breaks'] ?? []) as $break) {
				if (!is_array($break)) {
					continue;
				}
				$breaks[] = [
					'start' => (string)$break['start'],
					'end' => (string)$break['end'],
					'paid' => !empty($break['paid']),
				];
			}
			$normalized[$day] = [
				'work' => true,
				'start' => (string)$row['start'],
				'end' => (string)$row['end'],
				'breaks' => $breaks,
			];
		}

		return new self($normalized);
	}

	/**
	 * BANSS weekday preset (Q5 LOCKED): Mo–Thu 07:00–16:15 − 45′ unpaid = **8.50 h** net;
	 * Fri 07:00–11:45 − 15′ unpaid = **4.50 h** net; week sum **38.50 h** (not 8.25 / 37.5).
	 * Editable after apply.
	 *
	 * @return array{version: int, days: array<string, mixed>}
	 */
	public static function banssPreset(): array
	{
		$long = [
			'work' => true,
			'start' => '07:00',
			'end' => '16:15',
			'breaks' => [
				['start' => '12:15', 'end' => '13:00', 'paid' => false],
			],
		];
		$fri = [
			'work' => true,
			'start' => '07:00',
			'end' => '11:45',
			'breaks' => [
				['start' => '09:00', 'end' => '09:15', 'paid' => false],
			],
		];
		$off = ['work' => false];

		return [
			'version' => self::VERSION,
			'days' => [
				'mon' => $long,
				'tue' => $long,
				'wed' => $long,
				'thu' => $long,
				'fri' => $fri,
				'sat' => $off,
				'sun' => $off,
			],
		];
	}

	public function netHoursForWeekday(string $day): float
	{
		$day = strtolower($day);
		$row = $this->days[$day] ?? null;
		if ($row === null || empty($row['work'])) {
			return 0.0;
		}
		$start = self::parseHm((string)$row['start']);
		$end = self::parseHm((string)$row['end']);
		if ($start === null || $end === null || $end <= $start) {
			return 0.0;
		}
		$gross = ($end - $start) / 60.0;
		$unpaid = 0.0;
		foreach ((array)($row['breaks'] ?? []) as $break) {
			if (!is_array($break) || !empty($break['paid'])) {
				continue;
			}
			$bStart = self::parseHm((string)($break['start'] ?? ''));
			$bEnd = self::parseHm((string)($break['end'] ?? ''));
			if ($bStart === null || $bEnd === null || $bEnd <= $bStart) {
				continue;
			}
			$unpaid += ($bEnd - $bStart) / 60.0;
		}

		return round(max(0.0, $gross - $unpaid), 4);
	}

	public function weeklyNetHours(): float
	{
		$sum = 0.0;
		foreach (self::DAYS as $day) {
			$sum += $this->netHoursForWeekday($day);
		}

		return round($sum, 4);
	}

	public function workDaysPerWeek(): float
	{
		$n = 0;
		foreach (self::DAYS as $day) {
			if (!empty($this->days[$day]['work'])) {
				$n++;
			}
		}

		return (float)$n;
	}

	public function isWorkDay(string $day): bool
	{
		return !empty($this->days[$day]['work']);
	}

	public function averageDailyNetHours(): float
	{
		$days = $this->workDaysPerWeek();
		if ($days < 0.0001) {
			return 0.0;
		}

		return round($this->weeklyNetHours() / $days, 4);
	}

	/**
	 * @param callable(\DateTime): float $holidayWeightForDate 0.0 none, 0.5 half, 1.0 full
	 */
	public function requiredHoursForDateRange(\DateTime $start, \DateTime $end, callable $holidayWeightForDate): float
	{
		$cursor = (clone $start)->setTime(0, 0, 0);
		$last = (clone $end)->setTime(0, 0, 0);
		if ($last < $cursor) {
			return 0.0;
		}

		$total = 0.0;
		while ($cursor <= $last) {
			$n = (int)$cursor->format('N');
			$dayKey = self::N_TO_DAY[$n] ?? 'mon';
			$net = $this->netHoursForWeekday($dayKey);
			if ($net > 0.0) {
				$weight = (float)$holidayWeightForDate(clone $cursor);
				if ($weight >= 1.0) {
					$fraction = 0.0;
				} elseif ($weight > 0.0) {
					$fraction = max(0.0, 1.0 - $weight);
				} else {
					$fraction = 1.0;
				}
				$total += $net * $fraction;
			}
			$cursor->modify('+1 day');
		}

		return round($total, 4);
	}

	/**
	 * @return array{version: int, days: array<string, mixed>}
	 */
	public function toArray(): array
	{
		return [
			'version' => self::VERSION,
			'days' => $this->days,
		];
	}

	/**
	 * Merge schedule into break_rules without dropping legacy keys.
	 *
	 * @param array<string, mixed>|null $breakRules
	 * @param array<string, mixed> $schedulePayload from toArray()/banssPreset()
	 * @return array<string, mixed>
	 */
	public static function mergeIntoBreakRules(?array $breakRules, array $schedulePayload): array
	{
		$base = is_array($breakRules) && !array_is_list($breakRules) ? $breakRules : [];
		$base[self::KEY] = $schedulePayload;

		return $base;
	}

	private static function parseHm(string $hm): ?int
	{
		$hm = trim($hm);
		if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hm, $m)) {
			return null;
		}
		$h = (int)$m[1];
		$min = (int)$m[2];
		if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
			return null;
		}

		return $h * 60 + $min;
	}
}
