<?php

declare(strict_types=1);

/**
 * Tests for TimeTrackingService
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCA\ArbeitszeitCheck\Service\MonthClosureGuard;
use OCA\ArbeitszeitCheck\BusinessRuleCode;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IDateTimeZone;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Class TimeTrackingServiceTest
 */
class TimeTrackingServiceTest extends TestCase {

	/** @var TimeTrackingService */
	private $service;
	private array $stalePausedEntries = [];
	private string $noticeJson = '';

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var ComplianceViolationMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $violationMapper;

	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var ProjectCheckIntegrationService|\PHPUnit\Framework\MockObject\MockObject */
	private $projectCheckService;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var IDBConnection|\PHPUnit\Framework\MockObject\MockObject */
	private $db;

	/** @var ILockingProvider|\PHPUnit\Framework\MockObject\MockObject */
	private $lockingProvider;

	/** @var MonthClosureGuard|\PHPUnit\Framework\MockObject\MockObject */
	private $monthClosureGuard;

	/** @var DailyWorkingHoursCalculator */
	private $dailyHoursCalculator;

	/** @var UserWorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userWorkingTimeModelMapper;

	/** @var WorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $workingTimeModelMapper;

	/** @var TimeZoneService */
	private $timeZoneService;

	protected function setUp(): void {
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->projectCheckService = $this->createMock(ProjectCheckIntegrationService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$complianceService = $this->createMock(ComplianceService::class);
		$complianceService->method('checkComplianceBeforeClockIn')->willReturn([]);
		$this->config = $this->createMock(IConfig::class);
		$config = $this->config;
		$config->method('getAppValue')->willReturnCallback(fn ($app, $key, $default) => match ($key) {
			'max_daily_hours' => '10',
			'min_rest_period' => '11',
			'app_timezone' => 'Europe/Berlin',
			default => $default
		});
		$this->noticeJson = '';
		$config->method('getUserValue')->willReturnCallback(
			fn ($u, $a, $k, $d = '') => $k === 'auto_clockout_notice' ? $this->noticeJson : $d
		);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper->method('getStringSetting')->willReturn('1');
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$userWorkingTimeModelMapper = $this->userWorkingTimeModelMapper;
		$this->workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$workingTimeModelMapper = $this->workingTimeModelMapper;
		$this->monthClosureGuard = $this->createMock(MonthClosureGuard::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);

		// TimeZoneService is intentionally instantiated for real here: it has
		// no side effects and its behaviour is part of the contract under test
		// (storage TZ resolution, now() in storage TZ, day windows). Using the
		// real object makes the suite assert that contract end-to-end.
		$dateTimeZone = $this->createMock(IDateTimeZone::class);
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($config, $dateTimeZone, $userSession, new NullLogger());
		$this->timeZoneService = $timeZoneService;
		$dailyHoursCalculator = new DailyWorkingHoursCalculator($this->timeEntryMapper, $timeZoneService);
		$this->dailyHoursCalculator = $dailyHoursCalculator;

		$timeCaptureMethodService = $this->createMock(TimeCaptureMethodService::class);
		$timeCaptureMethodService->method('assertClockStampingAllowed')->willReturnCallback(static function (): void {
		});

		$this->service = new TimeTrackingService(
			$this->timeEntryMapper,
			$this->violationMapper,
			$this->auditLogMapper,
			$this->projectCheckService,
			$complianceService,
			$this->l10n,
			$config,
			$userSettingsMapper,
			$userWorkingTimeModelMapper,
			$workingTimeModelMapper,
			$this->monthClosureGuard,
			$this->db,
			$this->lockingProvider,
			$timeZoneService,
			$dailyHoursCalculator,
			null,
			$timeCaptureMethodService,
		);

		$this->stalePausedEntries = [];
		$this->timeEntryMapper->method('findStalePausedAutomaticEntries')
			->willReturnCallback(fn () => $this->stalePausedEntries);
	}

	/**
	 * Test that clocking in when already clocked in throws exception
	 */
	public function testClockInWhenAlreadyActiveThrowsException(): void {
		$userId = 'testuser';

		// Mock that user is already clocked in
		$this->timeEntryMapper->expects($this->once())
			->method('findActiveByUser')
			->with($userId)
			->willReturn($this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class));

		$this->l10n->expects($this->once())
			->method('t')
			->with('User is already clocked in')
			->willReturn('User is already clocked in');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('User is already clocked in');

		$this->service->clockIn($userId);
	}

	public function testClockInWhenPendingOpenSessionCorrectionThrows(): void {
		$userId = 'testuser';
		$pending = new TimeEntry();
		$pending->setId(7);
		$pending->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);

		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->expects($this->once())
			->method('findPendingOpenSessionByUser')
			->with($userId)
			->willReturn($pending);
		$this->l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		try {
			$this->service->clockIn($userId);
			$this->fail('expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame(BusinessRuleCode::OPEN_SESSION_CORRECTION_PENDING, $e->getReasonCode());
		}
	}

	/**
	 * Test successful clock in
	 */
	public function testClockInSuccess(): void {
		$userId = 'testuser';
		$projectId = 'proj123';
		$description = 'Working on project';

		$this->timeEntryMapper->expects($this->once())
			->method('findActiveByUser')
			->with($userId)
			->willReturn(null);

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findOnBreakByUser')
			->with($userId)
			->willReturn(null);

		$this->timeEntryMapper->method('getTotalHoursByUserAndDateRange')
			->willReturn(0.0);

		// Mock project validation
		$this->projectCheckService->expects($this->once())
			->method('userMayAttachProjectCheckProjectToOwnTime')
			->with($userId, $projectId)
			->willReturn(true);

		// Mock compliance check (no violations)
		$this->violationMapper->expects($this->never())
			->method('createViolation');

		// Mock time entry creation and saving
		$mockEntry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->willReturn($mockEntry);

		// Mock audit logging
		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'clock_in', 'time_entry', $this->anything(), null, $this->anything());

		$result = $this->service->clockIn($userId, $projectId, $description);

