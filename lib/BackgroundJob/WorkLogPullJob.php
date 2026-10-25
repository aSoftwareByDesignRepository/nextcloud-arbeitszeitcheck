<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Service\ExternalStampService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\App\IAppManager;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Suite flange F19b — pull consumer for CareCheck `check:work-log`.
 *
 * CareCheck exposes allow-listed visit segments (actor ref, timestamps,
 * duration, deterministic source ref — no client data). Each segment
 * becomes a pending draft stamp the worker confirms or dismisses on the
 * dashboard. Nothing is auto-counted (BetrVG boundary). Fail-closed:
 * carecheck absent/disabled/upgrading → the job is a no-op. Idempotent:
 * `source_ref` dedupe makes re-pulls and overlapping windows free.
 */
class WorkLogPullJob extends TimedJob
{
	/** Pull a short rolling window — rows are deduped by source_ref. */
	private const LOOKBACK_DAYS = 2;

	public function __construct(
		ITimeFactory $timeFactory,
		private readonly IAppManager $appManager,
		private readonly ExternalStampService $externalStamps,
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
				'work-log pull',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping work-log pull — upgrade backup/restore in progress.',
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
		if (!$this->appManager->isEnabledForUser('carecheck')
			|| !class_exists(\OCA\CareCheck\Service\WorkLogFacade::class)
			|| \OCA\CareCheck\Service\WorkLogFacade::FACADE_VERSION < 1) {
			return;
		}
		try {
			$facade = \OCP\Server::get(\OCA\CareCheck\Service\WorkLogFacade::class);
			$to = (new \DateTimeImmutable())->format('Y-m-d');
			$from = (new \DateTimeImmutable())->modify('-' . self::LOOKBACK_DAYS . ' days')->format('Y-m-d');
			$segments = $facade->segments($from, $to);
			foreach ($segments as $seg) {
				// Only uid: actor refs resolve to a Nextcloud account; member:/
				// station: refs are device/kiosk actors with no AZC user.
				$actor = (string)($seg['actor'] ?? '');
				if (str_starts_with($actor, 'uid:')) {
					$uid = substr($actor, 4);
				} elseif (preg_match('/^[A-Za-z0-9_.@-]{1,64}$/', $actor) === 1 && !str_contains($actor, ':')) {
					$uid = $actor; // plain uid for forward compatibility
				} else {
					continue;
				}
				$start = strtotime((string)($seg['start_ts'] ?? ''));
				$end = strtotime((string)($seg['end_ts'] ?? ''));
				$ref = preg_replace('/[^A-Za-z0-9:_-]/', '', (string)($seg['source_ref'] ?? ''));
				if ($start === false || $end === false || $ref === '') {
					continue; // implausible producer row → skip, never fabricate
				}
				$this->externalStamps->propose($uid, $start, $end, 'carecheck', 'cck:' . $ref);
			}
		} catch (\Throwable $e) {
			// Fail soft: a broken producer app must never break the job queue.
			$this->logger->info('arbeitszeitcheck work-log pull failed', ['exception' => $e->getMessage()]);
		}
	}
}
