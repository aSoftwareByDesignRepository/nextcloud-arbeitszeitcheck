<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Db\KioskEnrollmentMapper;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskTerminalService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Expires stale kiosk pairing codes, clears expired incomplete enrollments,
 * and keeps terminal tables tidy.
 */
class KioskMaintenanceJob extends TimedJob
{
	public function __construct(
		ITimeFactory $timeFactory,
		private readonly KioskTerminalService $kioskTerminalService,
		private readonly KioskEnrollmentMapper $enrollmentMapper,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $locking,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(15 * 60);
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
				'kiosk maintenance',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping kiosk maintenance — upgrade backup/restore in progress.',
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
		$expired = $this->kioskTerminalService->expireStalePendingTerminals();
		if ($expired > 0) {
			$this->logger->info('Kiosk maintenance: expired pending terminals', ['count' => $expired]);
		}

		$enrollments = $this->enrollmentMapper->deleteExpiredIncomplete($this->time->getDateTime());
		if ($enrollments > 0) {
			$this->logger->info('Kiosk maintenance: deleted expired incomplete enrollments', [
				'count' => $enrollments,
			]);
		}
	}
}
