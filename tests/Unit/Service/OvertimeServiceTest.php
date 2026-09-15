<?php

declare(strict_types=1);

/**
 * Unit tests for OvertimeService
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Class OvertimeServiceTest
 */
class OvertimeServiceTest extends TestCase
{
	/** @var OvertimeService */
	private $service;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var WorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $workingTimeModelMapper;

	/** @var UserWorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userWorkingTimeModelMapper;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var HolidayService|\PHPUnit\Framework\MockObject\MockObject */
	private $holidayCalendarService;

	/** @var UserOvertimeSettingsService|\PHPUnit\Framework\MockObject\MockObject */
	private $overtimeSettingsService;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->holidayCalendarService = $this->createMock(HolidayService::class);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(5.0);
		$this->overtimeSettingsService = $this->createMock(UserOvertimeSettingsService::class);
		$this->overtimeSettingsService->method('getTrackingFrom')->willReturn(null);
		$this->overtimeSettingsService->method('getOpeningBalanceHours')->willReturn(0.0);
		$this->overtimeSettingsService->method('resolveEffectiveYearStart')->willReturnCallback(
			static function (string $userId, int $year): \DateTime {
				return new \DateTime(sprintf('%04d-01-01 00:00:00', $year));
			}
		);

		$this->service = new OvertimeService(
			$this->timeEntryMapper,
			$this->workingTimeModelMapper,
			$this->userWorkingTimeModelMapper,
			$this->l10n,
			$this->holidayCalendarService,
			$this->overtimeSettingsService
		);
	}

	/**
	 * Test calculateOvertime with no working time model (uses defaults)
	 */
	public function testCalculateOvertimeWithDefaults(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-07'); // One week

		// Mock no working time model assigned
		$this->userWorkingTimeModelMapper->expects($this->atLeastOnce())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock time entries - 5 working days, 8 hours each = 40 hours
		$entries = [];
		for ($i = 1; $i <= 5; $i++) {
			$entry = new TimeEntry();
			$entry->setId($i);
			$entry->setUserId($userId);
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
			$entry->setStartTime(new \DateTime("2024-01-0$i 08:00:00"));
			$entry->setEndTime(new \DateTime("2024-01-0$i 17:00:00"));
			$entry->setBreaks(json_encode([[
				'start' => "2024-01-0{$i}T12:00:00+00:00",
				'end' => "2024-01-0{$i}T13:00:00+00:00",
			]]));
			$entry->setIsManualEntry(false);
			$entry->setCreatedAt(new \DateTime());
			$entry->setUpdatedAt(new \DateTime());
			$entries[] = $entry;
		}

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUserAndDateRange')
			->willReturn($entries);

		$result = $this->service->calculateOvertime($userId, $startDate, $endDate);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('total_hours_worked', $result);
		$this->assertArrayHasKey('required_hours', $result);
		$this->assertArrayHasKey('overtime_hours', $result);
		$this->assertEquals(40.0, $result['total_hours_worked']);
		// Required hours should be approximately 40 hours for 5 working days in a week
		$this->assertGreaterThan(0, $result['required_hours']);
		$this->assertEquals(8.0, $result['daily_hours']);
		$this->assertEquals(40.0, $result['weekly_hours']);
	}

	/**
	 * Test calculateOvertime with overtime (worked more than required)
	 */
	public function testCalculateOvertimeWithOvertime(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-07'); // One week

		// Mock no working time model (defaults to 40 hours/week)
		$this->userWorkingTimeModelMapper->expects($this->atLeastOnce())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock time entries - 5 working days, 9 hours each = 45 hours (5 hours overtime)
		$entries = [];
		for ($i = 1; $i <= 5; $i++) {
			$entry = new TimeEntry();
			$entry->setId($i);
			$entry->setUserId($userId);
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
			$entry->setStartTime(new \DateTime("2024-01-0$i 08:00:00")); // total 10h
			$entry->setEndTime(new \DateTime("2024-01-0$i 18:00:00"));
			$entry->setBreaks(json_encode([[
				'start' => "2024-01-0{$i}T12:00:00+00:00",
				'end' => "2024-01-0{$i}T13:00:00+00:00",
			]]));
			$entry->setIsManualEntry(false);
			$entry->setCreatedAt(new \DateTime());
			$entry->setUpdatedAt(new \DateTime());
			$entries[] = $entry;
		}

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUserAndDateRange')
			->willReturn($entries);

		$result = $this->service->calculateOvertime($userId, $startDate, $endDate);

		$this->assertEquals(45.0, $result['total_hours_worked']);
		// Overtime should be positive (worked more than required)
		$this->assertGreaterThan(0, $result['overtime_hours']);
	}

	/**
	 * Test calculateOvertime with undertime (worked less than required)
	 */
	public function testCalculateOvertimeWithUndertime(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-07'); // One week

		// Mock no working time model (defaults to 40 hours/week)
		$this->userWorkingTimeModelMapper->expects($this->atLeastOnce())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock time entries - 5 working days, 7 hours each = 35 hours (5 hours undertime)
		$entries = [];
		for ($i = 1; $i <= 5; $i++) {
			$entry = new TimeEntry();
			$entry->setId($i);
			$entry->setUserId($userId);
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
			$entry->setStartTime(new \DateTime("2024-01-0$i 08:00:00")); // total 8h
			$entry->setEndTime(new \DateTime("2024-01-0$i 16:00:00"));
			$entry->setBreaks(json_encode([[
				'start' => "2024-01-0{$i}T12:00:00+00:00",
				'end' => "2024-01-0{$i}T13:00:00+00:00",
			]]));
			$entry->setIsManualEntry(false);
			$entry->setCreatedAt(new \DateTime());
			$entry->setUpdatedAt(new \DateTime());
			$entries[] = $entry;
		}

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUserAndDateRange')
			->willReturn($entries);

		$result = $this->service->calculateOvertime($userId, $startDate, $endDate);

		$this->assertEquals(35.0, $result['total_hours_worked']);
		// Overtime should be negative (worked less than required)
		$this->assertLessThan(0, $result['overtime_hours']);
	}

	/**
	 * Test calculateOvertime with custom working time model
	 */
	public function testCalculateOvertimeWithCustomModel(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-07'); // One week

		// Mock custom working time model (part-time: 6 hours/day, 30 hours/week)
		$userModel = new UserWorkingTimeModel();
		$userModel->setId(1);
		$userModel->setUserId($userId);
		$userModel->setWorkingTimeModelId(1);
		$userModel->setCreatedAt(new \DateTime());
		$userModel->setUpdatedAt(new \DateTime());

		$model = new WorkingTimeModel();
		$model->setId(1);
		$model->setName('Part-time');
		$model->setDailyHours(6.0);
		$model->setWeeklyHours(30.0);
		$model->setCreatedAt(new \DateTime());
		$model->setUpdatedAt(new \DateTime());

		$this->userWorkingTimeModelMapper->expects($this->atLeastOnce())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn($userModel);

		$this->workingTimeModelMapper->expects($this->atLeastOnce())
			->method('find')
			->with(1)
			->willReturn($model);

		// Mock time entries - 5 working days, 6 hours each = 30 hours
		$entries = [];
		for ($i = 1; $i <= 5; $i++) {
			$entry = new TimeEntry();
			$entry->setId($i);
			$entry->setUserId($userId);
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
			$entry->setStartTime(new \DateTime("2024-01-0$i 08:00:00")); // total 7h
			$entry->setEndTime(new \DateTime("2024-01-0$i 15:00:00"));
			$entry->setBreaks(json_encode([[
				'start' => "2024-01-0{$i}T12:00:00+00:00",
				'end' => "2024-01-0{$i}T13:00:00+00:00",
			]]));
			$entry->setIsManualEntry(false);
			$entry->setCreatedAt(new \DateTime());
			$entry->setUpdatedAt(new \DateTime());
			$entries[] = $entry;
		}

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUserAndDateRange')
			->willReturn($entries);

		$result = $this->service->calculateOvertime($userId, $startDate, $endDate);

		$this->assertEquals(30.0, $result['total_hours_worked']);
		$this->assertEquals(6.0, $result['daily_hours']);
		$this->assertEquals(30.0, $result['weekly_hours']);
		$this->assertEquals(6.0, $result['implied_daily_hours']);
		$this->assertSame('weekly_contract', $result['required_hours_basis']);
		// Should be approximately balanced (worked exactly what's required)
		$this->assertLessThan(1.0, abs($result['overtime_hours']));
	}

	/**
	 * Test calculateOvertime excludes non-completed entries
	 */
	public function testCalculateOvertimeExcludesNonCompleted(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-07');

		// Mock no working time model
		$this->userWorkingTimeModelMapper->expects($this->atLeastOnce())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock entries: one completed, one active, one pending
		$completedEntry = new TimeEntry();
		$completedEntry->setId(1);
		$completedEntry->setUserId($userId);
		$completedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$completedEntry->setStartTime(new \DateTime('2024-01-01 08:00:00'));
		$completedEntry->setEndTime(new \DateTime('2024-01-01 17:00:00'));
		$completedEntry->setBreaks(json_encode([[
			'start' => '2024-01-01T12:00:00+00:00',
			'end' => '2024-01-01T13:00:00+00:00',
		]]));
		$completedEntry->setIsManualEntry(false);
		$completedEntry->setCreatedAt(new \DateTime());
		$completedEntry->setUpdatedAt(new \DateTime());

		$activeEntry = new TimeEntry();
		$activeEntry->setId(2);
		$activeEntry->setUserId($userId);
		$activeEntry->setStatus(TimeEntry::STATUS_ACTIVE);
		$activeEntry->setStartTime(new \DateTime('2024-01-02 08:00:00'));
		$activeEntry->setEndTime(null);
		$activeEntry->setBreaks(json_encode([]));
		$activeEntry->setIsManualEntry(false);
		$activeEntry->setCreatedAt(new \DateTime());
		$activeEntry->setUpdatedAt(new \DateTime());

		$pendingEntry = new TimeEntry();
		$pendingEntry->setId(3);
		$pendingEntry->setUserId($userId);
		$pendingEntry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$pendingEntry->setStartTime(new \DateTime('2024-01-03 08:00:00'));
		$pendingEntry->setEndTime(new \DateTime('2024-01-03 17:00:00'));
		$pendingEntry->setBreaks(json_encode([[
			'start' => '2024-01-03T12:00:00+00:00',
			'end' => '2024-01-03T13:00:00+00:00',
		]]));
		$pendingEntry->setIsManualEntry(false);
		$pendingEntry->setCreatedAt(new \DateTime());
		$pendingEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUserAndDateRange')
			->willReturn([$completedEntry, $activeEntry, $pendingEntry]);

		$result = $this->service->calculateOvertime($userId, $startDate, $endDate);

		// Only completed entry should be counted
		$this->assertEquals(8.0, $result['total_hours_worked']);
	}

	/**
	 * Test getOvertimeBalance returns current balance
	 */
	public function testGetOvertimeBalance(): void
	{
		$userId = 'testuser';

		// Mock no working time model
		$this->userWorkingTimeModelMapper->expects($this->atLeastOnce())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock time entries for current year
		$entries = [];
		for ($i = 1; $i <= 10; $i++) {
			$entry = new TimeEntry();
			$entry->setId($i);
			$entry->setUserId($userId);
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
			$entry->setStartTime(new \DateTime("2024-01-$i 08:00:00"));
			$entry->setEndTime(new \DateTime("2024-01-$i 17:00:00"));
			$entry->setBreaks(json_encode([[
				'start' => sprintf('2024-01-%02dT12:00:00+00:00', $i),
				'end' => sprintf('2024-01-%02dT13:00:00+00:00', $i),
			]]));
			$entry->setIsManualEntry(false);
			$entry->setCreatedAt(new \DateTime());
			$entry->setUpdatedAt(new \DateTime());
			$entries[] = $entry;
		}

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUserAndDateRange')
			->willReturn($entries);

		$balance = $this->service->getOvertimeBalance($userId);

		$this->assertIsFloat($balance);
		// Balance should be calculated based on worked vs required hours
	}

	/**
	 * Test getDailyOvertime returns daily data
	 */
	public function testGetDailyOvertime(): void
	{
		$userId = 'testuser';
		$date = new \DateTime('2024-01-15');

		// Mock no working time model
		$this->userWorkingTimeModelMapper->expects($this->atLeastOnce())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock time entry for the day
		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 08:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setBreaks(json_encode([[
			'start' => '2024-01-15T12:00:00+00:00',
			'end' => '2024-01-15T13:00:00+00:00',
		]]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$result = $this->service->getDailyOvertime($userId, $date);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('total_hours_worked', $result);
		$this->assertArrayHasKey('overtime_hours', $result);
		$this->assertEquals(8.0, $result['total_hours_worked']);
	}

	/**
	 * Test getWeeklyOvertime returns weekly data
	 */
	public function testGetWeeklyOvertime(): void
	{
		$userId = 'testuser';
		$weekStart = new \DateTime('2024-01-15'); // Monday

		// Mock no working time model
		$this->userWorkingTimeModelMapper->expects($this->atLeastOnce())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock time entries for the week (5 working days)
		$entries = [];
		for ($i = 15; $i <= 19; $i++) {
			$entry = new TimeEntry();
			$entry->setId($i);
			$entry->setUserId($userId);
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
			$entry->setStartTime(new \DateTime("2024-01-$i 08:00:00"));
			$entry->setEndTime(new \DateTime("2024-01-$i 17:00:00"));
			$entry->setBreaks(json_encode([[
				'start' => "2024-01-{$i}T12:00:00+00:00",
				'end' => "2024-01-{$i}T13:00:00+00:00",
			]]));
			$entry->setIsManualEntry(false);
			$entry->setCreatedAt(new \DateTime());
			$entry->setUpdatedAt(new \DateTime());
			$entries[] = $entry;
		}

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUserAndDateRange')
			->willReturn($entries);

		$result = $this->service->getWeeklyOvertime($userId, $weekStart);

		$this->assertIsArray($result);
		$this->assertArrayHasKey('total_hours_worked', $result);
		$this->assertArrayHasKey('overtime_hours', $result);
		$this->assertEquals(40.0, $result['total_hours_worked']);
		$this->assertEquals(40.0, $result['weekly_hours']);
	}

	/**
	 * Default week window starts on Monday (ISO-style calendar week).
	 */
	public function testGetWeeklyOvertimeDefaultWeekStartsOnMonday(): void
	{
		$userId = 'testuser';

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);

		$result = $this->service->getWeeklyOvertime($userId);

		$expectedMonday = new \DateTime('monday this week');
		$this->assertSame($expectedMonday->format('Y-m-d'), $result['period_start']);
	}

	/**
	 * When overtime tracking_from is set, the anchor calendar day must not
	 * contribute standalone target hours (avoids “−8 h” before first clock-in).
	 */
	public function testTrackingFromAnchorDayWaivesDailyTarget(): void
	{
		$userId = 'testuser';

		$overtimeSettings = $this->createMock(UserOvertimeSettingsService::class);
		$overtimeSettings->method('getTrackingFrom')->willReturn(new \DateTimeImmutable('2024-01-02'));
		$overtimeSettings->method('getOpeningBalanceHours')->willReturn(0.0);
		$overtimeSettings->method('resolveEffectiveYearStart')->willReturn(new \DateTime('2024-01-02 00:00:00'));

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturnCallback(static function (string $_uid, \DateTime $s, \DateTime $e): float {
			$sd = $s->format('Y-m-d');
			$ed = $e->format('Y-m-d');
			if ($sd === '2024-01-02' && $ed === '2024-01-02') {
				return 1.0;
			}
			if ($sd === '2024-01-02' && $ed === '2024-01-03') {
				return 2.0;
			}
			return 0.0;
		});

		$service = new OvertimeService(
			$this->timeEntryMapper,
			$this->workingTimeModelMapper,
			$this->userWorkingTimeModelMapper,
			$this->l10n,
			$holiday,
			$overtimeSettings
		);

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);

		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUserAndDateRange')
			->willReturn([]);

		$onlyAnchor = $service->calculateOvertime($userId, new \DateTime('2024-01-02'), new \DateTime('2024-01-02'));
		$this->assertSame(0.0, $onlyAnchor['required_hours']);
		$this->assertSame(0.0, $onlyAnchor['overtime_hours']);

		$anchorPlusNext = $service->calculateOvertime($userId, new \DateTime('2024-01-02'), new \DateTime('2024-01-03'));
		$this->assertSame(8.0, $anchorPlusNext['required_hours']);
	}

	/**
	 * When tracking-from is in the future, the visible window must not invent undertime.
	 */
	public function testFutureTrackingFromYieldsZeroRequiredInCurrentWindow(): void
	{
		$userId = 'futureuser';
		$today = new \DateTime('today');
		$future = (clone $today)->modify('+30 days');

		$overtimeSettings = $this->createMock(UserOvertimeSettingsService::class);
		$overtimeSettings->method('getTrackingFrom')->willReturn(\DateTimeImmutable::createFromMutable($future));
		$overtimeSettings->method('getOpeningBalanceHours')->willReturn(0.0);
		$overtimeSettings->method('resolveEffectiveYearStart')->willReturnCallback(
			static function (string $_uid, int $year) use ($future): \DateTime {
				$yearStart = new \DateTime(sprintf('%04d-01-01 00:00:00', $year));
				return $future > $yearStart ? $future : $yearStart;
			}
		);

		$holiday = $this->createMock(HolidayService::class);
		$holiday->method('computeWorkingDaysForUser')->willReturn(0.0);

		$service = new OvertimeService(
			$this->timeEntryMapper,
			$this->workingTimeModelMapper,
			$this->userWorkingTimeModelMapper,
			$this->l10n,
			$holiday,
			$overtimeSettings
		);

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->timeEntryMapper->expects($this->never())->method('findByUserAndDateRange');

		$windowStart = (clone $today)->modify('-7 days');
		$result = $service->calculateOvertime($userId, $windowStart, $today);

		$this->assertSame(0.0, $result['required_hours']);
		$this->assertSame(0.0, $result['overtime_hours']);
		$this->assertSame(0.0, $result['total_hours_worked']);
	}

	/**
	 * BANSS weekday matrix drives required hours (Mon–Fri week without holidays).
	 */
	public function testWeekdayScheduleRequiredHoursForBanssWeek(): void
	{
		$userId = 'banssuser';
		$startDate = new \DateTime('2026-08-03'); // Monday
		$endDate = new \DateTime('2026-08-07'); // Friday

		$userModel = new UserWorkingTimeModel();
		$userModel->setId(1);
		$userModel->setUserId($userId);
		$userModel->setWorkingTimeModelId(9);

		$model = new WorkingTimeModel();
		$model->setId(9);
		$model->setName('BANSS');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setWorkDaysPerWeek(5.0);
		$model->setBreakRulesArray([
			'weekday_schedule' => \OCA\ArbeitszeitCheck\Support\WeekdaySchedule::banssPreset(),
		]);

		$this->userWorkingTimeModelMapper->expects($this->atLeastOnce())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn($userModel);
		$this->workingTimeModelMapper->expects($this->atLeastOnce())
			->method('find')
			->with(9)
			->willReturn($model);

		$this->holidayCalendarService->method('getHolidayWeightForUser')->willReturn(0.0);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(5.0);
		$this->timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);

		$result = $this->service->calculateOvertime($userId, $startDate, $endDate, false);

		$this->assertSame('weekday_schedule', $result['required_hours_basis']);
		$this->assertEqualsWithDelta(38.5, $result['required_hours'], 0.01);
		$this->assertEqualsWithDelta(38.5, $result['weekly_hours'], 0.01);
		$this->assertEqualsWithDelta(-38.5, $result['overtime_hours'], 0.01);
	}

	/**
	 * Corrupt weekday_schedule must not change legacy required-hours math.
	 */
	public function testCorruptWeekdayScheduleFallsBackToLegacyWeeklyContract(): void
	{
		$userId = 'legacyuser';
		$startDate = new \DateTime('2026-08-03');
		$endDate = new \DateTime('2026-08-07');

		$userModel = new UserWorkingTimeModel();
		$userModel->setId(1);
		$userModel->setUserId($userId);
		$userModel->setWorkingTimeModelId(3);

		$model = new WorkingTimeModel();
		$model->setId(3);
		$model->setName('Legacy');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setWorkDaysPerWeek(5.0);
		$model->setBreakRulesArray([
			'weekday_schedule' => ['days' => ['mon' => ['work' => true]]],
		]);

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn($userModel);
		$this->workingTimeModelMapper->method('find')->with(3)->willReturn($model);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(5.0);
		$this->timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);

		$result = $this->service->calculateOvertime($userId, $startDate, $endDate, false);

		$this->assertSame('weekly_contract', $result['required_hours_basis']);
		$this->assertEqualsWithDelta(40.0, $result['required_hours'], 0.01);
	}

	/**
	 * Opt-in credit service absent/null → absence_credit_hours is 0 (legacy-identical Saldo).
	 */
	public function testAbsenceCreditHoursZeroWhenCreditServiceMissing(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-01');
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(1.0);
		$this->timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);

		$result = $this->service->calculateOvertime($userId, $startDate, $endDate, false);

		$this->assertArrayHasKey('absence_credit_hours', $result);
		$this->assertSame(0.0, $result['absence_credit_hours']);
	}

	/**
	 * When credit service returns planned hours, overtime Ist includes them without inflating total_hours_worked.
	 */
	public function testPaidAbsenceCreditAddsToOvertimeWithoutInflatingWorkedHours(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2026-09-12');
		$endDate = new \DateTime('2026-09-12');

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(0.0);
		$this->timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);

		$credit = $this->createMock(\OCA\ArbeitszeitCheck\Service\PaidAbsencePlannedHoursCreditService::class);
		$credit->expects($this->once())
			->method('creditHoursForRange')
			->with($userId, $this->anything(), $this->anything(), [])
			->willReturn(['hours' => 10.0, 'days_credited' => 1]);

		$service = new OvertimeService(
			$this->timeEntryMapper,
			$this->workingTimeModelMapper,
			$this->userWorkingTimeModelMapper,
			$this->l10n,
			$this->holidayCalendarService,
			$this->overtimeSettingsService,
			null,
			$credit,
		);

		$result = $service->calculateOvertime($userId, $startDate, $endDate, false);

		$this->assertSame(0.0, $result['total_hours_worked']);
		$this->assertEqualsWithDelta(10.0, $result['absence_credit_hours'], 0.001);
		// Credit must feed Ist for Saldo even when clocked hours stay 0.
		$this->assertEqualsWithDelta(
			$result['absence_credit_hours'] - $result['required_hours'],
			$result['overtime_hours'],
			0.001
		);
	}
}
