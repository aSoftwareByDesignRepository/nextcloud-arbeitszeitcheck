<?php

declare(strict_types=1);

/**
 * Integration checks for the DACH Phase 0 holiday migrations against the real
 * database of this instance:
 *
 *  - B-1 ({@see Version1035Date20260724130000}): the unique index on
 *    (state, date, scope) must actively reject duplicate rows, and a re-run
 *    of the dedupe pass on clean data must be a no-op.
 *  - B-2 ({@see Version1034Date20260724120000}): converting the legacy
 *    'holidays_initialized_state_years' flag must create per-date
 *    suppressions exactly once (idempotent re-run) and remove the key.
 *
 * All test data uses a far-future year (2097) and is removed in finally
 * blocks, so the dev instance stays clean even when an assertion fails.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Migration\Version1034Date20260724120000;
use OCA\ArbeitszeitCheck\Migration\Version1035Date20260724130000;
use OCA\ArbeitszeitCheck\Support\GermanStatutoryHolidayCatalog;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use Test\TestCase;

class HolidayMigrationsIntegrationTest extends TestCase
{
	private const LEGACY_KEY = 'holidays_initialized_state_years';
	private const TEST_YEAR = 2097;
	private const TEST_STATE = 'NW';

	private IDBConnection $db;
	private IConfig $config;

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = \OCP\Server::get(IDBConnection::class);
		$this->config = \OCP\Server::get(IConfig::class);
	}

	private function insertHoliday(string $state, string $date, string $scope): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('at_holidays')
			->values([
				'state' => $qb->createNamedParameter($state),
				'date' => $qb->createNamedParameter($date),
				'name' => $qb->createNamedParameter('Integration test row'),
				'kind' => $qb->createNamedParameter('company'),
				'scope' => $qb->createNamedParameter($scope),
				'source' => $qb->createNamedParameter('test'),
				'created_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
				'updated_at' => $qb->createNamedParameter(date('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();
	}

	private function deleteTestYearRows(): void
	{
		foreach (['at_holidays', 'at_holiday_suppress'] as $table) {
			$del = $this->db->getQueryBuilder();
			$del->delete($table)
				->where($del->expr()->gte('date', $del->createNamedParameter(self::TEST_YEAR . '-01-01')))
				->andWhere($del->expr()->lte('date', $del->createNamedParameter(self::TEST_YEAR . '-12-31 23:59:59')));
			$del->executeStatement();
		}
	}

	private function countSuppressions(string $state, int $year, ?string $suppressedBy = null): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from('at_holiday_suppress')
			->where($qb->expr()->eq('state', $qb->createNamedParameter($state)))
			->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($year . '-01-01')))
			->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($year . '-12-31 23:59:59')));
		if ($suppressedBy !== null) {
			$qb->andWhere($qb->expr()->eq('suppressed_by', $qb->createNamedParameter($suppressedBy)));
		}
		$cursor = $qb->executeQuery();
		$count = (int)$cursor->fetchOne();
		$cursor->closeCursor();

		return $count;
	}

	/**
	 * B-1: the unique index must make the duplicate-seeding race impossible.
	 */
	public function testUniqueIndexRejectsDuplicateStateDateScope(): void
	{
		$date = self::TEST_YEAR . '-12-30';
		try {
			$this->insertHoliday(self::TEST_STATE, $date, 'company');

			$violated = false;
			try {
				$this->insertHoliday(self::TEST_STATE, $date, 'company');
			} catch (\OCP\DB\Exception $e) {
				$violated = true;
				$this->assertSame(
					\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION,
					$e->getReason(),
					'Duplicate insert must fail with a unique-constraint violation'
				);
			}
			$this->assertTrue($violated, 'The unique (state, date, scope) index must reject the duplicate row');

			// A different scope for the same state/date is still allowed.
			$this->insertHoliday(self::TEST_STATE, $date, 'statutory');
		} finally {
			$this->deleteTestYearRows();
		}
	}

	/**
	 * B-1: re-running the dedupe pass on clean (index-protected) data deletes
	 * nothing and emits no output.
	 */
	public function testDedupePassIsIdempotentOnCleanData(): void
	{
		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		$migration = new Version1035Date20260724130000($this->db);
		$migration->preSchemaChange(
			$output,
			fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class),
			[]
		);
		$this->addToAssertionCount(1);
	}

	/**
	 * B-2: a flagged state/year without statutory rows gets one suppression per
	 * catalog date; a re-run creates nothing new; the legacy key is removed.
	 */
	public function testLegacyFlagConversionIsIdempotent(): void
	{
		$catalogCount = count(
			GermanStatutoryHolidayCatalog::getStatutoryHolidaysForStateAndYear(self::TEST_STATE, self::TEST_YEAR)
		);
		$this->assertGreaterThan(0, $catalogCount);

		$entry = self::TEST_STATE . '-' . self::TEST_YEAR;
		try {
			$this->assertSame(0, $this->countSuppressions(self::TEST_STATE, self::TEST_YEAR), 'Precondition: no leftover test suppressions');

			$this->config->setAppValue('arbeitszeitcheck', self::LEGACY_KEY, json_encode([$entry]));

			$migration = new Version1034Date20260724120000($this->db, $this->config);
			$run = fn () => $migration->postSchemaChange(
				$this->createMock(IOutput::class),
				fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class),
				[]
			);

			$run();
			$this->assertSame(
				$catalogCount,
				$this->countSuppressions(self::TEST_STATE, self::TEST_YEAR, 'migration:1034'),
				'Every catalog date must be suppressed after the first run'
			);
			$this->assertSame(
				'',
				$this->config->getAppValue('arbeitszeitcheck', self::LEGACY_KEY, ''),
				'Legacy key must be removed'
			);

			// Idempotency: flag re-appears (e.g. restored backup) — no new rows.
			$this->config->setAppValue('arbeitszeitcheck', self::LEGACY_KEY, json_encode([$entry]));
			$run();
			$this->assertSame(
				$catalogCount,
				$this->countSuppressions(self::TEST_STATE, self::TEST_YEAR),
				'Second run must not create additional suppressions'
			);
		} finally {
			$this->deleteTestYearRows();
			$this->config->deleteAppValue('arbeitszeitcheck', self::LEGACY_KEY);
		}
	}
}
