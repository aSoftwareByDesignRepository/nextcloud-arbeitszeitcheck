<?php

declare(strict_types=1);

/**
 * Pro-rata annual vacation entitlement for partial employment years.
 *
 * The {@see VacationEntitlementEngine} always resolves the *full* calendar-year
 * entitlement. When an employee joins or leaves mid-year, German practice
 * (BUrlG §5, "Zwölftelung") reduces that entitlement proportionally to the
 * part of the year actually covered by the employment relationship. This
 * service performs that reduction for a concrete `(user, year)` pair, driven
 * solely by the per-user employment start/end dates
 * ({@see UserEmploymentSettingsService}). When neither date is set, the full
 * entitlement is returned unchanged — preserving the historic behaviour.
 *
 * Two methods are supported (admin-configurable, see
 * {@see Constants::CONFIG_VACATION_PRORATION_METHOD}):
 *
 *  - `twelfths` (default): 1/12 of the annual entitlement per calendar month
 *    touched by the employment relationship; a started month counts in full
 *    (employee-favourable). The result is rounded **up** to a full day when its
 *    fractional part is at least half a day (§5(2)) and is never rounded down
 *    below the proportional minimum.
 *  - `daily`: `annual × (covered calendar days / days in year)`, 2 decimals.
 *
 * Output contract (consumed by {@see VacationAllocationService} and surfaced in
 * the entitlement trace / admin UI):
 *
 *     [
 *       'days'              => float,   // prorated entitlement, 0 ≤ days ≤ full_days
 *       'full_days'         => float,   // unprorated annual entitlement
 *       'prorated'          => bool,    // true iff days differ from full_days due to a partial year
 *       'method'            => string,  // 'twelfths' | 'daily'
 *       'months_covered'    => int,     // 0..12 (twelfths)
 *       'covered_days'      => int,     // covered calendar days in the year
 *       'days_in_year'      => int,     // 365 or 366
 *       'covered_from'      => string,  // Y-m-d (intersection of employment with the year)
 *       'covered_to'        => string,  // Y-m-d
 *       'employed_in_year'  => bool,    // false ⇒ not employed at all during the year ⇒ days = 0
 *       'employment_start'  => ?string, // Y-m-d or null
 *       'employment_end'    => ?string, // Y-m-d or null
 *       'algorithm_version' => int,
 *     ]
 *
 * @copyright Copyright (c) 2026 Alexander Mäule
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCP\IConfig;

class VacationProrationService
{
	public function __construct(
		private readonly UserEmploymentSettingsService $employmentSettings,
		private readonly IConfig $config,
		private readonly ?VacationUnitService $vacationUnitService = null,
	) {
	}

	/**
	 * Configured proration method, validated against the known values and
	 * defaulting to {@see Constants::DEFAULT_VACATION_PRORATION_METHOD}.
	 */
	public function getConfiguredMethod(): string
	{
		$raw = (string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_VACATION_PRORATION_METHOD,
			Constants::DEFAULT_VACATION_PRORATION_METHOD
		);

		return self::normalizeMethod($raw);
	}

	/**
	 * Prorate the full annual entitlement for `$userId` in calendar `$year`,
	 * using the employee's employment start/end dates and the configured
	 * method. Returns the full entitlement untouched when the employee has no
	 * employment period on file.
	 *
	 * @return array<string, mixed> See class docblock for the contract.
	 */
	public function prorateForYear(string $userId, int $year, float $fullAnnualDays): array
	{
		$start = $this->employmentSettings->getEmploymentStart($userId);
		$end = $this->employmentSettings->getEmploymentEnd($userId);
		$hoursMode = $this->vacationUnitService?->isHoursMode() === true;
		$maxAmount = $hoursMode ? 4000.0 : 366.0;
		$unitSize = $hoursMode
			? max(0.25, $this->vacationUnitService->getHoursPerDay())
			: 1.0;

		return self::computeProration(
			$year,
			$fullAnnualDays,
			$start,
			$end,
			$this->getConfiguredMethod(),
			$maxAmount,
			$unitSize,
		);
	}

	/**
	 * Pure, dependency-free proration math. Exposed statically so it can be
	 * unit-tested exhaustively against every calendar edge case without
	 * touching settings or config.
	 *
	 * @return array<string, mixed> See class docblock for the contract.
	 */
	/**
	 * @param float $maxAmount Ceiling for entitlement in the active unit (366 days / 4000 hours).
	 * @param float $unitSize Size of one “day” in the active unit (1.0 for days, hours_per_day for hours).
	 *                        Used so BUrlG §5(2) half-day rounding stays day-equivalent in hours mode.
	 */
	public static function computeProration(
		int $year,
		float $fullAnnualDays,
		?\DateTimeImmutable $employmentStart,
		?\DateTimeImmutable $employmentEnd,
		string $method,
		float $maxAmount = 366.0,
		float $unitSize = 1.0,
	): array {
		$method = self::normalizeMethod($method);
		$maxAmount = is_finite($maxAmount) && $maxAmount > 0 ? $maxAmount : 366.0;
		$unitSize = is_finite($unitSize) && $unitSize >= 0.25 ? $unitSize : 1.0;
		$fullDays = self::sanitizeDays($fullAnnualDays, $maxAmount);

		$yearStart = (new \DateTimeImmutable(sprintf('%04d-01-01', $year)))->setTime(0, 0, 0);
		$yearEnd = (new \DateTimeImmutable(sprintf('%04d-12-31', $year)))->setTime(0, 0, 0);
		$daysInYear = ((int)$yearStart->format('L') === 1) ? 366 : 365;

		$start = $employmentStart?->setTime(0, 0, 0);
		$end = $employmentEnd?->setTime(0, 0, 0);

		$base = [
			'full_days' => $fullDays,
			'method' => $method,
			'days_in_year' => $daysInYear,
			'employment_start' => $start?->format('Y-m-d'),
			'employment_end' => $end?->format('Y-m-d'),
			'algorithm_version' => Constants::VACATION_PRORATION_ALGORITHM_VERSION,
		];

		// No employment period configured ⇒ no proration (legacy behaviour).
		if ($start === null && $end === null) {
			return $base + [
				'days' => $fullDays,
				'prorated' => false,
				'months_covered' => 12,
				'covered_days' => $daysInYear,
				'covered_from' => $yearStart->format('Y-m-d'),
				'covered_to' => $yearEnd->format('Y-m-d'),
				'employed_in_year' => true,
			];
		}

		// Defensive: an inverted period (start > end) means the employment
		// window is empty. Fail closed to "not employed" rather than emitting
		// a negative/odd result.
		if ($start !== null && $end !== null && $start > $end) {
			return $base + [
				'days' => 0.0,
				'prorated' => true,
				'months_covered' => 0,
				'covered_days' => 0,
				'covered_from' => null,
				'covered_to' => null,
				'employed_in_year' => false,
			];
		}

		$coveredStart = ($start !== null && $start > $yearStart) ? $start : $yearStart;
		$coveredEnd = ($end !== null && $end < $yearEnd) ? $end : $yearEnd;

		// Employment does not intersect this calendar year at all.
		if ($coveredStart > $coveredEnd) {
			return $base + [
				'days' => 0.0,
				'prorated' => true,
				'months_covered' => 0,
				'covered_days' => 0,
				'covered_from' => null,
				'covered_to' => null,
				'employed_in_year' => false,
			];
		}

		$coveredFromYmd = $coveredStart->format('Y-m-d');
		$coveredToYmd = $coveredEnd->format('Y-m-d');
		$coveredDays = (int)$coveredStart->diff($coveredEnd)->days + 1;
		$monthsCovered = ((int)$coveredEnd->format('n') - (int)$coveredStart->format('n')) + 1;
		$monthsCovered = max(0, min(12, $monthsCovered));

		$spansFullYear = ($coveredStart->format('Y-m-d') === $yearStart->format('Y-m-d'))
			&& ($coveredEnd->format('Y-m-d') === $yearEnd->format('Y-m-d'));

		if ($spansFullYear) {
			return $base + [
				'days' => $fullDays,
				'prorated' => false,
				'months_covered' => 12,
				'covered_days' => $daysInYear,
				'covered_from' => $coveredFromYmd,
				'covered_to' => $coveredToYmd,
				'employed_in_year' => true,
			];
		}

		if ($method === Constants::VACATION_PRORATION_METHOD_DAILY) {
			$proratedDays = self::roundDays($fullDays * ($coveredDays / $daysInYear));
		} else {
			$proratedDays = self::applyStatutoryRounding($fullDays * ($monthsCovered / 12.0), $unitSize);
		}

		// Proration is a reduction: never exceed the full entitlement and
		// never fall below zero.
		$proratedDays = max(0.0, min($fullDays, $proratedDays));

		return $base + [
			'days' => $proratedDays,
			'prorated' => abs($proratedDays - $fullDays) > 0.0001,
			'months_covered' => $monthsCovered,
			'covered_days' => $coveredDays,
			'covered_from' => $coveredFromYmd,
			'covered_to' => $coveredToYmd,
			'employed_in_year' => true,
		];
	}

	public static function normalizeMethod(string $method): string
	{
		$method = strtolower(trim($method));

		return $method === Constants::VACATION_PRORATION_METHOD_DAILY
			? Constants::VACATION_PRORATION_METHOD_DAILY
			: Constants::VACATION_PRORATION_METHOD_TWELFTHS;
	}

	/**
	 * BUrlG §5(2): a prorated entitlement whose fractional part is at least
	 * half a day rounds **up** to the next full day. Smaller fractions are
	 * kept verbatim (the statute mandates rounding up only — rounding down
	 * would drop the employee below their proportional minimum), normalised to
	 * two decimal places.
	 *
	 * In hours mode `$unitSize` is hours_per_day so “half a day” stays
	 * day-equivalent (e.g. 4.0 hours when factor is 8).
	 */
	private static function applyStatutoryRounding(float $value, float $unitSize = 1.0): float
	{
		if (!is_finite($value) || $value <= 0.0) {
			return 0.0;
		}
		$unitSize = is_finite($unitSize) && $unitSize >= 0.25 ? $unitSize : 1.0;
		$inUnits = $value / $unitSize;
		$floor = floor($inUnits);
		$fraction = $inUnits - $floor;
		if ($fraction >= 0.5 - 1e-9) {
			return ($floor + 1.0) * $unitSize;
		}

		return round($value, 2, PHP_ROUND_HALF_UP);
	}

	private static function roundDays(float $value): float
	{
		if (!is_finite($value)) {
			return 0.0;
		}

		return round(max(0.0, $value), 2, PHP_ROUND_HALF_UP);
	}

	private static function sanitizeDays(float $value, float $maxAmount = 366.0): float
	{
		if (!is_finite($value)) {
			return 0.0;
		}
		$maxAmount = is_finite($maxAmount) && $maxAmount > 0 ? $maxAmount : 366.0;

		return round(max(0.0, min($maxAmount, $value)), 2, PHP_ROUND_HALF_UP);
	}
}
