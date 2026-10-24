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
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
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
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * Queue a model-wide recompute, deduplicated on the logical target.
	 * Returns true when a job was actually enqueued.
	 */
	public static function enqueueForModel(IJobList $jobList, int $workingTimeModelId): bool
	{
		if ($workingTimeModelId <= 0) {
			return false;
		}
		return self::enqueue($jobList, [self::ARG_MODEL_ID => $workingTimeModelId]);
	}

	/**
	 * Queue a full-history recompute for one user, deduplicated on the user.
	 */
	public static function enqueueForUser(IJobList $jobList, string $userId): bool
	{
		if ($userId === '') {
			return false;
		}
		return self::enqueue($jobList, [self::ARG_USER_ID => $userId]);
	}

	/**
	 * Dedupe window for the enqueue marker — long enough to cover the
	 * check→add race; the isQueued() scan still dedupes jobs that sit in the
	 * queue longer than the marker lives.
	 */
	private const ENQUEUE_MARKER_TTL = 600;

	/**
	 * @param array<string, mixed> $target logical target keys only
	 */
	private static function enqueue(IJobList $jobList, array $target): bool
	{
		if (self::isQueued($jobList, $target)) {
			return false;
		}
		// Atomic marker closes the check→add TOCTOU: ILockingCache::add only
		// succeeds for the first writer across processes. The job effect is
		// idempotent regardless — this just avoids a duplicate queue row.
		try {
			$cache = \OCP\Server::get(\OCP\ICacheFactory::class)->createLockingCache('azc_pbr_enqueue');
			if (!$cache->add('m_' . md5(json_encode($target) ?: ''), 1, self::ENQUEUE_MARKER_TTL)) {
				return false;
			}
		} catch (\Throwable) {
			// No locking cache backend — isQueued scan above remains; the job
			// is idempotent so a duplicate enqueue is benign.
		}
		$jobList->add(self::class, $target);
		return true;
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
			$jobs = $jobList->getJobs(self::class, 50, $offset);
			foreach ($jobs as $job) {
				$arg = $job['argument'] ?? [];
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
			$offset += count($jobs);
		} while (count($jobs) === 50);
		return false;
	}

	protected function run($argument): void
	{
		if (!is_array($argument)) {
			return;
		}
		$modelId = (int)($argument[self::ARG_MODEL_ID] ?? 0);
		$userId = $argument[self::ARG_USER_ID] ?? null;

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
