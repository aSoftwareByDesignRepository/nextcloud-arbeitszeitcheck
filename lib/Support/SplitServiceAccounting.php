<?php

declare(strict_types=1);

/**
 * Pure split-service-day accounting engine (SPEC split-day-working-model §9.6).
 *
 * Implements the normative steps 1–8: decide whether one clocking R=[s,e)
 * is split at the scheduled interruptions of its frozen context, compute the
 * excluded interruption intervals X and the resulting own-work segments.
 *
 * Purity contract: no DB, no config, no clock reads, no container. Identical
 * inputs produce identical outputs — every derived number must be recomputable
 * from `raw stamps + context + ENGINE_VERSION` alone (A31/A32 audit contract).
 * The caller injects "now" for open-entry previews via $nowTs.
 *
 * All interval arithmetic is half-open [a,b) in integer epoch seconds.
 * Local wall times ("HH:MM" boundaries) are resolved on the entry's local
 * start date in the frozen context timezone. A boundary that is nonexistent
 * (spring-forward gap) or ambiguous (fall-back fold) on that date makes the
 * entry `review`/`dst_review` — never a guessed epoch.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class SplitServiceAccounting
{
	public const ENGINE_VERSION = 1;

	public const ERR_OVERRIDE_NOT_APPLICABLE = 'SPLIT_OVERRIDE_NOT_APPLICABLE';

	public const STATE_ACTIVE = 'active';
	public const STATE_APPLIED = 'applied';
	public const STATE_REVIEW = 'review';

	public const REASON_CLOCK_OUT_AFTER_CUTOFF = 'clock_out_after_cutoff';
	public const REASON_NOT_AFTER_CUTOFF = 'not_after_cutoff';
	public const REASON_CLOCK_IN_DURING_INTERRUPTION = 'clock_in_during_interruption';
	public const REASON_NO_INTERRUPTION_TODAY = 'no_interruption_today';
	public const REASON_CROSSES_MIDNIGHT = 'crosses_midnight';
	public const REASON_DST_REVIEW = 'dst_review';
	public const REASON_OVERRIDE_SPLIT = 'override_split';
	public const REASON_OVERRIDE_CONTINUOUS = 'override_continuous';

	public const BREAK_COVERED_BY_INTERRUPTION = 'covered_by_interruption';
	public const BREAK_STANDARD = 'standard';
	public const BREAK_STANDARD_SEGMENT_TOO_LONG = 'standard_segment_too_long';
	public const BREAK_STANDARD_BREAK_TOO_SHORT = 'standard_break_too_short';
	public const BREAK_NOT_APPLICABLE = 'not_applicable';

	public const DECISION_AUTO = 'auto';
	public const DECISION_FORCE_SPLIT = 'force_split';
	public const DECISION_FORCE_CONTINUOUS = 'force_continuous';

	public const MAX_BLOCKS = 4;
	public const MAX_WORKED_OVERRIDES = 8;

	private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';
	private const ISO_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

	// ------------------------------------------------------------------ //
	// §9.6 steps 1–8: the split decision                                   //
	// ------------------------------------------------------------------ //

	/**
	 * Resolve the split decision for one entry R=[$startTs,$endTs) against a
	 * frozen per-entry context (§9.5 shape).
	 *
	 * @param array<string,mixed> $context  decoded work_context_json
	 * @param int $startTs                  clock-in epoch seconds
	 * @param int|null $endTs               clock-out epoch; null = open entry
	 * @param int|null $nowTs               required when $endTs is null: the
	 *                                      caller's "now" for the preview
	 *                                      (spec step 1 evaluates with e = now)
	 * @return array{
	 *   state: string,                    // active | applied | review
	 *   split: bool,
	 *   reason: ?string,                  // REASON_* enum; null when error set
	 *   error: ?string,                   // ERR_* code or null
	 *   excluded: list<array{0:string,1:string}>,   // ISO 'c', context tz
	 *   segments: list<array{0:string,1:string}>,
	 *   excludedEpoch: list<array{0:int,1:int}>,    // internal use, not stored
	 *   segmentsEpoch: list<array{0:int,1:int}>,
	 *   excludedSeconds: int,
	 *   engine: int,
	 * }
	 * @throws InvalidArgumentException on corrupt context or invalid evidence
	 */
	public static function resolveSplit(array $context, int $startTs, ?int $endTs, ?int $nowTs = null): array
	{
		if ($startTs < 0) {
			throw new InvalidArgumentException('startTs must be a non-negative epoch');
		}
		$isPreview = $endTs === null;
		if ($isPreview) {
			if ($nowTs === null) {
				throw new InvalidArgumentException('nowTs is required for an open-entry (active) evaluation');
			}
			$e = $nowTs;
		} else {
			$e = $endTs;
		}
		if ($e <= $startTs) {
			throw new InvalidArgumentException('end must be strictly after start');
		}

		$cfg = self::normaliseContext($context);
		$tz = $cfg['tz'];
		$state = $isPreview ? self::STATE_ACTIVE : self::STATE_APPLIED;

		// Step 2a: same local calendar day required.
		$date = self::localDate($tz, $startTs);
		if (self::localDate($tz, $e) !== $date) {
			return self::result($state, false, self::REASON_CROSSES_MIDNIGHT, [], [[$startTs, $e]], $tz);
		}

		// Step 2b: every cutoff/block/gap boundary must resolve uniquely on
		// this local date — a nonexistent or ambiguous wall time means no
		// automatic split (state review, legacy accounting stays).
		$geometry = self::resolveDayGeometry($tz, $date, $cfg['blocks'], $cfg['cutoffLsod']);
		if ($geometry === null) {
			return self::result(self::STATE_REVIEW, false, self::REASON_DST_REVIEW, [], [[$startTs, $e]], $tz);
		}
		$gaps = $geometry['gaps'];

		// Step 3: no scheduled interruption today.
		if ($gaps === []) {
			return self::result($state, false, self::REASON_NO_INTERRUPTION_TODAY, [], [[$startTs, $e]], $tz);
		}

		// Step 4: explicit clock-in wins — only gaps the entry did NOT start
		// inside. A clock-in exactly AT the gap start still counts as a
		// candidate (boundary per spec §8 boundary note); a clock-in inside
		// the gap does not.
		$gc = [];
		foreach ($gaps as $g) {
			if ($startTs <= $g[0]) {
				$gc[] = $g;
			}
		}
		if ($gc === []) {
			return self::result($state, false, self::REASON_CLOCK_IN_DURING_INTERRUPTION, [], [[$startTs, $e]], $tz);
		}

		// Step 5: decision.
		$split = false;
		$reason = self::REASON_NOT_AFTER_CUTOFF;
		switch ($cfg['decision']) {
			case self::DECISION_FORCE_CONTINUOUS:
				$reason = self::REASON_OVERRIDE_CONTINUOUS;
				break;
			case self::DECISION_FORCE_SPLIT:
				$maxGapEnd = 0;
				foreach ($gc as $g) {
					$maxGapEnd = max($maxGapEnd, $g[1]);
				}
				if ($e >= $maxGapEnd) {
					$split = true;
					$reason = self::REASON_OVERRIDE_SPLIT;
				} else {
					return self::errorResult($state, self::ERR_OVERRIDE_NOT_APPLICABLE);
				}
				break;
			default: // auto
				if ($cfg['automatic'] && self::localSecondsOfDay($tz, $e) > $cfg['cutoffLsod']) {
					$split = true;
					$reason = self::REASON_CLOCK_OUT_AFTER_CUTOFF;
				}
				break;
		}

		// Step 6: X = union(R ∩ g for g ∈ Gc) \ union(overrides.worked).
		$x = [];
		if ($split) {
			$base = self::intersect([[$startTs, $e]], $gc);
			$workedEffective = self::intersect($cfg['worked'], self::union($gc));
			$x = self::subtract($base, $workedEffective);
		}

		// Step 7: segments = maximal intervals of R \ X, ordered.
		$segments = self::subtract([[$startTs, $e]], $x);

		return self::result($state, $split, $reason, $x, $segments, $tz);
	}

	// ------------------------------------------------------------------ //
	// §9.6 step 10b: interruption break coverage                           //
	// ------------------------------------------------------------------ //

	/**
	 * Decide whether the scheduled interruption (plus already-recorded break
	 * portions) covers the required break for a split day.
	 *
	 * covered ⇔ BreakSplitValidator::meetsRequirement(portions ≥ floor, r, P)
	 *           AND every maximal own-work stretch ≤ $maxStretchSeconds.
	 *
	 * @param int $requiredBreakMinutes            required break r (fixpoint result)
	 * @param list<int> $interruptionPortionsMinutes  I_day portion lengths in minutes
	 * @param list<int> $recordedBreakPortionsMinutes Be_day portion lengths in minutes
	 * @param list<int> $ownWorkStretchesSeconds        maximal own-work stretches in seconds
	 * @param list<list<int>>|null $allowedPatterns   statutory split patterns; null = sum-only
	 * @param int|null $floorMinutes                  countable-portion floor; null = count all > 0
	 * @param int|null $maxStretchSeconds             L; null = no continuous-work limit
	 * @return string BREAK_* enum — one of covered_by_interruption,
	 *         standard_break_too_short, standard_segment_too_long, not_applicable
	 */
	public static function coverageCheck(
		int $requiredBreakMinutes,
		array $interruptionPortionsMinutes,
		array $recordedBreakPortionsMinutes,
		array $ownWorkStretchesSeconds,
		?array $allowedPatterns,
		?int $floorMinutes,
		?int $maxStretchSeconds = null,
	): string {
		if ($floorMinutes !== null && $floorMinutes < 1) {
			throw new InvalidArgumentException('floorMinutes must be >= 1 or null');
		}
		if ($maxStretchSeconds !== null && $maxStretchSeconds < 0) {
			throw new InvalidArgumentException('maxStretchSeconds must be >= 0 or null');
		}

		// Without an interruption window there is nothing the interruption
		// can cover — the standard path applies.
		if ($interruptionPortionsMinutes === []) {
			return self::BREAK_NOT_APPLICABLE;
		}

		$floor = $floorMinutes ?? 1;
		$portions = [];
		foreach (array_merge($interruptionPortionsMinutes, $recordedBreakPortionsMinutes) as $minutes) {
			if (!is_int($minutes) && !(is_string($minutes) && preg_match('/^-?\d+$/', $minutes))) {
				throw new InvalidArgumentException('break portions must be whole minutes');
			}
			$m = (int)$minutes;
			if ($m >= $floor) {
				$portions[] = $m;
			}
		}

		// Break-requirement gate first: when the counted portions cannot meet
		// the requirement, the failure is "break too short" regardless of
		// stretch length (documented precedence).
		if (!BreakSplitValidator::meetsRequirement($portions, $requiredBreakMinutes, $allowedPatterns)) {
			return self::BREAK_STANDARD_BREAK_TOO_SHORT;
		}

		if ($maxStretchSeconds !== null) {
			foreach ($ownWorkStretchesSeconds as $stretch) {
				if (!is_int($stretch) && !(is_string($stretch) && preg_match('/^\d+$/', $stretch))) {
					throw new InvalidArgumentException('own-work stretches must be seconds');
				}
				if ((int)$stretch > $maxStretchSeconds) {
					return self::BREAK_STANDARD_SEGMENT_TOO_LONG;
				}
			}
		}

		return self::BREAK_COVERED_BY_INTERRUPTION;
	}

	// ------------------------------------------------------------------ //
	// Scheduled interruptions for the day engine (I_day input, §9.6.10b)   //
	// ------------------------------------------------------------------ //

	/**
	 * Epoch pairs of the scheduled interruptions on the local date of
	 * $startTs, or null when any boundary is DST-ambiguous/nonexistent
	 * (caller treats the entry as `review`).
	 *
	 * @param array<string,mixed> $context
	 * @return list<array{0:int,1:int}>|null
	 */
	public static function localGapEpochs(array $context, int $startTs): ?array
	{
		$cfg = self::normaliseContext($context);
		$date = self::localDate($cfg['tz'], $startTs);
		$geometry = self::resolveDayGeometry($cfg['tz'], $date, $cfg['blocks'], $cfg['cutoffLsod']);
		return $geometry === null ? null : $geometry['gaps'];
	}

	// ------------------------------------------------------------------ //
	// Canonical hash (A19 idempotency / A31 replay verification)           //
	// ------------------------------------------------------------------ //

	/**
	 * Deterministic SHA-256 of a result/context fragment: associative keys
	 * are sorted recursively, list order is preserved, JSON is unescaped.
	 * Byte-identical for identical semantics — suitable for replay checks.
	 */
	public static function contextResultHash(array $result): string
	{
		return hash('sha256', self::canonicalJson($result));
	}

	// ------------------------------------------------------------------ //
	// Interval helpers — half-open [a,b) integer epoch, merged, sorted     //
	// ------------------------------------------------------------------ //

	/**
	 * @param list<array{0:int,1:int}> $intervals
	 * @return list<array{0:int,1:int}> merged, sorted by start
	 */
	public static function union(array $intervals): array
	{
		$clean = self::cleanIntervals($intervals);
		if ($clean === []) {
			return [];
		}
		usort($clean, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
		$out = [$clean[0]];
		foreach (array_slice($clean, 1) as [$s, $e]) {
			$last = count($out) - 1;
			if ($s <= $out[$last][1]) {
				$out[$last][1] = max($out[$last][1], $e);
			} else {
				$out[] = [$s, $e];
			}
		}
		return $out;
	}

	/**
	 * @param list<array{0:int,1:int>} $a
	 * @param list<array{0:int,1:int>} $b
	 * @return list<array{0:int,1:int}>
	 */
	public static function intersect(array $a, array $b): array
	{
		$ma = self::union($a);
		$mb = self::union($b);
		$out = [];
		$i = 0;
		$j = 0;
		while ($i < count($ma) && $j < count($mb)) {
			$s = max($ma[$i][0], $mb[$j][0]);
			$e = min($ma[$i][1], $mb[$j][1]);
			if ($s < $e) {
				$out[] = [$s, $e];
			}
			if ($ma[$i][1] < $mb[$j][1]) {
				$i++;
			} else {
				$j++;
			}
		}
		return $out;
	}

	/**
	 * a \ union(b)
	 *
	 * @param list<array{0:int,1:int>} $a
	 * @param list<array{0:int,1:int>} $b
	 * @return list<array{0:int,1:int}>
	 */
	public static function subtract(array $a, array $b): array
	{
		$remaining = self::union($a);
		foreach (self::union($b) as [$bs, $be]) {
			$next = [];
			foreach ($remaining as [$s, $e]) {
				if ($be <= $s || $bs >= $e) {
					$next[] = [$s, $e];
					continue;
				}
				if ($s < $bs) {
					$next[] = [$s, min($bs, $e)];
				}
				if ($e > $be) {
					$next[] = [max($be, $s), $e];
				}
			}
			$remaining = $next;
		}
		return $remaining;
	}

	/**
	 * @param list<array{0:int,1:int>} $intervals
	 */
	public static function totalSeconds(array $intervals): int
	{
		$total = 0;
		foreach (self::union($intervals) as [$s, $e]) {
			$total += $e - $s;
		}
		return $total;
	}

	// ------------------------------------------------------------------ //
	// Context normalisation (fail closed on anything malformed)            //
	// ------------------------------------------------------------------ //

	/**
	 * @param array<string,mixed> $context
	 * @return array{
	 *   tz: DateTimeZone,
	 *   blocks: list<array{0:int,1:int}>,   // local seconds-of-day pairs
	 *   automatic: bool,
	 *   cutoffLsod: ?int,
	 *   breakCover: bool,
	 *   decision: string,
	 *   worked: list<array{0:int,1:int}>,   // epoch pairs
	 * }
	 */
	private static function normaliseContext(array $context): array
	{
		if (($context['v'] ?? null) !== 1) {
			throw new InvalidArgumentException('split context requires "v": 1');
		}

		$tzName = $context['timezone'] ?? null;
		if (!is_string($tzName) || $tzName === '') {
			throw new InvalidArgumentException('split context requires a timezone string');
		}
		try {
			$tz = new DateTimeZone($tzName);
		} catch (\Throwable $e) {
			throw new InvalidArgumentException('unknown context timezone: ' . $tzName, 0, $e);
		}

		$day = $context['day'] ?? null;
		if (!is_array($day)) {
			throw new InvalidArgumentException('split context requires "day"');
		}
		$blocksRaw = $day['blocks'] ?? null;
		if (!is_array($blocksRaw) || !array_is_list($blocksRaw)) {
			throw new InvalidArgumentException('split context requires "day.blocks" as a list');
		}
		if (count($blocksRaw) > self::MAX_BLOCKS) {
			throw new InvalidArgumentException('split context allows at most ' . self::MAX_BLOCKS . ' blocks');
		}
		$blocks = [];
		foreach ($blocksRaw as $i => $block) {
			if (!is_array($block) || !array_is_list($block) || count($block) !== 2) {
				throw new InvalidArgumentException("day.blocks[$i] must be a [start,end] pair");
			}
			$bs = self::timeToLsod($block[0], "day.blocks[$i][0]");
			$be = self::timeToLsod($block[1], "day.blocks[$i][1]");
			if ($be <= $bs) {
				throw new InvalidArgumentException("day.blocks[$i] requires end > start");
			}
			if ($i > 0 && $bs <= $blocks[$i - 1][1]) {
				throw new InvalidArgumentException('day.blocks must be sorted, disjoint and non-touching');
			}
			$blocks[] = [$bs, $be];
		}

		$split = $context['split'] ?? null;
		if (!is_array($split)) {
			throw new InvalidArgumentException('split context requires "split"');
		}
		$automatic = $split['automatic'] ?? false;
		if (!is_bool($automatic)) {
			throw new InvalidArgumentException('split.automatic must be a boolean');
		}
		$breakCover = $split['break_cover'] ?? false;
		if (!is_bool($breakCover)) {
			throw new InvalidArgumentException('split.break_cover must be a boolean');
		}
		$cutoffRaw = $split['cutoff'] ?? null;
		if ($cutoffRaw === null) {
			$cutoffLsod = null;
		} else {
			$cutoffLsod = self::timeToLsod($cutoffRaw, 'split.cutoff');
		}
		if ($automatic && $cutoffLsod === null) {
			throw new InvalidArgumentException('split.cutoff is required when automatic split is on');
		}

		$overrides = $context['overrides'] ?? null;
		$decision = self::DECISION_AUTO;
		$worked = [];
		if ($overrides !== null) {
			if (!is_array($overrides)) {
				throw new InvalidArgumentException('split context "overrides" must be an object');
			}
			if (array_key_exists('decision', $overrides)) {
				$decision = $overrides['decision'];
				if (!in_array($decision, [self::DECISION_AUTO, self::DECISION_FORCE_SPLIT, self::DECISION_FORCE_CONTINUOUS], true)) {
					throw new InvalidArgumentException('overrides.decision must be auto|force_split|force_continuous');
				}
			}
			$workedRaw = $overrides['worked'] ?? [];
			if (!is_array($workedRaw) || !array_is_list($workedRaw)) {
				throw new InvalidArgumentException('overrides.worked must be a list of ISO interval pairs');
			}
			if (count($workedRaw) > self::MAX_WORKED_OVERRIDES) {
				throw new InvalidArgumentException('overrides.worked allows at most ' . self::MAX_WORKED_OVERRIDES . ' intervals');
			}
			foreach ($workedRaw as $i => $pair) {
				if (!is_array($pair) || !array_is_list($pair) || count($pair) !== 2) {
					throw new InvalidArgumentException("overrides.worked[$i] must be a [start,end] pair");
				}
				$ws = self::isoToEpoch($pair[0], "overrides.worked[$i][0]");
				$we = self::isoToEpoch($pair[1], "overrides.worked[$i][1]");
				if ($we <= $ws) {
					throw new InvalidArgumentException("overrides.worked[$i] requires end > start");
				}
				$worked[] = [$ws, $we];
			}
		}

		return [
			'tz' => $tz,
			'blocks' => $blocks,
			'automatic' => $automatic,
			'cutoffLsod' => $cutoffLsod,
			'breakCover' => $breakCover,
			'decision' => $decision,
			'worked' => $worked,
		];
	}

	/**
	 * @param array{0:int,1:int} $block
	 */
	private static function timeToLsod(mixed $value, string $field): int
	{
		if (!is_string($value) || preg_match(self::TIME_PATTERN, $value) !== 1) {
			throw new InvalidArgumentException($field . ' must be a HH:MM time');
		}
		return ((int)substr($value, 0, 2)) * 3600 + ((int)substr($value, 3, 2)) * 60;
	}

	private static function isoToEpoch(mixed $value, string $field): int
	{
		if (!is_string($value) || preg_match(self::ISO_PATTERN, $value) !== 1) {
			throw new InvalidArgumentException($field . ' must be an ISO-8601 timestamp');
		}
		try {
			return (new DateTimeImmutable($value))->getTimestamp();
		} catch (\Throwable $e) {
			throw new InvalidArgumentException($field . ' must be an ISO-8601 timestamp', 0, $e);
		}
	}

	// ------------------------------------------------------------------ //
	// Local-time geometry (DST-safe)                                       //
	// ------------------------------------------------------------------ //

	private static function localDate(DateTimeZone $tz, int $ts): string
	{
		return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d');
	}

	private static function localSecondsOfDay(DateTimeZone $tz, int $ts): int
	{
		$d = (new DateTimeImmutable('@' . $ts))->setTimezone($tz);
		return ((int)$d->format('H')) * 3600 + ((int)$d->format('i')) * 60 + ((int)$d->format('s'));
	}

	/**
	 * Resolve all wall boundaries of the day to unique epochs.
	 *
	 * @param list<array{0:int,1:int}> $blockLsods
	 * @return ?array{blocks: list<array{0:int,1:int}>, gaps: list<array{0:int,1:int}>}
	 *         null when any boundary is nonexistent or ambiguous on $date
	 */
	private static function resolveDayGeometry(DateTimeZone $tz, string $date, array $blockLsods, ?int $cutoffLsod): ?array
	{
		$walls = [];
		foreach ($blockLsods as $b) {
			$walls[] = $b[0];
			$walls[] = $b[1];
		}
		if ($cutoffLsod !== null) {
			$walls[] = $cutoffLsod;
		}
		foreach ($walls as $lsod) {
			if (self::resolveLocalWall($tz, $date, $lsod) === null) {
				return null;
			}
		}

		$blocks = [];
		foreach ($blockLsods as $i => $b) {
			$bs = self::resolveLocalWall($tz, $date, $b[0]);
			$be = self::resolveLocalWall($tz, $date, $b[1]);
			// Unambiguous wall resolution preserves order; a violation can only
			// be corrupt input — fail closed, never guess.
			if ($bs === null || $be === null || $be <= $bs) {
				throw new InvalidArgumentException('block boundaries do not resolve to an ordered interval');
			}
			if ($i > 0 && $bs <= $blocks[$i - 1][1]) {
				throw new InvalidArgumentException('resolved blocks must stay disjoint and non-touching');
			}
			$blocks[] = [$bs, $be];
		}

		$gaps = [];
		for ($i = 0, $n = count($blocks); $i + 1 < $n; $i++) {
			$gaps[] = [$blocks[$i][1], $blocks[$i + 1][0]];
		}

		return ['blocks' => $blocks, 'gaps' => $gaps];
	}

	/**
	 * Unique epoch for wall time "$date + $lsod" in $tz, or null when the
	 * wall time is nonexistent (spring-forward gap) or ambiguous (fall-back
	 * fold). Realization counting: a wall time w maps to epoch w − o for
	 * each UTC offset o; it exists iff offset-at-epoch equals o.
	 */
	private static function resolveLocalWall(DateTimeZone $tz, string $date, int $lsod): ?int
	{
		$wall = (int)strtotime($date . ' 00:00:00 UTC') + $lsod;

		// Candidate offsets: every offset in effect within ±2 days around the
		// wall coordinate (offsets are ≤ 14 h — generous margin).
		$candidates = [];
		$from = $wall - 2 * 86400;
		$to = $wall + 2 * 86400;
		$transitions = $tz->getTransitions($from, $to);
		if (is_array($transitions)) {
			foreach ($transitions as $t) {
				$candidates[$t['offset']] = true;
			}
		}
		$probe = (new DateTimeImmutable('@' . $wall))->setTimezone($tz);
		$candidates[$probe->getOffset()] = true;

		$epoch = null;
		$valid = 0;
		foreach (array_keys($candidates) as $offset) {
			$e = $wall - $offset;
			$at = (new DateTimeImmutable('@' . $e))->setTimezone($tz);
			if ($at->getOffset() === $offset) {
				$valid++;
				$epoch = $e;
			}
		}

		return $valid === 1 ? $epoch : null;
	}

	// ------------------------------------------------------------------ //
	// Result assembly                                                      //
	// ------------------------------------------------------------------ //

	/**
	 * @param list<array{0:int,1:int>} $xEpochs
	 * @param list<array{0:int,1:int>} $segmentEpochs
	 * @return array<string,mixed>
	 */
	private static function result(string $state, bool $split, ?string $reason, array $xEpochs, array $segmentEpochs, DateTimeZone $tz): array
	{
		return [
			'state' => $state,
			'split' => $split,
			'reason' => $reason,
			'error' => null,
			'excluded' => self::toIsoPairs($xEpochs, $tz),
			'segments' => self::toIsoPairs($segmentEpochs, $tz),
			'excludedEpoch' => $xEpochs,
			'segmentsEpoch' => $segmentEpochs,
			'excludedSeconds' => self::totalSeconds($xEpochs),
			'engine' => self::ENGINE_VERSION,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function errorResult(string $state, string $error): array
	{
		return [
			'state' => $state,
			'split' => false,
			'reason' => null,
			'error' => $error,
			'excluded' => [],
			'segments' => [],
			'excludedEpoch' => [],
			'segmentsEpoch' => [],
			'excludedSeconds' => 0,
			'engine' => self::ENGINE_VERSION,
		];
	}

	/**
	 * @param list<array{0:int,1:int>} $pairs
	 * @return list<array{0:string,1:string}> ISO 'c' strings in $tz
	 */
	private static function toIsoPairs(array $pairs, DateTimeZone $tz): array
	{
		$out = [];
		foreach ($pairs as [$s, $e]) {
			$out[] = [
				(new DateTimeImmutable('@' . $s))->setTimezone($tz)->format('c'),
				(new DateTimeImmutable('@' . $e))->setTimezone($tz)->format('c'),
			];
		}
		return $out;
	}

	/**
	 * @param list<array{0:int,1:int}> $intervals
	 * @return list<array{0:int,1:int}>
	 */
	private static function cleanIntervals(array $intervals): array
	{
		$out = [];
		foreach ($intervals as $i => $iv) {
			if (!is_array($iv) || !array_is_list($iv) || count($iv) !== 2
				|| !is_int($iv[0]) || !is_int($iv[1])) {
				throw new InvalidArgumentException("interval $i must be an [int,int] pair");
			}
			if ($iv[1] < $iv[0]) {
				throw new InvalidArgumentException("interval $i has end < start");
			}
			if ($iv[1] === $iv[0]) {
				continue; // empty interval contributes nothing
			}
			$out[] = $iv;
		}
		return $out;
	}

	private static function canonicalJson(mixed $value): string
	{
		if (is_array($value)) {
			if (array_is_list($value)) {
				$parts = array_map([self::class, 'canonicalJson'], $value);
				return '[' . implode(',', $parts) . ']';
			}
			ksort($value);
			$parts = [];
			foreach ($value as $k => $v) {
				$parts[] = json_encode((string)$k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
					. ':' . self::canonicalJson($v);
			}
			return '{' . implode(',', $parts) . '}';
		}
		return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
}
