<?php

declare(strict_types=1);

/**
 * Reference catalog of Swiss public holidays per canton (ArG / cantonal law).
 *
 * Switzerland has only a few truly federal holidays; most non-working days are
 * cantonal. This catalog seeds the union of federal + cantonal statutory days
 * used for working-day math. Names are English msgids for IL10N at seed time.
 *
 * Half-day statutory holidays (E-6): Zurich observes Sechseläuten and
 * Knabenschiessen as afternoon-only public holidays. They are seeded with
 * kind=half so vacation/working-day weights use 0.5. Germany and Austria never
 * produce half-day statutory entries.
 *
 * Used only to seed at_holidays — never as a runtime overlay for working-day
 * math. Conservative where cantonal practice varies by municipality.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class SwissStatutoryHolidayCatalog implements HolidayCatalogInterface
{
	/** Federal / nationwide fixed-date holidays (all 26 cantons). */
	private const FEDERAL_FIXED = [
		'01-01' => 'New Year',
		'08-01' => 'Swiss National Day',
		'12-25' => 'Christmas',
	];

	/**
	 * Berchtoldstag (2 Jan) — widespread in German-speaking Switzerland.
	 *
	 * @var list<string>
	 */
	private const BERCHTOLD_CANTONS = [
		'CH-AG', 'CH-BE', 'CH-FR', 'CH-GL', 'CH-JU', 'CH-LU', 'CH-NW', 'CH-OW',
		'CH-SH', 'CH-SO', 'CH-TG', 'CH-VD', 'CH-ZG', 'CH-ZH',
	];

	/**
	 * Epiphany (6 Jan).
	 *
	 * @var list<string>
	 */
	private const EPIPHANY_CANTONS = ['CH-GR', 'CH-LU', 'CH-SZ', 'CH-TI', 'CH-UR'];

	/**
	 * St. Joseph's Day (19 Mar).
	 *
	 * @var list<string>
	 */
	private const JOSEPH_CANTONS = ['CH-LU', 'CH-NW', 'CH-OW', 'CH-SZ', 'CH-TI', 'CH-UR', 'CH-VS', 'CH-ZG'];

	/**
	 * Labour Day (1 May).
	 *
	 * @var list<string>
	 */
	private const LABOUR_DAY_CANTONS = [
		'CH-AG', 'CH-BL', 'CH-BS', 'CH-JU', 'CH-NE', 'CH-SH', 'CH-SO', 'CH-TG',
		'CH-TI', 'CH-ZH',
	];

	/**
	 * Corpus Christi (Easter + 60).
	 *
	 * @var list<string>
	 */
	private const CORPUS_CHRISTI_CANTONS = [
		'CH-AI', 'CH-FR', 'CH-JU', 'CH-LU', 'CH-NW', 'CH-OW', 'CH-SZ', 'CH-SO',
		'CH-TI', 'CH-UR', 'CH-VS', 'CH-ZG',
	];

	/**
	 * Assumption Day (15 Aug).
	 *
	 * @var list<string>
	 */
	private const ASSUMPTION_CANTONS = [
		'CH-AI', 'CH-FR', 'CH-JU', 'CH-LU', 'CH-NW', 'CH-OW', 'CH-SZ', 'CH-SO',
		'CH-TI', 'CH-UR', 'CH-VS', 'CH-ZG',
	];

	/**
	 * All Saints (1 Nov).
	 *
	 * @var list<string>
	 */
	private const ALL_SAINTS_CANTONS = [
		'CH-AI', 'CH-FR', 'CH-GL', 'CH-JU', 'CH-LU', 'CH-NW', 'CH-OW', 'CH-SG',
		'CH-SZ', 'CH-SO', 'CH-TI', 'CH-UR', 'CH-VS', 'CH-ZG',
	];

	/**
	 * Immaculate Conception (8 Dec).
	 *
	 * @var list<string>
	 */
	private const IMMACULATE_CANTONS = [
		'CH-AI', 'CH-FR', 'CH-LU', 'CH-NW', 'CH-OW', 'CH-SZ', 'CH-TI', 'CH-UR',
		'CH-VS', 'CH-ZG',
	];

	/**
	 * St. Stephen's Day / Boxing Day (26 Dec).
	 *
	 * @var list<string>
	 */
	private const STEPHEN_CANTONS = [
		'CH-AG', 'CH-AR', 'CH-AI', 'CH-BE', 'CH-BL', 'CH-BS', 'CH-FR', 'CH-GL',
		'CH-GR', 'CH-JU', 'CH-LU', 'CH-NE', 'CH-NW', 'CH-OW', 'CH-SG', 'CH-SH',
		'CH-SZ', 'CH-SO', 'CH-TG', 'CH-TI', 'CH-UR', 'CH-VS', 'CH-ZG', 'CH-ZH',
	];

	/**
	 * Good Friday — most cantons except predominantly Catholic TI (and AI
	 * historically treats it differently; we still include AI as observed).
	 *
	 * @var list<string>
	 */
	private const GOOD_FRIDAY_CANTONS = [
		'CH-AG', 'CH-AR', 'CH-AI', 'CH-BE', 'CH-BL', 'CH-BS', 'CH-FR', 'CH-GE',
		'CH-GL', 'CH-GR', 'CH-JU', 'CH-LU', 'CH-NE', 'CH-NW', 'CH-OW', 'CH-SG',
		'CH-SH', 'CH-SZ', 'CH-SO', 'CH-TG', 'CH-UR', 'CH-VD', 'CH-VS', 'CH-ZG',
		'CH-ZH',
	];

	/**
	 * Easter Monday.
	 *
	 * @var list<string>
	 */
	private const EASTER_MONDAY_CANTONS = [
		'CH-AG', 'CH-AR', 'CH-AI', 'CH-BE', 'CH-BL', 'CH-BS', 'CH-FR', 'CH-GE',
		'CH-GL', 'CH-GR', 'CH-JU', 'CH-LU', 'CH-NE', 'CH-NW', 'CH-OW', 'CH-SG',
		'CH-SH', 'CH-SZ', 'CH-SO', 'CH-TG', 'CH-TI', 'CH-UR', 'CH-VD', 'CH-VS',
		'CH-ZG', 'CH-ZH',
	];

	/**
	 * Ascension Day — observed nationwide in practice.
	 *
	 * @var list<string>
	 */
	private const ASCENSION_CANTONS = [
		'CH-AG', 'CH-AR', 'CH-AI', 'CH-BE', 'CH-BL', 'CH-BS', 'CH-FR', 'CH-GE',
		'CH-GL', 'CH-GR', 'CH-JU', 'CH-LU', 'CH-NE', 'CH-NW', 'CH-OW', 'CH-SG',
		'CH-SH', 'CH-SZ', 'CH-SO', 'CH-TG', 'CH-TI', 'CH-UR', 'CH-VD', 'CH-VS',
		'CH-ZG', 'CH-ZH',
	];

	/**
	 * Whit Monday.
	 *
	 * @var list<string>
	 */
	private const WHIT_MONDAY_CANTONS = [
		'CH-AG', 'CH-AR', 'CH-AI', 'CH-BE', 'CH-BL', 'CH-BS', 'CH-FR', 'CH-GE',
		'CH-GL', 'CH-GR', 'CH-JU', 'CH-LU', 'CH-NE', 'CH-NW', 'CH-OW', 'CH-SG',
		'CH-SH', 'CH-SZ', 'CH-SO', 'CH-TG', 'CH-TI', 'CH-UR', 'CH-VD', 'CH-VS',
		'CH-ZG', 'CH-ZH',
	];

	/**
	 * @return array<string,string> date (Y-m-d) => English name (l10n msgid)
	 */
	public static function getStatutoryHolidaysForRegionAndYear(string $region, int $year): array
	{
		$entries = self::getStatutoryHolidayEntriesForRegionAndYear($region, $year);
		$out = [];
		foreach ($entries as $date => $entry) {
			$out[$date] = $entry['name'];
		}

		return $out;
	}

	/**
	 * Structured statutory entries including kind (full|half) — required for E-6.
	 *
	 * @return array<string, array{name: string, kind: string}>
	 */
	public static function getStatutoryHolidayEntriesForRegionAndYear(string $region, int $year): array
	{
		$region = strtoupper(trim($region));
		if (!isset(RegionRegistry::regionsForCountry(RegionRegistry::COUNTRY_CH)[$region])) {
			return [];
		}

		$holidays = [];

		foreach (self::FEDERAL_FIXED as $md => $name) {
			$holidays[sprintf('%04d-%s', $year, $md)] = ['name' => $name, 'kind' => 'full'];
		}

		self::addFixedIfCanton($holidays, $year, '01-02', 'Berchtoldstag', $region, self::BERCHTOLD_CANTONS);
		self::addFixedIfCanton($holidays, $year, '01-06', 'Epiphany', $region, self::EPIPHANY_CANTONS);
		self::addFixedIfCanton($holidays, $year, '03-19', 'St. Joseph\'s Day', $region, self::JOSEPH_CANTONS);
		self::addFixedIfCanton($holidays, $year, '05-01', 'Labour Day', $region, self::LABOUR_DAY_CANTONS);
		self::addFixedIfCanton($holidays, $year, '08-15', 'Assumption Day', $region, self::ASSUMPTION_CANTONS);
		self::addFixedIfCanton($holidays, $year, '11-01', 'All Saints', $region, self::ALL_SAINTS_CANTONS);
		self::addFixedIfCanton($holidays, $year, '12-08', 'Immaculate Conception', $region, self::IMMACULATE_CANTONS);
		self::addFixedIfCanton($holidays, $year, '12-26', 'St. Stephen\'s Day', $region, self::STEPHEN_CANTONS);

		$easter = EasterCalculator::easterDate($year);
		self::addDatedIfCanton(
			$holidays,
			$easter->modify('-2 days')->format('Y-m-d'),
			'Good Friday',
			$region,
			self::GOOD_FRIDAY_CANTONS
		);
		self::addDatedIfCanton(
			$holidays,
			$easter->modify('+1 day')->format('Y-m-d'),
			'Easter Monday',
			$region,
			self::EASTER_MONDAY_CANTONS
		);
		self::addDatedIfCanton(
			$holidays,
			$easter->modify('+39 days')->format('Y-m-d'),
			'Ascension',
			$region,
			self::ASCENSION_CANTONS
		);
		self::addDatedIfCanton(
			$holidays,
			$easter->modify('+50 days')->format('Y-m-d'),
			'Whit Monday',
			$region,
			self::WHIT_MONDAY_CANTONS
		);
		self::addDatedIfCanton(
			$holidays,
			$easter->modify('+60 days')->format('Y-m-d'),
			'Corpus Christi',
			$region,
			self::CORPUS_CHRISTI_CANTONS
		);

		// Geneva: Jeûne genevois — Thursday after the first Sunday of September.
		if ($region === 'CH-GE') {
			$holidays[self::jeuneGenevois($year)->format('Y-m-d')] = [
				'name' => 'Jeûne genevois',
				'kind' => 'full',
			];
		}

		// Vaud: Lundi du Jeûne — Monday after the federal day of fasting
		// (third Sunday of September).
		if ($region === 'CH-VD') {
			$holidays[self::lundiDuJeune($year)->format('Y-m-d')] = [
				'name' => 'Lundi du Jeûne',
				'kind' => 'full',
			];
		}

		// Zurich half-day city holidays (E-6) — afternoon only.
		if ($region === 'CH-ZH') {
			$holidays[self::sechselaeuten($year)->format('Y-m-d')] = [
				'name' => 'Sechseläuten',
				'kind' => 'half',
			];
			$holidays[self::knabenschiessen($year)->format('Y-m-d')] = [
				'name' => 'Knabenschiessen',
				'kind' => 'half',
			];
		}

		ksort($holidays);

		return $holidays;
	}

	/**
	 * @return array<string,string> date => name for half-day statutory only
	 */
	public static function getStatutoryHalfDayHolidaysForRegionAndYear(string $region, int $year): array
	{
		$half = [];
		foreach (self::getStatutoryHolidayEntriesForRegionAndYear($region, $year) as $date => $entry) {
			if ($entry['kind'] === 'half') {
				$half[$date] = $entry['name'];
			}
		}

		return $half;
	}

	/**
	 * Non-statutory suggestions (24/31 Dec are common company half/full days).
	 *
	 * @return array<string,string>
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
	 * Structured suggestion entries with rule anchors (B-R4).
	 *
	 * @return array<string, array{name: string, anchor: array{type: string, month?: int, day?: int, offset?: int}}>
	 */
	public static function getSuggestedCompanyHolidayEntriesForRegionAndYear(string $region, int $year): array
	{
		$region = strtoupper(trim($region));
		if (!isset(RegionRegistry::regionsForCountry(RegionRegistry::COUNTRY_CH)[$region])) {
			return [];
		}

		return [
			sprintf('%04d-12-24', $year) => [
				'name' => 'Christmas Eve',
				'anchor' => ['type' => 'date', 'month' => 12, 'day' => 24],
			],
			sprintf('%04d-12-31', $year) => [
				'name' => 'New Year\'s Eve',
				'anchor' => ['type' => 'date', 'month' => 12, 'day' => 31],
			],
		];
	}

	/**
	 * @param array<string, array{name: string, kind: string}> $holidays
	 * @param list<string> $cantons
	 */
	private static function addFixedIfCanton(
		array &$holidays,
		int $year,
		string $md,
		string $name,
		string $region,
		array $cantons,
	): void {
		if (!in_array($region, $cantons, true)) {
			return;
		}
		$holidays[sprintf('%04d-%s', $year, $md)] = ['name' => $name, 'kind' => 'full'];
	}

	/**
	 * @param array<string, array{name: string, kind: string}> $holidays
	 * @param list<string> $cantons
	 */
	private static function addDatedIfCanton(
		array &$holidays,
		string $ymd,
		string $name,
		string $region,
		array $cantons,
	): void {
		if (!in_array($region, $cantons, true)) {
			return;
		}
		$holidays[$ymd] = ['name' => $name, 'kind' => 'full'];
	}

	/**
	 * Thursday after the first Sunday of September (Geneva).
	 */
	public static function jeuneGenevois(int $year): \DateTimeImmutable
	{
		$firstSunday = (new \DateTimeImmutable(sprintf('%04d-09-01', $year)))
			->modify('first sunday of this month');

		return $firstSunday->modify('+4 days');
	}

	/**
	 * Monday after the third Sunday of September (Vaud / Jeûne fédéral).
	 */
	public static function lundiDuJeune(int $year): \DateTimeImmutable
	{
		$sept1 = new \DateTimeImmutable(sprintf('%04d-09-01', $year));
		$firstSunday = $sept1->modify('first sunday of this month');
		$thirdSunday = $firstSunday->modify('+14 days');

		return $thirdSunday->modify('+1 day');
	}

	/**
	 * Third Monday of April (Zurich Sechseläuten) — afternoon holiday.
	 */
	public static function sechselaeuten(int $year): \DateTimeImmutable
	{
		$april1 = new \DateTimeImmutable(sprintf('%04d-04-01', $year));
		$firstMonday = $april1->modify('first monday of this month');

		return $firstMonday->modify('+14 days');
	}

	/**
	 * Second Monday of September (Zurich Knabenschiessen) — afternoon holiday.
	 */
	public static function knabenschiessen(int $year): \DateTimeImmutable
	{
		$sept1 = new \DateTimeImmutable(sprintf('%04d-09-01', $year));
		$firstMonday = $sept1->modify('first monday of this month');

		return $firstMonday->modify('+7 days');
	}
}
