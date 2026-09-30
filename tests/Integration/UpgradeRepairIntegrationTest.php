<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Repair\BackfillAbsenceDays;
use OCA\ArbeitszeitCheck\Repair\EnsureArbeitszeitCheckSchema;
use OCA\ArbeitszeitCheck\Repair\ReleaseStuckPendingAbsences;
use OCA\ArbeitszeitCheck\Repair\RepairOrphanedPausedEntries;
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
			BackfillAbsenceDays::class,
			ReleaseStuckPendingAbsences::class,
			RepairOrphanedPausedEntries::class,
			UninstallDropTables::class,
			BackupBeforeUpdate::class,
		] as $class) {
			$step = \OC::$server->get($class);
			$this->assertInstanceOf($class, $step);
		}
	}

	public function testEnsureArbeitszeitCheckSchemaRunsWithoutFatal(): void
	{
		/** @var EnsureArbeitszeitCheckSchema $step */
		$step = \OC::$server->get(EnsureArbeitszeitCheckSchema::class);
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
		} finally {
			if (!$db->tableExists($table)) {
				$migrationService = new \OC\DB\MigrationService('arbeitszeitcheck', $db);
				$migrationService->migrate('latest', false);
			}
			self::assertTrue($db->tableExists($table), 'table must be restored even on failure');
		}
	}

	public function testBackfillAbsenceDaysRunsWithoutFatal(): void
	{
		/** @var BackfillAbsenceDays $step */
		$step = \OC::$server->get(BackfillAbsenceDays::class);
		$output = $this->createMock(IOutput::class);
		$output->method('info');
		$output->method('startProgress');
		$output->method('advance');
		$output->method('finishProgress');

		$step->run($output);
		$this->addToAssertionCount(1);
	}

	public function testAbsenceServiceReceivesWorkingHolidayService(): void
	{
		$holidayService = \OC::$server->get(HolidayService::class);
		$this->assertInstanceOf(HolidayService::class, $holidayService);

		$absenceService = \OC::$server->get(AbsenceService::class);
		$this->assertInstanceOf(AbsenceService::class, $absenceService);
	}
}
