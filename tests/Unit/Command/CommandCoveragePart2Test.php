<?php

declare(strict_types=1);

/**
 * Atlas coverage lane — console command coverage part 2:
 * SetOvertimeTrackingCommand, ImportVacationBalanceCommand,
 * EnsureE2eClockReadyCommand, EnsureE2eVacationEntitlementCommand,
 * UpgradeBackupCommand.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Command;

use OCA\ArbeitszeitCheck\Command\EnsureE2eClockReadyCommand;
use OCA\ArbeitszeitCheck\Command\EnsureE2eVacationEntitlementCommand;
use OCA\ArbeitszeitCheck\Command\ImportVacationBalanceCommand;
use OCA\ArbeitszeitCheck\Command\SetOvertimeTrackingCommand;
use OCA\ArbeitszeitCheck\Command\UpgradeBackupCommand;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignment;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Exception\UpgradeBackupException;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class CommandCoveragePart2Test extends TestCase
{
	private function runCmd(object $command, array $args = [], array $options = []): array
	{
		// Attach to an Application so global options (e.g. --no-interaction)
		// merge into the command definition like under occ.
		$app = new \Symfony\Component\Console\Application();
		$app->add($command);
		$app->setAutoExit(false);
		$resolved = $app->find($command->getName());
		$tester = new CommandTester($resolved);
		$tester->setInputs(['no']); // any interactive confirm defaults to abort
		$code = $tester->execute(array_merge($args, $options));
		return [$code, $tester->getDisplay()];
	}

	// -----------------------------------------------------------
	// SetOvertimeTrackingCommand
	// -----------------------------------------------------------

	private function overtimeCmd(?IUserManager $um = null, ?UserOvertimeSettingsService $svc = null): SetOvertimeTrackingCommand
	{
		return new SetOvertimeTrackingCommand(
			$um ?? $this->createMock(IUserManager::class),
			$svc ?? $this->createMock(UserOvertimeSettingsService::class),
		);
	}

	private function existingUser(string $uid = 'alice'): IUser
	{
		$u = $this->createMock(IUser::class);
		$u->method('getUID')->willReturn($uid);
		$u->method('isEnabled')->willReturn(true);
		return $u;
	}

	public function testOvertimeMetadata(): void
	{
		$cmd = $this->overtimeCmd();
		$this->assertSame('arbeitszeitcheck:set-overtime-tracking', $cmd->getName());
		$this->assertTrue($cmd->getDefinition()->hasOption('file'));
		$this->assertTrue($cmd->getDefinition()->hasOption('dry-run'));
	}

	public function testOvertimeFailsWithoutArgs(): void
	{
		[$code, $display] = $this->runCmd($this->overtimeCmd());
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('user_id', $display);
	}

	public function testOvertimeFailsUnknownUser(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturn(null);
		[$code, $display] = $this->runCmd($this->overtimeCmd($um), ['user_id' => 'ghost', 'tracking_from' => '2026-01-01']);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('Unknown user', $display);
	}

	public function testOvertimeFailsInvalidDate(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturn($this->existingUser());
		[$code] = $this->runCmd($this->overtimeCmd($um), ['user_id' => 'alice', 'tracking_from' => 'totally-not-a-date-xx']);
		$this->assertSame(Command::FAILURE, $code);
	}

	public function testOvertimeDryRunDoesNotPersist(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturn($this->existingUser());
		$svc = $this->createMock(UserOvertimeSettingsService::class);
		$svc->expects($this->never())->method('setTrackingFrom');
		[$code, $display] = $this->runCmd($this->overtimeCmd($um, $svc), ['user_id' => 'alice', 'tracking_from' => '2026-03-01'], ['--dry-run' => true]);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('dry-run', $display);
	}

	public function testOvertimeAppliesDate(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturn($this->existingUser());
		$svc = $this->createMock(UserOvertimeSettingsService::class);
		$svc->expects($this->once())->method('setTrackingFrom')
			->with('alice', $this->callback(static fn ($d) => $d instanceof \DateTimeImmutable && $d->format('Y-m-d') === '2026-03-01'), 'occ:set-overtime-tracking');
		[$code] = $this->runCmd($this->overtimeCmd($um, $svc), ['user_id' => 'alice', 'tracking_from' => '2026-03-01']);
		$this->assertSame(Command::SUCCESS, $code);
	}

	public function testOvertimeClearResetsToNull(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturn($this->existingUser());
		$svc = $this->createMock(UserOvertimeSettingsService::class);
		$svc->expects($this->once())->method('setTrackingFrom')->with('alice', null, $this->anything());
		[$code] = $this->runCmd($this->overtimeCmd($um, $svc), ['user_id' => 'alice', 'tracking_from' => 'clear']);
		$this->assertSame(Command::SUCCESS, $code);
	}

	public function testOvertimeCsvFileImport(): void
	{
		$csv = tempnam(sys_get_temp_dir(), 'azc-ot-') . '.csv';
		file_put_contents($csv, "user_id,tracking_from\nalice,2026-01-15\nghost,2026-02-01\n,2026-01-01\n");

		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturnCallback(fn (string $uid) => $uid === 'alice' ? $this->existingUser($uid) : null);
		$svc = $this->createMock(UserOvertimeSettingsService::class);
		$svc->expects($this->once())->method('setTrackingFrom')->with('alice');

		try {
			[$code] = $this->runCmd($this->overtimeCmd($um, $svc), [], ['--file' => $csv]);
			$this->assertSame(Command::FAILURE, $code); // 'ghost' row failed
		} finally {
			@unlink($csv);
		}
	}

	public function testOvertimeCsvUnreadableFile(): void
	{
		[$code] = $this->runCmd($this->overtimeCmd(), [], ['--file' => '/nonexistent/nope.csv']);
		$this->assertSame(Command::FAILURE, $code);
	}

	public function testOvertimeCsvBadHeader(): void
	{
		$csv = tempnam(sys_get_temp_dir(), 'azc-ot-') . '.csv';
		file_put_contents($csv, "foo,bar\nx,y\n");
		try {
			[$code] = $this->runCmd($this->overtimeCmd(), [], ['--file' => $csv]);
			$this->assertSame(Command::FAILURE, $code);
		} finally {
			@unlink($csv);
		}
	}

	// -----------------------------------------------------------
	// ImportVacationBalanceCommand
	// -----------------------------------------------------------

	private function importCmd(array $opts = []): ImportVacationBalanceCommand
	{
		$um = $opts['userManager'] ?? $this->createMock(IUserManager::class);
		$balancer = $opts['balanceMapper'] ?? $this->createMock(VacationYearBalanceMapper::class);
		$audit = $opts['auditLogMapper'] ?? $this->createMock(AuditLogMapper::class);
		$alloc = $opts['allocationService'] ?? $this->createMock(VacationAllocationService::class);
		if (!isset($opts['allocationService'])) {
			$alloc->method('applyCapToOpeningBalance')->willReturnArgument(0);
		}
		// final classes — real instances over mocked collaborators
		$unitConfig = $opts['unitConfig'] ?? null;
		if ($unitConfig === null) {
			$unitConfig = $this->createMock(IConfig::class);
			$unitConfig->method('getAppValue')->willReturnCallback(static fn ($a, $k, $d = '') => $d);
		}
		$unit = $opts['unitService'] ?? new VacationUnitService($unitConfig);
		$migr = $opts['migrationService'] ?? null;
		if ($migr === null) {
			$migrConfig = $this->createMock(IConfig::class);
			$migrConfig->method('getAppValue')->willReturnCallback(
				static fn ($a, $k, $d = '') => $k === Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING ? ($opts['pendingMigration'] ?? '') : $d
			);
			$migr = new VacationUnitMigrationService(
				$migrConfig,
				$this->createMock(\OCP\IDBConnection::class),
				$unit,
				$this->createMock(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$balancer,
				$audit,
			);
		}
		return new ImportVacationBalanceCommand($um, $balancer, $audit, $alloc, $unit, $migr);
	}

	public function testImportRejectsUnreadableFile(): void
	{
		[$code] = $this->runCmd($this->importCmd(), ['file' => '/nonexistent/x.csv']);
		$this->assertSame(Command::FAILURE, $code);
	}

	public function testImportRejectsBadHeader(): void
	{
		$f = tempnam(sys_get_temp_dir(), 'azc-imp-');
		file_put_contents($f, "a,b,c\n1,2,3\n");
		try {
			[$code] = $this->runCmd($this->importCmd(), ['file' => $f]);
			$this->assertSame(Command::FAILURE, $code);
		} finally {
			@unlink($f);
		}
	}

	public function testImportDryRunValidatesWithoutWrites(): void
	{
		$f = tempnam(sys_get_temp_dir(), 'azc-imp-');
		file_put_contents($f, "user_id,year,carryover_days\nalice,2026,5.5\nghost,2026,2\n,2026,3\nbob,1800,1\n");

		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturnCallback(fn (string $u) => $u === 'alice' ? $this->existingUser($u) : null);
		$balancer = $this->createMock(VacationYearBalanceMapper::class);
		$balancer->expects($this->never())->method('upsert');

		try {
			[$code, $display] = $this->runCmd($this->importCmd(['userManager' => $um, 'balanceMapper' => $balancer]), ['file' => $f], ['--dry-run' => true]);
			$this->assertSame(Command::SUCCESS, $code);
			$this->assertStringContainsString('1 rows would be imported', $display);
		} finally {
			@unlink($f);
		}
	}

	public function testImportWritesRowsAndAudit(): void
	{
		$f = tempnam(sys_get_temp_dir(), 'azc-imp-');
		file_put_contents($f, "user_id,year,carryover_days\nalice,2026,5\n");

		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturn($this->existingUser('alice'));
		$balancer = $this->createMock(VacationYearBalanceMapper::class);
		$balancer->expects($this->once())->method('upsert')
			->with('alice', 2026, 5.0, null, true);
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction')
			->with('alice', 'vacation_balance_import', 'vacation_year_balance', null, null, $this->isType('array'), 'cli');

		try {
			[$code, $display] = $this->runCmd($this->importCmd([
				'userManager' => $um, 'balanceMapper' => $balancer, 'auditLogMapper' => $audit,
			]), ['file' => $f]);
			$this->assertSame(Command::SUCCESS, $code);
			$this->assertStringContainsString('Imported 1', $display);
		} finally {
			@unlink($f);
		}
	}

	public function testImportFailsWhileUnitMigrationInProgress(): void
	{
		$f = tempnam(sys_get_temp_dir(), 'azc-imp-');
		file_put_contents($f, "user_id,year,carryover_days\nalice,2026,5\n");

		$um = $this->createMock(IUserManager::class);
		$um->method('get')->willReturn($this->existingUser('alice'));
		// pending-migration config makes the real service throw VAC_UNIT_MIGRATE_IN_PROGRESS
		try {
			[$code] = $this->runCmd($this->importCmd(['userManager' => $um, 'pendingMigration' => '{"from":"days","to":"hours"}']), ['file' => $f]);
			$this->assertSame(Command::FAILURE, $code);
		} finally {
			@unlink($f);
		}
	}

	// -----------------------------------------------------------
	// EnsureE2eClockReadyCommand
	// -----------------------------------------------------------

	private function clockReadyCmd(array $opts = []): EnsureE2eClockReadyCommand
	{
		$um = $opts['userManager'] ?? $this->createMock(IUserManager::class);
		$mapper = $opts['timeEntryMapper'] ?? $this->createMock(TimeEntryMapper::class);
		$tts = $opts['timeTrackingService'] ?? $this->createMock(TimeTrackingService::class);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($a, $k, $d = '') => $d);
		return new EnsureE2eClockReadyCommand($um, $mapper, $tts, $config);
	}

	public function testE2eClockRefusesNonTestUser(): void
	{
		[$code, $display] = $this->runCmd($this->clockReadyCmd(), ['user_id' => 'real_user'], ['--force' => true]);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('e2e_', $display);
	}

	public function testE2eClockRefusesUnknownUser(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(false);
		[$code] = $this->runCmd($this->clockReadyCmd(['userManager' => $um]), ['user_id' => 'e2e_x'], ['--force' => true]);
		$this->assertSame(Command::FAILURE, $code);
	}

	public function testE2eClockNoCompletedEntryIsReady(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(true);
		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->method('findActiveByUser')->willReturn(null);
		$mapper->method('findOnBreakByUser')->willReturn(null);
		$mapper->method('findLastCompletedByUser')->willReturn(null);
		$mapper->expects($this->never())->method('update');

		[$code, $display] = $this->runCmd($this->clockReadyCmd(['userManager' => $um, 'timeEntryMapper' => $mapper]), ['user_id' => 'e2e_a'], ['--force' => true]);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('ready', $display);
	}

	public function testE2eClockClocksOutActiveSessionAndBackdates(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(true);

		$active = new TimeEntry();
		$active->setUserId('e2e_a');
		$last = new TimeEntry();
		$last->setUserId('e2e_a');
		$last->setEndTime(new \DateTime('-30 minutes')); // inside rest window

		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->method('findActiveByUser')->willReturn($active);
		$mapper->method('findOnBreakByUser')->willReturn(null);
		$mapper->method('findLastCompletedByUser')->willReturn($last);
		$mapper->expects($this->once())->method('update')
			->with($this->callback(static fn (TimeEntry $e) => $e->getEndTime() < new \DateTime('-11 hours')));

		$tts = $this->createMock(TimeTrackingService::class);
		$tts->expects($this->once())->method('clockOut')->with('e2e_a');

		[$code, $display] = $this->runCmd($this->clockReadyCmd([
			'userManager' => $um, 'timeEntryMapper' => $mapper, 'timeTrackingService' => $tts,
		]), ['user_id' => 'e2e_a'], ['--force' => true]);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('Clocked out', $display);
		$this->assertStringContainsString('Backdated', $display);
	}

	public function testE2eClockRestAlreadySatisfied(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(true);
		$last = new TimeEntry();
		$last->setUserId('e2e_a');
		$last->setEndTime(new \DateTime('-2 days'));

		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->method('findActiveByUser')->willReturn(null);
		$mapper->method('findOnBreakByUser')->willReturn(null);
		$mapper->method('findLastCompletedByUser')->willReturn($last);
		$mapper->expects($this->never())->method('update');

		[$code] = $this->runCmd($this->clockReadyCmd(['userManager' => $um, 'timeEntryMapper' => $mapper]), ['user_id' => 'e2e_a'], ['--force' => true]);
		$this->assertSame(Command::SUCCESS, $code);
	}

	// -----------------------------------------------------------
	// EnsureE2eVacationEntitlementCommand
	// -----------------------------------------------------------

	private function e2eVacationCmd(array $opts = []): EnsureE2eVacationEntitlementCommand
	{
		$um = $opts['userManager'] ?? $this->createMock(IUserManager::class);
		$mapper = $opts['assignmentMapper'] ?? $this->createMock(UserVacationPolicyAssignmentMapper::class);
		return new EnsureE2eVacationEntitlementCommand($um, $mapper);
	}

	public function testE2eVacationRefusesUnknownUser(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(false);
		[$code] = $this->runCmd($this->e2eVacationCmd(['userManager' => $um]), ['user_id' => 'ghost'], ['--force' => true]);
		$this->assertSame(Command::FAILURE, $code);
	}

	public function testE2eVacationRejectsInvalidManualDays(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(true);
		[$code] = $this->runCmd($this->e2eVacationCmd(['userManager' => $um]), ['user_id' => 'e2e_a', '--manual-days' => '0'], ['--force' => true]);
		$this->assertSame(Command::FAILURE, $code);
	}

	public function testE2eVacationCreatesPolicyPerYear(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(true);
		$mapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$mapper->method('findCurrentByUser')->willReturn(null);
		$mapper->expects($this->exactly(2))->method('insert')
			->with($this->callback(static fn (UserVacationPolicyAssignment $a) =>
				$a->getVacationMode() === Constants::VACATION_MODE_MANUAL_FIXED && (float)$a->getManualDays() === 30.0
			));

		[$code, $display] = $this->runCmd(
			$this->e2eVacationCmd(['userManager' => $um, 'assignmentMapper' => $mapper]),
			['user_id' => 'e2e_a', '--years' => '2026,2027'],
			['--force' => true]
		);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('2026, 2027', $display);
	}

	public function testE2eVacationSkipsAlreadySufficientPolicy(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(true);

		$existing = new UserVacationPolicyAssignment();
		$existing->setUserId('e2e_a');
		$existing->setVacationMode(Constants::VACATION_MODE_MANUAL_FIXED);
		$existing->setManualDays(30.0);
		$existing->setEffectiveFrom(new \DateTime('2026-01-01'));
		$existing->setInheritLowerLayers(false);

		$mapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$mapper->method('findCurrentByUser')->willReturn($existing);
		$mapper->expects($this->never())->method('insert');
		$mapper->expects($this->never())->method('update');

		[$code, $display] = $this->runCmd(
			$this->e2eVacationCmd(['userManager' => $um, 'assignmentMapper' => $mapper]),
			['user_id' => 'e2e_a', '--years' => '2026'],
			['--force' => true]
		);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('already manual_fixed', $display);
	}

	public function testE2eVacationUpdatesSameDayPolicy(): void
	{
		$um = $this->createMock(IUserManager::class);
		$um->method('userExists')->willReturn(true);

		$existing = new UserVacationPolicyAssignment();
		$existing->setUserId('e2e_a');
		$existing->setVacationMode(Constants::VACATION_MODE_TARIFF_RULE_BASED);
		$existing->setEffectiveFrom(new \DateTime('2026-01-01'));
		$existing->setInheritLowerLayers(true);

		$mapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$mapper->method('findCurrentByUser')->willReturn($existing);
		$mapper->expects($this->once())->method('update')
			->with($this->callback(static fn (UserVacationPolicyAssignment $a) =>
				$a->getVacationMode() === Constants::VACATION_MODE_MANUAL_FIXED && (float)$a->getManualDays() === 25.0
			));

		[$code, $display] = $this->runCmd(
			$this->e2eVacationCmd(['userManager' => $um, 'assignmentMapper' => $mapper]),
			['user_id' => 'e2e_a', '--years' => '2026', '--manual-days' => '25'],
			['--force' => true]
		);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('updated policy', $display);
	}

	// -----------------------------------------------------------
	// UpgradeBackupCommand
	// -----------------------------------------------------------

	private function backupCmd(array $opts = []): UpgradeBackupCommand
	{
		$svc = $opts['service'] ?? $this->createMock(UpgradeBackupService::class);
		return new UpgradeBackupCommand($svc);
	}

	public function testBackupListEmpty(): void
	{
		$svc = $this->createMock(UpgradeBackupService::class);
		$svc->method('listSnapshots')->willReturn([]);
		[$code, $display] = $this->runCmd($this->backupCmd(['service' => $svc]));
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('No snapshots found', $display);
	}

	public function testBackupListRendersTable(): void
	{
		$svc = $this->createMock(UpgradeBackupService::class);
		$svc->method('listSnapshots')->willReturn([
			['id' => 'snap1', 'createdAt' => '2026-09-01T00:00:00Z', 'appVersion' => '1.2.0', 'reason' => 'pre-update', 'tables' => ['a', 'b']],
		]);
		[$code, $display] = $this->runCmd($this->backupCmd(['service' => $svc]));
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('snap1', $display);
		$this->assertStringContainsString('Found 1 snapshot', $display);
	}

	public function testBackupInvalidAction(): void
	{
		[$code, $display] = $this->runCmd($this->backupCmd(), ['action' => 'explode']);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('Unknown action', $display);
	}

	public function testBackupRestoreRequiresForce(): void
	{
		[$code, $display] = $this->runCmd($this->backupCmd(), ['action' => 'restore', '--id' => 'snap1']);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('--force', $display);
	}

	public function testBackupRestoreRequiresSnapshotId(): void
	{
		$svc = $this->createMock(UpgradeBackupService::class);
		$svc->method('getLatestSnapshotId')->willReturn(null);
		[$code, $display] = $this->runCmd($this->backupCmd(['service' => $svc]), ['action' => 'restore', '--force' => true, '--latest' => true]);
		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('Specify which snapshot', $display);
	}

	public function testBackupRestoreSuccess(): void
	{
		$svc = $this->createMock(UpgradeBackupService::class);
		$svc->expects($this->once())->method('restoreSnapshot')->with('20260901T000000Z-abcdef12', true);
		// --no-interaction skips the confirm() prompt
		[$code, $display] = $this->runCmd(
			$this->backupCmd(['service' => $svc]),
			['action' => 'restore', '--id' => '20260901T000000Z-abcdef12', '--force' => true, '--no-interaction' => true]
		);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('Restore completed', $display);
	}

	public function testBackupCreateSuccess(): void
	{
		$svc = $this->createMock(UpgradeBackupService::class);
		$svc->expects($this->once())->method('createSnapshot')->with('before risky change')
			->willReturn(['id' => 'snapX', 'manifest' => ['tables' => ['a']]]);
		[$code, $display] = $this->runCmd(
			$this->backupCmd(['service' => $svc]),
			['action' => 'create', '--reason' => 'before risky change']
		);
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('snapX', $display);
	}

	public function testBackupCreateHandlesBackupException(): void
	{
		$svc = $this->createMock(UpgradeBackupService::class);
		$svc->method('createSnapshot')->willThrowException(new UpgradeBackupException('disk full'));
		[$code] = $this->runCmd($this->backupCmd(['service' => $svc]), ['action' => 'create']);
		$this->assertSame(Command::FAILURE, $code);
	}
}
