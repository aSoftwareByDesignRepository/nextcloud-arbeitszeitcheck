<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

/**
 * Central holiday calculation service for the arbeitszeitcheck app.
 *
 * IMPORTANT:
 * - This class is deliberately stateless and uses only pure functions.
 * - All working-day calculations and generic German public holiday checks
 *   should go through this class so we have a single, testable implementation.
 *
 * NOTE:
 * - Current implementation provides a Germany-wide base set of public holidays.
 * - State-specific and custom calendars will be layered on top of this in
 *   follow-up iterations; the goal here is to first remove duplicate logic
 *   and make behaviour consistent across the app.
 */
class HolidayService
{
	/**
	 * Compute working days (Mon–Fri) for a given date range.
	 *
	 * - Excludes German public holidays (base calendar).
	 * - Optionally applies additional holidays via $extraHolidayWeights:
	 *   array<string,float> date (Y-m-d) => weight (1.0 = full holiday, 0.5 = half-day, 0 = no effect).
	 *   For half-day holidays the working day counts as 0.5.
	 */
	public static function computeWorkingDays(\DateTime $start, \DateTime $end, array $extraHolidayWeights = []): float
	{
		$start = (clone $start)->setTime(0, 0, 0);
		$end = (clone $end)->setTime(0, 0, 0);

		$workingDays = 0.0;

		$startYear = (int)$start->format('Y');
		$endYear = (int)$end->format('Y');

		$holidaysByYear = [];
		for ($y = $startYear; $y <= $endYear; $y++) {
			$holidaysByYear[$y] = self::getGermanPublicHolidaysForYear($y);
		}

		while ($start <= $end) {
			// 1=Mon .. 5=Fri are working weekdays
			if ((int)$start->format('N') < 6) {
				$year = (int)$start->format('Y');
				$dateStr = $start->format('Y-m-d');

				// Statutory public holiday: full non-working day
				if (!isset($holidaysByYear[$year][$dateStr])) {
					$weight = 1.0;
					if (isset($extraHolidayWeights[$dateStr])) {
						$extra = (float)$extraHolidayWeights[$dateStr];
						if ($extra >= 1.0) {
							$weight = 0.0;
						} elseif ($extra > 0.0) {
							$weight = max(0.0, 1.0 - $extra);
						}
					}
					$workingDays += $weight;
				}
			}
			$start->modify('+1 day');
		}

		return (float)$workingDays;
	}

	/**
	 * Compute working days per year for a date range (Mon–Fri), excluding
	 * German public holidays and optionally additional holidays.
	 *
	 * @param \DateTime $start
	 * @param \DateTime $end
	 * @param array<string,float> $extraHolidayWeights date (Y-m-d) => weight (1.0 = full holiday, 0.5 = half-day)
	 * @return array<int,float> year => working days
	 */
	public static function computeWorkingDaysPerYear(\DateTime $start, \DateTime $end, array $extraHolidayWeights = []): array
	{
		$start = (clone $start)->setTime(0, 0, 0);
		$end = (clone $end)->setTime(0, 0, 0);

		$result = [];
		$startYear = (int)$start->format('Y');
		$endYear = (int)$end->format('Y');

		$holidaysByYear = [];
		for ($y = $startYear; $y <= $endYear; $y++) {
			$holidaysByYear[$y] = self::getGermanPublicHolidaysForYear($y);
		}

		while ($start <= $end) {
			if ((int)$start->format('N') < 6) {
				$year = (int)$start->format('Y');
				$dateStr = $start->format('Y-m-d');
				if (!isset($holidaysByYear[$year][$dateStr])) {
					$weight = 1.0;
					if (isset($extraHolidayWeights[$dateStr])) {
						$extra = (float)$extraHolidayWeights[$dateStr];
						if ($extra >= 1.0) {
							$weight = 0.0;
						} elseif ($extra > 0.0) {
							$weight = max(0.0, 1.0 - $extra);
						}
					}
					if ($weight > 0.0) {
						$result[$year] = ($result[$year] ?? 0.0) + $weight;
					}
				}
			}
			$start->modify('+1 day');
		}

		foreach (array_keys($result) as $year) {
			$result[$year] = (float)$result[$year];
		}

		return $result;
	}

	/**
	 * Simple "is public holiday" helper for generic German public holidays
	 * (no state-specific differences yet).
	 */
	public static function isGermanPublicHoliday(\DateTime $date): bool
	{
		$year = (int)$date->format('Y');
		$holidays = self::getGermanPublicHolidaysForYear($year);
		return isset($holidays[$date->format('Y-m-d')]);
	}

	/**
	 * Get German public holidays for a year (for working-days calculation).
	 *
	 * NOTE:
	 * - This currently represents a Germany-wide base calendar.
	 * - State-specific variations will be layered on top in a future iteration.
	 *
	 * @return array<string,string> date (Y-m-d) => name
	 */
	public static function getGermanPublicHolidaysForYear(int $year): array
	{
		$holidays = [];

		// Fixed-date holidays
		$holidays[$year . '-01-01'] = 'New Year';
		$holidays[$year . '-05-01'] = 'Labour Day';
		$holidays[$year . '-10-03'] = 'Unity Day';
		$holidays[$year . '-10-31'] = 'Reformation Day';
		$holidays[$year . '-11-01'] = 'All Saints';
		$holidays[$year . '-12-25'] = 'Christmas';
		$holidays[$year . '-12-26'] = 'Second Christmas';

		// Easter-based holidays (Good Friday, Easter Monday, Ascension, Whit Monday, Corpus Christi)
		$easterDays = \function_exists('easter_days') ? \easter_days($year) : self::easterDaysGauss($year);
		$march21 = new \DateTimeImmutable($year . '-03-21');
		$easter = $march21->modify('+' . $easterDays . ' days');

		$goodFriday = $easter->modify('-2 days');
		$holidays[$goodFriday->format('Y-m-d')] = 'Good Friday';

		$easterMonday = $easter->modify('+1 day');
		$holidays[$easterMonday->format('Y-m-d')] = 'Easter Monday';

		$ascension = $easter->modify('+39 days');
		$holidays[$ascension->format('Y-m-d')] = 'Ascension';

		$whitMonday = $easter->modify('+50 days');
		$holidays[$whitMonday->format('Y-m-d')] = 'Whit Monday';

		$corpusChristi = $easter->modify('+60 days');
		$holidays[$corpusChristi->format('Y-m-d')] = 'Corpus Christi';

		return $holidays;
	}

	/**
	 * Gauss algorithm for Easter (fallback when ext/calendar easter_days()
	 * is not available).
	 */
	private static function easterDaysGauss(int $year): int
	{
		$a = $year % 19;
		$b = (int)($year / 100);
		$c = $year % 100;
		$d = (int)($b / 4);
		$e = $b % 4;
		$f = (int)(($b + 8) / 25);
		$g = (int)(($b - $f + 1) / 3);
		$h = (19 * $a + $b - $d - $g + 15) % 30;
		$i = (int)($c / 4);
		$k = $c % 4;
		$l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
		$m = (int)(($a + 11 * $h + 22 * $l) / 451);
		$month = (int)(($h + $l - 7 * $m + 114) / 31);
		$day = (($h + $l - 7 * $m + 114) % 31) + 1;

		$march21 = new \DateTimeImmutable($year . '-03-21');
		$easterDate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));

		return (int)$march21->diff($easterDate)->days;
	}
}

