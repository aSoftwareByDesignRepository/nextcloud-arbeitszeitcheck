<?php

declare(strict_types=1);

/**
 * Immutable per-country working-time law parameters (DE ArbZG, AT AZG/ARG, CH ArG).
 *
 * Integration contract:
 *  1. Explicitly configured admin values ALWAYS win — the profile only
 *     supplies the *defaults* passed to getAppValue().
 *  2. Break tiers, averaging windows, and the night window come from the
 *     profile; the German values equal the previous hard-coded literals.
 *  3. approximateWorkingDays() = avgWindowWeeks × 5 (26×5 = 130 for DE,
 *     17×5 = 85 for AT) — mirrors the historical approximation.
 *  4. Rules that a country does not have are expressed as null and must be
 *     skipped by the caller (e.g. dailyAvgMaxHours for AT,
 *     weeklyAbsoluteMaxHours for DE).
 *  5. allowedBreakSplitPatterns = null means “sum of break minutes is enough”
 *     (DE/CH). Austria supplies the AZG §11 statutory split patterns.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class LaborLawProfile
{
	/**
	 * @param list<array{afterHours: float, breakMinutes: int}> $breakTiers
	 *        Sorted descending by afterHours; first matching tier applies.
	 * @param list<list<int>>|null $allowedBreakSplitPatterns
	 *        null = sum-only; otherwise AZG-style portion patterns (e.g. [[15,15],[10,10,10]]).
	 * @param array<string,string> $lawShortLabels rule key => short label,
	 *        keys: daily, breaks, rest, weekly, night, sundayHoliday, dailyAvg
	 */
	public function __construct(
		public readonly string $country,
		public readonly float $dailyMaxHoursDefault,
		public readonly float $minRestHoursDefault,
		public readonly array $breakTiers,
		public readonly ?float $weeklyAvgMaxHours,
		public readonly int $avgWindowWeeks,
		public readonly ?float $weeklyAbsoluteMaxHours,
		public readonly ?float $dailyAvgMaxHours,
		public readonly int $nightWindowStartHour,
		public readonly int $nightWindowEndHour,
		public readonly array $lawShortLabels,
		public readonly int $vacationDaysSuggestion,
		public readonly int $minBreakMinutes,
		public readonly ?array $allowedBreakSplitPatterns = null,
	) {
	}

	/**
	 * Approximate working days inside the averaging window (5-day week).
	 */
	public function approximateWorkingDays(): int
	{
		return $this->avgWindowWeeks * 5;
	}

	/**
	 * Short legal reference for a rule key ('ArbZG §4', 'AZG §11', …).
	 * Falls back to the daily label so messages never render an empty cite.
	 */
	public function lawLabel(string $rule): string
	{
		return $this->lawShortLabels[$rule] ?? ($this->lawShortLabels['daily'] ?? '');
	}

	/**
	 * Highest break tier whose exclusive threshold the duration has crossed.
	 * Tiers are evaluated from the highest threshold down (first match wins).
	 *
	 * DACH statutes use “mehr als” / more than (ArbZG §4, AZG §11, ArG Art. 15):
	 * exactly 6 hours is not yet a 30-minute break; exactly 9 hours (DE) is still
	 * the 30-minute band, not 45.
	 *
	 * @return array{afterHours: float, breakMinutes: int}|null
	 */
	public function matchingBreakTier(float $durationHours): ?array
	{
		foreach ($this->breakTiers as $tier) {
			if ($durationHours > $tier['afterHours']) {
				return $tier;
			}
		}

		return null;
	}

	/**
	 * Required total break minutes for a shift duration.
	 */
	public function requiredBreakMinutes(float $durationHours): int
	{
		$tier = $this->matchingBreakTier($durationHours);

		return $tier !== null ? (int)$tier['breakMinutes'] : 0;
	}

	/**
	 * @return list<array{afterHours: float, breakMinutes: int}>
	 */
	public function breakTiersAscending(): array
	{
		$tiers = $this->breakTiers;
		usort($tiers, static fn (array $a, array $b): int => $a['afterHours'] <=> $b['afterHours']);

		return $tiers;
	}

	/**
	 * Human-readable hours for UI / compliance messages.
	 * Keeps fractional Swiss thresholds (5.5) intact — never truncate with (int).
	 */
	public static function formatHoursLabel(float $hours): string
	{
		if (abs($hours - round($hours)) < 0.001) {
			return (string)(int)round($hours);
		}

		return rtrim(rtrim(number_format($hours, 1, '.', ''), '0'), '.');
	}
}
