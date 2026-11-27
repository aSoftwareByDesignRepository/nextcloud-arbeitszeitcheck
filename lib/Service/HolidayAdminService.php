<?php

declare(strict_types=1);

/**
 * Admin-facing holiday operations: policy, suppressions, cache, verification.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\HolidaySuppressionMapper;
use OCA\ArbeitszeitCheck\Support\HolidayCatalogResolver;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class HolidayAdminService
{
	public function __construct(
		private readonly HolidayMapper $holidayMapper,
		private readonly HolidaySuppressionMapper $suppressionMapper,
		private readonly \OCA\ArbeitszeitCheck\Db\HolidayRuleMapper $holidayRuleMapper,
		private readonly \OCA\ArbeitszeitCheck\Db\HolidayRuleSkipMapper $holidayRuleSkipMapper,
		private readonly HolidayService $holidayCalendarService,
		private readonly IConfig $config,
		private readonly ?ILockingProvider $lockingProvider = null,
	) {
	}

	public function isStatutoryAutoReseedEnabled(): bool
	{
		return $this->config->getAppValue('arbeitszeitcheck', 'statutory_auto_reseed', '1') === '1';
	}

	/**
	 * @return array{success:bool, state?:string, year?:int, error?:string}
	 */
	public function deleteStateHolidayById(int $id, ?string $suppressedBy = null): array
	{
		if ($id <= 0) {
			return ['success' => false, 'error' => 'invalid_id'];
		}

		// Named catalog lock: find→delete→suppress must serialise against the
		// reconcile seeder (HolidayService holds the same key) and concurrent
		// admin deletes — otherwise a seeded row can resurrect between the
		// delete and the suppression write.
		$lock = DbLockKeys::policyCatalog('holiday');
		if ($this->lockingProvider !== null) {
			try {
				DbLockGuard::acquire($this->lockingProvider, $lock, ILockingProvider::LOCK_EXCLUSIVE, 'Holiday catalog mutation');
			} catch (LockedException) {
				return ['success' => false, 'error' => 'locked'];
			}
		}
		try {
			try {
				$existing = $this->holidayMapper->findById($id);
			} catch (DoesNotExistException) {
				return ['success' => true];
			}

			$state = $existing->getState();
			$date = $existing->getDate();
			$year = $date !== null ? (int)$date->format('Y') : null;
			$dateYmd = $date !== null ? $date->format('Y-m-d') : null;
			$scope = $existing->getScope();

			$this->holidayMapper->deleteById($id);

			if ($state !== '' && $year !== null) {
				$this->holidayCalendarService->clearCacheForStateYear($state, $year);
				if ($scope === Holiday::SCOPE_STATUTORY && $dateYmd !== null && !$this->isStatutoryAutoReseedEnabled()) {
					// The per-date suppression alone keeps the day deleted; the legacy
					// "initialized state/years" flag was retired in migration 1034.
					$this->suppressionMapper->addSuppression($state, $dateYmd, $suppressedBy);
				}
			}

			return [
				'success' => true,
				'state' => $state,
				'year' => $year,
			];
		} finally {
			if ($this->lockingProvider !== null) {
				$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}
	}

	/**
	 * When a statutory holiday is manually saved, clear any suppression for that date.
	 */
	public function onStatutoryHolidaySaved(string $state, string $dateYmd): void
	{
		// Same named catalog lock as deleteStateHolidayById — suppression writes
		// serialise against the reconcile seeder.
		$lock = DbLockKeys::policyCatalog('holiday');
		if ($this->lockingProvider === null) {
			$this->suppressionMapper->removeSuppression($state, $dateYmd);
			return;
		}
		DbLockGuard::acquire($this->lockingProvider, $lock, ILockingProvider::LOCK_EXCLUSIVE, 'Holiday catalog mutation');
		try {
			$this->suppressionMapper->removeSuppression($state, $dateYmd);
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Compare active DB holidays, catalog, and suppressions for support/audit.
	 *
	 * @return array<string,mixed>
	 */
	public function verifyStateYear(string $state, int $year): array
	{
		$state = strtoupper(trim($state));
		$catalog = HolidayCatalogResolver::statutoryHolidaysForRegionAndYear($state, $year);
		$suppressed = $this->suppressionMapper->findSuppressedDatesForStateAndYear($state, $year);

		// Read persisted rows only — do not call getHolidaysForRange() here because
		// that reconciles (seed/prune) and would hide stale statutory rows during audit.
		$activeStatutory = [];
		foreach ($this->holidayMapper->findByStateAndYear($state, $year) as $holiday) {
			if ($holiday->getScope() !== Holiday::SCOPE_STATUTORY) {
				continue;
			}
			$date = $holiday->getDate();
			if ($date === null) {
				continue;
			}
			$dateYmd = $date->format('Y-m-d');
			$activeStatutory[$dateYmd] = [
				'name' => $holiday->getName(),
				'source' => $holiday->getSource(),
			];
		}

		$missingInDb = [];
		foreach ($catalog as $dateYmd => $name) {
			if (in_array($dateYmd, $suppressed, true)) {
				continue;
			}
			if (!isset($activeStatutory[$dateYmd])) {
				$missingInDb[$dateYmd] = $name;
			}
		}

		$extraInDb = [];
		foreach ($activeStatutory as $dateYmd => $row) {
			if (!isset($catalog[$dateYmd])) {
				$extraInDb[$dateYmd] = $row['name'] ?? '';
			}
		}

		// B-R10: active rules and this year's skips — the support audit
		// explains rule-sourced days that are absent from at_holidays rows.
		$ruleRows = $this->holidayRuleMapper->findByState($state);
		$ruleIds = array_map(static fn ($r): int => (int)$r->getId(), $ruleRows);
		$skippedByRule = $this->holidayRuleSkipMapper->yearsForRuleIds($ruleIds);
		$rules = [];
		foreach ($ruleRows as $rule) {
			$resolved = $rule->resolveDateForYear($year);
			$rules[] = [
				'id' => (int)$rule->getId(),
				'name' => (string)$rule->getName(),
				'rule_key' => (string)$rule->getRuleKey(),
				'scope' => (string)$rule->getScope(),
				'kind' => (string)$rule->getKind(),
				'active' => (bool)$rule->getActive(),
				'start_year' => (int)$rule->getStartYear(),
				'resolved_date' => $resolved !== null ? $resolved->format('Y-m-d') : null,
				'skipped_years' => $skippedByRule[(int)$rule->getId()] ?? [],
			];
		}

		return [
			'state' => $state,
			'year' => $year,
			'statutoryAutoReseed' => $this->isStatutoryAutoReseedEnabled(),
			'catalogCount' => count($catalog),
			'activeStatutoryCount' => count($activeStatutory),
			'suppressedDates' => $suppressed,
			'missingInDb' => $missingInDb,
			'extraInDb' => $extraInDb,
			'rules' => $rules,
			'ok' => $missingInDb === [] && $extraInDb === [],
		];
	}
}
