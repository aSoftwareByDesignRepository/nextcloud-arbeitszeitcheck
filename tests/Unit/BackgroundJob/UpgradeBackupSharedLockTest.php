<?php

declare(strict_types=1);

/**
 * Contract proof for the upgrade-backup coordination contract:
 * UpgradeBackupService::restore() holds LOCK_KEY exclusively — every
 * BackgroundJob that scans/writes app tables must take the SHARED lock,
 * skip on LockedException without doing work, and always release.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\BackfillAuditCaptureSourceJob;
use OCA\ArbeitszeitCheck\BackgroundJob\BreakReminderJob;
use OCA\ArbeitszeitCheck\BackgroundJob\ClockOutReminderJob;
use OCA\ArbeitszeitCheck\BackgroundJob\DailyComplianceCheckJob;
use OCA\ArbeitszeitCheck\BackgroundJob\KioskMaintenanceJob;
use OCA\ArbeitszeitCheck\BackgroundJob\LicenseMaintenanceJob;
use OCA\ArbeitszeitCheck\BackgroundJob\ManagerPendingApprovalDigestJob;
use OCA\ArbeitszeitCheck\BackgroundJob\MissingTimeEntryJob;
use OCA\ArbeitszeitCheck\BackgroundJob\MonthClosureAutoFinalizeJob;
use OCA\ArbeitszeitCheck\BackgroundJob\OvertimeTrafficLightJob;
use OCA\ArbeitszeitCheck\BackgroundJob\PaidBreakRecomputeJob;
use OCA\ArbeitszeitCheck\BackgroundJob\StampIdempotencyCleanupJob;
use OCA\ArbeitszeitCheck\BackgroundJob\VacationRolloverJob;
use OCA\ArbeitszeitCheck\BackgroundJob\WorkLogPullJob;
use OCA\ArbeitszeitCheck\Db\KioskStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UpgradeBackupSharedLockTest extends TestCase
{
	/** Every app BackgroundJob — the whole family is wired into the contract. */
	private const JOB_CLASSES = [
		BackfillAuditCaptureSourceJob::class,
		BreakReminderJob::class,
		ClockOutReminderJob::class,
		DailyComplianceCheckJob::class,
		KioskMaintenanceJob::class,
		LicenseMaintenanceJob::class,
		ManagerPendingApprovalDigestJob::class,
		MissingTimeEntryJob::class,
		MonthClosureAutoFinalizeJob::class,
		OvertimeTrafficLightJob::class,
		PaidBreakRecomputeJob::class,
		StampIdempotencyCleanupJob::class,
		VacationRolloverJob::class,
		WorkLogPullJob::class,
	];

	/**
	 * Static contract: every job class (a) declares an ILockingProvider
	 * constructor dependency and (b) its source acquires the shared lock on
	 * UpgradeBackupService::LOCK_KEY and releases it in a finally.
	 */
	public function testEveryJobIsWiredIntoSharedLockContract(): void
	{
		foreach (self::JOB_CLASSES as $class) {
			$ref = new \ReflectionClass($class);
			$ctor = $ref->getConstructor();
			$this->assertNotNull($ctor, $class);
			$hasLockDep = false;
			foreach ($ctor->getParameters() as $p) {
				$t = $p->getType();
				if ($t instanceof \ReflectionNamedType && $t->getName() === ILockingProvider::class) {
					$hasLockDep = true;
				}
			}
			$this->assertTrue($hasLockDep, "$class constructor lacks an ILockingProvider dependency");

			$file = $ref->getFileName();
			$this->assertNotFalse($file, $class);
			$src = (string)file_get_contents($file);
			$this->assertStringContainsString('UpgradeBackupService::LOCK_KEY', $src, $class);
			$this->assertStringContainsString('ILockingProvider::LOCK_SHARED', $src, $class);
			$this->assertStringContainsString('releaseLock(UpgradeBackupService::LOCK_KEY', $src, $class);
			$this->assertStringContainsString('} finally {', $src, $class);
		}
		// Sanity: the contract really does cover the whole BackgroundJob dir —
		// no silently missed class when a fifteenth job lands.
		$dir = dirname((string)(new \ReflectionClass(self::JOB_CLASSES[0]))->getFileName());
		$onDisk = [];
		foreach (glob($dir . '/*Job.php') ?: [] as $f) {
			$onDisk[] = 'OCA\\ArbeitszeitCheck\\BackgroundJob\\' . basename($f, '.php');
		}
		sort($onDisk);
		$declared = self::JOB_CLASSES;
		sort($declared);
		$this->assertSame($onDisk, $declared, 'JOB_CLASSES must enumerate every *Job.php in lib/BackgroundJob');
	}

	private function invokeRun(object $job, $argument = null): void
	{
		$m = new \ReflectionMethod($job, 'run');
		$m->setAccessible(true);
		$m->invoke($job, $argument);
	}

	private function lockedProvider(): ILockingProvider
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED)
			->willThrowException(new LockedException('restore in progress'));
		return $locking;
	}

	public function testLockedOutJobSkipsWithoutTouchingAppTables(): void
	{
		$mobile = $this->createMock(MobileStampIdempotencyMapper::class);
		$mobile->expects($this->never())->method('deleteOlderThan');
		$kiosk = $this->createMock(KioskStampIdempotencyMapper::class);
		$kiosk->expects($this->never())->method('deleteOlderThan');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with($this->stringContains('skipping'), $this->arrayHasKey('app'));

		$time = $this->createMock(ITimeFactory::class);
		// Time must never be consulted — the skip happens before any work.
		$time->expects($this->never())->method('getTime');

		$job = new StampIdempotencyCleanupJob(
			$time,
			$mobile,
			$kiosk,
			$logger,
			$this->lockedProvider(),
		);
		$this->invokeRun($job);
	}

	public function testLockedOutQueuedJobDefersWithoutDoingWork(): void
	{
		// PaidBreakRecomputeJob is a QueuedJob — a skip would lose the queued
		// work (the oc_jobs row is already gone) and the enqueue marker would
		// swallow a fresh enqueue, so the job re-queues itself instead.
		$jobList = $this->createMock(\OCP\BackgroundJob\IJobList::class);
		$argument = [PaidBreakRecomputeJob::ARG_USER_ID => 'u1'];
		$jobList->expects($this->once())->method('add')
			->with(PaidBreakRecomputeJob::class, $argument);

		$timeEntries = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class);
		$timeEntries->expects($this->never())->method('findChunkByUserAfterId');
		$tracking = $this->createMock(\OCA\ArbeitszeitCheck\Service\TimeTrackingService::class);
		$tracking->expects($this->never())->method('recomputePaidBreaksForUserSpan');
		$audit = $this->createMock(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class);
		$audit->expects($this->never())->method('logAction');

		$job = new PaidBreakRecomputeJob(
			$this->createMock(ITimeFactory::class),
			$timeEntries,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			$tracking,
			$audit,
			$jobList,
			$this->createMock(LoggerInterface::class),
			$this->lockedProvider(),
		);
		$this->invokeRun($job, $argument);
	}

	public function testSharedLockIsReleasedWhenWorkThrows(): void
	{
		// acquire+release must bracket the work even when it blows up —
		// a leaked shared lock would starve the exclusive restore.
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED, $this->isType('string'));
		$locking->expects($this->once())->method('releaseLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);

		$service = $this->createMock(MonthClosureService::class);
		$service->method('runAutomaticFinalizeForAllUsers')
			->willThrowException(new \RuntimeException('db gone'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')
			->with($this->stringContains('Month closure auto-finalize job failed'));

		$job = new MonthClosureAutoFinalizeJob(
			$this->createMock(ITimeFactory::class),
			$service,
			$logger,
			$locking,
		);
		$this->invokeRun($job);
	}

	public function testHappyPathAcquiresAndReleasesSharedLock(): void
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED, $this->isType('string'));
		$locking->expects($this->once())->method('releaseLock')
			->with(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);

		$service = $this->createMock(MonthClosureService::class);
		$service->method('runAutomaticFinalizeForAllUsers')
			->willReturn(['finalized' => 0, 'pending_correction' => 0, 'errors' => 0]);

		$job = new MonthClosureAutoFinalizeJob(
			$this->createMock(ITimeFactory::class),
			$service,
			$this->createMock(LoggerInterface::class),
			$locking,
		);
		$this->invokeRun($job);
	}
}
