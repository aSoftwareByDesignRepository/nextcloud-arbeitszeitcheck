<?php

declare(strict_types=1);

/**
 * Holiday rules and working-day math for the arbeitszeitcheck app.
 *
 * Runtime source of truth: rows in at_holidays (seeded from the country
 * catalog resolved per region, see HolidayCatalogResolver).
 * Working-day math uses only DB-backed holidays — never the catalog directly.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\HolidaySuppressionMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Support\HolidayCatalogResolver;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class HolidayService
{
	public function __construct(
		private readonly HolidayMapper $holidayMapper,
		private readonly HolidaySuppressionMapper $suppressionMapper,
		private readonly UserSettingsMapper $userSettingsMapper,
		private readonly IConfig $config,
		ICacheFactory $cacheFactory,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
		$this->cache = $cacheFactory->createDistributed('arbeitszeitcheck_holidays');
	}

	/** @var \OCP\ICache|null */
	private $cache;

	/**
	 * Request-scoped guard: statutory seed/prune already ran for this state/year
	 * in the current PHP request (avoids N× reconciliation in per-day loops).
	 *
	 * @var array<string,true>
	 */
	private array $reconciledStateYears = [];

	/**
	 * Request-scoped resolved holiday entities per state/year.
	 * Cleared by clearCacheForStateYear() on admin writes.
	 *
	 * @var array<string,Holiday[]>
	 */
	private array $entitiesByStateYear = [];

	private function requestMemoKey(string $state, int $year): string
	{
		return $this->normalizeState($state) . '|' . $year;
	}

	private function getCacheKey(string $state, int $year): string
	{
		// Policy suffix avoids stale lists after toggling auto-restore OFF↔ON.
		$policy = $this->isStatutoryAutoReseedEnabled() ? 'reseed' : 'noreseed';

		return sprintf('holidays:%s:%d:%s', $this->normalizeState($state), $year, $policy);
	}

	public function clearCacheForStateYear(string $state, int $year): void
	{
		$state = $this->normalizeState($state);
		$memoKey = $this->requestMemoKey($state, $year);
		unset($this->entitiesByStateYear[$memoKey], $this->reconciledStateYears[$memoKey]);
		$this->clearDistributedCacheForStateYear($state, $year);
	}

	/**
	 * Invalidate only the distributed cache (not request memos). Used during
	 * statutory reconciliation so mid-request memo state stays consistent.
	 */
	private function clearDistributedCacheForStateYear(string $state, int $year): void
	{
		if ($this->cache === null) {
			return;
		}
		$this->cache->remove(sprintf('holidays:%s:%d:noreseed', $state, $year));
		$this->cache->remove(sprintf('holidays:%s:%d:reseed', $state, $year));
		// Pre-1.3.11 key without policy suffix (upgrade safety).
		$this->cache->remove(sprintf('holidays:%s:%d', $state, $year));
	}

	public function isStatutoryAutoReseedEnabled(): bool
	{
		return $this->config->getAppValue('arbeitszeitcheck', 'statutory_auto_reseed', '1') === '1';
	}

	public function resolveStateForUser(string $userId): string
	{
		$defaultState = $this->getDefaultState();
		$userState = $this->userSettingsMapper->getStringSetting($userId, 'german_state', '');

		$state = $userState !== '' ? $userState : $defaultState;

		if (!RegionRegistry::isValidRegion($state)) {
			$this->logger->warning('HolidayService: invalid region for user, falling back to default', [
				'userId' => $userId,
				'state' => $state,
				'defaultState' => $defaultState,
			]);
			return $defaultState;
		}

		return $state;
	}

	/**
	 * Instance default region. Falls back to the configured country's
	 * default region (DE => NW, AT => AT-W) when unset or invalid.
	 */
	private function getDefaultState(): string
	{
		$country = $this->getConfiguredCountry();
		$stored = $this->config->getAppValue(
			'arbeitszeitcheck',
			'german_state',
			RegionRegistry::defaultRegionForCountry($country)
		);

		return RegionRegistry::resolveDefaultRegionForCountry($country, (string)$stored);
	}

	private function getConfiguredCountry(): string
	{
		$country = strtoupper(trim($this->config->getAppValue('arbeitszeitcheck', 'country', RegionRegistry::COUNTRY_DE)));

		return RegionRegistry::isSupportedCountry($country) ? $country : RegionRegistry::COUNTRY_DE;
	}

	/**
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

		$result = [];

		foreach ($this->getYearsInRange($start, $end) as $year) {
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

		usort($result, static function (array $a, array $b): int {
			return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
		});

		return $result;
	}

	public function computeWorkingDaysForUser(string $userId, \DateTime $start, \DateTime $end): float
	{
		$state = $this->resolveStateForUser($userId);
		$weights = $this->buildHolidayWeightMapForState($state, $start, $end);

		return self::computeWorkingDaysFromWeights($start, $end, $weights);
	}

	/**
	 * @return array<int,float>
	 */
	public function computeWorkingDaysPerYearForUser(string $userId, \DateTime $start, \DateTime $end): array
	{
		$state = $this->resolveStateForUser($userId);
		$weights = $this->buildHolidayWeightMapForState($state, $start, $end);

		return self::computeWorkingDaysPerYearFromWeights($start, $end, $weights);
	}

	public function isHolidayForUser(string $userId, \DateTime $date): bool
	{
		return $this->isHolidayForState($this->resolveStateForUser($userId), $date);
	}

	/**
	 * Holiday weight for Sollzeit: 0.0 none, 0.5 half-day, 1.0 full day.
	 */
	public function getHolidayWeightForUser(string $userId, \DateTime $date): float
	{
		$state = $this->resolveStateForUser($userId);
		$day = (clone $date)->setTime(0, 0, 0);
		$weights = $this->buildHolidayWeightMapForState($state, $day, $day);
		$key = $day->format('Y-m-d');

		return (float)($weights[$key] ?? 0.0);
	}

	public function isHolidayForState(string $state, \DateTime $date): bool
	{
		$state = $this->normalizeState($state);
		$year = (int)$date->format('Y');
		$key = $date->format('Y-m-d');

		foreach ($this->getHolidaysForYearInternal($state, $year) as $holiday) {
			$holidayDate = $holiday->getDate();
			if ($holidayDate && $holidayDate->format('Y-m-d') === $key) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string,float> date (Y-m-d) => weight (1.0 full, 0.5 half)
	 */
	private function buildHolidayWeightMapForState(string $state, \DateTime $start, \DateTime $end): array
	{
		$state = $this->normalizeState($state);

		$start = (clone $start)->setTime(0, 0, 0);
		$end = (clone $end)->setTime(0, 0, 0);
		if ($end < $start) {
			[$start, $end] = [$end, $start];
		}

		$weights = [];

		foreach ($this->getYearsInRange($start, $end) as $year) {
			foreach ($this->getHolidaysForYearInternal($state, $year) as $holiday) {
				$date = $holiday->getDate();
				if ($date === null || $date < $start || $date > $end) {
					continue;
				}
				$dateStr = $date->format('Y-m-d');

				$weight = ($holiday->getKind() === Holiday::KIND_HALF) ? 0.5 : 1.0;

				$current = $weights[$dateStr] ?? 0.0;
				if ($weight > $current) {
					$weights[$dateStr] = $weight;
				}
			}
		}

		return $weights;
	}

	/**
	 * @return Holiday[]
	 */
	private function getHolidaysForYearInternal(string $state, int $year): array
	{
		$state = $this->normalizeState($state);
		$memoKey = $this->requestMemoKey($state, $year);

		if (isset($this->entitiesByStateYear[$memoKey])) {
			return $this->entitiesByStateYear[$memoKey];
		}

		$this->reconcileStatutoryHolidaysForStateYear($state, $year);

		$statutoryAutoReseed = $this->isStatutoryAutoReseedEnabled();
		$cacheKey = $this->getCacheKey($state, $year);
		if (!$statutoryAutoReseed && $this->cache !== null) {
			$cached = $this->cache->get($cacheKey);
			if (is_array($cached)) {
				$entities = $this->hydrateFromArray($cached);
				$this->entitiesByStateYear[$memoKey] = $entities;

				return $entities;
			}
		}

		$entities = $this->holidayMapper->findByStateAndYear($state, $year);

		// Distributed cache only when auto-restore is off (policy-stable reads).
		// With auto-restore on, cross-request restore is handled by reconciling
		// once per request above; request memo avoids N× DB work in per-day loops.
		if (!$statutoryAutoReseed && $this->cache !== null) {
			$this->cache->set($cacheKey, array_map(static function (Holiday $h): array {
				return $h->toArray();
			}, $entities));
		}

		$this->entitiesByStateYear[$memoKey] = $entities;

		return $entities;
	}

	/**
	 * Seed/prune statutory holidays at most once per (state, year) per request.
	 */
	private function reconcileStatutoryHolidaysForStateYear(string $state, int $year): void
	{
		$memoKey = $this->requestMemoKey($state, $year);
		if (isset($this->reconciledStateYears[$memoKey])) {
			return;
		}
		$this->reconciledStateYears[$memoKey] = true;

		$statutoryAutoReseed = $this->isStatutoryAutoReseedEnabled();

		if ($statutoryAutoReseed) {
			// Auto-restore is on: statutory opt-outs are void. Seed ignores
			// suppressions and clears any stale opt-out so that toggling the
			// policy OFF→ON reliably brings previously deleted statutory days
			// back (matches the documented "deleted rows reappear" contract).
			$this->seedStatutoryHolidaysForStateAndYear($state, $year, false);
			$this->clearDistributedCacheForStateYear($state, $year);
		} else {
			// Auto-restore is off: a fully emptied year stays empty because every
			// statutory delete records a per-date suppression (and migration 1034
			// converted the legacy "initialized" flag into suppressions), so the
			// seeder below re-inserts nothing for opted-out dates.
			if (!$this->holidayMapper->hasStatutoryHolidaysForStateAndYear($state, $year)) {
				$this->seedStatutoryHolidaysForStateAndYear($state, $year, true);
				$this->clearDistributedCacheForStateYear($state, $year);
			}
		}
	}

	/**
	 * Seed statutory holidays for a Bundesland/year from the catalog.
	 *
	 * @param bool $honorSuppressions When true (auto-restore OFF), per-date
	 *        suppressions block (re-)seeding. When false (auto-restore ON),
	 *        suppressions are ignored and any matching opt-out is cleared as the
	 *        date is restored, keeping the suppression table consistent.
	 */
	private function seedStatutoryHolidaysForStateAndYear(string $state, int $year, bool $honorSuppressions = true): void
	{
		try {
			$base = HolidayCatalogResolver::statutoryHolidaysForRegionAndYear($state, $year);
			$kinds = HolidayCatalogResolver::statutoryHolidayKindsForRegionAndYear($state, $year);
		} catch (\Throwable $e) {
			$this->logger->error('HolidayService: failed to get statutory catalog', [
				'year' => $year,
				'state' => $state,
				'exception' => $e,
			]);
			return;
		}

		// Self-heal (auto-restore ON only): remove auto-generated statutory rows
		// whose date the Bundesland's catalog no longer contains. This prunes
		// data seeded before the catalog became state-aware (e.g. Reformation
		// Day wrongly seeded for NW) so working-day math cannot count a day the
		// state does not actually observe. Only source=generated statutory rows
		// are touched; manually added entries are never auto-deleted.
		if (!$honorSuppressions) {
			$this->pruneObsoleteGeneratedStatutory($state, $year, $base);
		}

		foreach ($base as $dateStr => $name) {
			$isSuppressed = $this->suppressionMapper->isSuppressed($state, $dateStr);
			if ($isSuppressed && $honorSuppressions) {
				continue;
			}

			$desiredKind = ($kinds[$dateStr] ?? 'full') === Holiday::KIND_HALF
				? Holiday::KIND_HALF
				: Holiday::KIND_FULL;

			$existingId = $this->holidayMapper->findIdForStateDateScope($state, $dateStr, Holiday::SCOPE_STATUTORY);
			if ($existingId !== null) {
				// E-6 self-heal: generated statutory rows must track catalog kind
				// (e.g. Zurich Sechseläuten half-day). Manual statutory edits are
				// left alone.
				try {
					$existing = $this->holidayMapper->findById($existingId);
					if ($existing->getSource() === Holiday::SOURCE_GENERATED
						&& $existing->getKind() !== $desiredKind) {
						$existing->setKind($desiredKind);
						$existing->setName($this->l10n->t($name));
						$existing->setUpdatedAt(new \DateTime());
						$this->holidayMapper->update($existing);
					}
				} catch (\Throwable $e) {
					// best-effort: one stale kind row must not stop reconciling the
					// rest of the statutory catalog for the year.
					$this->logger->warning('HolidayService: failed to reconcile statutory kind', [
						'state' => $state,
						'date' => $dateStr,
						'exception' => $e,
					]);
				}
				continue;
			}

			// Auto-restore is on and this date carried a stale opt-out: drop the
			// suppression as we re-create the statutory row so support tooling
			// (verify) no longer reports a phantom suppression.
			if ($isSuppressed && !$honorSuppressions) {
				$this->suppressionMapper->removeSuppression($state, $dateStr);
			}

			$holiday = new Holiday();
			$holiday->setState($state);
			$holiday->setName($this->l10n->t($name));
			$holiday->setKind($desiredKind);
			$holiday->setScope(Holiday::SCOPE_STATUTORY);
			$holiday->setSource(Holiday::SOURCE_GENERATED);
			$holiday->setCreatedAt(new \DateTime());
			$holiday->setUpdatedAt(new \DateTime());

			try {
				$holiday->setDate(new \DateTime($dateStr));
			} catch (\Throwable $e) {
				$this->logger->warning('HolidayService: invalid catalog date skipped', [
					'date' => $dateStr,
					'state' => $state,
					'exception' => $e,
				]);
				continue;
			}

			if (!$holiday->isValid()) {
				continue;
			}

			try {
				$this->holidayMapper->insert($holiday);
			} catch (\Throwable $e) {
				// best-effort: seeding continues past duplicates and single-row
				// failures; non-duplicate errors are logged for the operator.
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
	 * Delete auto-generated statutory rows for a state/year that are no longer
	 * present in the supplied catalog map. Manual entries and non-statutory
	 * scopes are left untouched.
	 *
	 * @param array<string,string> $catalog date (Y-m-d) => name
	 */
	private function pruneObsoleteGeneratedStatutory(string $state, int $year, array $catalog): void
	{
		foreach ($this->holidayMapper->findByStateAndYear($state, $year) as $existing) {
			if ($existing->getScope() !== Holiday::SCOPE_STATUTORY
				|| $existing->getSource() !== Holiday::SOURCE_GENERATED) {
				continue;
			}
			$date = $existing->getDate();
			$id = $existing->getId();
			if ($date === null || $id === null) {
				continue;
			}
			if (!array_key_exists($date->format('Y-m-d'), $catalog)) {
				$this->holidayMapper->deleteById((int)$id);
			}
		}
	}

	private function normalizeState(string $state): string
	{
		$state = strtoupper(trim($state));
		if (!RegionRegistry::isValidRegion($state)) {
			$state = $this->getDefaultState();
		}
		return $state;
	}

	/**
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
				} catch (\Throwable) {
					$dateStr = null;
				}
			}
		}

		$weight = ($holiday->getKind() === Holiday::KIND_HALF) ? 0.5 : 1.0;

		$name = $holiday->getName();
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
	 * Mon–Fri working days; holidays from weight map only (no implicit national calendar).
	 *
	 * @param array<string,float> $holidayWeights date Y-m-d => weight
	 */
	public static function computeWorkingDaysFromWeights(\DateTime $start, \DateTime $end, array $holidayWeights): float
	{
		$start = (clone $start)->setTime(0, 0, 0);
		$end = (clone $end)->setTime(0, 0, 0);

		$workingDays = 0.0;

		while ($start <= $end) {
			if ((int)$start->format('N') < 6) {
				$dateStr = $start->format('Y-m-d');
				$weight = 1.0;
				if (isset($holidayWeights[$dateStr])) {
					$extra = (float)$holidayWeights[$dateStr];
					if ($extra >= 1.0) {
						$weight = 0.0;
					} elseif ($extra > 0.0) {
						$weight = max(0.0, 1.0 - $extra);
					}
				}
				$workingDays += $weight;
			}
			$start->modify('+1 day');
		}

		return (float)$workingDays;
	}

	/**
	 * @param array<string,float> $holidayWeights
	 * @return array<int,float>
	 */
	public static function computeWorkingDaysPerYearFromWeights(\DateTime $start, \DateTime $end, array $holidayWeights): array
	{
		$start = (clone $start)->setTime(0, 0, 0);
		$end = (clone $end)->setTime(0, 0, 0);

		$result = [];

		while ($start <= $end) {
			if ((int)$start->format('N') < 6) {
				$year = (int)$start->format('Y');
				$dateStr = $start->format('Y-m-d');
				$weight = 1.0;
				if (isset($holidayWeights[$dateStr])) {
					$extra = (float)$holidayWeights[$dateStr];
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
			$start->modify('+1 day');
		}

		foreach (array_keys($result) as $year) {
			$result[$year] = (float)$result[$year];
		}

		return $result;
	}

	/**
	 * @param array<string,float> $extraHolidayWeights
	 */
	public static function computeWorkingDays(\DateTime $start, \DateTime $end, array $extraHolidayWeights = []): float
	{
		return self::computeWorkingDaysFromWeights($start, $end, $extraHolidayWeights);
	}

	/**
	 * @param array<string,float> $extraHolidayWeights
	 * @return array<int,float>
	 */
	public static function computeWorkingDaysPerYear(\DateTime $start, \DateTime $end, array $extraHolidayWeights = []): array
	{
		return self::computeWorkingDaysPerYearFromWeights($start, $end, $extraHolidayWeights);
	}

	/**
	 * Catalog-only check (ignores DB overrides/suppressions).
	 *
	 * @deprecated Use isHolidayForState()/isHolidayForUser() (DB-backed) or
	 *             HolidayCatalogResolver::statutoryHolidaysForRegionAndYear().
	 */
	public static function isGermanPublicHoliday(\DateTime $date, string $state = 'NW'): bool
	{
		$year = (int)$date->format('Y');
		$holidays = HolidayCatalogResolver::statutoryHolidaysForRegionAndYear($state, $year);
		return isset($holidays[$date->format('Y-m-d')]);
	}

	/**
	 * @deprecated Use HolidayCatalogResolver::statutoryHolidaysForRegionAndYear()
	 * @return array<string,string>
	 */
	public static function getGermanPublicHolidaysForYear(int $year, string $state = 'NW'): array
	{
		return HolidayCatalogResolver::statutoryHolidaysForRegionAndYear($state, $year);
	}
}
