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

	public function testAbsenceServiceReceivesWorkingHolidayService(): void
	{
		$holidayService = \OCP\Server::get(HolidayService::class);
		$this->assertInstanceOf(HolidayService::class, $holidayService);

		$absenceService = \OCP\Server::get(AbsenceService::class);
		$this->assertInstanceOf(AbsenceService::class, $absenceService);
	}
}
