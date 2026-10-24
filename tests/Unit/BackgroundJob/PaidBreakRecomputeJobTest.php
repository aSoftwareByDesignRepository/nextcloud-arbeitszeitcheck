<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\PaidBreakRecomputeJob;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * Chunked recompute job: cursor continuation, serialized-stats
 * normalization, dedupe on enqueue, terminal audit event.
 */
class PaidBreakRecomputeJobTest extends TestCase
{
	private TimeEntryMapper $timeEntryMapper;
	private UserWorkingTimeModelMapper $uwtmMapper;
	private TimeTrackingService $timeTracking;
	private AuditLogMapper $auditLog;
	private IJobList $jobList;

	/** @var list<array{0:string,1:array}> recorded jobList->add calls */
	private array $addedJobs = [];

	/** @var list<array> recorded audit payloads */
	private array $auditPayloads = [];

	/** @var list<TimeEntry> rows served by the chunk finder */
	private array $chunkRows = [];

	/** @var list<array{user:string,from:mixed,to:mixed,afterId:int,limit:int}> recorded chunk-finder calls */
	private array $chunkCalls = [];

	/** @var array<string,list<TimeEntry>> per-user rows served by the chunk finder */
	private array $chunkRowsByUser = [];

	/** @var array<string,mixed>|null result of recomputePaidBreaksForUserSpan */
	private ?array $recomputeResult = ['changed' => 0, 'days_recomputed' => 0, 'skipped_finalized' => []];

	/** @var list<array{0:string,1:\DateTimeInterface,2:\DateTimeInterface}> recorded recompute spans */
	private array $recomputeCalls = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->addedJobs = [];
		$this->auditPayloads = [];
		$this->chunkRows = [];
		$this->chunkCalls = [];
		$this->chunkRowsByUser = [];
		$this->recomputeCalls = [];
		$this->recomputeResult = ['changed' => 0, 'days_recomputed' => 0, 'skipped_finalized' => []];

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->timeEntryMapper->method('findChunkByUserAfterId')->willReturnCallback(
			function (string $userId, $from, $to, int $afterId, int $limit): array {
				$this->chunkCalls[] = ['user' => $userId, 'from' => $from, 'to' => $to, 'afterId' => $afterId, 'limit' => $limit];
				if (array_key_exists($userId, $this->chunkRowsByUser)) {
					return array_values(array_filter(
						$this->chunkRowsByUser[$userId],
						static fn (TimeEntry $e): bool => (int)$e->getId() > $afterId,
					));
				}
				return $this->chunkRows;
			}
		);

		$this->uwtmMapper = $this->createMock(UserWorkingTimeModelMapper::class);

		$this->timeTracking = $this->createMock(TimeTrackingService::class);
		$this->timeTracking->method('recomputePaidBreaksForUserSpan')->willReturnCallback(
			function (string $userId, \DateTimeInterface $from, \DateTimeInterface $to): array {
				$this->recomputeCalls[] = [$userId, $from, $to];
				return $this->recomputeResult;
			}
		);

