<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Db\KioskStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Reaps offline stamp idempotency rows past the replay window.
 *
 * Rows exist so retried/replayed client requests resolve to one server
 * mutation. Once a row is older than any plausible drain lag (queue skew is
 * capped at 72 h), it only grows the table — delete after 7 days.
 */
class StampIdempotencyCleanupJob extends TimedJob
{
	private const RETENTION_SECONDS = 7 * 24 * 3600;

	public function __construct(
		ITimeFactory $timeFactory,
		private readonly MobileStampIdempotencyMapper $mobileMapper,
		private readonly KioskStampIdempotencyMapper $kioskMapper,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $locking,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(24 * 3600);
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
				'stamp idempotency cleanup',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping stamp idempotency cleanup — upgrade backup/restore in progress.',
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
		$cutoff = $this->time->getTime() - self::RETENTION_SECONDS;
		$mobile = $this->mobileMapper->deleteOlderThan($cutoff);
		$kiosk = $this->kioskMapper->deleteOlderThan($cutoff);
		if ($mobile + $kiosk > 0) {
			$this->logger->info('Stamp idempotency cleanup: removed expired rows', [
				'mobile' => $mobile,
				'kiosk' => $kiosk,
			]);
		}
	}
}