		$this->assertSame($mockEntry, $result);
	}

	public function testClockInSurvivesSummaryAndNoticeClearFailures(): void {
		$userId = 'testuser';

		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('getTotalHoursByUserAndDateRange')->willReturn(0.0);

		$mockEntry = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntry::class);
		$mockEntry->method('getSummary')
			->willThrowException(new \RuntimeException('summary broken'));
		$this->timeEntryMapper->method('insert')->willReturn($mockEntry);

		// safeGetSummary catch arm + clearAutoClockoutNotice catch arm
		$this->config->method('deleteUserValue')
			->willThrowException(new \RuntimeException('cfg gone'));

		$this->auditLogMapper->expects($this->once())->method('logAction');

		$result = $this->service->clockIn($userId);
		$this->assertSame($mockEntry, $result);
	}

	/**
	 * Test clocking in with invalid project throws exception
	 */
	public function testClockInWithInvalidProjectThrowsException(): void {
		$userId = 'testuser';
		$projectId = '999';

		$this->projectCheckService->expects($this->once())
			->method('userMayAttachProjectCheckProjectToOwnTime')
			->with($userId, $projectId)
			->willReturn(false);

		$this->l10n->expects($this->once())
			->method('t')
			->with($this->stringContains('cannot clock in'))
			->willReturn('You cannot clock in on the selected project.');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('You cannot clock in on the selected project.');

		$this->service->clockIn($userId, $projectId);
	}

	/**
	 * Test getting current status
	 */
	public function testGetStatus(): void {
		$userId = 'testuser';

		// Mock active entry
		$mockEntry = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$mockEntry->setId(1);
		$mockEntry->setUserId($userId);
		$mockEntry->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_ACTIVE);
		$mockEntry->setStartTime(new \DateTime()); // avoid flakiness from "now - startTime" exceeding max daily hours
		$mockEntry->setEndTime(null);
		$mockEntry->setBreaks(json_encode([]));
		$mockEntry->setIsManualEntry(false);
		$mockEntry->setCreatedAt(new \DateTime());
		$mockEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findActiveByUser')
			->with($userId)
			->willReturn($mockEntry);

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findOverlapping')
			->willReturn([$mockEntry]);

		$result = $this->service->getStatus($userId);

		$this->assertEquals('active', $result['status']);
		$this->assertEquals(0.0, $result['working_today_hours']);
		$this->assertIsBool($result['at_daily_maximum']);
		$this->assertIsFloat($result['session_hours_on_calendar_today']);
	}

	/**
	 * Test getting status when not clocked in
	 */
	public function testGetStatusWhenNotActive(): void {
		$userId = 'testuser';

		// Mock no active entry
		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findActiveByUser')
			->with($userId)
			->willReturn(null);

		// Mock no break entry
		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findOnBreakByUser')
			->with($userId)
			->willReturn(null);

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findOverlapping')
			->willReturn([]);

		$result = $this->service->getStatus($userId);

		$this->assertEquals('clocked_out', $result['status']);
		$this->assertNull($result['current_entry']);
		$this->assertEquals(0.0, $result['working_today_hours']);
		$this->assertIsBool($result['at_daily_maximum']);
		$this->assertSame(0.0, $result['session_hours_on_calendar_today']);
	}

	public function testClockInResumesPausedEntryWhenPausedEntryExists(): void
	{
		$userId = 'testuser';

		$this->timeEntryMapper->expects($this->once())
			->method('findActiveByUser')
			->with($userId)
			->willReturn(null);

		$this->timeEntryMapper->expects($this->once())
			->method('findOnBreakByUser')
			->with($userId)
			->willReturn(null);

		$start = (new \DateTime())->setTime(9, 0, 0);
		$pausedAt = (new \DateTime())->setTime(12, 0, 0);

		$pausedEntry = new TimeEntry();
		$pausedEntry->setId(123);
		$pausedEntry->setUserId($userId);
		$pausedEntry->setStatus(TimeEntry::STATUS_PAUSED);
		$pausedEntry->setStartTime($start);
		$pausedEntry->setUpdatedAt($pausedAt);
		$pausedEntry->setBreaks(json_encode([[
			'start' => $start->format('c'),
			'end' => (clone $start)->modify('+15 minutes')->format('c'),
			'duration_minutes' => 15,
			'automatic' => false,
			'reason' => 'Manual break',
		]]));
		$pausedEntry->setIsManualEntry(false);
		$pausedEntry->setCreatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->once())
			->method('findPausedOrUnfinishedTodayByUser')
			->with(
				$userId,
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class)
			)
			->willReturn($pausedEntry);

		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);

		$this->timeEntryMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static function (TimeEntry $entry): TimeEntry {
				$entry->setId(999);
				return $entry;
			});

		$this->auditLogMapper->expects($this->once())->method('logAction')->with(
			$userId,
			'clock_in',
			'time_entry',
			999,
			null,
			$this->anything()
		);

		$result = $this->service->clockIn($userId);
		$this->assertSame(999, $result->getId());
		$this->assertSame(TimeEntry::STATUS_ACTIVE, $result->getStatus());
		$this->assertNotNull($result->getBreaks());
	}

	/**
	 * Multi-project same day: clocking in with a *different* ProjectCheck project
	 * while a paused session exists must complete the paused row (keeping its
	 * original project attribution) and create a fresh entry — never overwrite.
	 */
	public function testClockInWithDifferentProjectCompletesPausedThenCreatesNew(): void
	{
		$userId = 'testuser';

		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('getTotalHoursByUserAndDateRange')->willReturn(0.0);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);

		$start = (new \DateTime())->setTime(9, 0, 0);
		$pausedAt = (new \DateTime())->setTime(12, 0, 0);

		$pausedEntry = new TimeEntry();
		$pausedEntry->setId(123);
		$pausedEntry->setUserId($userId);
		$pausedEntry->setStatus(TimeEntry::STATUS_PAUSED);
		$pausedEntry->setStartTime($start);
		$pausedEntry->setUpdatedAt($pausedAt);
		$pausedEntry->setProjectCheckProjectId('11');
		$pausedEntry->setBreaks('');
		$pausedEntry->setIsManualEntry(false);
		$pausedEntry->setCreatedAt(new \DateTime());

		$this->timeEntryMapper->method('findPausedOrUnfinishedTodayByUser')->willReturn($pausedEntry);

		$this->projectCheckService->expects($this->once())
			->method('userMayAttachProjectCheckProjectToOwnTime')
			->with($userId, '22')
			->willReturn(true);

		$completedIds = [];
		$inserted = null;
		$this->timeEntryMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static function (TimeEntry $entry) use (&$completedIds): TimeEntry {
				$completedIds[] = $entry->getId();
				return $entry;
			});
		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static function (TimeEntry $entry) use (&$inserted): TimeEntry {
				$entry->setId(456);
				$inserted = $entry;
				return $entry;
			});

		$actions = [];
		$auditLog = new \OCA\ArbeitszeitCheck\Db\AuditLog();
		$this->auditLogMapper->expects($this->exactly(2))
			->method('logAction')
			->willReturnCallback(static function (...$args) use (&$actions, $auditLog) {
				$actions[] = $args[1];
				return $auditLog;
			});

		$result = $this->service->clockIn($userId, '22');

		$this->assertSame([123], $completedIds);
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $pausedEntry->getStatus());
		$this->assertSame('11', $pausedEntry->getProjectCheckProjectId());
		$this->assertEquals($pausedAt->format('c'), $pausedEntry->getEndTime()->format('c'));
		$this->assertSame(456, $result->getId());
		$this->assertSame(TimeEntry::STATUS_ACTIVE, $result->getStatus());
		$this->assertNotNull($inserted);
		$this->assertSame('22', $inserted->getProjectCheckProjectId());
		$this->assertSame(['clock_out', 'clock_in'], $actions);
	}

	public function testClockInDoesNotResumePausedEntryWhenStampingDisabled(): void
	{
		$userId = 'testuser';

		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);

		$pausedEntry = new TimeEntry();
		$pausedEntry->setId(123);
		$pausedEntry->setUserId($userId);
		$pausedEntry->setStatus(TimeEntry::STATUS_PAUSED);
		$pausedEntry->setStartTime((new \DateTime())->setTime(9, 0, 0));
		$pausedEntry->setUpdatedAt(new \DateTime());
		$pausedEntry->setCreatedAt(new \DateTime());

		$this->timeEntryMapper->method('findPausedOrUnfinishedTodayByUser')->willReturn($pausedEntry);
		$this->timeEntryMapper->expects($this->never())->method('update');

		$timeCapture = $this->createMock(TimeCaptureMethodService::class);
		$timeCapture->expects($this->once())
			->method('assertClockStampingAllowed')
			->with($userId)
			->willThrowException(new \OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException(
				'Clock in/out (stamping) is not enabled for your account.',
				\OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException::CODE_CLOCK_STAMPING_DISABLED,
			));

		$ref = new \ReflectionClass($this->service);
		$prop = $ref->getProperty('timeCaptureMethodService');
		$prop->setAccessible(true);
		$prop->setValue($this->service, $timeCapture);

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException::class);
		$this->service->clockIn($userId);
	}

	public function testClockInWithPausedEntryFromPreviousDayStartsNewEntry(): void
	{
		$userId = 'testuser';

		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('findPausedOrUnfinishedTodayByUser')->willReturn(null);

		$startYesterday = (new \DateTime())->modify('-1 day')->setTime(9, 0, 0);
		$pausedOneHourAgo = (new \DateTime())->modify('-1 hour');

		$pausedEntry = new TimeEntry();
		$pausedEntry->setId(123);
		$pausedEntry->setUserId($userId);
		$pausedEntry->setStatus(TimeEntry::STATUS_PAUSED);
		$pausedEntry->setStartTime($startYesterday);
		$pausedEntry->setUpdatedAt($pausedOneHourAgo);
		$pausedEntry->setBreaks('');
		$pausedEntry->setIsManualEntry(false);
		$pausedEntry->setCreatedAt(new \DateTime());

		$this->timeEntryMapper->method('getTotalHoursByUserAndDateRange')->willReturn(0.0);
		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static function (TimeEntry $entry): TimeEntry {
				$entry->setId(321);
				return $entry;
			});

		$result = $this->service->clockIn($userId);
		$this->assertSame(321, $result->getId());
		$this->assertSame(TimeEntry::STATUS_ACTIVE, $result->getStatus());
	}

	public function testClockInFailsWhenMaxDailyHoursAlreadyReached(): void
	{
		$userId = 'testuser';

		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('findPausedOrUnfinishedTodayByUser')->willReturn(null);

		// Decouple from wall-clock calendar clipping (midnight flake): assert the
		// clock-in gate against an already-reached daily total.
		$dailyHours = $this->createMock(DailyWorkingHoursCalculator::class);
		$dailyHours->method('getWorkingHoursForToday')->willReturn(10.5);
		$ref = new \ReflectionClass($this->service);
		$prop = $ref->getProperty('dailyWorkingHoursCalculator');
		$prop->setAccessible(true);
		$prop->setValue($this->service, $dailyHours);

		$this->l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		$this->expectException(BusinessRuleException::class);
		$this->expectExceptionMessage('Maximum daily working hours');

		$this->service->clockIn($userId);
	}

	/**
	 * Auto-completion at the daily maximum must:
	 *  - cap the entry's end time so working hours == max,
	 *  - mark the entry as completed,
	 *  - record the audit-trail reason ENDED_REASON_AUTO_DAILY_MAX, and
	 *  - record the policy 'arbzg_daily_maximum'.
	 *
	 * Uses fixed storage-TZ instants (not wall-clock-relative offsets) so the
	 * suite is deterministic at any hour of the day.
	 */
	public function testCompleteEntryIfDailyMaximumReachedSetsAuditFields(): void
	{
		$userId = 'testuser';
		$tz = new \DateTimeZone('Europe/Berlin');

		// Same calendar day: 06:00–17:00 → 11 h on 2026-05-20, above the 10 h §3 cap.
		$start = new \DateTime('2026-05-20 06:00:00', $tz);
		$referenceNow = new \DateTime('2026-05-20 17:00:00', $tz);

		$entry = new TimeEntry();
		$entry->setId(42);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime($start);
		$entry->setEndTime(null);
		$entry->setBreaks(json_encode([]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(clone $start);
		$entry->setUpdatedAt(clone $referenceNow);

		// No other overlapping entries on today's calendar day
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$capturedEntry = null;
		$this->timeEntryMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static function (TimeEntry $arg) use (&$capturedEntry): TimeEntry {
				$capturedEntry = $arg;
				return $arg;
			});

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with(
				$userId,
				'time_entry_auto_completed_daily_max',
				'time_entry',
				42,
				$this->anything(),
				$this->anything(),
				'system'
			);

		$result = $this->service->completeEntryIfDailyMaximumReached($entry, $referenceNow);

		$this->assertTrue($result, 'Entry must be reported as auto-completed.');
		$this->assertInstanceOf(TimeEntry::class, $capturedEntry);
		/** @var TimeEntry $capturedEntry */
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $capturedEntry->getStatus());
		$this->assertSame(TimeEntry::ENDED_REASON_AUTO_DAILY_MAX, $capturedEntry->getEndedReason());
		$this->assertSame('arbzg_daily_maximum', $capturedEntry->getPolicyApplied());
		$endTime = $capturedEntry->getEndTime();
		$this->assertInstanceOf(\DateTime::class, $endTime, 'End time must be set.');

		// ArbZG §3 is evaluated per calendar day — never via raw entry span alone.
		[$dayStart, $dayEnd] = $this->timeZoneService->dayWindowInStorage($referenceNow);
		$calendarDayHours = $this->dailyHoursCalculator->getEntryWorkingHoursOnCalendarDay(
			$capturedEntry,
			$dayStart,
			$dayEnd,
			$endTime,
		);
		$this->assertLessThanOrEqual(
			10.001,
			$calendarDayHours,
			'Calendar-day working time must respect ArbZG §3 daily cap.'
		);
	}

	/**
	 * Overnight sessions must cap only today's portion (midnight clip), not the
	 * full entry span from yesterday evening.
	 */
	public function testCompleteEntryIfDailyMaximumReachedCapsOvernightSessionOnTodayOnly(): void
	{
		$userId = 'testuser';
		$tz = new \DateTimeZone('Europe/Berlin');

		// Monday 22:00 – still running; "now" is Tuesday 12:00 → 12 h on Tuesday.
		$start = new \DateTime('2026-05-19 22:00:00', $tz);
		$referenceNow = new \DateTime('2026-05-20 12:00:00', $tz);

		$entry = new TimeEntry();
		$entry->setId(43);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime($start);
		$entry->setEndTime(null);
		$entry->setBreaks(json_encode([]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(clone $start);
		$entry->setUpdatedAt(clone $referenceNow);

		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);
		$this->timeEntryMapper->expects($this->once())->method('update');
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$result = $this->service->completeEntryIfDailyMaximumReached($entry, $referenceNow);

		$this->assertTrue($result);
		$endTime = $entry->getEndTime();
		$this->assertInstanceOf(\DateTime::class, $endTime);

		[$tueStart, $tueEnd] = $this->timeZoneService->dayWindowInStorage($referenceNow);
		$tuesdayHours = $this->dailyHoursCalculator->getEntryWorkingHoursOnCalendarDay(
			$entry,
			$tueStart,
			$tueEnd,
			$endTime,
		);
		$this->assertLessThanOrEqual(10.001, $tuesdayHours, 'Tuesday portion must respect §3 cap.');

		// Entry span crosses midnight — total row duration may exceed 10 h legally.
		$entrySpanHours = $entry->getWorkingDurationHours() ?? 0.0;
		$this->assertGreaterThan(10.0, $entrySpanHours, 'Overnight row span may exceed 10 h.');
	}

	/**
	 * Below the maximum, completeEntryIfDailyMaximumReached must NOT auto-complete.
	 */
	public function testCompleteEntryIfDailyMaximumReachedIsNoOpBelowMax(): void
	{
		$userId = 'testuser';
		$tz = new \DateTimeZone('Europe/Berlin');

		$start = new \DateTime('2026-05-20 08:00:00', $tz);
		$referenceNow = new \DateTime('2026-05-20 12:00:00', $tz); // 4 h on the day

		$entry = new TimeEntry();
		$entry->setId(7);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime($start);
		$entry->setEndTime(null);
		$entry->setBreaks(json_encode([]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(clone $start);
		$entry->setUpdatedAt(clone $referenceNow);

		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		// Must not write to the database under the threshold.
		$this->timeEntryMapper->expects($this->never())->method('update');
		$this->auditLogMapper->expects($this->never())->method('logAction');

		$result = $this->service->completeEntryIfDailyMaximumReached($entry, $referenceNow);

		$this->assertFalse($result, 'Below the daily maximum, no auto-completion may occur.');
		$this->assertNull($entry->getEndedReason(), 'Reason must remain unset below the threshold.');
		$this->assertSame(TimeEntry::STATUS_ACTIVE, $entry->getStatus());
	}

	/**
	 * One-click recovery from a `paused` row must:
	 *  - flip status to COMPLETED,
	 *  - default end time to updated_at (moment the entry was frozen),
	 *  - record an audit-trail reason and policy so the row is auditable,
	 *  - persist via update() and log a `time_entry_paused_completed` audit event.
	 */
	public function testCompletePausedEntryUsesUpdatedAtAsDefaultEndTime(): void
	{
		$userId = 'testuser';
		$start = (new \DateTime())->modify('-1 day')->setTime(9, 0, 0);
		$pausedAt = (clone $start)->modify('+8 hours'); // 17:00 yesterday

		$entry = new TimeEntry();
		$entry->setId(77);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_PAUSED);
		$entry->setStartTime($start);
		$entry->setEndTime(null);
		$entry->setBreaks(json_encode([]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(clone $start);
		$entry->setUpdatedAt($pausedAt);

		$this->timeEntryMapper->expects($this->once())
			->method('find')
			->with(77)
			->willReturn($entry);

		$this->timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);

		$capturedEntry = null;
		$this->timeEntryMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static function (TimeEntry $arg) use (&$capturedEntry): TimeEntry {
				$capturedEntry = $arg;
				return $arg;
			});

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with(
				$userId,
				'time_entry_paused_completed',
				'time_entry',
				77,
				$this->anything(),
				$this->anything()
			);

		$result = $this->service->completePausedEntry($userId, 77);

		$this->assertSame($entry, $result);
		$this->assertInstanceOf(TimeEntry::class, $capturedEntry);
		/** @var TimeEntry $capturedEntry */
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $capturedEntry->getStatus());
		$this->assertNotNull($capturedEntry->getEndTime());
		$this->assertGreaterThanOrEqual($start, $capturedEntry->getEndTime(), 'End time must not be before start.');
		$this->assertNotNull($capturedEntry->getEndedReason(), 'Audit reason must be set for traceability.');
		$this->assertNotNull($capturedEntry->getPolicyApplied(), 'Policy must be set so audits can identify the recovery path.');
	}

	/**
	 * Legacy rows can be `paused` while already carrying an `end_time` (status
	 * mismatch). Completion must keep that end_time — not overwrite it with
	 * `updated_at`, which would distort payroll hours.
	 */
	public function testCompletePausedEntryPreservesExistingEndTime(): void
	{
		$userId = 'testuser';
		$start = (new \DateTime())->modify('-1 day')->setTime(9, 0, 0);
		$frozenEnd = (clone $start)->modify('+8 hours');
		$pausedAt = (clone $frozenEnd)->modify('+30 minutes');

		$entry = new TimeEntry();
		$entry->setId(78);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_PAUSED);
		$entry->setStartTime($start);
		$entry->setEndTime($frozenEnd);
		$entry->setBreaks(json_encode([]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(clone $start);
		$entry->setUpdatedAt($pausedAt);

		$this->timeEntryMapper->method('find')->with(78)->willReturn($entry);
		$this->timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);

		$capturedEntry = null;
		$this->timeEntryMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static function (TimeEntry $arg) use (&$capturedEntry): TimeEntry {
				$capturedEntry = $arg;
				return $arg;
			});

		$this->auditLogMapper->expects($this->once())->method('logAction');

		$this->service->completePausedEntry($userId, 78);

		$this->assertInstanceOf(TimeEntry::class, $capturedEntry);
		/** @var TimeEntry $capturedEntry */
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $capturedEntry->getStatus());
		$this->assertEquals($frozenEnd, $capturedEntry->getEndTime());
	}

	/**
	 * An explicit caller-supplied end time (e.g. from an admin or `occ` command)
	 * must win over the default updated_at, but the service must still enforce
	 * `end >= start` so a bad override cannot create a negative duration row.
	 */
	public function testCompletePausedEntryRespectsExplicitEndTime(): void
	{
		$userId = 'testuser';
		$start = (new \DateTime())->modify('-1 day')->setTime(9, 0, 0);
		$pausedAt = (clone $start)->modify('+2 hours');
		$explicit = (clone $start)->modify('+7 hours 30 minutes'); // 16:30

		$entry = new TimeEntry();
		$entry->setId(88);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_PAUSED);
		$entry->setStartTime($start);
		$entry->setEndTime(null);
		$entry->setBreaks(json_encode([]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(clone $start);
		$entry->setUpdatedAt($pausedAt);

		$this->timeEntryMapper->method('find')->with(88)->willReturn($entry);
		$this->timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);

		$capturedEntry = null;
		$this->timeEntryMapper->expects($this->once())
			->method('update')
			->willReturnCallback(static function (TimeEntry $arg) use (&$capturedEntry): TimeEntry {
				$capturedEntry = $arg;
				return $arg;
			});

		$this->auditLogMapper->expects($this->once())->method('logAction');

		$this->service->completePausedEntry($userId, 88, $explicit);

		$this->assertInstanceOf(TimeEntry::class, $capturedEntry);
		/** @var TimeEntry $capturedEntry */
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $capturedEntry->getStatus());
		$captured = $capturedEntry->getEndTime();
		$this->assertNotNull($captured);
		// Daily-max adjustment may cap the end, but it must never come out earlier than the start.
		$this->assertGreaterThanOrEqual($start, $captured);
	}

	/**
	 * Completing an entry the caller does not own must be rejected with a
	 * business-rule error so the controller can map it to HTTP 403.
	 */
	public function testCompletePausedEntryRejectsOtherUsersEntry(): void
	{
		$entry = new TimeEntry();
		$entry->setId(99);
		$entry->setUserId('owner');
		$entry->setStatus(TimeEntry::STATUS_PAUSED);
		$entry->setStartTime(new \DateTime('-2 hours'));
		$entry->setUpdatedAt(new \DateTime('-1 hour'));
		$entry->setBreaks(json_encode([]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime('-2 hours'));

		$this->timeEntryMapper->method('find')->with(99)->willReturn($entry);
		$this->timeEntryMapper->expects($this->never())->method('update');
		$this->auditLogMapper->expects($this->never())->method('logAction');

		$this->l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		$this->expectException(\OCP\AppFramework\Db\DoesNotExistException::class);
		$this->expectExceptionMessage('Time entry not found');

		$this->service->completePausedEntry('intruder', 99);
	}

	/**
	 * Completing a non-paused entry must be rejected: this endpoint is the
	 * dedicated recovery path for the broken `paused` state and must not be
	 * abused to silently re-finalise already-completed rows.
	 */
	public function testCompletePausedEntryRejectsNonPausedStatus(): void
	{
		$entry = new TimeEntry();
		$entry->setId(101);
		$entry->setUserId('testuser');
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime(new \DateTime('-2 hours'));
		$entry->setBreaks(json_encode([]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime('-2 hours'));
		$entry->setUpdatedAt(new \DateTime('-1 hour'));

		$this->timeEntryMapper->method('find')->with(101)->willReturn($entry);
		$this->timeEntryMapper->expects($this->never())->method('update');
		$this->auditLogMapper->expects($this->never())->method('logAction');

		$this->l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\BusinessRuleException::class);
		$this->expectExceptionMessage('not in a paused state');

		$this->service->completePausedEntry('testuser', 101);
	}

	/**
	 * A negative or zero entry ID must be rejected up front. Without this guard
	 * the mapper would issue a SELECT WHERE id=0 (or worse, attempt index lookup
	 * by 0/-1) and the user would receive an opaque NOT FOUND that obscures the
	 * actual programmer error.
	 */
	public function testCompletePausedEntryRejectsInvalidId(): void
	{
		$this->l10n->method('t')->willReturnCallback(static fn ($s) => $s);
		$this->timeEntryMapper->expects($this->never())->method('find');

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\BusinessRuleException::class);
		$this->expectExceptionMessage('Invalid entry ID');

		$this->service->completePausedEntry('testuser', 0);
	}

	public function testStartBreakTransitionsActiveEntry(): void
	{
		$this->l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		$entry = new TimeEntry();
		$entry->setId(7);
		$entry->setUserId('alice');
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime(new \DateTime('-2 hours'));

		$this->timeEntryMapper->method('findActiveByUser')->willReturn($entry);
		$this->timeEntryMapper->expects($this->once())->method('update')
			->willReturnArgument(0);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$this->lockingProvider->expects($this->once())->method('acquireLock');
		$this->lockingProvider->expects($this->once())->method('releaseLock');
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'start_break', 'time_entry', 7, self::anything(), self::anything());

		$result = $this->service->startBreak('alice');
		$this->assertSame(TimeEntry::STATUS_BREAK, $result->getStatus());
		$this->assertNotNull($result->getBreakStartTime());
		$this->assertNull($result->getBreakEndTime());
	}

	public function testStartBreakWithoutActiveEntryThrows(): void
	{
		$this->l10n->method('t')->willReturnCallback(static fn ($s) => $s);
		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->db->expects($this->once())->method('rollBack');

		$this->expectException(BusinessRuleException::class);
		$this->expectExceptionMessage('User is not currently clocked in');
		$this->service->startBreak('alice');
	}

	public function testStartBreakWhileOnBreakThrows(): void
	{
		$this->l10n->method('t')->willReturnCallback(static fn ($s) => $s);
		$entry = new TimeEntry();
		$entry->setId(7);
		$entry->setUserId('alice');
		$entry->setStatus(TimeEntry::STATUS_BREAK);
		$entry->setStartTime(new \DateTime('-2 hours'));
		$entry->setBreakStartTime(new \DateTime('-30 minutes'));

		$this->timeEntryMapper->method('findActiveByUser')->willReturn($entry);
		$this->db->expects($this->once())->method('rollBack');

		$this->expectException(BusinessRuleException::class);
		$this->service->startBreak('alice');
	}

	public function testStartBreakArchivesPreviousCompletedBreak(): void
	{
		$this->l10n->method('t')->willReturnCallback(static fn ($s) => $s);
		$entry = new TimeEntry();
		$entry->setId(7);
		$entry->setUserId('alice');
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime(new \DateTime('-3 hours'));
		$entry->setBreakStartTime(new \DateTime('-2 hours'));
		$entry->setBreakEndTime(new \DateTime('-90 minutes'));

		$this->timeEntryMapper->method('findActiveByUser')->willReturn($entry);
		$this->timeEntryMapper->method('update')->willReturnArgument(0);

		$result = $this->service->startBreak('alice');
		$this->assertSame(TimeEntry::STATUS_BREAK, $result->getStatus());
		$this->assertNull($result->getBreakEndTime());
	}

	public function testEndBreakTransitionsBreakEntryToActive(): void
	{
		$this->l10n->method('t')->willReturnCallback(static fn ($s) => $s);
		$entry = new TimeEntry();
		$entry->setId(7);
		$entry->setUserId('alice');
		$entry->setStatus(TimeEntry::STATUS_BREAK);
		$entry->setStartTime(new \DateTime('-2 hours'));
		$entry->setBreakStartTime(new \DateTime('-30 minutes'));

		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn($entry);
		$this->timeEntryMapper->expects($this->once())->method('update')
			->willReturnArgument(0);
		$this->db->expects($this->once())->method('commit');
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'end_break', 'time_entry', 7, self::anything(), self::anything());

		$result = $this->service->endBreak('alice');
		$this->assertSame(TimeEntry::STATUS_ACTIVE, $result->getStatus());
		$this->assertNotNull($result->getBreakEndTime());
	}

	public function testEndBreakWithoutBreakThrows(): void
	{
		$this->l10n->method('t')->willReturnCallback(static fn ($s) => $s);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->db->expects($this->once())->method('rollBack');

		$this->expectException(BusinessRuleException::class);
		$this->service->endBreak('alice');
	}

	private function completedEntryToday(int $id, string $start, string $end): TimeEntry
	{
		$tz = new \DateTimeZone('Europe/Berlin');
		$entry = new TimeEntry();
		$entry->setId($id);
		$entry->setUserId('alice');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime($start, $tz));
		$entry->setEndTime(new \DateTime($end, $tz));
		return $entry;
	}

	public function testGetStatusReturnsPausedPayloadForPausedEntry(): void
	{
		$entry = new TimeEntry();
		$entry->setId(9);
		$entry->setUserId('alice');
		$entry->setStatus(TimeEntry::STATUS_PAUSED);
		$entry->setStartTime(new \DateTime('-3 hours'));
		$entry->setUpdatedAt(new \DateTime('-1 hour'));

		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('findPausedOrUnfinishedTodayByUser')->willReturn($entry);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);

		$status = $this->service->getStatus('alice');
		$this->assertSame(TimeEntry::STATUS_PAUSED, $status['status']);
		$this->assertSame(9, $status['current_entry']['id']);
		$this->assertArrayHasKey('server_now', $status);
		$this->assertArrayHasKey('at_daily_maximum', $status);
	}

	public function testGetStatusReturnsActivePayloadWithSessionDuration(): void
	{
		$entry = new TimeEntry();
		$entry->setId(8);
		$entry->setUserId('alice');
		$entry->setStatus(TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime(new \DateTime('-2 hours'));

		$this->timeEntryMapper->method('findActiveByUser')->willReturn($entry);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$status = $this->service->getStatus('alice');
		$this->assertSame(TimeEntry::STATUS_ACTIVE, $status['status']);
		$this->assertGreaterThan(0, $status['current_session_duration']);
		$this->assertSame('Europe/Berlin', $status['server_timezone']);
	}

	public function testGetStatusIncludesAutoClockoutNoticeWhenPresent(): void
	{
		$this->config->method('getUserValue')->willReturnCallback(
			static fn (string $u, string $app, string $key, $d = '') =>
				$key === 'auto_clockout_notice' ? '{"at":"2026-09-01T10:00:00+02:00","reason":"auto"}' : $d
		);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);
		$this->timeEntryMapper->method('findPausedOrUnfinishedTodayByUser')->willReturn(null);

		$status = $this->service->getStatus('alice');
		$this->assertSame('clocked_out', $status['status']);
	}

	public function testGetTodayHoursSumsOverlappingEntries(): void
	{
		// Fixed same-day window: 'today 00:15→08:15' is in the future when the
		// suite runs before 08:15 (midnight flake — entry counted 0h then).
		$tz = new \DateTimeZone('Europe/Berlin');
		$now = new \DateTime('now', $tz);
		$start = (clone $now)->setTime(0, 0, 0);
		$end = (clone $now)->setTime(0, 30, 0);
		if ($now <= $end) {
			$this->markTestSkipped('Fixed same-day window is not yet fully in the past.');
		}
		$entry = $this->completedEntryToday(7, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$this->assertEqualsWithDelta(0.5, $this->service->getTodayHours('alice'), 0.05);
	}

	public function testGetWorkingHoursForPeriodSumsAcrossDays(): void
	{
		$entry = $this->completedEntryToday(7, '2026-09-01 08:00', '2026-09-01 16:00');
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$total = $this->service->getWorkingHoursForPeriod(
			'alice',
			new \DateTime('2026-09-01'),
			new \DateTime('2026-09-03'),
		);
		$this->assertEqualsWithDelta(8.0, $total, 0.2);
	}

	public function testCalculateTakenBreakMinutesSumsBreaks(): void
	{
		$entry = $this->completedEntryToday(7, 'today 00:15', 'today 08:15');
		$entry->setBreakStartTime(new \DateTime('today 03:00', new \DateTimeZone('Europe/Berlin')));
		$entry->setBreakEndTime(new \DateTime('today 03:30', new \DateTimeZone('Europe/Berlin')));
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$this->assertEqualsWithDelta(30.0, $this->service->calculateTakenBreakMinutes('alice'), 0.5);
	}

	public function testGetBreakStatusReportsRequiredBreak(): void
	{
		// Break-required needs >6h completed today — 'today 00:15→23:45' is a
		// future entry before 23:45 and counts 0h then (midnight flake).
		$tz = new \DateTimeZone('Europe/Berlin');
		$now = new \DateTime('now', $tz);
		$start = (clone $now)->setTime(0, 0, 0);
		$end = (clone $now)->setTime(6, 30, 0);
		if ($now <= $end) {
			$this->markTestSkipped('Cannot express >6h of completed today-work this early in the day.');
		}
		$entry = $this->completedEntryToday(7, $start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s'));
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$status = $this->service->getBreakStatus('alice');
		$this->assertTrue($status['break_required']);
		$this->assertGreaterThan(0, $status['required_break_minutes']);
		$this->assertContains($status['warning_level'], ['none', 'info', 'warning', 'critical']);
	}

	public function testGetBreakStatusEmptyHistoryIsNone(): void
	{
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);
		$status = $this->service->getBreakStatus('alice');
		$this->assertFalse($status['break_required']);
		$this->assertSame('none', $status['warning_level']);
	}

	public function testFindCalendarDayExceedingMaximumNullForIncompleteEntry(): void
	{
		$entry = new TimeEntry();
		$entry->setId(7);
		$entry->setUserId('alice');
		$this->assertNull($this->service->findCalendarDayExceedingMaximum($entry));
	}

	public function testFindCalendarDayExceedingMaximumDelegatesToCalculator(): void
	{
		$entry = $this->completedEntryToday(7, 'today 00:15', 'today 23:45');
		// the entry itself plus a second overlapping entry push the day past 10h
		$other = $this->completedEntryToday(8, 'today 01:00', 'today 11:00');
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry, $other]);

		$result = $this->service->findCalendarDayExceedingMaximum($entry);
		$this->assertNotNull($result);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $result['date']);
		$this->assertGreaterThan(10.0, $result['hours']);
	}

	// ---------------------------------------------------------------
	// enforceBreakAutoFallbackForUser / enforceDailyMaximumForUser
	// ---------------------------------------------------------------

	private function breakEntry(int $id, int $breakStartMinutesAgo): \OCA\ArbeitszeitCheck\Db\TimeEntry
	{
		$e = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$e->setId($id);
		$e->setUserId('u1');
		$e->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_BREAK);
		$e->setStartTime(new \DateTime('-8 hours'));
		$e->setBreakStartTime(new \DateTime("-{$breakStartMinutesAgo} minutes"));
		return $e;
	}

	public function testBreakAutoFallbackNoBreakEntryIsNoop(): void
	{
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->assertNull($this->service->enforceBreakAutoFallbackForUser('u1'));
	}

	public function testBreakAutoFallbackDisabledReturnsEntry(): void
	{
		$entry = $this->breakEntry(5, 500);
		$this->config->method('getAppValue')->willReturnCallback(fn ($a, $k, $d) => $k === 'break_auto_fallback_enabled' ? '0' : $d);
		$this->timeEntryMapper->expects($this->never())->method('update');
		$this->assertSame($entry, $this->service->enforceBreakAutoFallbackForUser('u1', $entry));
	}

	public function testBreakAutoFallbackUnderThresholdReturnsEntry(): void
	{
		$this->shiftWorkModelFor('u1');
		$entry = $this->breakEntry(5, 10); // 10 min < 120 strict-shift threshold
		$this->timeEntryMapper->expects($this->never())->method('update');
		$this->assertSame($entry, $this->service->enforceBreakAutoFallbackForUser('u1', $entry));
	}

	private function shiftWorkModelFor(string $userId): void
	{
		// strict_shift mode bypasses the flex-window gate entirely, so the
		// over-threshold path is deterministic regardless of wall-clock time.
		$assignment = new \OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel();
		$assignment->setWorkingTimeModelId(7);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn($assignment);
		$model = new \OCA\ArbeitszeitCheck\Db\WorkingTimeModel();
		$model->setType(\OCA\ArbeitszeitCheck\Db\WorkingTimeModel::TYPE_SHIFT_WORK);
		$this->workingTimeModelMapper->method('find')->with(7)->willReturn($model);
	}

	public function testBreakAutoFallbackOverThresholdClocksOut(): void
	{
		$this->shiftWorkModelFor('u1');
		$entry = $this->breakEntry(5, 300); // 300 min > 120 strict-shift default
		$this->config->expects($this->once())->method('setUserValue')
			->with('u1', 'arbeitszeitcheck', 'auto_clockout_notice', $this->isType('string'));
		// clockOut re-fetches the break entry and completes it
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn($entry);
		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('update')->willReturnArgument(0);

		$result = $this->service->enforceBreakAutoFallbackForUser('u1', $entry);
		$this->assertNull($result);
		$this->assertSame(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED, $entry->getStatus());
		$this->assertSame(\OCA\ArbeitszeitCheck\Db\TimeEntry::ENDED_REASON_AUTO_BREAK_FALLBACK, $entry->getEndedReason());
	}

	public function testBreakAutoFallbackFailureReturnsEntry(): void
	{
		$this->shiftWorkModelFor('u1');
		$entry = $this->breakEntry(5, 300);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn($entry);
		$this->monthClosureGuard->method('assertTimeEntryMutable')
			->willThrowException(new \RuntimeException('month closed'));

		$this->assertSame($entry, $this->service->enforceBreakAutoFallbackForUser('u1', $entry));
	}

	public function testEnforceDailyMaximumNoActiveEntryIsNoop(): void
	{
		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->db->expects($this->once())->method('commit');
		$this->assertNull($this->service->enforceDailyMaximumForUser('u1'));
	}

	public function testEnforceDailyMaximumCompletesOverMaxSession(): void
	{
		// active session started 11h ago — over the 10h configured maximum
		$entry = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$entry->setId(9);
		$entry->setUserId('u1');
		$entry->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_ACTIVE);
		// Overnight sessions are clipped at midnight — a '-11 hours' start counts
		// only today's portion, which is under the 10h maximum before ~10:10.
		// Storage/window math runs in Europe/Berlin — the PHPUnit bootstrap TZ is UTC.
		$tz = new \DateTimeZone('Europe/Berlin');
		$now = new \DateTime('now', $tz);
		$threshold = (clone $now)->setTime(10, 10, 0);
		if ($now < $threshold) {
			$this->markTestSkipped('Session cannot exceed the daily maximum this early in the day.');
		}
		$entry->setStartTime((clone $now)->setTime(0, 0, 0));

		$this->timeEntryMapper->method('findActiveByUser')->willReturn($entry);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);
		$this->timeEntryMapper->method('find')->with(9)->willReturn($entry);
		$this->timeEntryMapper->method('update')->willReturnArgument(0);
		$this->config->expects($this->once())->method('setUserValue')
			->with('u1', 'arbeitszeitcheck', 'auto_clockout_notice', $this->isType('string'));

		$updated = $this->service->enforceDailyMaximumForUser('u1');
		$this->assertSame($entry, $updated);
		$this->assertSame(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED, $entry->getStatus());
		$this->assertSame(\OCA\ArbeitszeitCheck\Db\TimeEntry::ENDED_REASON_AUTO_DAILY_MAX, $entry->getEndedReason());
		$this->assertNotNull($entry->getEndTime());
	}

	public function testEnforceDailyMaximumLeavesUnderMaxSessionRunning(): void
	{
		$entry = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$entry->setId(9);
		$entry->setUserId('u1');
		$entry->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_ACTIVE);
		$entry->setStartTime(new \DateTime('-2 hours'));

		$this->timeEntryMapper->method('findActiveByUser')->willReturn($entry);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);
		$this->timeEntryMapper->expects($this->never())->method('update');
		$this->config->expects($this->never())->method('setUserValue');

		$this->assertNull($this->service->enforceDailyMaximumForUser('u1'));
		$this->assertSame(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_ACTIVE, $entry->getStatus());
	}

	public function testGetTodayHoursReturnsCalculatorValueAndZeroOnFailure(): void
	{
		$entry = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$entry->setUserId('u1');
		$entry->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED);
		// Fixed same-day window [00:00, 00:30] — a window derived from two separate
		// "now" reads races across midnight (expected computed before, service
		// evaluated after) — deterministic regardless of wall clock.
		$tz = new \DateTimeZone('Europe/Berlin');
		$now = new \DateTime('now', $tz);
		$start = new \DateTime('today 00:00:00', $tz);
		$end = new \DateTime('today 00:30:00', $tz);
		if ($now <= $end) {
			$this->markTestSkipped('Fixed same-day window is not yet fully in the past.');
		}
		$expected = 0.5;
		$entry->setStartTime($start);
		$entry->setEndTime($end);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);
		$this->assertEqualsWithDelta($expected, $this->service->getTodayHours('u1'), 0.01);

		// mapper failure -> defensive 0.0
		$mapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class);
		$mapper->method('findOverlapping')->willThrowException(new \RuntimeException('db down'));
		$svc = new TimeTrackingService(
			$mapper,
			$this->violationMapper,
			$this->auditLogMapper,
			$this->projectCheckService,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ComplianceService::class),
			$this->l10n,
			$this->config,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
			$this->monthClosureGuard,
			$this->db,
			$this->lockingProvider,
			$this->timeZoneService,
			new \OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator($mapper, $this->timeZoneService),
			null,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService::class),
		);
		$this->assertSame(0.0, $svc->getTodayHours('u1'));
	}

	public function testAdjustEndTimeForDailyMaximumCapsLongEntry(): void
	{
		// 12h entry today exceeds the 10h max -> end clipped to start+10h
		$entry = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$entry->setId(3);
		$entry->setUserId('u1');
		$entry->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('today 07:00'));
		$entry->setEndTime(new \DateTime('today 19:00'));

		// no other entries today; calculator sees only this entry
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$entry]);

		$this->assertTrue($this->service->adjustEndTimeForDailyMaximum($entry));
		$adjusted = $entry->getEndTime();
		$worked = ($adjusted->getTimestamp() - $entry->getStartTime()->getTimestamp()) / 3600.0;
		$this->assertEqualsWithDelta(10.0, $worked, 0.02);
	}

	public function testAdjustEndTimeForDailyMaximumNoops(): void
	{
		// missing times -> false
		$empty = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$empty->setUserId('u1');
		$this->assertFalse($this->service->adjustEndTimeForDailyMaximum($empty));

		// short entry under the cap -> false (no adjustment needed)
		$short = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$short->setId(4);
		$short->setUserId('u1');
		$short->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED);
		$short->setStartTime(new \DateTime('today 08:00'));
		$short->setEndTime(new \DateTime('today 10:00'));
		$this->timeEntryMapper->method('findOverlapping')->willReturn([$short]);
		$this->assertFalse($this->service->adjustEndTimeForDailyMaximum($short));
	}

	public function testClockInRepairsStalePausedEntryThenInserts(): void
	{
		// a stale paused entry from yesterday gets auto-repaired during clockIn
		$stale = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
		$stale->setId(77);
		$stale->setUserId('u1');
		$stale->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PAUSED);
		$stale->setStartTime(new \DateTime('yesterday 09:00'));
		$stale->setUpdatedAt(new \DateTime('yesterday 17:30'));

		$this->stalePausedEntries = [$stale];
		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('findPendingOpenSessionByUser')->willReturn(null);
		$this->timeEntryMapper->method('findPausedOrUnfinishedTodayByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);
		$this->timeEntryMapper->method('update')->willReturnArgument(0);
		$this->timeEntryMapper->method('insert')->willReturnCallback(static function (\OCA\ArbeitszeitCheck\Db\TimeEntry $e) {
			$e->setId(100);
			return $e;
		});
		// notice cleared on fresh session
		$this->config->expects($this->once())->method('deleteUserValue')
			->with('u1', 'arbeitszeitcheck', 'auto_clockout_notice');
		// repair + clock_in both audited
		$this->auditLogMapper->expects($this->exactly(2))->method('logAction');

		$saved = $this->service->clockIn('u1');
		$this->assertSame(100, $saved->getId());
		$this->assertSame(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_ACTIVE, $saved->getStatus());
		// stale paused row was repaired
		$this->assertSame(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED, $stale->getStatus());
		$this->assertSame(\OCA\ArbeitszeitCheck\Db\TimeEntry::ENDED_REASON_STALE_PAUSED_REPAIR, $stale->getEndedReason());
		$this->assertNotNull($stale->getEndTime());
	}

	public function testClockInBlockedByCriticalComplianceIssue(): void
	{
		$compliance = $this->createMock(\OCA\ArbeitszeitCheck\Service\ComplianceService::class);
		$compliance->method('checkComplianceBeforeClockIn')->willReturn([
			[
				'severity' => 'error',
				'type' => \OCA\ArbeitszeitCheck\Db\ComplianceViolation::TYPE_INSUFFICIENT_REST_PERIOD,
				'message' => 'Rest period not met',
				'details' => ['required_hours' => 11],
			],
		]);

		$svc = new TimeTrackingService(
			$this->timeEntryMapper,
			$this->violationMapper,
			$this->auditLogMapper,
			$this->projectCheckService,
			$compliance,
			$this->l10n,
			$this->config,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
			$this->monthClosureGuard,
			$this->db,
			$this->lockingProvider,
			$this->timeZoneService,
			$this->dailyHoursCalculator,
			null,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService::class),
		);

		$this->stalePausedEntries = [];
		$this->timeEntryMapper->method('findStalePausedAutomaticEntries')
			->willReturnCallback(fn () => $this->stalePausedEntries);
		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('findPendingOpenSessionByUser')->willReturn(null);
		$this->timeEntryMapper->method('findPausedOrUnfinishedTodayByUser')->willReturn(null);

		try {
			$svc->clockIn('u1');
			$this->fail('expected BusinessRuleException');
		} catch (\OCA\ArbeitszeitCheck\Exception\BusinessRuleException $e) {
			$this->assertSame('Rest period not met', $e->getMessage());
			$this->assertSame(BusinessRuleCode::REST_PERIOD_REQUIRED, $e->getReasonCode());
		}
	}

	public function testGetStatusAppendsFreshAutoClockoutNotice(): void
	{
		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('findPausedOrUnfinishedTodayByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);
		$notice = json_encode([
			'message' => 'Auto clock-out at 18:00',
			'reason' => 'daily_maximum_reached',
			'at' => (new \DateTimeImmutable('-1 hour'))->format('c'),
		]);
		$this->noticeJson = $notice;

		$status = $this->service->getStatus('u1');
		$this->assertSame('daily_maximum_reached', $status['auto_clockout_notice']['reason']);
	}

	public function testGetStatusDropsStaleNoticeOver24h(): void
	{
		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('findPausedOrUnfinishedTodayByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);
		$notice = json_encode([
			'message' => 'old',
			'reason' => 'daily_maximum_reached',
			'at' => (new \DateTimeImmutable('-2 days'))->format('c'),
		]);
		$this->noticeJson = $notice;

		$status = $this->service->getStatus('u1');
		$this->assertArrayNotHasKey('auto_clockout_notice', $status);
	}

	public function testReleaseLockFailureDoesNotEscapeMutation(): void
	{
		// releaseLock throwing must not propagate — the mutation itself succeeded
		$this->lockingProvider->method('releaseLock')
			->willThrowException(new \RuntimeException('lock backend gone'));
		$this->timeEntryMapper->method('findActiveByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOnBreakByUser')->willReturn(null);
		$this->timeEntryMapper->method('findPendingOpenSessionByUser')->willReturn(null);
		$this->timeEntryMapper->method('findPausedOrUnfinishedTodayByUser')->willReturn(null);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);
		$this->timeEntryMapper->method('insert')->willReturnCallback(static function (\OCA\ArbeitszeitCheck\Db\TimeEntry $e) {
			$e->setId(5);
			return $e;
		});

		$saved = $this->service->clockIn('u1');
		$this->assertSame(5, $saved->getId());
	}
}