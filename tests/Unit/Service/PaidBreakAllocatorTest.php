<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLog;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\PaidBreakAllocator;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Deterministic paid-break allocation (SPEC §10 binding algorithm):
 * per-day pool = merged deductible break portions (floor-filtered, merged),
 * distributed to entries ordered by (start_time, id) up to the model cap.
 */
class PaidBreakAllocatorTest extends TestCase
{
	private TimeEntryMapper $timeEntryMapper;
	private MonthClosureService $monthClosure;
	private PaidBreakAllocator $allocator;
	private BreakPolicyResolver $resolver;
	private TimeZoneService $timeZoneService;

	/** @var list<array<int, mixed>> logAction() invocations captured by the audit spy */
	private array $auditCalls = [];

	/** @var array<string,mixed> model break_rules handed to the resolver */
	private array $modelBreakRules = [];

	/** @var list<TimeEntry> entries returned by findOverlapping */
	private array $entries = [];

	/** @var array<string,bool> 'Y-m' → finalized */
	private array $finalizedMonths = [];

	/** @var array<int,int> entry id → paid_break_seconds written via update() */
	private array $writes = [];

	private bool $hasAssignment = true;

	protected function setUp(): void
	{
		parent::setUp();

		$this->modelBreakRules = [];
		$this->entries = [];
		$this->finalizedMonths = [];
		$this->writes = [];
		$this->hasAssignment = true;
		$this->auditCalls = [];

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default) => $key === 'app_timezone' ? 'Europe/Berlin' : $default
		);
		$config->method('getUserValue')->willReturn('');

		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->method('findUserIdsWithNonEmptySetting')->willReturn([]);

		$assignment = new UserWorkingTimeModel();
		$assignment->setUserId('u1');
		$assignment->setWorkingTimeModelId(7);
		$userWtmMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$userWtmMapper->method('findCurrentByUser')->willReturnCallback(
			fn () => $this->hasAssignment ? $assignment : null
		);
		$userWtmMapper->method('findByUserAndDate')->willReturnCallback(
			fn () => $this->hasAssignment ? $assignment : null
		);

		$wtmMapper = $this->createMock(WorkingTimeModelMapper::class);
		$wtmMapper->method('find')->willReturnCallback(function (): WorkingTimeModel {
			$model = new WorkingTimeModel();
			$model->setId(7);
			$model->setBreakRules(json_encode($this->modelBreakRules));
			return $model;
		});

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		// Range-faithful mock: only entries overlapping [$start, $end) come back —
		// like the real mapper. Returning everything would hide the day-pool
		// widening regression (a span query for entry B must not see entry A).
		$this->timeEntryMapper->method('findOverlapping')->willReturnCallback(
			function (string $userId, \DateTime $start, \DateTime $end) {
				$ws = $start->getTimestamp();
				$we = $end->getTimestamp();
				return array_values(array_filter($this->entries, function (TimeEntry $e) use ($ws, $we): bool {
					$es = $e->getStartTime()?->getTimestamp();
					$ee = ($e->getEndTime() ?? new \DateTime('2100-01-01'))->getTimestamp();
					return $es !== null && $es < $we && $ee > $ws;
				}));
			}
		);
		$this->timeEntryMapper->method('update')->willReturnCallback(
			function (TimeEntry $e): TimeEntry {
				$this->writes[(int)$e->getId()] = $e->getPaidBreakSeconds();
				return $e;
			}
		);

		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$this->timeZoneService = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => $text
		);

		$this->resolver = new BreakPolicyResolver(
			$config,
			new LaborLawProfileFactory($config, $userSettingsMapper),
			$userSettingsMapper,
			$userWtmMapper,
			$wtmMapper,
			$this->timeEntryMapper,
			$this->timeZoneService,
			$l10n,
		);

		$this->monthClosure = $this->createMock(MonthClosureService::class);
		$this->monthClosure->method('isMonthFinalized')->willReturnCallback(
			fn (string $uid, int $year, int $month) => $this->finalizedMonths[sprintf('%04d-%02d', $year, $month)] ?? false
		);

		$this->allocator = new PaidBreakAllocator(
			$this->timeEntryMapper,
			$this->resolver,
			$this->timeZoneService,
			$this->monthClosure,
			new NullLogger(),
		);
	}

	/**
	 * Rebuild the allocator with a spy AuditLogMapper wired as the optional
	 * 6th ctor arg (same DI path production uses). $auditThrow simulates a
	 * failing audit sink — the write must survive it.
	 */
	private function rebuildAllocatorWithAuditSpy(?\Throwable $auditThrow = null): void
	{
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->method('logAction')->willReturnCallback(function (...$args) use ($auditThrow) {
			$this->auditCalls[] = $args;
			if ($auditThrow !== null) {
				throw $auditThrow;
			}
			return new AuditLog();
		});
		$this->allocator = new PaidBreakAllocator(
			$this->timeEntryMapper,
			$this->resolver,
			$this->timeZoneService,
			$this->monthClosure,
			new NullLogger(),
			$audit,
		);
	}

	private function entry(int $id, string $start, string $end, array $breaks = [], string $status = TimeEntry::STATUS_COMPLETED): TimeEntry
	{
		$e = new TimeEntry();
		$e->setId($id);
		$e->setUserId('u1');
		$e->setStatus($status);
		$e->setStartTime(new \DateTime($start));
		$e->setEndTime(new \DateTime($end));
		$e->setCountableMinBreakMinutes(15);
		if ($breaks !== []) {
			$e->setBreaks(json_encode($breaks));
		}
		return $e;
	}

	private function breaks(array $ranges): array
	{
		return array_map(
			static fn (array $r): array => ['start' => $r[0], 'end' => $r[1]],
			$ranges
		);
	}

	private function runSpan(): array
	{
		return $this->allocator->recomputeSpan(
			'u1',
			new \DateTime('2026-03-01 00:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2026-04-01 00:00:00', new \DateTimeZone('Europe/Berlin')),
		);
	}

	public function testFlagOffPaysNothing(): void
	{
		$this->modelBreakRules = [];
		$this->entries = [$this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:30:00+01:00']]))];

		$r = $this->runSpan();
		$this->assertSame(0, $r['changed']);
		$this->assertSame([], $this->writes);
		$this->assertSame(0, $this->entries[0]->getPaidBreakSeconds());
	}

	public function testNoAssignmentPaysNothing(): void
	{
		$this->hasAssignment = false;
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		$this->entries = [$this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:30:00+01:00']]))];

		$r = $this->runSpan();
		$this->assertSame(0, $r['changed']);
		$this->assertSame(0, $this->entries[0]->getPaidBreakSeconds());
	}

	public function testFullPoolPaidWithoutCap(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		$this->entries = [$this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:30:00+01:00']]))];

		$r = $this->runSpan();
		$this->assertSame(1, $r['changed']);
		$this->assertSame(1800, $this->writes[1]);
		$this->assertSame(1800, $this->entries[0]->getPaidBreakSeconds());
	}

	public function testCapTruncatesPool(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true, 'paid_break_cap_minutes' => 20];
		$this->entries = [$this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:30:00+01:00']]))];

		$r = $this->runSpan();
		$this->assertSame(1200, $this->writes[1]);
	}

	public function testCapZeroPaysNothing(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true, 'paid_break_cap_minutes' => 0];
		$this->entries = [$this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:30:00+01:00']]))];

		$r = $this->runSpan();
		$this->assertSame(0, $r['changed']);
		$this->assertSame(0, $this->entries[0]->getPaidBreakSeconds());
	}

	/**
	 * Day-pool widening regression (probe-found): when the caller's span only
	 * overlaps entry B, entry A — same calendar day, non-overlapping spans —
	 * must still join the allocation set. Otherwise B takes the whole pool and
	 * the daily cap is silently exceeded (A=2700 AND B=2700 observed live).
	 */
	public function testSameDayNonOverlappingEntriesSharePool(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true, 'paid_break_cap_minutes' => 45];
		$early = $this->entry(1, '2026-03-10 08:00', '2026-03-10 12:30', $this->breaks([['2026-03-10T10:00:00+01:00', '2026-03-10T11:00:00+01:00']]));
		$late = $this->entry(2, '2026-03-10 13:30', '2026-03-10 18:00', $this->breaks([['2026-03-10T15:00:00+01:00', '2026-03-10T16:00:00+01:00']]));
		$early->setPaidBreakSeconds(2700); // already snapshot-correct from its own create
		$this->entries = [$early, $late];

		// Caller span = LATE entry only — the create-time recompute path.
		$r = $this->allocator->recomputeSpan(
			'u1',
			new \DateTime('2026-03-10 13:30:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2026-03-10 18:00:00', new \DateTimeZone('Europe/Berlin')),
		);

		// Earliest start wins the whole 45-min pool; the later entry gets 0.
		// The regression signature: writes[2] === 2700 = B took a full pool of
		// its own because the seed set never widened to entry A's day.
		$this->assertSame(2700, $this->writes[1] ?? $early->getPaidBreakSeconds());
		$this->assertNotSame(2700, $this->writes[2] ?? null);
		$this->assertSame(0, $late->getPaidBreakSeconds());
		$this->assertSame(0, $r['changed']);
	}

	public function testPoolDistributedPerEntryByStartOrder(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true, 'paid_break_cap_minutes' => 30];
		$this->entries = [
			$this->entry(1, '2026-03-10 06:00', '2026-03-10 12:00', $this->breaks([['2026-03-10T09:00:00+01:00', '2026-03-10T09:20:00+01:00']])),
			$this->entry(2, '2026-03-10 12:30', '2026-03-10 18:30', $this->breaks([['2026-03-10T15:00:00+01:00', '2026-03-10T15:20:00+01:00']])),
		];

		$r = $this->runSpan();
		// Pool 30min: first entry takes its full 20min, second gets the rest.
		$this->assertSame(1200, $this->writes[1]);
		$this->assertSame(600, $this->writes[2]);
	}

	public function testDeterministicOrderIndependentOfInputOrder(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true, 'paid_break_cap_minutes' => 30];
		// Same entries, reversed list order — outcome must not change.
		$this->entries = [
			$this->entry(2, '2026-03-10 12:30', '2026-03-10 18:30', $this->breaks([['2026-03-10T15:00:00+01:00', '2026-03-10T15:20:00+01:00']])),
			$this->entry(1, '2026-03-10 06:00', '2026-03-10 12:00', $this->breaks([['2026-03-10T09:00:00+01:00', '2026-03-10T09:20:00+01:00']])),
		];

		$this->runSpan();
		$this->assertSame(1200, $this->writes[1]);
		$this->assertSame(600, $this->writes[2]);
	}

	public function testSubFloorBreakNeverPaid(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		$this->entries = [$this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:10:00+01:00']]))];

		$r = $this->runSpan();
		$this->assertSame(0, $r['changed']);
		$this->assertSame(0, $this->entries[0]->getPaidBreakSeconds());
	}

	public function testBreakExactlyAtFloorIsPaid(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		// Floor = 15min (fixture default): a break of exactly the floor length
		// counts for the statutory deduction, so it must be payable.
		$this->entries = [$this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:15:00+01:00']]))];

		$r = $this->runSpan();
		$this->assertSame(900, $this->writes[1]);
	}

	public function testOverlappingBreaksMergeBeforePayment(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		$this->entries = [$this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([
			['2026-03-10T12:00:00+01:00', '2026-03-10T12:20:00+01:00'],
			['2026-03-10T12:10:00+01:00', '2026-03-10T12:30:00+01:00'],
		]))];

		$r = $this->runSpan();
		$this->assertSame(1800, $this->writes[1]); // merged 12:00–12:30 once
	}

	public function testRejectedEntryStaysZero(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		$rejected = $this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:30:00+01:00']]), TimeEntry::STATUS_REJECTED);
		$rejected->setPaidBreakSeconds(999); // stale value must be reset to 0
		$this->entries = [$rejected];

		$r = $this->runSpan();
		$this->assertSame(0, $this->entries[0]->getPaidBreakSeconds());
		$this->assertSame(0, $this->writes[1]);
	}

	public function testFinalizedMonthSkippedAndReported(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		$this->finalizedMonths['2026-03'] = true;
		$this->entries = [$this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:30:00+01:00']]))];

		$r = $this->runSpan();
		$this->assertSame(['2026-03'], $r['skipped_finalized']);
		$this->assertSame(0, $r['changed']);
		$this->assertSame([], $this->writes);
		$this->assertSame(0, $this->entries[0]->getPaidBreakSeconds());
	}

	public function testIdempotentNoRewriteWhenSnapshotCorrect(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		$e = $this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:30:00+01:00']]));
		$e->setPaidBreakSeconds(1800); // already correct
		$this->entries = [$e];

		$r = $this->runSpan();
		$this->assertSame(0, $r['changed']);
		$this->assertSame([], $this->writes);
	}

	public function testOvernightBreakAttributedToDayItStarted(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		// Shift 22:00–06:00, break 01:00–01:30 — the portion lands on day 2.
		$this->entries = [$this->entry(1, '2026-03-10 22:00', '2026-03-11 06:00', $this->breaks([['2026-03-11T01:00:00+01:00', '2026-03-11T01:30:00+01:00']]))];

		$r = $this->allocator->recomputeSpan(
			'u1',
			new \DateTime('2026-03-10 00:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2026-03-12 00:00:00', new \DateTimeZone('Europe/Berlin')),
		);
		$this->assertSame(1800, $this->writes[1]);
		$this->assertSame(2, $r['days_recomputed']);
	}

	public function testEntryStartingBeforeSpanExtendsWindow(): void
	{
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		// Entry starts before the requested span: its full extent must still be
		// recomputed, so a break on the pre-span day is paid (doc: "entry spans
		// are followed to their full extent").
		$this->entries = [$this->entry(1, '2026-02-28 22:00', '2026-03-01 06:00', $this->breaks([['2026-02-28T23:30:00+01:00', '2026-02-28T23:45:00+01:00']]))];

		$r = $this->allocator->recomputeSpan(
			'u1',
			new \DateTime('2026-03-01 00:00:00', new \DateTimeZone('Europe/Berlin')),
			new \DateTime('2026-03-02 00:00:00', new \DateTimeZone('Europe/Berlin')),
		);
		$this->assertSame(900, $this->writes[1]);
		$this->assertSame(2, $r['days_recomputed']);
	}

	/**
	 * L1-06 regression leg: each changed row must emit exactly one
	 * `paid_break_share_changed` audit record with the full before/after
	 * payload — the batch-level `paid_break_recompute` totals alone cannot
	 * show which rows a same-day reallocation moved.
	 */
	public function testShareChangeEmitsAuditRow(): void
	{
		$this->rebuildAllocatorWithAuditSpy();
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		$this->entries = [$this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:30:00+01:00']]))];

		$r = $this->runSpan();

		$this->assertSame(1, $r['changed']);
		$this->assertCount(1, $this->auditCalls, 'expected exactly one audit row for the changed entry');
		[$userId, $action, $entityType, $entityId, $old, $new, $by] = $this->auditCalls[0];
		$this->assertSame('system', $userId);
		$this->assertSame('paid_break_share_changed', $action);
		$this->assertSame('time_entry', $entityType);
		$this->assertSame(1, $entityId);
		$this->assertNull($old);
		$this->assertSame('paid_break_allocator', $by);
		$this->assertSame('u1', $new['user_id']);
		$this->assertSame(1, $new['entry_id']);
		$this->assertSame('2026-03-10', $new['date']);
		$this->assertSame(0, $new['paid_break_seconds_before']);
		$this->assertSame(1800, $new['paid_break_seconds_after']);
	}

	/**
	 * Two same-day entries share a 30-min cap pool: the earlier entry wins,
	 * the later entry's stale paid snapshot is rewritten to 0 — the exact
	 * silent-sibling-rewrite class L1-06 forensics must capture, one row per
	 * changed entry with correct before/after values.
	 */
	public function testSiblingReallocationEmitsPerEntryRows(): void
	{
		$this->rebuildAllocatorWithAuditSpy();
		$this->modelBreakRules = ['breaks_count_as_work' => true, 'paid_break_cap_minutes' => 30];
		$e1 = $this->entry(1, '2026-03-10 08:00', '2026-03-10 12:30', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:30:00+01:00']]));
		$e1->setPaidBreakSeconds(0);
		$e2 = $this->entry(2, '2026-03-10 13:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T14:00:00+01:00', '2026-03-10T14:30:00+01:00']]));
		$e2->setPaidBreakSeconds(1800);
		$this->entries = [$e1, $e2];

		$r = $this->runSpan();

		$this->assertSame(2, $r['changed']);
		$this->assertCount(2, $this->auditCalls, 'each changed row must emit its own audit row');
		$byEntry = [];
		foreach ($this->auditCalls as $c) {
			$byEntry[$c[3]] = $c;
		}
		$this->assertSame(1800, $byEntry[1][5]['paid_break_seconds_after']);
		$this->assertSame(0, $byEntry[1][5]['paid_break_seconds_before']);
		$this->assertSame(0, $byEntry[2][5]['paid_break_seconds_after']);
		$this->assertSame(1800, $byEntry[2][5]['paid_break_seconds_before']);
		$this->assertSame('paid_break_share_changed', $byEntry[2][1]);
	}

	/**
	 * Audit is best-effort forensics: a throwing audit sink must warn and
	 * continue — the snapshot write and the changed counter survive.
	 */
	public function testAuditFailureNeverBlocksSnapshotWrite(): void
	{
		$this->rebuildAllocatorWithAuditSpy(new \RuntimeException('audit sink down'));
		$this->modelBreakRules = ['breaks_count_as_work' => true];
		$this->entries = [$this->entry(1, '2026-03-10 08:00', '2026-03-10 17:00', $this->breaks([['2026-03-10T12:00:00+01:00', '2026-03-10T12:30:00+01:00']]))];

		$r = $this->runSpan();

		$this->assertSame(1, $r['changed'], 'audit failure must not roll back the paid-break write');
		$this->assertSame(1800, $this->writes[1]);
		$this->assertCount(1, $this->auditCalls);
	}
}
