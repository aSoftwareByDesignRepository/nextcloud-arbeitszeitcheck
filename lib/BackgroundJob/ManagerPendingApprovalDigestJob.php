<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\ManagerPendingApprovalMailService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Daily digest of open manager approvals (when mode=digest).
 */
class ManagerPendingApprovalDigestJob extends TimedJob
{
	public function __construct(
		ITimeFactory $timeFactory,
		private ManagerPendingApprovalMailService $mailService,
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
				'manager pending digest',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping manager pending digest — upgrade backup/restore in progress.',
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
			$sent = $this->mailService->sendDailyDigests();
			if ($sent > 0) {
				$this->logger->info('arbeitszeitcheck: manager pending digest sent to {n} manager(s)', [
					'app' => 'arbeitszeitcheck',
					'n' => $sent,
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('arbeitszeitcheck: manager pending digest failed: ' . $e->getMessage(), [
				'app' => 'arbeitszeitcheck',
				'exception' => $e,
			]);
		}
	}
}
