<?php

declare(strict_types=1);

/**
 * Single source of truth for supported countries and holiday regions (DACH).
 *
 * Region codes:
 *  - Germany keeps the historical Bundesland codes without a country prefix
 *    ('BW' … 'TH'). Rule: a code without '-' is always a German region (legacy).
 *  - Austria uses prefixed codes ('AT-W', 'AT-NOE', …), max 6 characters, so
 *    they fit the existing at_holidays.state VARCHAR(8) column.
 *  - Switzerland uses prefixed codes ('CH-ZH', …) for all 26 cantons
 *    (ISO 3166-2 letters, max 5 characters — fits VARCHAR(8)).
 *
 * Austrian codes intentionally use letter abbreviations instead of the ISO
 * 3166-2 numeric codes (AT-1 … AT-9): letters are far less error-prone in
 * support tickets, logs, and CSV exports. ISO mapping for reference:
 *   AT-1 Burgenland (AT-B),        AT-2 Kärnten (AT-K),
 *   AT-3 Niederösterreich (AT-NOE), AT-4 Oberösterreich (AT-OOE),
 *   AT-5 Salzburg (AT-S),          AT-6 Steiermark (AT-ST),
 *   AT-7 Tirol (AT-T),             AT-8 Vorarlberg (AT-V),
 *   AT-9 Wien (AT-W)
 *
 * This registry replaces the previously 7× duplicated Bundesland lists
 * (HolidayService, AdminController ×2, AdminUserProfileUpdateService, and
 * three templates) which had already drifted in ordering.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class RegionRegistry
{
	public const COUNTRY_DE = 'DE';
	public const COUNTRY_AT = 'AT';
	public const COUNTRY_CH = 'CH';

	/**
	 * German region labels. IMPORTANT: label strings are existing l10n msgids
	 * (note the U+2011 non-breaking hyphens) — do not "fix" the hyphens or the
	 * existing translations will be orphaned.
	 *
	 * @var array<string,string> code => label msgid
	 */
	private const DE_REGIONS = [
		'BW' => 'Baden‑Württemberg',
		'BY' => 'Bayern',
		'BE' => 'Berlin',
		'BB' => 'Brandenburg',
		'HB' => 'Bremen',
		'HH' => 'Hamburg',
		'HE' => 'Hessen',
		'MV' => 'Mecklenburg‑Vorpommern',
		'NI' => 'Niedersachsen',
		'NW' => 'Nordrhein‑Westfalen',
		'RP' => 'Rheinland‑Pfalz',
		'SL' => 'Saarland',
		'SN' => 'Sachsen',
		'ST' => 'Sachsen‑Anhalt',
		'SH' => 'Schleswig‑Holstein',
		'TH' => 'Thüringen',
	];

	/** @var array<string,string> code => label msgid */
	private const AT_REGIONS = [
		'AT-B' => 'Burgenland',
		'AT-K' => 'Kärnten',
		'AT-NOE' => 'Niederösterreich',
		'AT-OOE' => 'Oberösterreich',
		'AT-S' => 'Salzburg',
		'AT-ST' => 'Steiermark',
		'AT-T' => 'Tirol',
		'AT-V' => 'Vorarlberg',
		'AT-W' => 'Wien',
	];

	/** @var array<string,string> code => label msgid (ISO 3166-2 cantons) */
	private const CH_REGIONS = [
		'CH-AG' => 'Aargau',
		'CH-AI' => 'Appenzell Innerrhoden',
		'CH-AR' => 'Appenzell Ausserrhoden',
		'CH-BE' => 'Bern',
		'CH-BL' => 'Basel-Landschaft',
		'CH-BS' => 'Basel-Stadt',
		'CH-FR' => 'Fribourg',
		'CH-GE' => 'Geneva',
		'CH-GL' => 'Glarus',
		'CH-GR' => 'Graubünden',
		'CH-JU' => 'Jura',
		'CH-LU' => 'Lucerne',
		'CH-NE' => 'Neuchâtel',
		'CH-NW' => 'Nidwalden',
		'CH-OW' => 'Obwalden',
		'CH-SG' => 'St. Gallen',
		'CH-SH' => 'Schaffhausen',
		'CH-SO' => 'Solothurn',
		'CH-SZ' => 'Schwyz',
		'CH-TG' => 'Thurgau',
		'CH-TI' => 'Ticino',
		'CH-UR' => 'Uri',
		'CH-VD' => 'Vaud',
		'CH-VS' => 'Valais',
		'CH-ZG' => 'Zug',
		'CH-ZH' => 'Zurich',
	];

	private const DEFAULT_REGION_BY_COUNTRY = [
		self::COUNTRY_DE => 'NW',
		self::COUNTRY_AT => 'AT-W',
		self::COUNTRY_CH => 'CH-ZH',
	];

	/** English msgids for country names (translate with $l->t()). */
	private const COUNTRY_LABELS = [
		self::COUNTRY_DE => 'Germany',
		self::COUNTRY_AT => 'Austria',
		self::COUNTRY_CH => 'Switzerland',
	];

	/**
	 * @return list<string> ISO country codes supported by this release
	 */
	public static function supportedCountries(): array
	{
		return [self::COUNTRY_DE, self::COUNTRY_AT, self::COUNTRY_CH];
	}

	public static function isSupportedCountry(string $country): bool
	{
		return in_array(strtoupper(trim($country)), self::supportedCountries(), true);
	}

	/**
	 * @return array<string,string> country code => English label msgid
	 */
	public static function countryLabels(): array
	{
		return self::COUNTRY_LABELS;
	}

	/**
	 * @return array<string,string> region code => label msgid
	 */
	public static function regionsForCountry(string $country): array
	{
		return match (strtoupper(trim($country))) {
			self::COUNTRY_DE => self::DE_REGIONS,
			self::COUNTRY_AT => self::AT_REGIONS,
			self::COUNTRY_CH => self::CH_REGIONS,
			default => [],
		};
	}

	/**
	 * 'BW' → 'DE'; 'AT-W' → 'AT'; 'CH-ZH' → 'CH'.
	 * Codes without '-' are always DE (legacy rule).
	 */
	public static function countryOf(string $regionCode): string
	{
		$regionCode = strtoupper(trim($regionCode));
		$dash = strpos($regionCode, '-');
		if ($dash === false) {
			return self::COUNTRY_DE;
		}

		return substr($regionCode, 0, $dash);
	}

	public static function isValidRegion(string $regionCode): bool
	{
		$regionCode = strtoupper(trim($regionCode));

		return isset(self::DE_REGIONS[$regionCode])
			|| isset(self::AT_REGIONS[$regionCode])
			|| isset(self::CH_REGIONS[$regionCode]);
	}

	/**
	 * Safe default region per country (DE → NW to preserve historic behaviour).
	 * Unknown countries fall back to the German default.
	 */
	public static function defaultRegionForCountry(string $country): string
	{
		return self::DEFAULT_REGION_BY_COUNTRY[strtoupper(trim($country))]
			?? self::DEFAULT_REGION_BY_COUNTRY[self::COUNTRY_DE];
	}

	/**
	 * @return array<string,string> region code => label msgid (all countries)
	 */
	public static function allRegions(): array
	{
		return self::DE_REGIONS + self::AT_REGIONS + self::CH_REGIONS;
	}

	/**
	 * @return list<string> region codes of all supported countries
	 */
	public static function allRegionCodes(): array
	{
		return array_keys(self::allRegions());
	}

	public static function regionLabel(string $regionCode): string
	{
		return self::allRegions()[strtoupper(trim($regionCode))] ?? $regionCode;
	}

	/**
	 * Resolve a stored default region against the organisation country.
	 * Invalid codes OR regions of another country fall back to the country default
	 * (prevents orphan pairs like country=AT + german_state=NW after races / legacy).
	 */
	public static function resolveDefaultRegionForCountry(string $country, string $storedRegion): string
	{
		$country = strtoupper(trim($country));
		if (!self::isSupportedCountry($country)) {
			$country = self::COUNTRY_DE;
		}
		$fallback = self::defaultRegionForCountry($country);
		$region = strtoupper(trim($storedRegion));
		if ($region === ''
			|| !self::isValidRegion($region)
			|| self::countryOf($region) !== $country) {
			return $fallback;
		}

		return $region;
	}
}
