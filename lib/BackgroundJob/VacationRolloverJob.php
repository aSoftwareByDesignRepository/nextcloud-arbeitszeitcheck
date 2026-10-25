<?php

declare(strict_types=1);

/**
 * Daily job: apply vacation carryover rollover when enabled (see VacationRolloverService).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCA\ArbeitszeitCheck\Service\VacationRolloverService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class VacationRolloverJob extends TimedJob
{
	public function __construct(
		ITimeFactory $timeFactory,
		private VacationRolloverService $vacationRolloverService,
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
				'vacation rollover',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping vacation rollover — upgrade backup/restore in progress.',
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
			$stats = $this->vacationRolloverService->runForAllUsers(null, false, false, false);
			$this->logger->info('Vacation rollover job finished', [
				'app' => 'arbeitszeitcheck',
				'applied' => $stats['applied'],
				'skipped' => $stats['skipped'],
				'errors' => $stats['errors'],
			]);
		} catch (\Throwable $e) {
			$this->logger->error('Vacation rollover job failed', [
				'app' => 'arbeitszeitcheck',
				'exception' => $e,
			]);
		}
	}
}
