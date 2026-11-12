<?php

declare(strict_types=1);

/**
 * Canonical half-day / boundary-fraction shape authority
 * (AZC-HALF-DAY-SLOTS-BOUNDARIES).
 *
 * Single source of truth for:
 *   - parsing + normalizing the wire day-fraction / day-slot fields,
 *   - the canonical per-absence shape (fractions + slots per boundary day),
 *   - per-calendar-day coverage/slot lookup used by the slot-aware overlap
 *     predicate and by the planned-hours credit map.
 *
 * Interior days are always full coverage by construction — the schema and
 * this contract only allow fractions on the FIRST and LAST day of a range.
 *
 * This class is deliberately pure (no DB, no l10n): callers map the thrown
 * ShapeInputException error codes to localized BusinessRuleException codes.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Db\Absence;

final class AbsenceDayShape
{
	public const SLOT_AM = 'am';
	public const SLOT_PM = 'pm';

	/** Fraction membership set for built-in day-granularity types. */
	private const ALLOWED_FRACTIONS = [1.0, 0.5];

	/** Comparison epsilon shared with the write/repair path. */
	public const EPS = 0.011;

	// ShapeInputException codes — callers map to localized error codes.
	public const ERR_FRACTION = 'fraction';
	public const ERR_SLOT = 'slot';
	public const ERR_SLOT_GHOST = 'slot_ghost';        // slot supplied for a full day
	public const ERR_END_ON_SINGLE = 'end_on_single';  // end_* fields on a single-day range
	public const ERR_CONFLICT = 'fraction_conflict';   // day_fraction vs start_day_fraction disagree

	/**
	 * Canonical request shape for one absence.
	 *
	 * @return array{
	 *   single: bool,
	 *   start_fraction: float,
	 *   start_slot: ?string,
	 *   end_fraction: float,
	 *   end_slot: ?string,
	 *   fractional: bool,
	 *   legacy_range_fraction: bool
	 * }
	 * @throws ShapeInputException on malformed/ambiguous input (getCode = ERR_*)
	 */
	public static function canonicalize(array $data, \DateTimeInterface $start, \DateTimeInterface $end): array
	{
		$single = $start->format('Y-m-d') === $end->format('Y-m-d');

		$dayF = self::parseFraction(self::present($data, 'day_fraction'));
		$dayS = self::parseSlot(self::present($data, 'day_slot'));
		$sF = self::parseFraction(self::present($data, 'start_day_fraction'));
		$sS = self::parseSlot(self::present($data, 'start_day_slot'));
		$eF = self::parseFraction(self::present($data, 'end_day_fraction'));
		$eS = self::parseSlot(self::present($data, 'end_day_slot'));

		if ($single) {
			$endTouched = self::present($data, 'end_day_fraction') !== null
				|| self::present($data, 'end_day_slot') !== null;
			if ($endTouched && ($eF < 1.0 - self::EPS || $eS !== null)) {
				throw new ShapeInputException(self::ERR_END_ON_SINGLE);
			}
			$dayFSet = self::present($data, 'day_fraction') !== null;
			$sFSet = self::present($data, 'start_day_fraction') !== null;
			if ($dayFSet && $sFSet && abs($dayF - $sF) >= self::EPS) {
				throw new ShapeInputException(self::ERR_CONFLICT);
			}
			$daySSet = self::present($data, 'day_slot') !== null;
			$sSSet = self::present($data, 'start_day_slot') !== null;
			if ($daySSet && $sSSet && $dayS !== $sS) {
				throw new ShapeInputException(self::ERR_CONFLICT);
			}
			$f = $dayFSet ? $dayF : $sF;
			$slot = $daySSet ? $dayS : $sS;
			if ($slot !== null && $f >= 1.0 - self::EPS) {
				throw new ShapeInputException(self::ERR_SLOT_GHOST);
			}
			return [
				'single' => true,
				'start_fraction' => $f,
				'start_slot' => $f < 1.0 - self::EPS ? $slot : null,
				'end_fraction' => 1.0,
				'end_slot' => null,
				'fractional' => $f < 1.0 - self::EPS,
				'legacy_range_fraction' => false,
			];
		}

		// Multi-day: day_fraction!=1 is the single-day-only contract violation
		// (RANGE_FORBIDDEN is thrown later in the debit pipeline after the
		// enabled-gate, preserving the established error precedence); for
		// overlap evaluation it leniently maps onto the start boundary.
		if ($dayS !== null && $dayF >= 1.0 - self::EPS) {
			throw new ShapeInputException(self::ERR_SLOT_GHOST);
		}
		$startFraction = $sF;
		$startSlot = $sS;
		if ($dayF < 1.0 - self::EPS) {
			$startFraction = $dayF;
			$startSlot = $dayS !== null ? $dayS : $sS;
		}
		if ($startSlot !== null && $startFraction >= 1.0 - self::EPS) {
			throw new ShapeInputException(self::ERR_SLOT_GHOST);
		}
		if ($eS !== null && $eF >= 1.0 - self::EPS) {
			throw new ShapeInputException(self::ERR_SLOT_GHOST);
		}

		return [
			'single' => false,
			'start_fraction' => $startFraction,
			'start_slot' => $startFraction < 1.0 - self::EPS ? $startSlot : null,
			'end_fraction' => $eF,
			'end_slot' => $eF < 1.0 - self::EPS ? $eS : null,
			'fractional' => ($startFraction < 1.0 - self::EPS) || ($eF < 1.0 - self::EPS),
			// The legacy `day_fraction` field is single-day-only: on a range it
			// was leniently mapped onto the start boundary for overlap purposes,
			// and the debit pipeline must still reject it AFTER the enabled-gate
			// (HALF_DAY_RANGE_FORBIDDEN precedence, parent spec §9).
			'legacy_range_fraction' => $dayF < 1.0 - self::EPS,
		];
	}

	/**
	 * True when the request carries any explicit fraction/slot input —
	 * distinguishes "client asked for a shape" from "no opinion, preserve".
	 *
	 * @param array<string, mixed> $data
	 */
	/**
	 * Canonical full-coverage shape (all boundaries 1.0, no slots) — used to
	 * clear stored columns when a date move invalidates the prior shape.
	 */
	public static function full(): array
	{
		return [
			'single' => true,
			'start_fraction' => 1.0,
			'start_slot' => null,
			'end_fraction' => 1.0,
			'end_slot' => null,
			'fractional' => false,
			'legacy_range_fraction' => false,
		];
	}

	public static function hasShapeInput(array $data): bool
	{
		foreach (['day_fraction', 'day_slot', 'start_day_fraction', 'start_day_slot', 'end_day_fraction', 'end_day_slot'] as $key) {
			if (self::present($data, $key) !== null) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Coverage (0..1) an absence claims on a calendar date.
	 *
	 * Explicit columns win; a legacy single-day row without columns derives
	 * `days / weight(day)` via $weightFor (only called when actually needed).
	 *
	 * @param callable(string $ymd): float $weightFor schedule weight of one day
	 */
	public static function coverageOn(Absence $a, \DateTimeInterface $date, callable $weightFor): float
	{
		$start = $a->getStartDate();
		$end = $a->getEndDate();
		if ($start === null || $end === null) {
			return 0.0;
		}
		$ymd = $date->format('Y-m-d');
		$sYmd = $start->format('Y-m-d');
		$eYmd = $end->format('Y-m-d');
		if ($ymd < $sYmd || $ymd > $eYmd) {
			return 0.0;
		}
		if ($sYmd === $eYmd) {
			$f = $a->getStartDayFraction();
			if ($f !== null && is_finite((float)$f)) {
				return min(1.0, max(0.0, (float)$f));
			}
			$days = $a->getDays();
			if ($days === null || !is_finite((float)$days)) {
				return 1.0;
			}
			$w = $weightFor($ymd);
			if (!is_finite($w) || $w <= self::EPS) {
				return abs((float)$days - 0.5) < self::EPS ? 0.5 : 1.0;
			}
			return min(1.0, max(0.0, (float)$days / $w));
		}
		if ($ymd === $sYmd) {
			$f = $a->getStartDayFraction();
			return ($f !== null && is_finite((float)$f)) ? min(1.0, max(0.0, (float)$f)) : 1.0;
		}
		if ($ymd === $eYmd) {
			$f = $a->getEndDayFraction();
			return ($f !== null && is_finite((float)$f)) ? min(1.0, max(0.0, (float)$f)) : 1.0;
		}
		return 1.0;
	}

	/**
	 * Slot ('am'/'pm') an absence claims on a calendar date, if any.
	 */
	public static function slotOn(Absence $a, \DateTimeInterface $date): ?string
	{
		$start = $a->getStartDate();
		$end = $a->getEndDate();
		if ($start === null || $end === null) {
			return null;
		}
		$ymd = $date->format('Y-m-d');
		$sYmd = $start->format('Y-m-d');
		$eYmd = $end->format('Y-m-d');
		if ($ymd < $sYmd || $ymd > $eYmd) {
			return null;
		}
		if ($sYmd === $eYmd || $ymd === $sYmd) {
			return self::cleanSlot($a->getStartDaySlot());
		}
		if ($ymd === $eYmd) {
			return self::cleanSlot($a->getEndDaySlot());
		}
		return null;
	}

	/**
	 * Can a set of day covers legally share one calendar day?
	 *
	 * Legal iff every cover is fractional (<1), the sum stays ≤1, every
	 * cover carries an explicit slot and all slots are distinct. With the
	 * {0.5,1} fraction set this reduces to exactly one 'am' + one 'pm'.
	 *
	 * @param list<array{0: float, 1: ?string}> $covers [fraction, slot]
	 */
	public static function coversCompatible(array $covers): bool
	{
		$sum = 0.0;
		$slots = [];
		foreach ($covers as [$fraction, $slot]) {
			if ($fraction >= 1.0 - self::EPS) {
				return false;
			}
			if ($slot === null || !in_array($slot, [self::SLOT_AM, self::SLOT_PM], true)) {
				return false;
			}
			if (isset($slots[$slot])) {
				return false;
			}
			$slots[$slot] = true;
			$sum += $fraction;
		}
		return $sum <= 1.0 + self::EPS;
	}

	/**
	 * Days debit for a canonical shape: interior weight + boundary weight ×
	 * boundary fraction. `$vacationBoundaryGuard` requires each fractional
	 * boundary day's weight ≥ ~1.0 (vacation absolute-0.5 semantics); the
	 * caller decides which type rules apply.
	 *
	 * @param callable(\DateTimeInterface $from, \DateTimeInterface $to): float $weightOf
	 * @return array{days: float, start_weight: float, end_weight: float}
	 * @throws ShapeInputException ERR_FRACTION never — guard violations surface
	 *         as `boundary_weights` for the caller to map onto type errors.
	 */
	public static function debitForShape(
		array $shape,
		string $userId,
		\DateTimeInterface $start,
		\DateTimeInterface $end,
		callable $weightOf,
	): array {
		$total = $weightOf($start, $end);
		if (!is_finite($total)) {
			$total = 0.0;
		}
		if (!$shape['fractional']) {
			// Full coverage: exactly the whole-range weight — identical to the
			// pre-shape debit and free of boundary-weight edge cases.
			return [
				'days' => round($total, 4),
				'start_weight' => $total,
				'end_weight' => 0.0,
			];
		}
		$wStart = $weightOf($start, $start);
		if (!is_finite($wStart)) {
			$wStart = 0.0;
		}
		if ($shape['single']) {
			return [
				'days' => round($wStart * $shape['start_fraction'], 4),
				'start_weight' => $wStart,
				'end_weight' => 0.0,
			];
		}
		$wEnd = $weightOf($end, $end);
		if (!is_finite($wEnd)) {
			$wEnd = 0.0;
		}
		$interior = max(0.0, $total - $wStart - $wEnd);
		$days = $interior
			+ $wStart * $shape['start_fraction']
			+ $wEnd * $shape['end_fraction'];
		return [
			'days' => round($days, 4),
			'start_weight' => $wStart,
			'end_weight' => $wEnd,
		];
	}

	/**
	 * Fraction+slot columns to persist for a canonical shape.
	 * Full boundaries canonicalize to NULL ("nothing special stored").
	 *
	 * @return array{0: ?float, 1: ?string, 2: ?float, 3: ?string}
	 *   [startFraction, startSlot, endFraction, endSlot]
	 */
	public static function columnsForShape(array $shape): array
	{
		$sF = $shape['start_fraction'] < 1.0 - self::EPS ? $shape['start_fraction'] : null;
		$eF = (!$shape['single'] && $shape['end_fraction'] < 1.0 - self::EPS) ? $shape['end_fraction'] : null;
		return [
			$sF,
			$sF !== null ? $shape['start_slot'] : null,
			$eF,
			$eF !== null ? $shape['end_slot'] : null,
		];
	}

	/**
	 * Fraction+slot columns a NEW/shortened range inherits from a source
	 * absence: a boundary keeps its stored half only when the boundary DATE
	 * survives as that boundary in the new range.
	 *
	 * @return array{0: ?float, 1: ?string, 2: ?float, 3: ?string}
	 */
	public static function columnsInheritedForRange(Absence $source, \DateTimeInterface $newStart, \DateTimeInterface $newEnd): array
	{
		$s = $source->getStartDate();
		$e = $source->getEndDate();
		if ($s === null || $e === null) {
			return [null, null, null, null];
		}
		$single = $newStart->format('Y-m-d') === $newEnd->format('Y-m-d');
		$sF = $newStart->format('Y-m-d') === $s->format('Y-m-d') ? $source->getStartDayFraction() : null;
		$sS = $sF !== null ? $source->getStartDaySlot() : null;
		if ($single) {
			// Single-day: either boundary date may carry the half.
			if ($sF === null && $newEnd->format('Y-m-d') === $e->format('Y-m-d')) {
				$sF = $source->getEndDayFraction();
				$sS = $sF !== null ? $source->getEndDaySlot() : null;
			}
			return [$sF !== null ? (float)$sF : null, $sS, null, null];
		}
		$eF = $newEnd->format('Y-m-d') === $e->format('Y-m-d') ? $source->getEndDayFraction() : null;
		$eS = $eF !== null ? $source->getEndDaySlot() : null;
		return [
			$sF !== null ? (float)$sF : null,
			$sS,
			$eF !== null ? (float)$eF : null,
			$eS,
		];
	}

	/**
	 * Fraction a single canonical boundary carries, or null when absent.
	 */
	public static function fractionForBoundary(Absence $a, \DateTimeInterface $date): ?float
	{
		$start = $a->getStartDate();
		$end = $a->getEndDate();
		if ($start === null || $end === null) {
			return null;
		}
		$ymd = $date->format('Y-m-d');
		$sYmd = $start->format('Y-m-d');
		$eYmd = $end->format('Y-m-d');
		if ($sYmd === $eYmd || $ymd === $sYmd) {
			$f = $a->getStartDayFraction();
		} elseif ($ymd === $eYmd) {
			$f = $a->getEndDayFraction();
		} else {
			return null;
		}
		return ($f !== null && is_finite((float)$f)) ? (float)$f : null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function present(array $data, string $key): mixed
	{
		if (!array_key_exists($key, $data)) {
			return null;
		}
		$v = $data[$key];
		if ($v === null || $v === '' || (is_array($v) && $v === [])) {
			return null;
		}
		return $v;
	}

	/**
	 * @throws ShapeInputException ERR_FRACTION
	 */
	private static function parseFraction(mixed $raw): float
	{
		if ($raw === null) {
			return 1.0;
		}
		if (is_array($raw)) {
			$raw = reset($raw);
			if ($raw === false) {
				return 1.0;
			}
		}
		if (is_bool($raw) || is_object($raw)) {
			throw new ShapeInputException(self::ERR_FRACTION);
		}
		$s = str_replace(',', '.', trim((string)$raw));
		if ($s === '') {
			return 1.0;
		}
		if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
			throw new ShapeInputException(self::ERR_FRACTION);
		}
		$v = (float)$s;
		if (!is_finite($v)) {
			throw new ShapeInputException(self::ERR_FRACTION);
		}
		foreach (self::ALLOWED_FRACTIONS as $allowed) {
			if (abs($v - $allowed) < 1e-9) {
				return $allowed;
			}
		}
		throw new ShapeInputException(self::ERR_FRACTION);
	}

	/**
	 * @throws ShapeInputException ERR_SLOT
	 */
	private static function parseSlot(mixed $raw): ?string
	{
		if ($raw === null) {
			return null;
		}
		if (is_array($raw)) {
			$raw = reset($raw);
			if ($raw === false) {
				return null;
			}
		}
		if (is_bool($raw) || is_object($raw)) {
			throw new ShapeInputException(self::ERR_SLOT);
		}
		$s = strtolower(trim((string)$raw));
		if ($s === '') {
			return null;
		}
		if ($s === self::SLOT_AM || $s === self::SLOT_PM) {
			return $s;
		}
		throw new ShapeInputException(self::ERR_SLOT);
	}

	private static function cleanSlot(mixed $raw): ?string
	{
		if (!is_string($raw)) {
			return null;
		}
		$s = strtolower(trim($raw));
		return ($s === self::SLOT_AM || $s === self::SLOT_PM) ? $s : null;
	}
}
