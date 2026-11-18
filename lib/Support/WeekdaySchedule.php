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
 *
 * Version 1: one outer daily span (`start`/`end`) + fixed breaks.
 * Version 2: explicit work `blocks[]` per day (sorted, disjoint, same-day);
 * gaps between blocks are derived, never stored. `interruption_mode`
 * `recorded_only` keeps v1 semantics; further modes are reserved.
 *
 * v2 may carry an optional `split_service` group (split-day working model):
 * canonical stored form is exactly the three keys `automatic_split`,
 * `split_when_clock_out_after` (null iff automatic off) and
 * `interruption_counts_as_break`. Absent ≡ all off.
 */
final class WeekdaySchedule
{
	public const VERSION = 1;
	public const VERSION_V2 = 2;

	public const KEY = 'weekday_schedule';

	public const SPLIT_SERVICE_KEY = 'split_service';

	/** @var list<string> canonical nested keys of the split_service group */
	private const SPLIT_SERVICE_KEYS = [
		'automatic_split',
		'split_when_clock_out_after',
		'interruption_counts_as_break',
		'weekday_cutoffs',
	];

	public const MAX_BLOCKS_PER_DAY = 4;
	public const MAX_BREAKS_PER_DAY = 8;
	public const INTERRUPTION_MODE_RECORDED_ONLY = 'recorded_only';

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
	 *   blocks?: list<array{start: string, end: string}>,
	 *   breaks?: list<array{start: string, end: string, paid?: bool}>
	 * }> $days
	 * @param array{automatic_split: bool, split_when_clock_out_after: ?string, interruption_counts_as_break: bool}|null $splitService
	 *   canonical v2 split_service group, null when the document carries none
	 */
	private function __construct(
		private readonly array $days,
		private readonly int $version = self::VERSION,
		private readonly string $interruptionMode = self::INTERRUPTION_MODE_RECORDED_ONLY,
		private readonly ?array $splitService = null,
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
	 * Strict-bool parse for stored form-ish flags — a "false"/"0" string must
	 * never be truthy (fail closed: not-working / unpaid).
	 */
	private static function truthyFlag(mixed $value): bool
	{
		return $value === true || $value === 1 || $value === '1'
			|| (is_string($value) && strtolower(trim($value)) === 'true');
	}

	/**
	 * Schedule document version: explicit `version` wins; absent means v1.
	 * Non-integer-ish values fail closed as unknown.
	 *
	 * @param array<string, mixed> $raw
	 */
	public static function versionOf(array $raw): ?int
	{
		$v = $raw['version'] ?? self::VERSION;
		if (is_int($v)) {
			return $v;
		}
		if (is_string($v) && preg_match('/^\d+$/', $v) === 1) {
			return (int)$v;
		}

		return null;
	}

	/**
	 * Content token of a stored weekday_schedule document — a sha256 over
	 * the stored JSON (schedules are normalized via toArray() at write, so
	 * the stored form is canonical). Public change token for schedule CAS;
	 * it only moves when the schedule itself changes. '' when absent.
	 *
	 * @param array<string, mixed>|null $schedule
	 */
	public static function revisionOf(?array $schedule): string
	{
		if (!is_array($schedule)) {
			return '';
		}
		return hash('sha256', (string)json_encode($schedule));
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return list<string> error codes
	 */
	public static function validate(array $raw): array
	{
		$version = self::versionOf($raw);
		if ($version === self::VERSION_V2) {
			return self::validateV2($raw);
		}
		if ($version !== self::VERSION) {
			return ['SCHEDULE_UNKNOWN_VERSION'];
		}
		// split_service is a v2-only group — a v1 (or versionless) document
		// carrying it must fail loudly instead of silently dropping it.
		if (array_key_exists(self::SPLIT_SERVICE_KEY, $raw)) {
			return ['SPLIT_REQUIRES_SCHEDULE_V2'];
		}

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
			$work = self::truthyFlag($row['work'] ?? null);
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
	 * Strict v2 validation. Unknown top-level/day-level keys are rejected
	 * (fail closed) — v1 stays permissive for byte-compat with stored data.
	 *
	 * @param array<string, mixed> $raw
	 * @return list<string> error codes
	 */
	private static function validateV2(array $raw): array
	{
		foreach (array_keys($raw) as $key) {
			if (!in_array($key, ['version', 'interruption_mode', 'days', self::SPLIT_SERVICE_KEY], true)) {
				return ['SCHEDULE_UNKNOWN_KEY'];
			}
		}
		$mode = $raw['interruption_mode'] ?? self::INTERRUPTION_MODE_RECORDED_ONLY;
		if ($mode !== self::INTERRUPTION_MODE_RECORDED_ONLY) {
			return ['SCHEDULE_UNKNOWN_INTERRUPTION_MODE'];
		}
		$days = $raw['days'] ?? null;
		if (!is_array($days)) {
			return ['SCHEDULE_MISSING_DAYS'];
		}

		$errors = [];
		// split_service structural validation (non-array, unknown nested keys,
		// strict booleans, cut-off shape). Cross-checks against the schedule
		// geometry run after the day loop below.
		$splitInfo = null;
		if (array_key_exists(self::SPLIT_SERVICE_KEY, $raw)) {
			[$splitErrors, $splitInfo] = self::validateSplitService($raw[self::SPLIT_SERVICE_KEY]);
			foreach ($splitErrors as $splitError) {
				$errors[] = $splitError;
			}
		}
		$latestGapEnd = null;
		$gapEndByDay = [];
		$hasMultiBlockDay = false;
		foreach (self::DAYS as $day) {
			if (!isset($days[$day]) || !is_array($days[$day])) {
				$errors[] = 'SCHEDULE_MISSING_DAY_' . strtoupper($day);
				continue;
			}
			$row = $days[$day];
			foreach (array_keys($row) as $key) {
				if (!in_array($key, ['work', 'blocks', 'breaks'], true)) {
					$errors[] = 'SCHEDULE_UNKNOWN_DAY_KEY';
				}
			}
			$work = self::truthyFlag($row['work'] ?? null);
			if (!$work) {
				// Explicitly empty on non-work days; stray content is an error.
				if ((array)($row['blocks'] ?? []) !== [] || (array)($row['breaks'] ?? []) !== []) {
					$errors[] = 'SCHEDULE_NONWORKDAY_CONTENT';
				}
				continue;
			}
			$blocks = $row['blocks'] ?? null;
			if (!is_array($blocks) || $blocks === [] || count($blocks) > self::MAX_BLOCKS_PER_DAY) {
				$errors[] = 'SCHEDULE_INVALID_BLOCKS';
				continue;
			}
			$prevEnd = null;
			$envelopeStart = null;
			$envelopeEnd = null;
			$dayIntervals = [];
			foreach ($blocks as $block) {
				if (!is_array($block)) {
					$errors[] = 'SCHEDULE_INVALID_BLOCK';
					continue;
				}
				$bStart = self::parseHm(isset($block['start']) ? (string)$block['start'] : '');
				$bEnd = self::parseHm(isset($block['end']) ? (string)$block['end'] : '');
				if ($bStart === null || $bEnd === null || $bEnd <= $bStart) {
					$errors[] = 'SCHEDULE_INVALID_BLOCK';
					continue;
				}
				if ($prevEnd !== null && $bStart < $prevEnd) {
					$errors[] = 'SCHEDULE_BLOCKS_OVERLAP';
				} elseif ($prevEnd !== null && $bStart === $prevEnd) {
					// Touching blocks share a boundary — a zero-length "gap" is not
					// an interruption. The admin merges them explicitly (spec §9.2).
					$errors[] = 'SCHEDULE_BLOCK_TOUCHING';
				}
				$prevEnd = $bEnd;
				$dayIntervals[] = [$bStart, $bEnd];
				$envelopeStart = $envelopeStart === null ? $bStart : min($envelopeStart, $bStart);
				$envelopeEnd = $envelopeEnd === null ? $bEnd : max($envelopeEnd, $bEnd);
			}
			// Interruption geometry for split_service cross-checks: a gap is
			// the idle interval between consecutive blocks and ends when the
			// next block starts.
			if (count($dayIntervals) >= 2) {
				$hasMultiBlockDay = true;
				for ($i = 1, $n = count($dayIntervals); $i < $n; $i++) {
					if ($dayIntervals[$i][0] > $dayIntervals[$i - 1][1]) {
						$latestGapEnd = max($latestGapEnd ?? 0, $dayIntervals[$i][0]);
						$gapEndByDay[$day] = max($gapEndByDay[$day] ?? 0, $dayIntervals[$i][0]);
					}
				}
			}
			$breaks = $row['breaks'] ?? [];
			if ($breaks !== null && !is_array($breaks)) {
				$errors[] = 'SCHEDULE_INVALID_BREAK';
				continue;
			}
			if (count((array)$breaks) > self::MAX_BREAKS_PER_DAY) {
				$errors[] = 'SCHEDULE_TOO_MANY_BREAKS';
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
				if ($envelopeStart === null || $bStart < $envelopeStart || $bEnd > $envelopeEnd) {
					$errors[] = 'SCHEDULE_BREAK_OUTSIDE_BLOCKS';
				}
			}
		}

		// split_service cross-checks against the parsed schedule geometry.
		if ($splitInfo !== null) {
			if ($splitInfo['automatic'] && $splitInfo['cutoffMinutes'] !== null
				&& $latestGapEnd !== null && $splitInfo['cutoffMinutes'] < $latestGapEnd) {
				$errors[] = 'SPLIT_CUTOFF_BEFORE_INTERRUPTION_END';
			}
			foreach ($splitInfo['weekdayCutoffs'] as $cutoffDay => $cutoffMin) {
				if (!isset($gapEndByDay[$cutoffDay])) {
					// A per-weekday cut-off on a day without an interruption
					// can never fire — that is a configuration error, not a
					// harmless no-op.
					$errors[] = 'SPLIT_NO_INTERRUPTION';
				} elseif ($cutoffMin < $gapEndByDay[$cutoffDay]) {
					$errors[] = 'SPLIT_CUTOFF_BEFORE_INTERRUPTION_END';
				}
			}
			if (($splitInfo['automatic'] || $splitInfo['breakCover']) && !$hasMultiBlockDay) {
				$errors[] = 'SPLIT_NO_INTERRUPTION';
			}
		}

		return array_values(array_unique($errors));
	}

	/**
	 * Structural validation of a `split_service` group. Strict on purpose:
	 * booleans must be real JSON booleans ("false"/"0"/1 are rejected here,
	 * unlike legacy form-encoded flags), unknown nested keys fail closed, and
	 * the cut-off must match the strict `HH:MM` shape (no 24:00).
	 *
	 * @param mixed $split value of the `split_service` key
	 * @return array{0: list<string>, 1: ?array{automatic: bool, cutoffMinutes: ?int, breakCover: bool}}
	 *   error codes plus parsed info (null when the group is structurally
	 *   invalid — cross-checks are then skipped)
	 */
	private static function validateSplitService(mixed $split): array
	{
		if (!is_array($split)) {
			return [['SPLIT_CONFIG_INVALID'], null];
		}
		foreach (array_keys($split) as $key) {
			if (!in_array($key, self::SPLIT_SERVICE_KEYS, true)) {
				return [['SCHEDULE_UNKNOWN_KEY'], null];
			}
		}
		$automatic = $split['automatic_split'] ?? null;
		$breakCover = $split['interruption_counts_as_break'] ?? null;
		if (!is_bool($automatic) || !is_bool($breakCover)) {
			return [['SPLIT_CONFIG_INVALID'], null];
		}
		$cutoff = $split['split_when_clock_out_after'] ?? null;
		$cutoffMinutes = null;
		if ($automatic) {
			if (!is_string($cutoff) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $cutoff) !== 1) {
				return [['SPLIT_CUTOFF_INVALID'], null];
			}
			$cutoffMinutes = ((int)substr($cutoff, 0, 2)) * 60 + (int)substr($cutoff, 3, 2);
		} elseif ($cutoff !== null) {
			// Cut-off only exists while automatic split is on — a stored
			// value with automatic off is contradictory state.
			return [['SPLIT_CUTOFF_INVALID'], null];
		}

		$weekdayCutoffs = [];
		$weekdayCutoffsRaw = $split['weekday_cutoffs'] ?? null;
		if ($weekdayCutoffsRaw !== null) {
			if (!is_array($weekdayCutoffsRaw) || array_is_list($weekdayCutoffsRaw) && $weekdayCutoffsRaw !== []) {
				return [['SPLIT_CONFIG_INVALID'], null];
			}
			if (!$automatic) {
				// Same contradiction rule as the default cut-off: overrides
				// only exist while automatic split is on.
				return [['SPLIT_CUTOFF_INVALID'], null];
			}
			foreach ($weekdayCutoffsRaw as $day => $value) {
				if (!is_string($day) || !in_array($day, self::DAYS, true)) {
					return [['SCHEDULE_UNKNOWN_KEY'], null];
				}
				if (!is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
					return [['SPLIT_CUTOFF_INVALID'], null];
				}
				$weekdayCutoffs[$day] = ((int)substr($value, 0, 2)) * 60 + (int)substr($value, 3, 2);
			}
		}

		return [[], [
			'automatic' => $automatic,
			'cutoffMinutes' => $cutoffMinutes,
			'breakCover' => $breakCover,
			'weekdayCutoffs' => $weekdayCutoffs,
		]];
	}

	/**
	 * @param array<string, mixed> $raw validated
	 */
	public static function fromValidated(array $raw): self
	{
		if (self::versionOf($raw) === self::VERSION_V2) {
			return self::fromValidatedV2($raw);
		}
		$daysIn = is_array($raw['days'] ?? null) ? $raw['days'] : [];
		$normalized = [];
		foreach (self::DAYS as $day) {
			$row = is_array($daysIn[$day] ?? null) ? $daysIn[$day] : ['work' => false];
			$work = self::truthyFlag($row['work'] ?? null);
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
					'paid' => self::truthyFlag($break['paid'] ?? null),
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
	 * @param array<string, mixed> $raw validated v2 document
	 */
	private static function fromValidatedV2(array $raw): self
	{
		$daysIn = is_array($raw['days'] ?? null) ? $raw['days'] : [];
		$normalized = [];
		foreach (self::DAYS as $day) {
			$row = is_array($daysIn[$day] ?? null) ? $daysIn[$day] : ['work' => false];
			$work = self::truthyFlag($row['work'] ?? null);
			if (!$work) {
				$normalized[$day] = ['work' => false];
				continue;
			}
			$blocks = [];
			foreach ((array)($row['blocks'] ?? []) as $block) {
				if (!is_array($block)) {
					continue;
				}
				$blocks[] = [
					'start' => (string)$block['start'],
					'end' => (string)$block['end'],
				];
			}
			usort($blocks, static fn(array $a, array $b): int => strcmp((string)$a['start'], (string)$b['start']));
			$breaks = [];
			foreach ((array)($row['breaks'] ?? []) as $break) {
				if (!is_array($break)) {
					continue;
				}
				$breaks[] = [
					'start' => (string)$break['start'],
					'end' => (string)$break['end'],
					'paid' => self::truthyFlag($break['paid'] ?? null),
				];
			}
			$normalized[$day] = [
				'work' => true,
				'blocks' => $blocks,
				'breaks' => $breaks,
			];
		}

		$mode = $raw['interruption_mode'] ?? self::INTERRUPTION_MODE_RECORDED_ONLY;

		// Canonical split_service form: exactly the three keys, cut-off null
		// iff automatic off. Input is validated, so booleans are real bools —
		// deliberately no truthyFlag() here ("false" strings are already
		// rejected by validateSplitService, never coerced).
		$split = null;
		$splitRaw = $raw[self::SPLIT_SERVICE_KEY] ?? null;
		if (is_array($splitRaw)) {
			$automatic = ($splitRaw['automatic_split'] ?? null) === true;
			$cutoffRaw = $splitRaw['split_when_clock_out_after'] ?? null;
			$split = [
				'automatic_split' => $automatic,
				'split_when_clock_out_after' => $automatic && is_string($cutoffRaw) ? $cutoffRaw : null,
				'interruption_counts_as_break' => ($splitRaw['interruption_counts_as_break'] ?? null) === true,
			];
			$weekdayCutoffsRaw = $automatic && is_array($splitRaw['weekday_cutoffs'] ?? null)
				? $splitRaw['weekday_cutoffs']
				: [];
			if ($weekdayCutoffsRaw !== []) {
				$ordered = [];
				foreach (self::DAYS as $day) {
					if (isset($weekdayCutoffsRaw[$day]) && is_string($weekdayCutoffsRaw[$day])) {
						$ordered[$day] = $weekdayCutoffsRaw[$day];
					}
				}
				if ($ordered !== []) {
					$split['weekday_cutoffs'] = $ordered;
				}
			}
		}

		return new self($normalized, self::VERSION_V2, (string)$mode, $split);
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
		if ($this->version === self::VERSION_V2) {
			return $this->netHoursForWeekdayV2($row);
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

	/**
	 * v2 day net: sum of block spans minus unpaid break minutes that overlap
	 * a block. Break minutes inside a block gap are not working time anyway
	 * and must not be deducted twice — each break is clipped to block union.
	 *
	 * @param array<string, mixed> $row normalized v2 day row
	 */
	private function netHoursForWeekdayV2(array $row): float
	{
		$blockMinutes = $this->blockIntervalsForDay($row);
		$gross = 0.0;
		foreach ($blockMinutes as [$bStart, $bEnd]) {
			$gross += ($bEnd - $bStart) / 60.0;
		}
		$unpaid = 0.0;
		foreach ((array)($row['breaks'] ?? []) as $break) {
			if (!is_array($break) || !empty($break['paid'])) {
				continue;
			}
			$brStart = self::parseHm((string)($break['start'] ?? ''));
			$brEnd = self::parseHm((string)($break['end'] ?? ''));
			if ($brStart === null || $brEnd === null || $brEnd <= $brStart) {
				continue;
			}
			foreach ($blockMinutes as [$bStart, $bEnd]) {
				$overlap = min($bEnd, $brEnd) - max($bStart, $brStart);
				if ($overlap > 0) {
					$unpaid += $overlap / 60.0;
				}
			}
		}

		return round(max(0.0, $gross - $unpaid), 4);
	}

	/**
	 * Work-block intervals of a normalized day row, minutes-of-day, sorted.
	 * v1 rows yield their single outer span.
	 *
	 * @param array<string, mixed> $row
	 * @return list<array{0:int, 1:int}>
	 */
	private function blockIntervalsForDay(array $row): array
	{
		if ($this->version === self::VERSION_V2) {
			$out = [];
			foreach ((array)($row['blocks'] ?? []) as $block) {
				if (!is_array($block)) {
					continue;
				}
				$bStart = self::parseHm((string)($block['start'] ?? ''));
				$bEnd = self::parseHm((string)($block['end'] ?? ''));
				if ($bStart === null || $bEnd === null || $bEnd <= $bStart) {
					continue;
				}
				$out[] = [$bStart, $bEnd];
			}
			return $out;
		}
		$start = self::parseHm((string)($row['start'] ?? ''));
		$end = self::parseHm((string)($row['end'] ?? ''));
		if ($start === null || $end === null || $end <= $start) {
			return [];
		}
		return [[$start, $end]];
	}

	/**
	 * Scheduled work blocks for a weekday, minutes-of-day `[[start,end], …]`.
	 * Empty for non-work days. Same list for v1 (single outer span).
	 *
	 * @return list<array{0:int, 1:int}>
	 */
	public function blocksForWeekday(string $day): array
	{
		$day = strtolower($day);
		$row = $this->days[$day] ?? null;
		if ($row === null || empty($row['work'])) {
			return [];
		}
		return $this->blockIntervalsForDay($row);
	}

	/**
	 * Derived interruption gaps between consecutive blocks, minutes-of-day.
	 * Gaps are computed from blocks, never stored (single source of truth).
	 *
	 * @return list<array{0:int, 1:int}>
	 */
	public function gapsForWeekday(string $day): array
	{
		$blocks = $this->blocksForWeekday($day);
		$gaps = [];
		for ($i = 1, $n = count($blocks); $i < $n; $i++) {
			if ($blocks[$i][0] > $blocks[$i - 1][1]) {
				$gaps[] = [$blocks[$i - 1][1], $blocks[$i][0]];
			}
		}
		return $gaps;
	}

	public function getVersion(): int
	{
		return $this->version;
	}

	public function getInterruptionMode(): string
	{
		return $this->interruptionMode;
	}

	/**
	 * Normalized split-service options. A document without `split_service`
	 * is equivalent to all-off, so absent/off both yield
	 * `{automatic: false, cutoff: null, breakCover: false}`.
	 *
	 * @return array{automatic: bool, cutoff: ?string, breakCover: bool, weekdayCutoffs: array<string,int>}
	 */
	public function getSplitService(): array
	{
		$automatic = ($this->splitService['automatic_split'] ?? null) === true;
		$weekdayCutoffs = [];
		if ($automatic && is_array($this->splitService['weekday_cutoffs'] ?? null)) {
			foreach ($this->splitService['weekday_cutoffs'] as $day => $hm) {
				if (is_string($day) && is_string($hm)
					&& preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hm) === 1) {
					$weekdayCutoffs[$day] = ((int)substr($hm, 0, 2)) * 60 + (int)substr($hm, 3, 2);
				}
			}
		}
		return [
			'automatic' => $automatic,
			'cutoff' => $this->splitService !== null && $automatic
				? ($this->splitService['split_when_clock_out_after'] ?? null)
				: null,
			'breakCover' => ($this->splitService['interruption_counts_as_break'] ?? null) === true,
			'weekdayCutoffs' => $weekdayCutoffs,
		];
	}

	/**
	 * Effective cut-off for one weekday in minutes-of-day: the per-weekday
	 * override when configured, otherwise the model default. Null when
	 * automatic split is off.
	 */
	public function splitCutoffMinutesForWeekday(string $weekday): ?int
	{
		$split = $this->getSplitService();
		if (!$split['automatic']) {
			return null;
		}
		return $split['weekdayCutoffs'][$weekday] ?? $this->hmToMinutesNullable($split['cutoff']);
	}

	/**
	 * Resolved effective cut-offs (override ?? default) as "HH:MM" for every
	 * weekday that has at least one scheduled interruption. Frozen into the
	 * entry context so the pinned rule is fully self-contained.
	 *
	 * @return array<string,string>
	 */
	public function splitWeekdayCutoffsHm(): array
	{
		$resolved = [];
		foreach (self::DAYS as $day) {
			if (count($this->blocksForWeekday($day)) < 2) {
				continue;
			}
			$minutes = $this->splitCutoffMinutesForWeekday($day);
			if ($minutes !== null) {
				$resolved[$day] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
			}
		}
		return $resolved;
	}

	private function hmToMinutesNullable(?string $hm): ?int
	{
		if (!is_string($hm) || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $hm) !== 1) {
			return null;
		}
		return ((int)substr($hm, 0, 2)) * 60 + (int)substr($hm, 3, 2);
	}

	/**
	 * True iff any split-service option is on (automatic split or
	 * interruption-counts-as-break).
	 */
	public function hasActiveSplitService(): bool
	{
		$s = $this->getSplitService();
		return $s['automatic'] || $s['breakCover'];
	}

	/**
	 * Latest interruption (gap) end across all work weekdays, in
	 * minutes-of-day — i.e. the latest moment a scheduled interruption
	 * ends and work resumes. Null when no day has a gap.
	 */
	public function latestGapEndMinutes(): ?int
	{
		$latest = null;
		foreach (self::DAYS as $day) {
			foreach ($this->gapsForWeekday($day) as [, $gapEnd]) {
				$latest = $latest === null ? $gapEnd : max($latest, $gapEnd);
			}
		}
		return $latest;
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
	 * @return array{version: int, interruption_mode?: string, split_service?: array<string, mixed>, days: array<string, mixed>}
	 */
	public function toArray(): array
	{
		$out = [
			'version' => $this->version,
			'days' => $this->days,
		];
		if ($this->version === self::VERSION_V2) {
			$out['interruption_mode'] = $this->interruptionMode;
			if ($this->splitService !== null) {
				$out[self::SPLIT_SERVICE_KEY] = $this->splitService;
			}
		}
		return $out;
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
