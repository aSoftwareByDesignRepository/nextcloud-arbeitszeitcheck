<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Repair\BackupBeforeUpdate;
use OCP\Migration\IOutput;
use Test\TestCase;

final class BackupBeforeUpdateIntegrationTest extends TestCase
{
	public function testPreMigrationRepairStepRunsInContainer(): void
	{
		/** @var BackupBeforeUpdate $step */
		$step = \OCP\Server::get(BackupBeforeUpdate::class);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::atLeastOnce())->method('info');

		$step->run($output);
		$this->addToAssertionCount(1);
	}
}
