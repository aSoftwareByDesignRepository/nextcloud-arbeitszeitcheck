<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (C) 2026 Software by Design GmbH
 *
 * Annual year-end reset authority (§9.5/§15.9). Cron-side owner of the
 * automatic run: when the policy window is due (Jan 1 03:00 policy TZ,
 * catch-up through Jan 7), it materializes the run + items and keeps the
 * 20s tick alive until every item is terminal.
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Db\YearResetRunMapper;
use OCA\ArbeitszeitCheck\Db\YearResetRun;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCA\ArbeitszeitCheck\Service\YearEndResetService;
use OCA\ArbeitszeitCheck\Support\DbLockGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class YearEndAnnualJob extends TimedJob
{
	public function __construct(
		ITimeFactory $timeFactory,
		private readonly YearEndResetService $yearEndService,
		private readonly YearResetRunMapper $runMapper,
		private readonly ILockingProvider $locking,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(15 * 60);
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	protected function run($argument): void
	{
		try {
			DbLockGuard::acquire(
				$this->locking,
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'year-end annual reset run',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping year-end job — upgrade backup/restore in progress.',
				['app' => 'arbeitszeitcheck'],
			);
			return;
		}
		try {
			$this->yearEndService->annualTick();
			// Keep manual runs ticking too: a request-bounded execute may have
			// run out of its 20s budget with items still pending.
			foreach ($this->runMapper->findExecuting() as $run) {
				$this->yearEndService->runTick($run->getId());
			}
			// §16.9: expired never-consumed previews hold financial snapshots —
			// purge them with their refs on the same cadence.
			$this->yearEndService->purgeExpiredPreviews();
		} catch (\Throwable $e) {
			$this->logger->error('ArbeitszeitCheck: year-end job failed', [
				'app' => 'arbeitszeitcheck',
				'exception' => $e,
			]);
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}
