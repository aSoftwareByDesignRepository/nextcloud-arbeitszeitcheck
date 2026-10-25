<?php

declare(strict_types=1);

/**
 * Queued, chunked recompute of paid_break_seconds snapshots (US-PB-06).
 *
 * Trigger points:
 *  - {@see enqueueForModel()} — a working-time model's `breaks_count_as_work`
 *    or `paid_break_cap_minutes` changed; entries inside every assignment
 *    window of that model (current AND historical rows) are recomputed.
 *  - {@see enqueueForUser()} — a user's model assignment changed; their full
 *    history is rescanned so days that newly resolve to/from a flagged
 *    model converge (MH-07 / EC-08).
 *
 * Properties required by the spec:
 *  - Chunked (200 entries per run) with a stable primary-key cursor — the
 *    job reschedules itself with the cursor until the worklist is drained.
 *  - Dedupe: enqueueing is a no-op when a job for the same target is
 *    already queued; redundant runs converge anyway (EC-14).
 *  - Serialized: every entry write happens inside the per-user mutation
 *    lock via {@see TimeTrackingService::recomputePaidBreaksForUserSpan()}.
 *  - Finalized months are never written; skipped months are collected and
 *    named in the single `paid_break_recompute` audit event written when
 *    the cursor reaches the end.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class PaidBreakRecomputeJob extends QueuedJob
{
	public const ARG_MODEL_ID = 'model_id';
	public const ARG_USER_ID = 'user_id';
	public const ARG_ASSIGNMENT_IDX = 'assignment_idx';
	public const ARG_AFTER_ENTRY_ID = 'after_entry_id';
	public const ARG_STATS = 'stats';

	public const CHUNK = 200;
	private const MAX_SKIPPED_NAMES = 50;

	public function __construct(
		ITimeFactory $timeFactory,
		private TimeEntryMapper $timeEntryMapper,
		private UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private TimeTrackingService $timeTrackingService,
		private AuditLogMapper $auditLogMapper,
		private IJobList $jobList,
		private LoggerInterface $logger,
		private ILockingProvider $locking,
		private ?ICacheFactory $cacheFactory = null,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * Queue a model-wide recompute, deduplicated on the logical target.
	 * Returns true when a job was actually enqueued.
	 */
	public static function enqueueForModel(IJobList $jobList, int $workingTimeModelId, ?ICacheFactory $cacheFactory = null): bool
	{
		if ($workingTimeModelId <= 0) {
			return false;
		}
		return self::enqueue($jobList, [self::ARG_MODEL_ID => $workingTimeModelId], $cacheFactory);
	}

	/**
	 * Queue a full-history recompute for one user, deduplicated on the user.
	 */
	public static function enqueueForUser(IJobList $jobList, string $userId, ?ICacheFactory $cacheFactory = null): bool
	{
		if ($userId === '') {
			return false;
		}
		return self::enqueue($jobList, [self::ARG_USER_ID => $userId], $cacheFactory);
	}

	/**
	 * Dedupe window for the enqueue marker — long enough to cover the
	 * check→add race; the isQueued() scan still dedupes jobs that sit in the
	 * queue longer than the marker lives. The marker is released inside
	 * run() at job start — it must never outlive the job it guards, or a
	 * post-completion enqueue is silently dropped while callers report
	 * success.
	 */
	private const ENQUEUE_MARKER_TTL = 600;

	private const ENQUEUE_CACHE_PREFIX = 'azc_pbr_enqueue';

	/**
	 * @param array<string, mixed> $target logical target keys only
	 */
	private static function enqueue(IJobList $jobList, array $target, ?ICacheFactory $cacheFactory = null): bool
	{
		if (self::isQueued($jobList, $target)) {
			return false;
		}
		// Atomic marker closes the check→add TOCTOU: IMemcache::add() only
		// succeeds for the first writer across processes. The job effect is
		// idempotent regardless — this just avoids a duplicate queue row.
		$markerCache = self::enqueueMarkerCache($cacheFactory);
		$markerKey = self::enqueueMarkerKey($target);
		$markerSet = false;
		try {
			if ($markerCache !== null && !$markerCache->add($markerKey, 1, self::ENQUEUE_MARKER_TTL)) {
				return false;
			}
			$markerSet = $markerCache !== null;
		} catch (\Throwable) {
			// Backend hiccup mid-add → degrade to the isQueued scan; the
			// job is idempotent so a duplicate enqueue is benign.
		}
		try {
			$jobList->add(self::class, $target);
		} catch (\Throwable $e) {
			// The marker we just set must not outlive a failed queue write —
			// an orphan would swallow the next enqueue for the TTL remainder
			// with no job existing. (Only our own marker is removed: add()
			// returned true, so no other process holds this key.)
			if ($markerSet && $markerCache !== null) {
				try {
					$markerCache->remove($markerKey);
				} catch (\Throwable) {
					// best-effort cleanup
				}
			}
			throw $e;
		}
		return true;
	}

	/**
	 * Locking-cache backend for the enqueue marker; null when unavailable
	 * (unit context, no locking backend configured → createLocking() yields
	 * a NullCache whose add() always succeeds, and the isQueued() scan
	 * remains as the dedupe — a duplicate run stays idempotent-benign).
	 */
	private static function enqueueMarkerCache(?ICacheFactory $cacheFactory): ?IMemcache
	{
		try {
			$cacheFactory ??= \OCP\Server::get(ICacheFactory::class);
			return $cacheFactory->createLocking(self::ENQUEUE_CACHE_PREFIX);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * Marker key derived from the logical target only — cursor/continuation
	 * arguments never change which marker guards the target.
	 *
	 * @param array<string, mixed> $target
	 */
	private static function enqueueMarkerKey(array $target): string
	{
		return 'm_' . md5(json_encode($target) ?: '');
	}

	/**
	 * Whether a queued (not yet executed) job already targets this model/user.
	 * Cursor arguments differ between continuations — only the logical
	 * target keys are compared.
	 *
	 * @param array<string, mixed> $target
	 */
	private static function isQueued(IJobList $jobList, array $target): bool
	{
		$offset = 0;
		do {
			$count = 0;
			// Iterate lazily: buildJob() resolves job classes through the DI
			// container, which returns a shared instance mutated per row —
			// getJobs() materializes the whole page first, so every element
			// would expose only the LAST row's argument. Reading the argument
			// inside the foreach sees each row's state before the next
			// hydration overwrites it.
			foreach ($jobList->getJobsIterator(self::class, 50, $offset) as $job) {
				$count++;
				$arg = $job->getArgument();
				if (!is_array($arg)) {
					continue;
				}
				$match = true;
				foreach ($target as $k => $v) {
					if (($arg[$k] ?? null) !== $v) {
						$match = false;
						break;
					}
				}
				if ($match) {
					return true;
				}
			}
			$offset += $count;
		} while ($count === 50);
		return false;
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
				'paid-break recompute',
			);
		} catch (LockedException) {
			// A skipped QueuedJob run leaves no oc_jobs row behind, and
			// the enqueue marker would swallow a fresh enqueue for its
			// TTL remainder — re-add so the deferred recompute is not
			// silently dropped. The marker stays held, so concurrent
			// enqueues still dedupe onto it.
			if (is_array($argument)) {
				$this->jobList->add(self::class, $argument);
			}
			$this->logger->warning(
				'ArbeitszeitCheck: deferring paid-break recompute — upgrade backup/restore in progress.',
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
		if (!is_array($argument)) {
			return;
		}
		$modelId = (int)($argument[self::ARG_MODEL_ID] ?? 0);
		$userId = $argument[self::ARG_USER_ID] ?? null;

		// The oc_jobs row is already gone (QueuedJob::start() removes it
		// before run()), so the enqueue marker must be released now —
		// otherwise every legitimate re-enqueue for this target is
		// silently swallowed for the marker TTL remainder while callers
		// still report success (dedupe_marker_lost_enqueue). Worklist and
		// policy are re-read at execution, so a post-start edit covered by
		// a fresh enqueue converges correctly.
		$this->clearEnqueueMarker($modelId, is_string($userId) ? $userId : null);

		// Worklist: (user, windowStart, windowEnd) triples. Model mode scans
		// every assignment row ever written for the model — including
		// end-dated ones, so retro-active corrections converge as well.
		$worklist = [];
		if (is_string($userId) && $userId !== '') {
			$worklist[] = ['user' => $userId, 'from' => null, 'to' => null];
		} elseif ($modelId > 0) {
			foreach ($this->userWorkingTimeModelMapper->findByWorkingTimeModel($modelId, false) as $assignment) {
				$start = $assignment->getStartDate();
				$end = $assignment->getEndDate();
				$worklist[] = [
					'user' => $assignment->getUserId(),
					'from' => $start,
					'to' => $end !== null ? (clone $end)->modify('+1 day') : null,
				];
			}
		}
		if ($worklist === []) {
			$this->writeAudit($argument, $this->freshStats());
			return;
		}

		$assignmentIdx = (int)($argument[self::ARG_ASSIGNMENT_IDX] ?? 0);
		$afterId = (int)($argument[self::ARG_AFTER_ENTRY_ID] ?? 0);
		$stats = is_array($argument[self::ARG_STATS] ?? null)
			? $argument[self::ARG_STATS]
			: $this->freshStats();
		// Continuation arguments carry sets as JSON lists — normalize back so
		// `count()` and merging stay correct.
		$stats['users'] = array_fill_keys(array_values((array)($stats['users'] ?? [])), true);
		$stats['skipped'] = array_fill_keys(array_values((array)($stats['skipped'] ?? [])), true);

		$processed = 0;
		$done = true;
		while ($assignmentIdx < count($worklist)) {
			$item = $worklist[$assignmentIdx];
			$remaining = self::CHUNK - $processed;
			if ($remaining <= 0) {
				$done = false;
				break;
			}

			$entries = $this->timeEntryMapper->findChunkByUserAfterId(
				$item['user'],
				$item['from'],
				$item['to'],
				$afterId,
				$remaining,
			);
			if ($entries === []) {
				$assignmentIdx++;
				$afterId = 0;
				continue;
			}

			$spanStart = null;
			$spanEnd = null;
			foreach ($entries as $entry) {
				$s = $entry->getStartTime();
				$e = $entry->getEndTime() ?? $entry->getUpdatedAt() ?? $s;
				if ($s !== null) {
					$spanStart = $spanStart === null || $s < $spanStart ? $s : $spanStart;
				}
				if ($e !== null) {
					$spanEnd = $spanEnd === null || $e > $spanEnd ? $e : $spanEnd;
				}
				$afterId = (int)$entry->getId();
				$processed++;
			}
			$stats['entries'] += count($entries);
			$stats['users'][$item['user']] = true;

			// One locked recompute over the union span — the allocator resolves
			// the as-of model per day, so window edges handle themselves.
			if ($spanStart !== null && $spanEnd !== null && $spanEnd > $spanStart) {
				$r = $this->timeTrackingService->recomputePaidBreaksForUserSpan(
					$item['user'],
					$spanStart,
					$spanEnd
				);
				$stats['changed'] += (int)($r['changed'] ?? 0);
				$stats['days'] += (int)($r['days_recomputed'] ?? 0);
				foreach ((array)($r['skipped_finalized'] ?? []) as $monthKey) {
					$stats['skipped'][$item['user'] . '@' . $monthKey] = true;
				}
			}

			if (count($entries) === $remaining) {
				// Possibly more rows in this window — keep scanning next run
				// only when the chunk budget is exhausted.
				$done = false;
				break;
			}
			$assignmentIdx++;
			$afterId = 0;
		}

		if (!$done && $assignmentIdx < count($worklist)) {
			$argument[self::ARG_ASSIGNMENT_IDX] = $assignmentIdx;
			$argument[self::ARG_AFTER_ENTRY_ID] = $afterId;
			$argument[self::ARG_STATS] = $this->serializeStats($stats);
			$this->jobList->add(self::class, $argument);
			return;
		}

		$this->writeAudit($argument, $stats);
	}

	/**
	 * Release the dedupe marker for this job's logical target so an edit
	 * landing after job start can queue a fresh recompute. The recompute
	 * is idempotent, so overlapping queued runs converge. Best-effort:
	 * without a locking backend there is no marker to clear.
	 */
	private function clearEnqueueMarker(int $modelId, ?string $userId): void
	{
		$target = [];
		if (is_string($userId) && $userId !== '') {
			$target[self::ARG_USER_ID] = $userId;
		} elseif ($modelId > 0) {
			$target[self::ARG_MODEL_ID] = $modelId;
		} else {
			return;
		}
		$cache = self::enqueueMarkerCache($this->cacheFactory);
		if ($cache === null) {
			return;
		}
		try {
			$cache->remove(self::enqueueMarkerKey($target));
		} catch (\Throwable $e) {
			$this->logger->warning('PaidBreakRecomputeJob: could not clear enqueue marker: ' . $e->getMessage(), [
				'app' => 'arbeitszeitcheck',
				'exception' => $e,
			]);
		}
	}

	/**
	 * @return array{entries: int, changed: int, days: int, users: array<string,bool>, skipped: array<string,bool>}
	 */
	private function freshStats(): array
	{
		return ['entries' => 0, 'changed' => 0, 'days' => 0, 'users' => [], 'skipped' => []];
	}

	/**
	 * Sets are carried in the job argument as lists (JSON-safe).
	 *
	 * @param array{entries: int, changed: int, days: int, users: array<string,bool>, skipped: array<string,bool>} $stats
	 * @return array<string, mixed>
	 */
	private function serializeStats(array $stats): array
	{
		return [
			'entries' => $stats['entries'],
			'changed' => $stats['changed'],
			'days' => $stats['days'],
			'users' => array_keys($stats['users']),
			'skipped' => array_keys($stats['skipped']),
		];
	}

	/**
	 * @param array<string, mixed>|array{entries: int, changed: int, days: int, users: array<string,bool>, skipped: array<string,bool>} $stats
	 */
	private function writeAudit(array $argument, array $stats): void
	{
		// Stats from a continuation argument arrive serialized (lists).
		$users = $stats['users'] ?? [];
		$skipped = $stats['skipped'] ?? [];
		$userCount = is_array($users) ? count($users) : 0;
		$skippedList = is_array($skipped) ? array_keys($skipped) : [];
		if ($skippedList !== [] && array_is_list($skipped)) {
			$skippedList = array_values($skipped);
		}
		sort($skippedList);
		$skippedTotal = count($skippedList);
		if ($skippedTotal > self::MAX_SKIPPED_NAMES) {
			$skippedList = array_slice($skippedList, 0, self::MAX_SKIPPED_NAMES);
		}

		$payload = [
			'model_id' => (int)($argument[self::ARG_MODEL_ID] ?? 0) ?: null,
			'user_id' => $argument[self::ARG_USER_ID] ?? null,
			'users' => $userCount,
			'entries_touched' => (int)($stats['entries'] ?? 0),
			'entries_changed' => (int)($stats['changed'] ?? 0),
			'days_recomputed' => (int)($stats['days'] ?? 0),
			'skipped_finalized_months' => $skippedList,
			'skipped_finalized_total' => $skippedTotal,
		];

		try {
			$this->auditLogMapper->logAction(
				'system',
				'paid_break_recompute',
				$payload['model_id'] !== null ? 'working_time_model' : 'user',
				$payload['model_id'] ?? 0,
				null,
				$payload,
				'system'
			);
		} catch (\Throwable $e) {
			$this->logger->warning('PaidBreakRecomputeJob: audit write failed: ' . $e->getMessage(), [
				'app' => 'arbeitszeitcheck',
				'exception' => $e,
			]);
		}

		$this->logger->info('PaidBreakRecomputeJob finished', [
			'app' => 'arbeitszeitcheck',
			'stats' => $payload,
		]);
	}
}
