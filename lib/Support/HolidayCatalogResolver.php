<?php

declare(strict_types=1);

/**
 * Maps a region code to the statutory holiday catalog of its country.
 * The dispatch rule mirrors RegionRegistry::countryOf(): codes without a
 * dash are German Bundesländer (legacy), 'AT-…' Austrian, 'CH-…' Swiss.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class HolidayCatalogResolver
{
	/**
	 * @return class-string<HolidayCatalogInterface>
	 */
	public static function catalogClassForRegion(string $region): string
	{
		return match (RegionRegistry::countryOf($region)) {
			RegionRegistry::COUNTRY_AT => AustrianStatutoryHolidayCatalog::class,
			RegionRegistry::COUNTRY_CH => SwissStatutoryHolidayCatalog::class,
			default => GermanStatutoryHolidayCatalog::class,
		};
	}

	/**
	 * @return array<string,string> date (Y-m-d) => English name (l10n msgid)
	 */
	public static function statutoryHolidaysForRegionAndYear(string $region, int $year): array
	{
		$catalog = self::catalogClassForRegion($region);

		return $catalog::getStatutoryHolidaysForRegionAndYear($region, $year);
	}

	/**
	 * Kind map for statutory seeding (E-6). Missing dates default to full-day.
	 * Only the Swiss catalog currently emits half-day statutory entries.
	 *
	 * @return array<string,string> date (Y-m-d) => 'full'|'half'
	 */
	public static function statutoryHolidayKindsForRegionAndYear(string $region, int $year): array
	{
		if (RegionRegistry::countryOf($region) !== RegionRegistry::COUNTRY_CH) {
			$kinds = [];
			foreach (self::statutoryHolidaysForRegionAndYear($region, $year) as $date => $_name) {
				$kinds[$date] = 'full';
			}

			return $kinds;
		}

		$kinds = [];
		foreach (SwissStatutoryHolidayCatalog::getStatutoryHolidayEntriesForRegionAndYear($region, $year) as $date => $entry) {
			$kinds[$date] = $entry['kind'] === 'half' ? 'half' : 'full';
		}

		return $kinds;
	}

	/**
	 * @return array<string,string> date (Y-m-d) => English name (l10n msgid)
	 */
	public static function suggestedCompanyHolidaysForRegionAndYear(string $region, int $year): array
	{
		$catalog = self::catalogClassForRegion($region);

		return $catalog::getSuggestedCompanyHolidaysForRegionAndYear($region, $year);
	}

	/**
	 * Structured suggestion entries incl. rule anchors (B-R4): the anchor
	 * comes from the catalog itself — date-equality inference is forbidden
	 * because a fixed MM-DD suggestion can coincidentally land on
	 * easter+offset in a given year.
	 *
	 * @return array<string, array{name: string, anchor: array{type: string, month?: int, day?: int, offset?: int}}>
	 */
	public static function suggestedCompanyHolidayEntriesForRegionAndYear(string $region, int $year): array
	{
		$catalog = self::catalogClassForRegion($region);

		if (method_exists($catalog, 'getSuggestedCompanyHolidayEntriesForRegionAndYear')) {
			return $catalog::getSuggestedCompanyHolidayEntriesForRegionAndYear($region, $year);
		}

		// Legacy fallback: date anchors derived from the resolved day
		// (never easter — inference rule above).
		$out = [];
		foreach ($catalog::getSuggestedCompanyHolidaysForRegionAndYear($region, $year) as $date => $msgid) {
			$out[$date] = [
				'name' => $msgid,
				'anchor' => [
					'type' => 'date',
					'month' => (int)substr($date, 5, 2),
					'day' => (int)substr($date, 8, 2),
				],
			];
		}
		return $out;
	}
}
