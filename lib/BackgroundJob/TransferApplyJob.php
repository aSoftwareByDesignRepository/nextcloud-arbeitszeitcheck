<?php

declare(strict_types=1);

/**
 * Queued apply job (spec FS-5): the apply ALWAYS runs here — the web request
 * only queues it; the browser may close while the job completes and writes
 * its persisted receipt. `occ config:import --apply` calls the identical
 * TransferService::applyPending code path synchronously.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\ConfigTransfer\TransferService;
use OCA\ArbeitszeitCheck\Service\DbLockGuard;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class TransferApplyJob extends QueuedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly TransferService $transferService,
		private readonly ILockingProvider $locking,
		private readonly IJobList $jobList,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	/**
	 * @param array{pendingId:string,actor:string,replaceConfirmed:bool} $argument
	 */
	protected function run($argument): void
	{
		$args = is_array($argument) ? $argument : [];
		// Shared lock on the upgrade-backup key: a restore holds it
		// exclusively (UpgradeBackupService::runExclusive) — a transfer apply
		// wiping/loading the same tables must never interleave with a
		// mid-restore truncate+reinsert. start() already removed this job
		// from the list, so a blocked run MUST re-queue itself — a silent
		// return would strand the pending apply forever (NN-15) and a throw
		// would not be retried either.
		try {
			DbLockGuard::acquire($this->locking, 
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'config transfer apply',
			);
		} catch (LockedException) {
			$this->jobList->add(self::class, $args);
			$this->logger->info(
				'arbeitszeitcheck: config transfer apply deferred — upgrade backup/restore in progress; job re-queued.',
				['app' => 'arbeitszeitcheck'],
			);
			return;
		}
		try {
			$this->transferService->runApplyJob(
				(string)($args['pendingId'] ?? ''),
				(string)($args['actor'] ?? ''),
				(bool)($args['replaceConfirmed'] ?? false),
				true, // this job holds the shared backup lock for the whole apply
			);
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}
}
