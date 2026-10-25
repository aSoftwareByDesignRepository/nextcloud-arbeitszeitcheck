<?php

declare(strict_types=1);

/**
 * Daily job: auto-finalize calendar months after grace period (see MonthClosureService).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class MonthClosureAutoFinalizeJob extends TimedJob
{
	public function __construct(
		ITimeFactory $timeFactory,
		private MonthClosureService $monthClosureService,
		private LoggerInterface $logger,
		private ILockingProvider $locking,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		// Shared lock on the upgrade-backup key: a restore holds it
		// exclusively (UpgradeBackupService::runExclusive) — app-table
		// scans/writes must not interleave with a mid-restore
		// truncate+reinsert. A skip is not a failure: TimedJobs re-run
		// on their interval.
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'month closure auto-finalize',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping month closure auto-finalize — upgrade backup/restore in progress.',
				['app' => 'arbeitszeitcheck'],
			);
			return;
		}
		try {
			$this->doRun($argument);
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}

	private function doRun($argument): void
	{
		try {
			$today = $this->time->getDateTime();
			$stats = $this->monthClosureService->runAutomaticFinalizeForAllUsers($today);
			$this->logger->info('Month closure auto-finalize job finished', [
				'app' => 'arbeitszeitcheck',
				'finalized' => $stats['finalized'],
				'pending_correction' => $stats['pending_correction'],
				'errors' => $stats['errors'],
			]);
		} catch (\Throwable $e) {
			$this->logger->error('Month closure auto-finalize job failed', [
				'app' => 'arbeitszeitcheck',
				'exception' => $e,
			]);
		}
	}
}
