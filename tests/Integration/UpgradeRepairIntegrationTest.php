<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Repair\BackfillAbsenceDays;
use OCA\ArbeitszeitCheck\Repair\CleanDanglingTariffRuleSetRefs;
use OCA\ArbeitszeitCheck\Repair\EnsureArbeitszeitCheckSchema;
use OCA\ArbeitszeitCheck\Repair\InitializeWorkforceSuiteFreshState;
use OCA\ArbeitszeitCheck\Repair\InitializeWorkforceSuiteUpgradeState;
use OCA\ArbeitszeitCheck\Repair\ReleaseStuckPendingAbsences;
use OCA\ArbeitszeitCheck\Repair\RepairOrphanedPausedEntries;
use OCA\ArbeitszeitCheck\Repair\SeedWorkLocationCatalog;
use OCA\ArbeitszeitCheck\Repair\UninstallDropTables;
use OCA\ArbeitszeitCheck\Repair\BackupBeforeUpdate;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * Mirrors production app upgrade: post-migration repair steps must resolve from the
 * server container (same path as OC_App::executeRepairSteps during occ upgrade).
 */
class UpgradeRepairIntegrationTest extends TestCase
{
	public function testPostMigrationRepairStepsResolveFromContainer(): void
	{
		foreach ([
			EnsureArbeitszeitCheckSchema::class,
			InitializeWorkforceSuiteFreshState::class,
			InitializeWorkforceSuiteUpgradeState::class,
			SeedWorkLocationCatalog::class,
			BackfillAbsenceDays::class,
			ReleaseStuckPendingAbsences::class,
			RepairOrphanedPausedEntries::class,
			CleanDanglingTariffRuleSetRefs::class,
			UninstallDropTables::class,
			BackupBeforeUpdate::class,
		] as $class) {
			$step = \OCP\Server::get($class);
			$this->assertInstanceOf($class, $step);
		}
	}

	public function testEnsureArbeitszeitCheckSchemaRunsWithoutFatal(): void
	{
		/** @var EnsureArbeitszeitCheckSchema $step */
		$step = \OCP\Server::get(EnsureArbeitszeitCheckSchema::class);
		$output = $this->createMock(IOutput::class);
		$output->method('info');

		$step->run($output);
		$this->addToAssertionCount(1);
	}

	/**
	 * Exercises the real repair path: a catalog table is dropped and its
	 * migration record rolled back, so the step must detect it, re-run the
	 * pending migration, and finish with a complete schema.
	 */
	public function testEnsureSchemaRecreatesDroppedTableViaPendingMigration(): void
	{
		$db = \OC::$server->get(\OCP\IDBConnection::class);
		$table = 'at_mob_stamp_idem';
		$version = '1045Date20260916100000';

		self::assertTrue($db->tableExists($table), 'fixture requires ' . $table . ' to exist');

		$db->dropTable($table);
		$qb = $db->getQueryBuilder();
		$qb->delete('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter('arbeitszeitcheck')))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($version)));
		$deleted = $qb->executeStatement();
		self::assertSame(1, $deleted, 'fixture must roll back migration ' . $version);
		// Later column-level migrations must roll back too: recreate via 1045
		// alone leaves the table without columns added by still-recorded
		// migrations (e.g. 1050 payload_hash) — degrading the shared dev DB.
		$qb2 = $db->getQueryBuilder();
		$qb2->delete('migrations')
			->where($qb2->expr()->eq('app', $qb2->createNamedParameter('arbeitszeitcheck')))
			->andWhere($qb2->expr()->eq('version', $qb2->createNamedParameter('1050Date20261020090000')));
		$qb2->executeStatement();
		self::assertFalse($db->tableExists($table));

		try {
			/** @var EnsureArbeitszeitCheckSchema $step */
			$step = \OC::$server->get(EnsureArbeitszeitCheckSchema::class);
			$messages = [];
			$output = $this->createMock(IOutput::class);
			$output->method('info')->willReturnCallback(static function (string $m) use (&$messages): void {
				$messages[] = $m;
			});

			$step->run($output);

			self::assertTrue(
				$db->tableExists($table),
				'repair step must recreate the dropped table via migrate(latest)'
			);
			self::assertNotEmpty(
				array_filter($messages, static fn (string $m): bool => str_contains($m, 'missing')),
				'repair step must report the missing table before migrating'
			);
			// The recreate must restore columns from later migrations too —
			// a table recreated at 1045 shape without 1050's payload_hash
			// silently breaks every replay consumer afterwards.
			$schema = $db->createSchema();
			$schemaTable = null;
			foreach ($schema->getTableNames() as $name) {
				if (str_ends_with($name, $table)) {
					$schemaTable = $schema->getTable($name);
					break;
				}
			}
			self::assertNotNull($schemaTable, 'recreated table must appear in schema');
			self::assertTrue(
				$schemaTable->hasColumn('payload_hash'),
				'recreated table must include later-migration columns'
			);
		} finally {
			if (!$db->tableExists($table)) {
				$migrationService = new \OC\DB\MigrationService('arbeitszeitcheck', $db);
				$migrationService->migrate('latest', false);
			}
			self::assertTrue($db->tableExists($table), 'table must be restored even on failure');
		}
	}

	/**
	 * GH #45 regression: the step that fataled the 1.9.4 web update must run
	 * cleanly through the real container and database. Idempotent by design —
	 * seeds only missing codes and skips users that already have a policy.
	 */
	public function testSeedWorkLocationCatalogRunsWithoutFatal(): void
	{
		/** @var SeedWorkLocationCatalog $step */
		$step = \OCP\Server::get(SeedWorkLocationCatalog::class);
		$output = $this->createMock(IOutput::class);
		$output->method('info');

		$step->run($output);
		$this->addToAssertionCount(1);
	}

	public function testBackfillAbsenceDaysRunsWithoutFatal(): void
	{
		/** @var BackfillAbsenceDays $step */
		$step = \OCP\Server::get(BackfillAbsenceDays::class);
		$output = $this->createMock(IOutput::class);
		$output->method('info');
		$output->method('startProgress');
		$output->method('advance');
		$output->method('finishProgress');

		$step->run($output);
		$this->addToAssertionCount(1);
	}

	public function testCleanDanglingTariffRuleSetRefsRunsWithoutFatal(): void
	{
		/** @var CleanDanglingTariffRuleSetRefs $step */
		$step = \OCP\Server::get(CleanDanglingTariffRuleSetRefs::class);
		$output = $this->createMock(IOutput::class);
		$output->method('info');

		$step->run($output);
		$this->addToAssertionCount(1);
	}

	public function testAbsenceServiceReceivesWorkingHolidayService(): void
	{
		$holidayService = \OCP\Server::get(HolidayService::class);
		$this->assertInstanceOf(HolidayService::class, $holidayService);

		$absenceService = \OCP\Server::get(AbsenceService::class);
		$this->assertInstanceOf(AbsenceService::class, $absenceService);
	}
}
