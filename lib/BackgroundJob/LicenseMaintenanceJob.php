<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Service\LicenseEnforcementService;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Daily enforcement when AZC2 licenses expire or limits shrink overnight.
 */
class LicenseMaintenanceJob extends TimedJob
{
	public function __construct(
		ITimeFactory $timeFactory,
		private readonly LicenseService $licenseService,
		private readonly LicenseEnforcementService $licenseEnforcementService,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $locking,
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
				'license maintenance',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping license maintenance — upgrade backup/restore in progress.',
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
		if (!$this->licenseService->hasStoredLicense()) {
			return;
		}

		$enforced = $this->licenseEnforcementService->enforceCurrentLimits();
		if ($this->licenseService->isStoredLicenseExpired()) {
			$this->logger->info('AZC2 license maintenance: expired license trimmed', $enforced);
			return;
		}

		if (($enforced['mobileSeatsRemoved'] ?? 0) > 0 || ($enforced['terminalsRevoked'] ?? 0) > 0) {
			$this->logger->info('AZC2 license maintenance: limits enforced', $enforced);
		}
	}
}