		$this->auditLog = $this->createMock(AuditLogMapper::class);
		$this->auditLog->method('logAction')->willReturnCallback(
			function (string $by, string $action, string $entityType, $entityId, $old, $new, string $actor): void {
				$this->auditPayloads[] = ['action' => $action, 'entity_type' => $entityType, 'payload' => $new];
			}
		);

		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('add')->willReturnCallback(
			function ($class, $argument): void {
				$this->addedJobs[] = [$class, $argument];
			}
		);
	}

	private function job(?\OCP\ICacheFactory $cacheFactory = null): PaidBreakRecomputeJob
	{
		return new PaidBreakRecomputeJob(
			$this->createMock(ITimeFactory::class),
			$this->timeEntryMapper,
			$this->uwtmMapper,
			$this->timeTracking,
			$this->auditLog,
			$this->jobList,
			new NullLogger(),
			$cacheFactory,
		);
	}

	private function runJob(PaidBreakRecomputeJob $job, $argument): void
	{
		$ref = new ReflectionClass($job);
		$m = $ref->getMethod('run');
		$m->setAccessible(true);
		$m->invoke($job, $argument);
	}

	private function entry(int $id, string $start, string $end): TimeEntry
	{
		$e = new TimeEntry();
		$e->setId($id);
		$e->setUserId('u1');
		$e->setStatus(TimeEntry::STATUS_COMPLETED);
		$e->setStartTime(new \DateTime($start));
		$e->setEndTime(new \DateTime($end));
		return $e;
	}

	public function testUserRunRecomputesUnionSpanAndAudits(): void
	{
		$this->chunkRows = [
			$this->entry(5, '2026-03-10 08:00', '2026-03-10 17:00'),
			$this->entry(6, '2026-03-11 08:00', '2026-03-11 12:00'),
		];
		$this->recomputeResult = ['changed' => 2, 'days_recomputed' => 2, 'skipped_finalized' => []];

		$this->runJob($this->job(), [PaidBreakRecomputeJob::ARG_USER_ID => 'u1']);

		$this->assertCount(1, $this->recomputeCalls);
		[$uid, $from, $to] = $this->recomputeCalls[0];
		$this->assertSame('u1', $uid);
		$this->assertSame('2026-03-10 08:00', $from->format('Y-m-d H:i'));
		$this->assertSame('2026-03-11 12:00', $to->format('Y-m-d H:i'));

		$this->assertCount(0, $this->addedJobs, 'drained worklist must not reschedule');
		$this->assertCount(1, $this->auditPayloads);
		$p = $this->auditPayloads[0];
		$this->assertSame('paid_break_recompute', $p['action']);
		$this->assertSame('user', $p['entity_type']);
		$this->assertSame(2, $p['payload']['entries_touched']);
		$this->assertSame(2, $p['payload']['entries_changed']);
		$this->assertSame(1, $p['payload']['users']);
	}

	public function testFullChunkReschedulesWithCursor(): void
	{
		$rows = [];
		for ($i = 1; $i <= PaidBreakRecomputeJob::CHUNK; $i++) {
			$rows[] = $this->entry($i, '2026-03-10 08:00', '2026-03-10 09:00');
		}
		$this->chunkRows = $rows;

		$this->runJob($this->job(), [PaidBreakRecomputeJob::ARG_USER_ID => 'u1']);

		$this->assertCount(1, $this->addedJobs, 'full chunk must reschedule a continuation');
		[$class, $arg] = $this->addedJobs[0];
		$this->assertSame(PaidBreakRecomputeJob::class, $class);
		$this->assertSame(PaidBreakRecomputeJob::CHUNK, $arg[PaidBreakRecomputeJob::ARG_AFTER_ENTRY_ID]);
		$this->assertSame('u1', $arg[PaidBreakRecomputeJob::ARG_USER_ID]);
		$this->assertSame(PaidBreakRecomputeJob::CHUNK, $arg[PaidBreakRecomputeJob::ARG_STATS]['entries']);
		$this->assertSame(['u1'], $arg[PaidBreakRecomputeJob::ARG_STATS]['users']);
		$this->assertCount(0, $this->auditPayloads, 'no terminal audit mid-cursor');
	}

	public function testContinuationNormalizesSerializedStats(): void
	{
		$this->chunkRows = [$this->entry(300, '2026-03-12 08:00', '2026-03-12 09:00')];
		$this->recomputeResult = ['changed' => 0, 'days_recomputed' => 1, 'skipped_finalized' => ['2026-02']];

		$this->runJob($this->job(), [
			PaidBreakRecomputeJob::ARG_USER_ID => 'u1',
			PaidBreakRecomputeJob::ARG_AFTER_ENTRY_ID => 200,
			PaidBreakRecomputeJob::ARG_STATS => [
				'entries' => 200,
				'changed' => 7,
				'days' => 30,
				'users' => ['u1'],            // serialized as list, not set
				'skipped' => ['u1@2026-01'],  // serialized as list, not set
			],
		]);

		$this->assertCount(1, $this->auditPayloads);
		$p = $this->auditPayloads[0]['payload'];
		$this->assertSame(201, $p['entries_touched']);
		$this->assertSame(7, $p['entries_changed']);
		$this->assertSame(1, $p['users'], 'users set must dedupe, not count twice');
		$this->assertSame(['u1@2026-01', 'u1@2026-02'], $p['skipped_finalized_months']);
		$this->assertSame(2, $p['skipped_finalized_total']);
	}

	public function testEmptyWorklistWritesZeroAudit(): void
	{
		$this->chunkRows = [];
		$this->runJob($this->job(), [PaidBreakRecomputeJob::ARG_USER_ID => 'u1']);

		$this->assertCount(0, $this->recomputeCalls);
		$this->assertCount(1, $this->auditPayloads);
		$this->assertSame(0, $this->auditPayloads[0]['payload']['entries_touched']);
	}

	public function testModelModeBuildsWorklistFromAssignments(): void
	{
		$a1 = new UserWorkingTimeModel();
		$a1->setUserId('u1');
		$a1->setWorkingTimeModelId(7);
		$a1->setStartDate(new \DateTime('2026-01-01'));
		$a1->setEndDate(new \DateTime('2026-03-31'));
		$a2 = new UserWorkingTimeModel();
		$a2->setUserId('u2');
		$a2->setWorkingTimeModelId(7);
		$a2->setStartDate(new \DateTime('2026-02-01'));

		$this->uwtmMapper->method('findByWorkingTimeModel')->willReturnCallback(
			function (int $modelId, bool $onlyActive) use ($a1, $a2): array {
				// Historical rows must be included — assert the flag off.
				$this->assertFalse($onlyActive);
				return [$a1, $a2];
			}
		);

		$this->chunkRows = [$this->entry(1, '2026-02-10 08:00', '2026-02-10 09:00')];
		$this->runJob($this->job(), [PaidBreakRecomputeJob::ARG_MODEL_ID => 7]);

		// Both assignments' windows were scanned (same chunk rows served both).
		$this->assertCount(2, $this->recomputeCalls);
		$this->assertSame('u1', $this->recomputeCalls[0][0]);
		$this->assertSame('u2', $this->recomputeCalls[1][0]);
		$this->assertSame('working_time_model', $this->auditPayloads[0]['entity_type']);
	}

	public function testModelModePassesAssignmentWindowToChunkFinder(): void
	{
		$a1 = new UserWorkingTimeModel();
		$a1->setUserId('u1');
		$a1->setWorkingTimeModelId(7);
		$a1->setStartDate(new \DateTime('2026-01-01'));
		$a1->setEndDate(new \DateTime('2026-03-31'));

		$this->uwtmMapper->method('findByWorkingTimeModel')->willReturn([$a1]);
		$this->chunkRows = [$this->entry(1, '2026-02-10 08:00', '2026-02-10 09:00')];
		$this->runJob($this->job(), [PaidBreakRecomputeJob::ARG_MODEL_ID => 7]);

		$this->assertNotEmpty($this->chunkCalls);
		$c = $this->chunkCalls[0];
		$this->assertSame('u1', $c['user']);
		$this->assertSame('2026-01-01', $c['from']->format('Y-m-d'));
		// End-dated window is inclusive: the finder must scan through the day
		// AFTER the assignment end, not stop at it.
		$this->assertSame('2026-04-01', $c['to']->format('Y-m-d'));
	}

	public function testCursorResetsBetweenWorklistWindows(): void
	{
		$a1 = new UserWorkingTimeModel();
		$a1->setUserId('u1');
		$a1->setWorkingTimeModelId(7);
		$a1->setStartDate(new \DateTime('2026-01-01'));
		$a2 = new UserWorkingTimeModel();
		$a2->setUserId('u2');
		$a2->setWorkingTimeModelId(7);
		$a2->setStartDate(new \DateTime('2026-01-01'));

		$this->uwtmMapper->method('findByWorkingTimeModel')->willReturn([$a1, $a2]);
		// u2's only row has a LOWER id than u1's last row — a stale cursor
		// would skip it entirely.
		$this->chunkRowsByUser = [
			'u1' => [$this->entry(5, '2026-02-10 08:00', '2026-02-10 09:00')],
			'u2' => [$this->entry(3, '2026-02-10 08:00', '2026-02-10 09:00')],
		];
		$this->runJob($this->job(), [PaidBreakRecomputeJob::ARG_MODEL_ID => 7]);

		$this->assertCount(2, $this->recomputeCalls);
		$this->assertSame('u1', $this->recomputeCalls[0][0]);
		$this->assertSame('u2', $this->recomputeCalls[1][0]);
		$u2Call = null;
		foreach ($this->chunkCalls as $c) {
			if ($c['user'] === 'u2') {
				$u2Call = $c;
				break;
			}
		}
		$this->assertNotNull($u2Call);
		$this->assertSame(0, $u2Call['afterId'], 'new worklist window must restart the cursor at 0');
	}

	public function testNonArrayArgumentIsIgnored(): void
	{
		$this->runJob($this->job(), null);
		$this->assertCount(0, $this->recomputeCalls);
		$this->assertCount(0, $this->auditPayloads);
		$this->assertCount(0, $this->addedJobs);
	}

	public function testEnqueueForUserDedupesExistingJob(): void
	{
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('getJobsIterator')->willReturnCallback(
			function (string $class, int $limit, int $offset): array {
				if ($offset !== 0) {
					return [];
				}
				$job = $this->createMock(\OCP\BackgroundJob\IJob::class);
				$job->method('getArgument')->willReturn(
					[PaidBreakRecomputeJob::ARG_USER_ID => 'u1', 'after_entry_id' => 55]
				);
				return [$job];
			}
		);
		$this->jobList->expects($this->never())->method('add');
		$this->assertFalse(PaidBreakRecomputeJob::enqueueForUser($this->jobList, 'u1'));
	}

	public function testEnqueueIgnoresJobQueuedForDifferentTarget(): void
	{
		// Regression: the job list yields IJob objects, not row arrays — the
		// dedupe scan must read getArgument(), and a queued job for another
		// target must not block (or crash) a new enqueue.
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('getJobsIterator')->willReturnCallback(
			function (string $class, int $limit, int $offset): array {
				if ($offset !== 0) {
					return [];
				}
				$job = $this->createMock(\OCP\BackgroundJob\IJob::class);
				$job->method('getArgument')->willReturn(
					[PaidBreakRecomputeJob::ARG_USER_ID => 'u2']
				);
				return [$job];
			}
		);
		$this->jobList->expects($this->once())->method('add')->with(
			PaidBreakRecomputeJob::class,
			[PaidBreakRecomputeJob::ARG_USER_ID => 'u1']
		);
		$this->assertTrue(PaidBreakRecomputeJob::enqueueForUser($this->jobList, 'u1'));
	}

	public function testEnqueueForModelAddsWhenNotQueued(): void
	{
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('getJobsIterator')->willReturn([]);
		$this->jobList->expects($this->once())->method('add')->with(
			PaidBreakRecomputeJob::class,
			[PaidBreakRecomputeJob::ARG_MODEL_ID => 7]
		);
		$this->assertTrue(PaidBreakRecomputeJob::enqueueForModel($this->jobList, 7));
		$this->assertFalse(PaidBreakRecomputeJob::enqueueForModel($this->jobList, 0));
	}

	/**
	 * In-memory locking cache with real add/remove semantics — models a
	 * deployed memcache.locking backend so the marker path is testable.
	 *
	 * @param array<string,bool> &$markers shared marker state
	 * @return array{0: \OCP\ICacheFactory, 1: \OCP\IMemcache}
	 */
	private function fakeMarkerBackend(array &$markers): array
	{
		$memcache = $this->createMock(\OCP\IMemcache::class);
		$memcache->method('add')->willReturnCallback(
			function ($key, $value, $ttl = 0) use (&$markers): bool {
				if (isset($markers[$key])) {
					return false;
				}
				$markers[$key] = true;
				return true;
			}
		);
		$memcache->method('remove')->willReturnCallback(
			function ($key) use (&$markers): bool {
				unset($markers[$key]);
				return true;
			}
		);
		$memcache->method('hasKey')->willReturnCallback(
			function ($key) use (&$markers): bool {
				return isset($markers[$key]);
			}
		);
		$factory = $this->createMock(\OCP\ICacheFactory::class);
		$factory->method('createLocking')->willReturn($memcache);
		return [$factory, $memcache];
	}

	public function testEnqueueMarkerBlocksPostDrainUntilRunClearsIt(): void
	{
		// dedupe_marker_lost_enqueue: once the job drains oc_jobs the
		// isQueued() scan no longer sees it, so a marker that outlives the
		// job silently drops the next legitimate enqueue. run() must clear
		// the marker at job start so the post-completion enqueue lands.
		$markers = [];
		[$factory] = $this->fakeMarkerBackend($markers);

		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('getJobsIterator')->willReturn([]); // drained queue
		$this->jobList->method('add')->willReturnCallback(
			function ($class, $argument): void {
				$this->addedJobs[] = [$class, $argument];
			}
		);

		// First enqueue lands and sets the marker.
		$this->assertTrue(PaidBreakRecomputeJob::enqueueForUser($this->jobList, 'u1', $factory));
		$this->assertCount(1, $this->addedJobs);

		// Marker still set, job row gone → the marker alone blocks the
		// enqueue. This pinpoints the marker's role in the swallow window.
		$this->assertFalse(PaidBreakRecomputeJob::enqueueForUser($this->jobList, 'u1', $factory));
		$this->assertCount(1, $this->addedJobs);

		// run() clears the marker at job start → post-completion enqueue lands.
		$this->runJob($this->job($factory), [PaidBreakRecomputeJob::ARG_USER_ID => 'u1']);
		$this->assertTrue(PaidBreakRecomputeJob::enqueueForUser($this->jobList, 'u1', $factory));
		$this->assertCount(2, $this->addedJobs);
	}

	public function testMarkerClearUsesLogicalTargetFromContinuationArgs(): void
	{
		$markers = [];
		[$factory, $memcache] = $this->fakeMarkerBackend($markers);

		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('getJobsIterator')->willReturn([]);
		$this->jobList->method('add')->willReturnCallback(
			function ($class, $argument): void {
				$this->addedJobs[] = [$class, $argument];
			}
		);

		$this->assertTrue(PaidBreakRecomputeJob::enqueueForModel($this->jobList, 7, $factory));
		$this->assertFalse(PaidBreakRecomputeJob::enqueueForModel($this->jobList, 7, $factory));

		// A continuation argument (cursor + stats attached) targets the same
		// logical marker — the model key must be reconstructed exactly.
		$this->uwtmMapper->method('findByWorkingTimeModel')->willReturn([]);
		$this->runJob($this->job($factory), [
			PaidBreakRecomputeJob::ARG_MODEL_ID => 7,
			PaidBreakRecomputeJob::ARG_ASSIGNMENT_IDX => 3,
			PaidBreakRecomputeJob::ARG_AFTER_ENTRY_ID => 55,
			PaidBreakRecomputeJob::ARG_STATS => ['entries' => 12, 'users' => ['u9']],
		]);

		$this->assertTrue(PaidBreakRecomputeJob::enqueueForModel($this->jobList, 7, $factory));
		$this->assertCount(2, $this->addedJobs);
	}

	public function testEnqueueStillAddsWhenMarkerBackendUnavailable(): void
	{
		// No locking backend / factory failure → degrade to the isQueued
		// scan; a duplicate run is idempotent-benign but a lost enqueue is not.
		$factory = $this->createMock(\OCP\ICacheFactory::class);
		$factory->method('createLocking')->willThrowException(new \RuntimeException('no backend'));

		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('getJobsIterator')->willReturn([]);
		$this->jobList->expects($this->once())->method('add');

		$this->assertTrue(PaidBreakRecomputeJob::enqueueForUser($this->jobList, 'u1', $factory));
	}
}
