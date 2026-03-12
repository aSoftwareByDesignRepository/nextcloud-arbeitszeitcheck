<?php

declare(strict_types=1);

/**
 * HolidayCalendarService for the arbeitszeitcheck app
 *
 * Central, state-aware holiday calculation service.
 *
 * Responsibilities:
 * - Resolve the effective German state (Bundesland) for a given user.
 * - Provide per-state holiday lists for date ranges.
 * - Delegate working-day calculations to HolidayService, enriched with
 *   state-specific statutory holidays and company/custom holidays from DB.
 *
 * This service is intentionally free of controller logic and only uses
 * injected collaborators and pure PHP logic so it can be unit-tested
 * in isolation.
 *
 * NOTE:
 * - The existing HolidayService provides a Germany-wide base calendar.
 *   This service layers state-specific calendars and persisted holidays
 *   (at_holidays) on top of that.
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
use Psr\Log\LoggerInterface;

class HolidayCalendarService
{
	/** @var HolidayMapper */
	private $holidayMapper;

	/** @var UserSettingsMapper */
	private $userSettingsMapper;

	/** @var IConfig */
	private $config;

	/** @var LoggerInterface */
	private $logger;

	/** @var \OCP\ICache|null */
	private $cache;

	/** @var string[] */
	private const VALID_STATES = [
		'BW', 'BY', 'BE', 'BB', 'HB', 'HH', 'HE', 'MV',
		'NI', 'NW', 'RP', 'SL', 'SN', 'ST', 'SH', 'TH',
	];

	public function __construct(
		HolidayMapper $holidayMapper,
		UserSettingsMapper $userSettingsMapper,
		IConfig $config,
		ICacheFactory $cacheFactory,
		LoggerInterface $logger
	) {
		$this->holidayMapper = $holidayMapper;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->config = $config;
		$this->logger = $logger;
		// Use a local app-specific cache namespace
		$this->cache = $cacheFactory->createDistributed('arbeitszeitcheck_holidays');
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
			$this->logger->warning('HolidayCalendarService: invalid state for user, falling back to default', [
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
				if ($date < $start || $date > $end) {
					continue;
				}
				$result[] = $this->buildHolidayDto($holiday);
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

		return HolidayService::computeWorkingDays($start, $end, $extraWeights);
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

		return HolidayService::computeWorkingDaysPerYear($start, $end, $extraWeights);
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
	 * The weight semantics are aligned with HolidayService:
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
	 * holidays from HolidayService if necessary.
	 *
	 * @return Holiday[]
	 */
	private function getHolidaysForYearInternal(string $state, int $year): array
	{
		$state = $this->normalizeState($state);

		$cacheKey = sprintf('holidays:%s:%d', $state, $year);
		if ($this->cache !== null) {
			$cached = $this->cache->get($cacheKey);
			if (is_array($cached)) {
				return $this->hydrateFromArray($cached);
			}
		}

		// If no holidays for this state/year exist yet, seed statutory ones
		if (!$this->holidayMapper->hasHolidaysForStateAndYear($state, $year)) {
			$this->seedStatutoryHolidaysForStateAndYear($state, $year);
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
	 * Seed statutory holidays for a state/year based on the base
	 * German calendar from HolidayService.
	 */
	private function seedStatutoryHolidaysForStateAndYear(string $state, int $year): void
	{
		try {
			$base = HolidayService::getGermanPublicHolidaysForYear($year);
		} catch (\Throwable $e) {
			$this->logger->error('HolidayCalendarService: failed to get base holidays', [
				'year' => $year,
				'exception' => $e,
			]);
			return;
		}

		foreach ($base as $dateStr => $name) {
			$holiday = new Holiday();
			$holiday->setState($state);
			$holiday->setName($name);
			$holiday->setKind(Holiday::KIND_FULL);
			$holiday->setScope(Holiday::SCOPE_STATUTORY);
			$holiday->setSource(Holiday::SOURCE_GENERATED);
			$holiday->setCreatedAt(new \DateTime());
			$holiday->setUpdatedAt(new \DateTime());

			try {
				$holiday->setDate(new \DateTime($dateStr));
			} catch (\Throwable $e) {
				$this->logger->warning('HolidayCalendarService: invalid base holiday date skipped', [
					'date' => $dateStr,
					'state' => $state,
					'exception' => $e,
				]);
				continue;
			}

			if (!$holiday->isValid()) {
				$this->logger->warning('HolidayCalendarService: generated holiday failed validation, skipped', [
					'state' => $state,
					'year' => $year,
					'date' => $dateStr,
					'name' => $name,
				]);
				continue;
			}

			try {
				$this->holidayMapper->insert($holiday);
			} catch (\Throwable $e) {
				$this->logger->error('HolidayCalendarService: failed to insert generated holiday', [
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

		// Working day weight is derived from kind/scope; we expose it
		// here so callers do not have to duplicate this logic.
		$weight = ($holiday->getKind() === Holiday::KIND_HALF) ? 0.5 : 1.0;
		if ($holiday->getScope() === Holiday::SCOPE_STATUTORY) {
			$weight = 1.0;
		}

		return [
			'id' => $holiday->getId(),
			'state' => $holiday->getState(),
			'date' => $date ? $date->format('Y-m-d') : null,
			'name' => $holiday->getName(),
			'kind' => $holiday->getKind(),
			'scope' => $holiday->getScope(),
			'source' => $holiday->getSource(),
			'weight' => $weight,
		];
	}
}

