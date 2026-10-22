<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — console command coverage: option parsing, validation
 * gates, service delegation, and exit codes for VacationRolloverCommand and
 * VerifyHolidaysCommand.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Command;

use OCA\ArbeitszeitCheck\Command\VacationRolloverCommand;
use OCA\ArbeitszeitCheck\Command\VerifyHolidaysCommand;
use OCA\ArbeitszeitCheck\Service\HolidayAdminService;
use OCA\ArbeitszeitCheck\Service\VacationRolloverService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CommandCoverageTest extends TestCase
{
	private function runCmd(object $command, array $args = [], array $options = []): array
	{
		$tester = new CommandTester($command);
		$code = $tester->execute(array_merge($args, $options));
		return [$code, $tester->getDisplay()];
	}

	// ---------------------------------------------------------------
	// VacationRolloverCommand
	// ---------------------------------------------------------------

	private function rolloverCmd(array $stats = ['applied' => 1, 'skipped' => 2, 'errors' => 0]): VacationRolloverCommand
	{
		$svc = $this->createMock(VacationRolloverService::class);
		$svc->method('runForAllUsers')->willReturn($stats);
		$svc->method('runForSingleUser')->willReturn($stats);
		return new VacationRolloverCommand($svc);
	}

	public function testRolloverCommandMetadata(): void
	{
		$cmd = $this->rolloverCmd();
		$this->assertSame('arbeitszeitcheck:vacation-rollover', $cmd->getName());
		$this->assertNotEmpty($cmd->getDescription());
		foreach (['dry-run', 'force', 'ignore-disabled', 'year', 'user'] as $opt) {
			$this->assertTrue($cmd->getDefinition()->hasOption($opt), "missing option $opt");
		}
	}

	public function testRolloverRejectsInvalidYear(): void
	{
		[$code, $display] = $this->runCmd($this->rolloverCmd(), [], ['--year' => '1800']);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('Invalid --year', $display);
	}

	public function testRolloverAllUsers(): void
	{
		$svc = $this->createMock(VacationRolloverService::class);
		$svc->expects($this->once())->method('runForAllUsers')
			->with(2025, true, true, true)
			->willReturn(['applied' => 4, 'skipped' => 1, 'errors' => 0]);
		$cmd = new VacationRolloverCommand($svc);

		[$code, $display] = $this->runCmd($cmd, [], [
			'--dry-run' => true, '--force' => true, '--ignore-disabled' => true, '--year' => '2025',
		]);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('applied/would_apply=4', $display);
	}

	public function testRolloverSingleUser(): void
	{
		$svc = $this->createMock(VacationRolloverService::class);
		$svc->expects($this->once())->method('runForSingleUser')
			->with('alice', null, false, false, false)
			->willReturn(['applied' => 1, 'skipped' => 0, 'errors' => 0]);
		$cmd = new VacationRolloverCommand($svc);

		[$code] = $this->runCmd($cmd, [], ['--user' => 'alice']);
		$this->assertSame(Command::SUCCESS, $code);
	}

	// ---------------------------------------------------------------
	// VerifyHolidaysCommand
	// ---------------------------------------------------------------

	private function verifyCmd(array $report): VerifyHolidaysCommand
	{
		$svc = $this->createMock(HolidayAdminService::class);
		$svc->method('verifyStateYear')->willReturn($report);
		return new VerifyHolidaysCommand($svc);
	}

	public function testVerifyCommandMetadata(): void
	{
		$cmd = $this->verifyCmd([]);
		$this->assertSame('arbeitszeitcheck:holidays:verify', $cmd->getName());
		$this->assertTrue($cmd->getDefinition()->hasArgument('state'));
		$this->assertTrue($cmd->getDefinition()->hasArgument('year'));
		$this->assertTrue($cmd->getDefinition()->hasOption('json'));
	}

	public function testVerifyRejectsUnknownRegion(): void
	{
		$svc = $this->createMock(HolidayAdminService::class);
		$svc->expects($this->never())->method('verifyStateYear');
		[$code, $display] = $this->runCmd(new VerifyHolidaysCommand($svc), ['state' => 'XX', 'year' => '2026']);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('Unknown region code', $display);
	}

	public function testVerifyRejectsOutOfRangeYear(): void
	{
		$svc = $this->createMock(HolidayAdminService::class);
		$svc->expects($this->never())->method('verifyStateYear');
		[$code] = $this->runCmd(new VerifyHolidaysCommand($svc), ['state' => 'NW', 'year' => '1950']);
		$this->assertSame(Command::FAILURE, $code);
	}

	public function testVerifyJsonSuccess(): void
	{
		$cmd = $this->verifyCmd(['ok' => true, 'catalogCount' => 11, 'activeStatutoryCount' => 11, 'suppressedDates' => [], 'statutoryAutoReseed' => true]);
		[$code, $display] = $this->runCmd($cmd, ['state' => 'NW', 'year' => '2026'], ['--json' => true]);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('"ok": true', $display);
	}

	public function testVerifyJsonFailureOnGaps(): void
	{
		$cmd = $this->verifyCmd(['ok' => false, 'missingInDb' => ['2026-12-25' => 'Christmas']]);
		[$code] = $this->runCmd($cmd, ['state' => 'NW', 'year' => '2026'], ['--json' => true]);
		$this->assertSame(Command::FAILURE, $code);
	}

	public function testVerifyHumanOutputWithGaps(): void
	{
		$cmd = $this->verifyCmd([
			'ok' => false,
			'catalogCount' => 11,
			'activeStatutoryCount' => 10,
			'suppressedDates' => ['2026-05-01'],
			'missingInDb' => ['2026-12-25' => 'Christmas Day'],
			'extraInDb' => ['2026-06-17' => 'Legacy Row'],
			'statutoryAutoReseed' => false,
		]);
		[$code, $display] = $this->runCmd($cmd, ['state' => 'BB', 'year' => '2026']);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('2026-05-01', $display);   // suppressed listing
		$this->assertStringContainsString('2026-12-25', $display);  // missing listing
		$this->assertStringContainsString('Legacy Row', $display);  // extra listing
		$this->assertStringContainsString('auto-restore', $display);
	}
}
