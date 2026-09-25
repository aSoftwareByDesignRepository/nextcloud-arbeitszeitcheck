<?php

declare(strict_types=1);

/**
 * Resolves LaborLawProfile from the instance country and optional per-user
 * override (E-9). Holidays remain region-based; working-time law may follow
 * a user-specific country when `labor_law_country` is set.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCP\IConfig;

class LaborLawProfileFactory
{
	public const CONFIG_KEY_COUNTRY = 'country';
	public const CONFIG_KEY_WEEKLY_ABSOLUTE_MAX = 'weekly_absolute_max_hours';
	public const USER_SETTING_LABOR_LAW_COUNTRY = 'labor_law_country';

	/** @var array<string, LaborLawProfile> */
	private array $cachedByKey = [];

	/** @var array<string, string> userId ('' = instance) → effective country */
	private array $effectiveCountryByUser = [];

	public function __construct(
		private readonly IConfig $config,
		private readonly ?UserSettingsMapper $userSettingsMapper = null,
	) {
	}

	public function getConfiguredCountry(): string
	{
		$country = strtoupper(trim(
			$this->config->getAppValue('arbeitszeitcheck', self::CONFIG_KEY_COUNTRY, RegionRegistry::COUNTRY_DE)
		));

		return RegionRegistry::isSupportedCountry($country) ? $country : RegionRegistry::COUNTRY_DE;
	}

	/**
	 * Effective labour-law country for a user (E-9).
	 * Empty / invalid per-user override → instance country.
	 */
	public function getEffectiveCountry(?string $userId = null): string
	{
		$cacheKey = $userId ?? '';
		if (isset($this->effectiveCountryByUser[$cacheKey])) {
			return $this->effectiveCountryByUser[$cacheKey];
		}

		$instance = $this->getConfiguredCountry();
		if ($userId === null || $userId === '' || $this->userSettingsMapper === null) {
			return $this->effectiveCountryByUser[$cacheKey] = $instance;
		}

		try {
			$override = strtoupper(trim(
				$this->userSettingsMapper->getStringSetting($userId, self::USER_SETTING_LABOR_LAW_COUNTRY, '')
			));
		} catch (\Throwable) {
			return $this->effectiveCountryByUser[$cacheKey] = $instance;
		}

		$resolved = RegionRegistry::isSupportedCountry($override) ? $override : $instance;
		return $this->effectiveCountryByUser[$cacheKey] = $resolved;
	}

	/**
	 * Profile for the instance country, or for a specific user's effective country.
	 */
	public function getProfile(?string $userId = null): LaborLawProfile
	{
		$country = $this->getEffectiveCountry($userId);
		$weeklyAbs = $country === RegionRegistry::COUNTRY_CH
			? $this->resolveSwissWeeklyAbsoluteMax()
			: null;
		$cacheKey = $country . ':' . ($weeklyAbs !== null ? (string)$weeklyAbs : '-');

		if (!isset($this->cachedByKey[$cacheKey])) {
			$this->cachedByKey[$cacheKey] = self::profileForCountry($country, $weeklyAbs);
		}

		return $this->cachedByKey[$cacheKey];
	}

	/**
	 * Profile for the logged-in session user (E-9), falling back to the
	 * instance profile when no session is available (CLI / background).
	 */
	public function getProfileForCurrentUser(): LaborLawProfile
	{
		$uid = null;
		try {
			$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
			$uid = $user !== null ? $user->getUID() : null;
		} catch (\Throwable) {
			$uid = null;
		}

		return $this->getProfile($uid);
	}

	/**
	 * Drop the request cache (used after the admin saves a new country / weekly cap).
	 */
	public function clearCache(): void
	{
		$this->cachedByKey = [];
		$this->effectiveCountryByUser = [];
	}

	/**
	 * Swiss ArG Art. 9: weekly absolute maximum is 45 h generally, 50 h for
	 * certain sectors. Admin may choose 45 or 50; anything else falls back to 45.
	 */
	public function resolveSwissWeeklyAbsoluteMax(): float
	{
		$raw = trim($this->config->getAppValue(
			'arbeitszeitcheck',
			self::CONFIG_KEY_WEEKLY_ABSOLUTE_MAX,
			'45'
		));
		$value = (float)$raw;

		return ($value === 50.0) ? 50.0 : 45.0;
	}

	public static function profileForCountry(string $country, ?float $swissWeeklyAbsolute = null): LaborLawProfile
	{
		return match (strtoupper(trim($country))) {
			RegionRegistry::COUNTRY_AT => self::austrianProfile(),
			RegionRegistry::COUNTRY_CH => self::swissProfile($swissWeeklyAbsolute),
			default => self::germanProfile(),
		};
	}

	/**
	 * German ArbZG — every value equals the pre-profile hard-coded literal
	 * (behaviour-neutral by construction, see snapshot tests).
	 */
	private static function germanProfile(): LaborLawProfile
	{
		return new LaborLawProfile(
			country: RegionRegistry::COUNTRY_DE,
			dailyMaxHoursDefault: 10.0,
			minRestHoursDefault: 11.0,
			breakTiers: [
				['afterHours' => 9.0, 'breakMinutes' => 45],
				['afterHours' => 6.0, 'breakMinutes' => 30],
			],
			weeklyAvgMaxHours: 48.0,
			avgWindowWeeks: 26,
			weeklyAbsoluteMaxHours: null,
			dailyAvgMaxHours: 8.0,
			nightWindowStartHour: 23,
			nightWindowEndHour: 6,
			lawShortLabels: [
				'daily' => 'ArbZG §3',
				'breaks' => 'ArbZG §4',
				'rest' => 'ArbZG §5',
				'weekly' => 'ArbZG §3',
				'night' => 'ArbZG §6',
				'sundayHoliday' => 'ArbZG §9',
				'dailyAvg' => 'ArbZG §3',
			],
			vacationDaysSuggestion: 25,
			minBreakMinutes: 15,
			allowedBreakSplitPatterns: null,
		);
	}

	/**
	 * Austrian AZG/ARG. Deliberately conservative where the AZG offers
	 * sector-specific extensions:
	 *  - 12 h daily / 60 h weekly absolute maximum (AZG §9),
	 *  - 48 h weekly average over a 17-week window (AZG §9 Abs. 4),
	 *  - 30 min break after more than 6 h (AZG §11) with statutory splits
	 *    2×15 / 3×10 (other splits require a works agreement — rejected),
	 *  - 11 h daily rest (AZG §12),
	 *  - night window 22:00–05:00 (AZG §12b, informational),
	 *  - weekend/holiday rest per ARG §3.
	 */
	private static function austrianProfile(): LaborLawProfile
	{
		return new LaborLawProfile(
			country: RegionRegistry::COUNTRY_AT,
			dailyMaxHoursDefault: 12.0,
			minRestHoursDefault: 11.0,
			breakTiers: [
				['afterHours' => 6.0, 'breakMinutes' => 30],
			],
			weeklyAvgMaxHours: 48.0,
			avgWindowWeeks: 17,
			weeklyAbsoluteMaxHours: 60.0,
			dailyAvgMaxHours: null,
			nightWindowStartHour: 22,
			nightWindowEndHour: 5,
			lawShortLabels: [
				'daily' => 'AZG §9',
				'breaks' => 'AZG §11',
				'rest' => 'AZG §12',
				'weekly' => 'AZG §9',
				'night' => 'AZG §12b',
				'sundayHoliday' => 'ARG §3',
				'dailyAvg' => 'AZG §9',
			],
			vacationDaysSuggestion: 25,
			minBreakMinutes: 10,
			allowedBreakSplitPatterns: [
				[15, 15],
				[10, 10, 10],
			],
		);
	}

	/**
	 * Swiss ArG (Arbeitsgesetz). Conservative general regime:
	 *  - 10 h daily maximum (ArG Art. 9),
	 *  - absolute weekly maximum 45 h (default) or 50 h (admin override),
	 *  - no averaging-window daily/weekly average rule (unlike ArbZG),
	 *  - break tiers ArG Art. 15: 15 min after 5.5 h, 30 after 7 h, 60 after 9 h,
	 *  - 11 h daily rest (ArG Art. 15a),
	 *  - night window 23:00–06:00 (ArG Art. 16 informational),
	 *  - Sunday/holiday rest ArG Art. 18,
	 *  - vacation suggestion 20 days (OrG Art. 329a — profile suggestion only).
	 */
	private static function swissProfile(?float $weeklyAbsolute = null): LaborLawProfile
	{
		$weekly = ($weeklyAbsolute === 50.0) ? 50.0 : 45.0;

		return new LaborLawProfile(
			country: RegionRegistry::COUNTRY_CH,
			dailyMaxHoursDefault: 10.0,
			minRestHoursDefault: 11.0,
			breakTiers: [
				['afterHours' => 9.0, 'breakMinutes' => 60],
				['afterHours' => 7.0, 'breakMinutes' => 30],
				['afterHours' => 5.5, 'breakMinutes' => 15],
			],
			weeklyAvgMaxHours: null,
			avgWindowWeeks: 0,
			weeklyAbsoluteMaxHours: $weekly,
			dailyAvgMaxHours: null,
			nightWindowStartHour: 23,
			nightWindowEndHour: 6,
			lawShortLabels: [
				'daily' => 'ArG Art. 9',
				'breaks' => 'ArG Art. 15',
				'rest' => 'ArG Art. 15a',
				'weekly' => 'ArG Art. 9',
				'night' => 'ArG Art. 16',
				'sundayHoliday' => 'ArG Art. 18',
				'dailyAvg' => 'ArG Art. 9',
			],
			vacationDaysSuggestion: 20,
			minBreakMinutes: 15,
			allowedBreakSplitPatterns: null,
		);
	}
}
