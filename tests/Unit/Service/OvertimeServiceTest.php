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
use OCA\ArbeitszeitCheck\Service\HolidayCalendarService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
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

	/** @var HolidayCalendarService|\PHPUnit\Framework\MockObject\MockObject */
	private $holidayCalendarService;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->holidayCalendarService = $this->createMock(HolidayCalendarService::class);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(5.0);

		$this->service = new OvertimeService(
			$this->timeEntryMapper,
			$this->workingTimeModelMapper,
			$this->userWorkingTimeModelMapper,
			$this->l10n,
			$this->holidayCalendarService
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
}
