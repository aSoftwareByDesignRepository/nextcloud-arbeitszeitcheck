<?php

declare(strict_types=1);

/**
 * Reference catalog of Austrian statutory public holidays (ARG §7).
 *
 * All 13 statutory holidays apply nationwide — identical for all nine
 * Bundesländer, so the region argument only matters for suggestions.
 *
 * Guard rails (legal basis, verified against ARG as of 2026):
 *  - Good Friday (Karfreitag) has NOT been a statutory public holiday since
 *    2019 (ARG §7a introduced the "personal holiday" instead). It must never
 *    be seeded as statutory; it is offered as a company-holiday suggestion.
 *  - Patron saint days (St. Joseph, St. Florian, St. Rupert, St. Martin,
 *    St. Leopold, Carinthian Plebiscite Day) are collective-agreement / school
 *    holidays, not statutory — suggestions only.
 *  - 24 and 31 December are free (fully or partly) under most collective
 *    agreements but not statutory — suggestions only.
 *
 * Used only to seed at_holidays — never as a runtime overlay for working-day
 * math. Names are English msgids for IL10N in HolidayService.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class AustrianStatutoryHolidayCatalog implements HolidayCatalogInterface
{
	/** Nationwide fixed-date statutory holidays (ARG §7). */
	private const NATIONAL_FIXED = [
		'01-01' => 'New Year',
		'01-06' => 'Epiphany',
		'05-01' => 'Labour Day (State Holiday)',
		'08-15' => 'Assumption Day',
		'10-26' => 'Austrian National Day',
		'11-01' => 'All Saints',
		'12-08' => 'Immaculate Conception',
		'12-25' => 'Christmas',
		'12-26' => 'St. Stephen\'s Day',
	];

	/**
	 * Patron saint / commemoration days per region (suggestions only, never
	 * statutory). Fixed dates as MM-DD.
	 *
	 * @var array<string,array<string,string>> region => (MM-DD => msgid)
	 */
	private const REGIONAL_SUGGESTION_DAYS = [
		'AT-B' => ['11-11' => 'St. Martin\'s Day'],
		'AT-K' => ['03-19' => 'St. Joseph\'s Day', '10-10' => 'Carinthian Plebiscite Day'],
		'AT-NOE' => ['11-15' => 'St. Leopold\'s Day'],
		'AT-OOE' => ['05-04' => 'St. Florian\'s Day'],
		'AT-S' => ['09-24' => 'St. Rupert\'s Day'],
		'AT-ST' => ['03-19' => 'St. Joseph\'s Day'],
		'AT-T' => ['03-19' => 'St. Joseph\'s Day'],
		'AT-V' => ['03-19' => 'St. Joseph\'s Day'],
		'AT-W' => ['11-15' => 'St. Leopold\'s Day'],
	];

	/**
	 * The 13 ARG §7 statutory holidays. Identical for every Austrian region.
	 *
	 * @return array<string,string> date (Y-m-d) => English name (l10n msgid)
	 */
	public static function getStatutoryHolidaysForRegionAndYear(string $region, int $year): array
	{
		$holidays = [];

		foreach (self::NATIONAL_FIXED as $md => $name) {
			$holidays[sprintf('%04d-%s', $year, $md)] = $name;
		}

		$easter = EasterCalculator::easterDate($year);
		// NOTE: no Good Friday here — not statutory in Austria (ARG §7a).
		$holidays[$easter->modify('+1 day')->format('Y-m-d')] = 'Easter Monday';
		$holidays[$easter->modify('+39 days')->format('Y-m-d')] = 'Ascension';
		$holidays[$easter->modify('+50 days')->format('Y-m-d')] = 'Whit Monday';
		$holidays[$easter->modify('+60 days')->format('Y-m-d')] = 'Corpus Christi';

		ksort($holidays);

		return $holidays;
	}

	/**
	 * Common non-statutory days: Good Friday, patron saint day(s) of the
	 * region, and 24/31 December.
	 *
	 * @return array<string,string> date (Y-m-d) => English name (l10n msgid)
	 */
	public static function getSuggestedCompanyHolidaysForRegionAndYear(string $region, int $year): array
	{
		$out = [];
		foreach (self::getSuggestedCompanyHolidayEntriesForRegionAndYear($region, $year) as $date => $entry) {
			$out[$date] = $entry['name'];
		}
		return $out;
	}

	/**
	 * Structured suggestion entries (B-R4): each carries its rule anchor so
	 * "add annually" creates a semantically correct rule (Good Friday as an
	 * easter-offset rule, fixed days as date anchors) — never inferred from
	 * date equality.
	 *
	 * @return array<string, array{name: string, anchor: array{type: string, month?: int, day?: int, offset?: int}}>
	 */
	public static function getSuggestedCompanyHolidayEntriesForRegionAndYear(string $region, int $year): array
	{
		$region = strtoupper(trim($region));
		$suggestions = [];

		$easter = EasterCalculator::easterDate($year);
		$suggestions[$easter->modify('-2 days')->format('Y-m-d')] = [
			'name' => 'Good Friday',
			'anchor' => ['type' => 'easter', 'offset' => -2],
		];

		foreach (self::REGIONAL_SUGGESTION_DAYS[$region] ?? [] as $md => $name) {
			[$m, $d] = array_map('intval', explode('-', $md));
			$suggestions[sprintf('%04d-%s', $year, $md)] = [
				'name' => $name,
				'anchor' => ['type' => 'date', 'month' => $m, 'day' => $d],
			];
		}

		$suggestions[sprintf('%04d-12-24', $year)] = [
			'name' => 'Christmas Eve',
			'anchor' => ['type' => 'date', 'month' => 12, 'day' => 24],
		];
		$suggestions[sprintf('%04d-12-31', $year)] = [
			'name' => 'New Year\'s Eve',
			'anchor' => ['type' => 'date', 'month' => 12, 'day' => 31],
		];

		ksort($suggestions);

		return $suggestions;
	}
}
