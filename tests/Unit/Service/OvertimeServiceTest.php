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

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->l10n = $this->createMock(IL10N::class);

		$this->service = new OvertimeService(
			$this->timeEntryMapper,
			$this->workingTimeModelMapper,
			$this->userWorkingTimeModelMapper,
			$this->l10n
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
		$this->userWorkingTimeModelMapper->expects($this->once())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock time entries - 5 working days, 8 hours each = 40 hours
		$entries = [];
		for ($i = 1; $i <= 5; $i++) {
			$entry = $this->createMock(TimeEntry::class);
			$entry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
			$entry->method('getEndTime')->willReturn(new \DateTime("2024-01-0$i 17:00:00"));
			$entry->method('getWorkingDurationHours')->willReturn(8.0);
			$entries[] = $entry;
		}

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with($userId, $startDate, $endDate)
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
		$this->userWorkingTimeModelMapper->expects($this->once())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock time entries - 5 working days, 9 hours each = 45 hours (5 hours overtime)
		$entries = [];
		for ($i = 1; $i <= 5; $i++) {
			$entry = $this->createMock(TimeEntry::class);
			$entry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
			$entry->method('getEndTime')->willReturn(new \DateTime("2024-01-0$i 18:00:00"));
			$entry->method('getWorkingDurationHours')->willReturn(9.0);
			$entries[] = $entry;
		}

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with($userId, $startDate, $endDate)
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
		$this->userWorkingTimeModelMapper->expects($this->once())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock time entries - 5 working days, 7 hours each = 35 hours (5 hours undertime)
		$entries = [];
		for ($i = 1; $i <= 5; $i++) {
			$entry = $this->createMock(TimeEntry::class);
			$entry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
			$entry->method('getEndTime')->willReturn(new \DateTime("2024-01-0$i 16:00:00"));
			$entry->method('getWorkingDurationHours')->willReturn(7.0);
			$entries[] = $entry;
		}

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with($userId, $startDate, $endDate)
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
		$userModel = $this->createMock(UserWorkingTimeModel::class);
		$userModel->method('getWorkingTimeModelId')->willReturn(1);

		$model = $this->createMock(WorkingTimeModel::class);
		$model->method('getDailyHours')->willReturn(6.0);
		$model->method('getWeeklyHours')->willReturn(30.0);

		$this->userWorkingTimeModelMapper->expects($this->once())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn($userModel);

		$this->workingTimeModelMapper->expects($this->once())
			->method('find')
			->with(1)
			->willReturn($model);

		// Mock time entries - 5 working days, 6 hours each = 30 hours
		$entries = [];
		for ($i = 1; $i <= 5; $i++) {
			$entry = $this->createMock(TimeEntry::class);
			$entry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
			$entry->method('getEndTime')->willReturn(new \DateTime("2024-01-0$i 15:00:00"));
			$entry->method('getWorkingDurationHours')->willReturn(6.0);
			$entries[] = $entry;
		}

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with($userId, $startDate, $endDate)
			->willReturn($entries);

		$result = $this->service->calculateOvertime($userId, $startDate, $endDate);

		$this->assertEquals(30.0, $result['total_hours_worked']);
		$this->assertEquals(6.0, $result['daily_hours']);
		$this->assertEquals(30.0, $result['weekly_hours']);
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
		$this->userWorkingTimeModelMapper->expects($this->once())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock entries: one completed, one active, one pending
		$completedEntry = $this->createMock(TimeEntry::class);
		$completedEntry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$completedEntry->method('getEndTime')->willReturn(new \DateTime('2024-01-01 17:00:00'));
		$completedEntry->method('getWorkingDurationHours')->willReturn(8.0);

		$activeEntry = $this->createMock(TimeEntry::class);
		$activeEntry->method('getStatus')->willReturn(TimeEntry::STATUS_ACTIVE);
		$activeEntry->method('getEndTime')->willReturn(null);

		$pendingEntry = $this->createMock(TimeEntry::class);
		$pendingEntry->method('getStatus')->willReturn(TimeEntry::STATUS_PENDING_APPROVAL);
		$pendingEntry->method('getEndTime')->willReturn(new \DateTime('2024-01-02 17:00:00'));
		$pendingEntry->method('getWorkingDurationHours')->willReturn(8.0);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with($userId, $startDate, $endDate)
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
			$entry = $this->createMock(TimeEntry::class);
			$entry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
			$entry->method('getEndTime')->willReturn(new \DateTime("2024-01-$i 17:00:00"));
			$entry->method('getWorkingDurationHours')->willReturn(8.0);
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
		$this->userWorkingTimeModelMapper->expects($this->once())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock time entry for the day
		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$entry->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$entry->method('getWorkingDurationHours')->willReturn(8.0);

		$this->timeEntryMapper->expects($this->once())
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
		$this->userWorkingTimeModelMapper->expects($this->once())
			->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);

		// Mock time entries for the week (5 working days)
		$entries = [];
		for ($i = 15; $i <= 19; $i++) {
			$entry = $this->createMock(TimeEntry::class);
			$entry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
			$entry->method('getEndTime')->willReturn(new \DateTime("2024-01-$i 17:00:00"));
			$entry->method('getWorkingDurationHours')->willReturn(8.0);
			$entries[] = $entry;
		}

		$this->timeEntryMapper->expects($this->once())
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
