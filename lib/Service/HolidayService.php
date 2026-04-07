<?php

declare(strict_types=1);

/**
 * Holiday rules and working-day math for the arbeitszeitcheck app.
 *
 * Combines:
 * - Static helpers: Germany-wide base public-holiday calendar and pure
 *   working-day calculations (Mon–Fri, excluding those holidays).
 * - Instance API: Bundesland resolution, DB-backed holidays (statutory +
 *   company/custom), caching, and user-aware working-day counts.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class HolidayService
{
	/** @var HolidayMapper */
	private $holidayMapper;

	/** @var UserSettingsMapper */
	private $userSettingsMapper;

	/** @var IConfig */
	private $config;

	/** @var IL10N */
	private $l10n;

	/** @var LoggerInterface */
	private $logger;

	/** @var \OCP\ICache|null */
	private $cache;

	/** @var string[] */
	private const VALID_STATES = [
		'BW', 'BY', 'BE', 'BB', 'HB', 'HH', 'HE', 'MV',
		'NI', 'NW', 'RP', 'SL', 'SN', 'ST', 'SH', 'TH',
	];

	/**
	 * App config key that tracks which (state, year) combinations have already
	 * been initialised from the base German public holiday calendar.
	 *
	 * Once a (state, year) is marked as initialised we NEVER auto-seed it
	 * again, even if all holidays are later deleted from at_holidays. This
	 * allows administrators to permanently remove statutory holidays without
	 * them being recreated on the next read.
	 */
	private const INITIALIZED_CONFIG_KEY = 'holidays_initialized_state_years';

	public function __construct(
		HolidayMapper $holidayMapper,
		UserSettingsMapper $userSettingsMapper,
		IConfig $config,
		ICacheFactory $cacheFactory,
		IL10N $l10n,
		LoggerInterface $logger
	) {
		$this->holidayMapper = $holidayMapper;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->config = $config;
		$this->l10n = $l10n;
		$this->logger = $logger;
		// Use a local app-specific cache namespace
		$this->cache = $cacheFactory->createDistributed('arbeitszeitcheck_holidays');
	}

	/**
	 * Build cache key for a given state/year combination.
	 */
	private function getCacheKey(string $state, int $year): string
	{
		return sprintf('holidays:%s:%d', $this->normalizeState($state), $year);
	}

	/**
	 * Invalidate cached holidays for a given state/year.
	 *
	 * This is called from admin write operations so that changes to
	 * holidays become visible immediately in the admin UI and services.
	 */
	public function clearCacheForStateYear(string $state, int $year): void
	{
		if ($this->cache === null) {
			return;
		}
		$this->cache->remove($this->getCacheKey($state, $year));
	}

	/**
	 * Resolve the effective German state (Bundesland) for a given user.
	 *
	 * Precedence:
	 * 1) Per-user setting "german_state" (UserSettingsMapper)
	 * 2) Global app default (app config "german_state", default "NW")
	 *
	 * @param string $userId
	 * @return string
	 */
	public function resolveStateForUser(string $userId): string
	{
		$defaultState = $this->getDefaultState();
		$userState = $this->userSettingsMapper->getStringSetting($userId, 'german_state', '');

		$state = $userState !== '' ? $userState : $defaultState;

		if (!in_array($state, self::VALID_STATES, true)) {
			$this->logger->warning('HolidayService: invalid state for user, falling back to default', [
				'userId' => $userId,
				'state' => $state,
				'defaultState' => $defaultState,
			]);
			return $defaultState;
		}

		return $state;
	}

	/**
	 * Get the configured default German state for the instance.
	 */
	private function getDefaultState(): string
	{
		$state = $this->config->getAppValue('arbeitszeitcheck', 'german_state', 'NW');
		if (!in_array($state, self::VALID_STATES, true)) {
			$state = 'NW';
		}
		return $state;
	}

	/**
	 * DTO for holiday information used in API / higher-level services.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function getHolidaysForRange(string $state, \DateTime $start, \DateTime $end): array
	{
		$state = $this->normalizeState($state);

		$start = (clone $start)->setTime(0, 0, 0);
		$end = (clone $end)->setTime(0, 0, 0);

		if ($end < $start) {
			[$start, $end] = [$end, $start];
		}

		$years = $this->getYearsInRange($start, $end);

		$result = [];

		foreach ($years as $year) {
			$holidays = $this->getHolidaysForYearInternal($state, $year);
			foreach ($holidays as $holiday) {
				$date = $holiday->getDate();
				if ($date === null) {
					continue;
				}
				$dateNorm = $date instanceof \DateTimeInterface ? $date : new \DateTime((string)$date);
				if ($dateNorm < $start || $dateNorm > $end) {
					continue;
				}
				$dto = $this->buildHolidayDto($holiday);
				if (isset($dto['date']) && $dto['date'] !== null) {
					$result[] = $dto;
				}
			}
		}

		// Sort by date ascending
		usort($result, static function (array $a, array $b): int {
			return strcmp($a['date'], $b['date']);
		});

		return $result;
	}

	/**
	 * Compute working days (Mon–Fri) for a user and date range, using
	 * state-specific holidays plus company/custom holidays from DB.
	 */
	public function computeWorkingDaysForUser(string $userId, \DateTime $start, \DateTime $end): float
	{
		$state = $this->resolveStateForUser($userId);
		$extraWeights = $this->buildExtraHolidayWeightsForUser($userId, $start, $end, $state);

		return self::computeWorkingDays($start, $end, $extraWeights);
	}

	/**
	 * Compute working days per year (Mon–Fri) for a user over a date range.
	 *
	 * @return array<int,float> year => working days
	 */
	public function computeWorkingDaysPerYearForUser(string $userId, \DateTime $start, \DateTime $end): array
	{
		$state = $this->resolveStateForUser($userId);
		$extraWeights = $this->buildExtraHolidayWeightsForUser($userId, $start, $end, $state);

		return self::computeWorkingDaysPerYear($start, $end, $extraWeights);
	}

	/**
	 * Check if a given date is a (full or half) holiday for the user.
	 */
	public function isHolidayForUser(string $userId, \DateTime $date): bool
	{
		$state = $this->resolveStateForUser($userId);
		return $this->isHolidayForState($state, $date);
	}

	/**
	 * Check if a given date is a holiday for a specific state.
	 */
	public function isHolidayForState(string $state, \DateTime $date): bool
	{
		$state = $this->normalizeState($state);

		$year = (int)$date->format('Y');
		$holidays = $this->getHolidaysForYearInternal($state, $year);
		$key = $date->format('Y-m-d');

		foreach ($holidays as $holiday) {
			$holidayDate = $holiday->getDate();
			if ($holidayDate && $holidayDate->format('Y-m-d') === $key) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a map of additional holiday weights (full/half, statutory/company/custom)
	 * for the given date range and user/state.
	 *
	 * The weight semantics are aligned with static computeWorkingDays():
	 * - 1.0  => full holiday (day becomes non-working)
	 * - 0.5  => half holiday (day counts as 0.5 working day)
	 * - 0.0  => no special treatment
	 *
	 * @return array<string,float> date (Y-m-d) => weight
	 */
	private function buildExtraHolidayWeightsForUser(string $userId, \DateTime $start, \DateTime $end, string $state): array
	{
		unset($userId); // reserved for future user-specific overrides

		$start = (clone $start)->setTime(0, 0, 0);
		$end = (clone $end)->setTime(0, 0, 0);
		if ($end < $start) {
			[$start, $end] = [$end, $start];
		}

		$weights = [];

		$years = $this->getYearsInRange($start, $end);
		foreach ($years as $year) {
			$holidays = $this->getHolidaysForYearInternal($state, $year);
			foreach ($holidays as $holiday) {
				$date = $holiday->getDate();
				if ($date < $start || $date > $end) {
					continue;
				}
				$dateStr = $date->format('Y-m-d');

				$kind = $holiday->getKind();
				$scope = $holiday->getScope();

				// Statutory holidays always count as full non-working days
				if ($scope === Holiday::SCOPE_STATUTORY) {
					$weight = 1.0;
				} else {
					$weight = ($kind === Holiday::KIND_HALF) ? 0.5 : 1.0;
				}

				$current = $weights[$dateStr] ?? 0.0;
				if ($weight > $current) {
					$weights[$dateStr] = $weight;
				}
			}
		}

		return $weights;
	}

	/**
	 * Retrieve holidays for a given state and year, seeding statutory
	 * holidays from the base calendar if necessary.
	 *
	 * @return Holiday[]
	 */
	private function getHolidaysForYearInternal(string $state, int $year): array
	{
		$state = $this->normalizeState($state);

		// Seed statutory holidays when missing. Company holidays alone must not
		// prevent statutory seeding (bug: admin adding company holidays first
		// caused statutory to be skipped).
		$statutoryAutoReseed = $this->config->getAppValue('arbeitszeitcheck', 'statutory_auto_reseed', '1') === '1';
		$needsStatutory = !$this->holidayMapper->hasStatutoryHolidaysForStateAndYear($state, $year);
		// When auto-reseed is disabled and year was already initialized, do not re-seed
		// (preserves admin-deleted statutory holidays)
		if ($needsStatutory && !$statutoryAutoReseed && $this->isYearInitialized($state, $year)) {
			$needsStatutory = false;
		}
		if ($needsStatutory) {
			$this->seedStatutoryHolidaysForStateAndYear($state, $year);
			$this->clearCacheForStateYear($state, $year);
		}
		if (!$this->isYearInitialized($state, $year)) {
			$this->markYearInitialized($state, $year);
		}

		$cacheKey = $this->getCacheKey($state, $year);
		if (!$needsStatutory && $this->cache !== null) {
			$cached = $this->cache->get($cacheKey);
			if (is_array($cached)) {
				return $this->hydrateFromArray($cached);
			}
		}

		$entities = $this->holidayMapper->findByStateAndYear($state, $year);

		if ($this->cache !== null) {
			$this->cache->set($cacheKey, array_map(static function (Holiday $h): array {
				return $h->toArray();
			}, $entities));
		}

		return $entities;
	}

	/**
	 * Seed statutory holidays for a state/year based on the base German calendar.
	 */
	private function seedStatutoryHolidaysForStateAndYear(string $state, int $year): void
	{
		try {
			$base = self::getGermanPublicHolidaysForYear($year);
		} catch (\Throwable $e) {
			$this->logger->error('HolidayService: failed to get base holidays', [
				'year' => $year,
				'exception' => $e,
			]);
			return;
		}

		foreach ($base as $dateStr => $name) {
			$holiday = new Holiday();
			$holiday->setState($state);
			// Store a localized, human-readable name for statutory holidays.
			$holiday->setName($this->l10n->t($name));
			$holiday->setKind(Holiday::KIND_FULL);
			$holiday->setScope(Holiday::SCOPE_STATUTORY);
			$holiday->setSource(Holiday::SOURCE_GENERATED);
			$holiday->setCreatedAt(new \DateTime());
			$holiday->setUpdatedAt(new \DateTime());

			try {
				$holiday->setDate(new \DateTime($dateStr));
			} catch (\Throwable $e) {
				$this->logger->warning('HolidayService: invalid base holiday date skipped', [
					'date' => $dateStr,
					'state' => $state,
					'exception' => $e,
				]);
				continue;
			}

			if (!$holiday->isValid()) {
				$this->logger->warning('HolidayService: generated holiday failed validation, skipped', [
					'state' => $state,
					'year' => $year,
					'date' => $dateStr,
					'name' => $name,
				]);
				continue;
			}

			// Idempotent: skip if already exists (avoids duplicates from concurrent requests)
			if ($this->holidayMapper->existsForStateDateScope($state, $dateStr, Holiday::SCOPE_STATUTORY)) {
				continue;
			}

			try {
				$this->holidayMapper->insert($holiday);
			} catch (\Throwable $e) {
				$msg = (string)$e->getMessage();
				$isDuplicate = $e instanceof \OCP\DB\Exception
					&& $e->getReason() === \OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION;
				if ($isDuplicate || str_contains($msg, 'Duplicate entry') || str_contains($msg, 'unique constraint')) {
					continue;
				}
				$this->logger->error('HolidayService: failed to insert generated holiday', [
					'state' => $state,
					'year' => $year,
					'date' => $dateStr,
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * Normalize a state code and ensure it is a known value.
	 */
	private function normalizeState(string $state): string
	{
		$state = strtoupper(trim($state));
		if (!in_array($state, self::VALID_STATES, true)) {
			$state = $this->getDefaultState();
		}
		return $state;
	}

	/**
	 * Helper: determine all years touched by a date range.
	 *
	 * @return int[]
	 */
	private function getYearsInRange(\DateTime $start, \DateTime $end): array
	{
		$years = [];
		$current = (int)$start->format('Y');
		$last = (int)$end->format('Y');
		for ($y = $current; $y <= $last; $y++) {
			$years[] = $y;
		}
		return $years;
	}

	/**
	 * Check whether a given (state, year) has already been initialised from
	 * the base German public holiday calendar.
	 */
	private function isYearInitialized(string $state, int $year): bool
	{
		$json = $this->config->getAppValue('arbeitszeitcheck', self::INITIALIZED_CONFIG_KEY, '[]');
		$list = json_decode($json, true);
		if (!is_array($list)) {
			$list = [];
		}
		$key = sprintf('%s-%04d', $state, $year);
		return in_array($key, $list, true);
	}

	/**
	 * Mark a (state, year) combination as initialised so that we never
	 * auto-seed it again, even if the at_holidays table becomes empty
	 * for that state/year later.
	 */
	private function markYearInitialized(string $state, int $year): void
	{
		$json = $this->config->getAppValue('arbeitszeitcheck', self::INITIALIZED_CONFIG_KEY, '[]');
		$list = json_decode($json, true);
		if (!is_array($list)) {
			$list = [];
		}
		$key = sprintf('%s-%04d', $state, $year);
		if (!in_array($key, $list, true)) {
			$list[] = $key;
			$this->config->setAppValue('arbeitszeitcheck', self::INITIALIZED_CONFIG_KEY, json_encode($list));
		}
	}

	/**
	 * Convert an array representation (from cache) back into Holiday entities.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return Holiday[]
	 */
	private function hydrateFromArray(array $rows): array
	{
		$result = [];
		foreach ($rows as $row) {
			$holiday = new Holiday();
			if (isset($row['id'])) {
				$holiday->setId((int)$row['id']);
			}
			if (isset($row['state'])) {
				$holiday->setState((string)$row['state']);
			}
			if (isset($row['date']) && $row['date'] !== null) {
				try {
					$holiday->setDate(new \DateTime((string)$row['date']));
				} catch (\Throwable) {
				}
			}
			if (isset($row['name'])) {
				$holiday->setName((string)$row['name']);
			}
			if (isset($row['kind'])) {
				$holiday->setKind((string)$row['kind']);
			}
			if (isset($row['scope'])) {
				$holiday->setScope((string)$row['scope']);
			}
			if (array_key_exists('source', $row)) {
				$holiday->setSource($row['source'] !== null ? (string)$row['source'] : null);
			}
			if (isset($row['createdAt']) && $row['createdAt'] !== null) {
				try {
					$holiday->setCreatedAt(new \DateTime((string)$row['createdAt']));
				} catch (\Throwable) {
				}
			}
			if (isset($row['updatedAt']) && $row['updatedAt'] !== null) {
				try {
					$holiday->setUpdatedAt(new \DateTime((string)$row['updatedAt']));
				} catch (\Throwable) {
				}
			}
			$result[] = $holiday;
		}

		return $result;
	}

	/**
	 * Build a flat DTO for a Holiday entity for API responses.
	 *
	 * @return array<string,mixed>
	 */
	private function buildHolidayDto(Holiday $holiday): array
	{
		$date = $holiday->getDate();
		$dateStr = null;
		if ($date !== null) {
			$dateStr = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : (string)$date;
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr) !== 1) {
				try {
					$dateStr = (new \DateTime($dateStr))->format('Y-m-d');
				} catch (\Throwable $e) {
					$dateStr = null;
				}
			}
		}

		// Working day weight is derived from kind/scope; we expose it
		// here so callers do not have to duplicate this logic.
		$weight = ($holiday->getKind() === Holiday::KIND_HALF) ? 0.5 : 1.0;
		if ($holiday->getScope() === Holiday::SCOPE_STATUTORY) {
			$weight = 1.0;
		}

		$name = $holiday->getName();
		// Statutory holidays are always run through the translator so that:
		// - generated base-calendar entries with English names become localized
		// - existing rows from older versions are also shown in the current UI language
		if ($holiday->getScope() === Holiday::SCOPE_STATUTORY) {
			$name = $this->l10n->t($name);
		}

		return [
			'id' => $holiday->getId(),
			'state' => $holiday->getState(),
			'date' => $dateStr,
			'name' => $name,
			'kind' => $holiday->getKind(),
			'scope' => $holiday->getScope(),
			'source' => $holiday->getSource(),
			'weight' => $weight,
		];
	}

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
	 * @return array<string,string> date (Y-m-d) => name
	 */
	public static function getGermanPublicHolidaysForYear(int $year): array
	{
		$holidays = [];

		$holidays[$year . '-01-01'] = 'New Year';
		$holidays[$year . '-05-01'] = 'Labour Day';
		$holidays[$year . '-10-03'] = 'Unity Day';
		$holidays[$year . '-10-31'] = 'Reformation Day';
		$holidays[$year . '-11-01'] = 'All Saints';
		$holidays[$year . '-12-25'] = 'Christmas';
		$holidays[$year . '-12-26'] = 'Second Christmas';

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

