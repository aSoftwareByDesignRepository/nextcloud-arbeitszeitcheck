<?php

declare(strict_types=1);

/**
 * Retire the legacy app-config flag 'holidays_initialized_state_years' (B-2).
 *
 * The flag used to mean "statutory holidays for this state/year were seeded
 * once — do not seed again", which made a fully emptied year stay empty even
 * without per-date suppressions (rows deleted before the suppression table
 * existed, pre-1.3.x). Since 1029 every statutory delete records a per-date
 * suppression, so the flag is redundant — except for those pre-suppression
 * deletions. This migration converts exactly that remainder:
 *
 * For every flagged state/year, each catalog date that has neither an active
 * statutory row nor a suppression gets a suppression row (suppressed_by =
 * 'migration:1034'), preserving the "deleted days stay deleted" contract.
 * Afterwards the config key is removed. Data-only, no schema change.
 *
 * Idempotent: re-running finds the key absent (or the suppressions present)
 * and does nothing.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCA\ArbeitszeitCheck\Support\HolidayCatalogResolver;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1034Date20260724120000 extends SimpleMigrationStep
{
	private const LEGACY_KEY = 'holidays_initialized_state_years';

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$json = $this->config->getAppValue('arbeitszeitcheck', self::LEGACY_KEY, '');
		if ($json === '') {
			return; // fresh install or already migrated
		}

		$entries = json_decode($json, true);
		if (is_array($entries)
			&& $this->db->tableExists('at_holidays')
			&& $this->db->tableExists('at_holiday_suppress')) {
			$converted = 0;
			foreach ($entries as $entry) {
				if (!is_string($entry)) {
					continue;
				}
				// Format: '<STATE>-<YYYY>', e.g. 'NW-2026'. State codes may
				// contain dashes themselves (future-proof), so split on the
				// final dash.
				if (preg_match('/^(.+)-(\d{4})$/', $entry, $m) !== 1) {
					continue;
				}
				$state = strtoupper(trim($m[1]));
				$year = (int)$m[2];
				if (!RegionRegistry::isValidRegion($state) || $year < 1970 || $year > 2100) {
					continue;
				}
				$converted += $this->convertStateYear($state, $year);
			}
			if ($converted > 0) {
				$output->info(sprintf(
					'arbeitszeitcheck: converted %d legacy statutory opt-out(s) to holiday suppressions.',
					$converted
				));
			}
		}

		$this->config->deleteAppValue('arbeitszeitcheck', self::LEGACY_KEY);
	}

	/**
	 * @return int number of suppressions created
	 */
	private function convertStateYear(string $state, int $year): int
	{
		try {
			$catalog = HolidayCatalogResolver::statutoryHolidaysForRegionAndYear($state, $year);
		} catch (\Throwable) {
			return 0;
		}
		if ($catalog === []) {
			return 0;
		}

		$activeStatutory = $this->fetchDates('at_holidays', $state, $year, true);
		$suppressed = $this->fetchDates('at_holiday_suppress', $state, $year, false);

		$created = 0;
		foreach (array_keys($catalog) as $dateYmd) {
			if (isset($activeStatutory[$dateYmd]) || isset($suppressed[$dateYmd])) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->insert('at_holiday_suppress')
				->values([
					'state' => $qb->createNamedParameter($state),
					'date' => $qb->createNamedParameter($dateYmd),
					'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
					'suppressed_by' => $qb->createNamedParameter('migration:1034'),
				]);
			try {
				$qb->executeStatement();
				$created++;
			} catch (\Throwable $e) {
				// non-fatal: unique (state, date) race with a parallel request —
				// the suppression already exists, which is the desired end state.
				\OCP\Log\logger('arbeitszeitcheck')->debug(
					'Holiday suppression insert skipped for ' . $state . ' ' . $dateYmd . ': ' . $e->getMessage()
				);
			}
		}

		return $created;
	}

	/**
	 * @return array<string,true> Y-m-d dates present for state/year
	 */
	private function fetchDates(string $table, string $state, int $year, bool $statutoryOnly): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('date')
			->from($table)
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere($qb->expr()->gte('date', $qb->createNamedParameter(sprintf('%04d-01-01', $year))))
			->andWhere($qb->expr()->lte('date', $qb->createNamedParameter(sprintf('%04d-12-31 23:59:59', $year))));
		if ($statutoryOnly) {
			$qb->andWhere($qb->expr()->eq('scope', $qb->createNamedParameter('statutory')));
		}

		$dates = [];
		$cursor = $qb->executeQuery();
		while (($row = $cursor->fetch()) !== false) {
			$dates[substr((string)$row['date'], 0, 10)] = true;
		}
		$cursor->closeCursor();

		return $dates;
	}
}
