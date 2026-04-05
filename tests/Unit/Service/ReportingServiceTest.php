<?php

declare(strict_types=1);

/**
 * Unit tests for ReportingService
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Service\HolidayCalendarService;
use OCA\ArbeitszeitCheck\Service\ReportingService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCP\IUserManager;
use OCP\IUser;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Class ReportingServiceTest
 */
class ReportingServiceTest extends TestCase
{
	/** @var ReportingService */
	private $service;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var AbsenceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;

	/** @var ComplianceViolationMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $violationMapper;

	/** @var OvertimeService|\PHPUnit\Framework\MockObject\MockObject */
	private $overtimeService;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var HolidayCalendarService|\PHPUnit\Framework\MockObject\MockObject */
	private $holidayCalendarService;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$this->overtimeService = $this->createMock(OvertimeService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->holidayCalendarService = $this->createMock(HolidayCalendarService::class);
		$this->holidayCalendarService->method('isHolidayForUser')->willReturn(false);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(0.0);

		$this->service = new ReportingService(
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->violationMapper,
			$this->overtimeService,
			$this->userManager,
			$this->l10n,
			$this->holidayCalendarService
		);
	}

	/**
	 * Test generating daily report for single user
	 */
	public function testGenerateDailyReportSingleUser(): void
	{
		$userId = 'testuser';
		$date = new \DateTime('2024-01-15');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('Test User');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->expects($this->atLeastOnce())
			->method('get')
			->with($userId)
			->willReturn($user);

		// Mock time entries
		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 08:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 16:45:00')); // 8h work + 45m break
		$entry->setBreaks(json_encode([[
			'start' => '2024-01-15T12:00:00+00:00',
			'end' => '2024-01-15T12:45:00+00:00',
		]]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$entry]);

		// Mock overtime data
		$this->overtimeService->expects($this->once())
			->method('getDailyOvertime')
			->with($userId, $date)
			->willReturn([
				'total_hours_worked' => 8.0,
				'required_hours' => 8.0,
				'overtime_hours' => 0.0
			]);

		// Mock violations
		$this->violationMapper->method('findByDateRange')->willReturn([]);

		$report = $this->service->generateDailyReport($date, $userId);

		$this->assertIsArray($report);
		$this->assertEquals('daily', $report['type']);
		$this->assertEquals('2024-01-15', $report['date']);
		$this->assertEquals(1, $report['total_users']);
		$this->assertEquals(1, $report['active_users']);
		$this->assertEquals(8.0, $report['total_hours']);
		$this->assertEquals(0.75, $report['total_break_hours']);
		$this->assertCount(1, $report['users']);
	}

	/**
	 * Test generating daily report for all users
	 */
	public function testGenerateDailyReportAllUsers(): void
	{
		$date = new \DateTime('2024-01-15');

		$user1 = $this->createMock(IUser::class);
		$user1->method('getUID')->willReturn('user1');
		$user1->method('isEnabled')->willReturn(true);

		$user2 = $this->createMock(IUser::class);
		$user2->method('getUID')->willReturn('user2');
		$user2->method('isEnabled')->willReturn(true);

		$this->userManager->expects($this->once())
			->method('callForAllUsers')
			->willReturnCallback(function ($callback) use ($user1, $user2) {
				$callback($user1);
				$callback($user2);
			});

		// Mock time entries for user1
		$entry1 = new TimeEntry();
		$entry1->setId(1);
		$entry1->setUserId('user1');
		$entry1->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry1->setStartTime(new \DateTime('2024-01-15 08:00:00'));
		$entry1->setEndTime(new \DateTime('2024-01-15 16:45:00')); // 8h work + 45m break
		$entry1->setBreaks(json_encode([[
			'start' => '2024-01-15T12:00:00+00:00',
			'end' => '2024-01-15T12:45:00+00:00',
		]]));
		$entry1->setIsManualEntry(false);
		$entry1->setCreatedAt(new \DateTime());
		$entry1->setUpdatedAt(new \DateTime());

		// Mock time entries for user2 (no entries)
		$this->timeEntryMapper->expects($this->exactly(2))
			->method('findByUserAndDateRange')
			->willReturnOnConsecutiveCalls([$entry1], []);

		// Mock overtime data
		$this->overtimeService->expects($this->exactly(2))
			->method('getDailyOvertime')
			->willReturn([
				'total_hours_worked' => 8.0,
				'required_hours' => 8.0,
				'overtime_hours' => 0.0
			]);

		// Mock violations
		$this->violationMapper->expects($this->exactly(2))
			->method('findByDateRange')
			->willReturn([]);

		$report = $this->service->generateDailyReport($date, null);

		$this->assertIsArray($report);
		$this->assertEquals('daily', $report['type']);
		$this->assertEquals(2, $report['total_users']);
		$this->assertEquals(1, $report['active_users']); // Only user1 has entries
		$this->assertEquals(8.0, $report['total_hours']);
		$this->assertCount(2, $report['users']);
	}

	/**
	 * Test generating weekly report
	 */
	public function testGenerateWeeklyReport(): void
	{
		$userId = 'testuser';
		$weekStart = new \DateTime('2024-01-15'); // Monday

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('Test User');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->expects($this->atLeastOnce())
			->method('get')
			->with($userId)
			->willReturn($user);

		// Mock overtime data
		$this->overtimeService->expects($this->once())
			->method('getWeeklyOvertime')
			->with($userId, $weekStart)
			->willReturn([
				'total_hours_worked' => 40.0,
				'required_hours' => 40.0,
				'overtime_hours' => 0.0
			]);

		// Mock time entries
		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 08:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 16:45:00'));
		$entry->setBreaks(json_encode([[
			'start' => '2024-01-15T12:00:00+00:00',
			'end' => '2024-01-15T12:45:00+00:00',
		]]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		// Weekly report internally generates 7 daily reports too; don't over-specify mapper call counts.
		$this->timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);

		// Mock violations
		$this->violationMapper->method('findByDateRange')->willReturn([]);

		// Mock daily reports (called 7 times for each day of the week)
		$this->overtimeService->expects($this->exactly(7))
			->method('getDailyOvertime')
			->willReturn([
				'total_hours_worked' => 0.0,
				'required_hours' => 0.0,
				'overtime_hours' => 0.0
			]);

		$this->violationMapper->method('findByDateRange')->willReturn([]);

		$report = $this->service->generateWeeklyReport($weekStart, $userId);

		$this->assertIsArray($report);
		$this->assertEquals('weekly', $report['type']);
		$this->assertArrayHasKey('week_start', $report);
		$this->assertArrayHasKey('week_end', $report);
		$this->assertArrayHasKey('daily_breakdown', $report);
		$this->assertCount(7, $report['daily_breakdown']); // 7 days in a week
	}

	/**
	 * Test generating monthly report
	 */
	public function testGenerateMonthlyReport(): void
	{
		$userId = 'testuser';
		$month = new \DateTime('2024-01-15');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('Test User');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->expects($this->once())
			->method('get')
			->with($userId)
			->willReturn($user);

		$this->overtimeService->expects($this->once())
			->method('calculateOvertime')
			->with(
				$userId,
				$this->callback(static function (\DateTime $start): bool {
					return $start->format('Y-m-d') === '2024-01-01';
				}),
				$this->callback(static function (\DateTime $end): bool {
					return $end->format('Y-m-d') === '2024-01-31';
				})
			)
			->willReturn([
				'total_hours_worked' => 160.0,
				'required_hours' => 160.0,
				'overtime_hours' => 0.0,
			]);

		// Mock time entries
		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-02 08:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-02 16:45:00'));
		$entry->setBreaks(json_encode([[
			'start' => '2024-01-02T12:00:00+00:00',
			'end' => '2024-01-02T12:45:00+00:00',
		]]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$entry]);

		// Mock violations
		$this->violationMapper->expects($this->once())
			->method('findByDateRange')
			->willReturn([]);

		$report = $this->service->generateMonthlyReport($month, $userId);

		$this->assertIsArray($report);
		$this->assertEquals('monthly', $report['type']);
		$this->assertEquals('2024-01', $report['month']);
		$this->assertArrayHasKey('month_name', $report);
		$this->assertArrayHasKey('working_days', $report);
		$this->assertEquals(160.0, $report['total_hours']);
	}

	/**
	 * Test generating overtime report
	 */
	public function testGenerateOvertimeReport(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('Test User');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->expects($this->atLeastOnce())
			->method('get')
			->with($userId)
			->willReturn($user);

		// Mock overtime data with positive overtime
		$this->overtimeService->expects($this->once())
			->method('calculateOvertime')
			->with($userId, $startDate, $endDate)
			->willReturn([
				'total_hours_worked' => 170.0,
				'required_hours' => 160.0,
				'overtime_hours' => 10.0,
				'cumulative_balance_after' => 10.0
			]);

		$report = $this->service->generateOvertimeReport($startDate, $endDate, $userId);

		$this->assertIsArray($report);
		$this->assertEquals('overtime', $report['type']);
		$this->assertEquals(1, $report['total_users']);
		$this->assertEquals(1, $report['users_with_overtime']);
		$this->assertEquals(0, $report['users_with_undertime']);
		$this->assertEquals(10.0, $report['total_overtime']);
		$this->assertEquals(0.0, $report['total_undertime']);
		$this->assertCount(1, $report['users']);
		$this->assertEquals(10.0, $report['users'][0]['overtime_hours']);
	}

	/**
	 * Test generating overtime report with undertime
	 */
	public function testGenerateOvertimeReportWithUndertime(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('Test User');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->expects($this->atLeastOnce())
			->method('get')
			->with($userId)
			->willReturn($user);

		// Mock overtime data with negative overtime (undertime)
		$this->overtimeService->expects($this->once())
			->method('calculateOvertime')
			->with($userId, $startDate, $endDate)
			->willReturn([
				'total_hours_worked' => 150.0,
				'required_hours' => 160.0,
				'overtime_hours' => -10.0,
				'cumulative_balance_after' => -10.0
			]);

		$report = $this->service->generateOvertimeReport($startDate, $endDate, $userId);

		$this->assertEquals(0, $report['users_with_overtime']);
		$this->assertEquals(1, $report['users_with_undertime']);
		$this->assertEquals(0.0, $report['total_overtime']);
		$this->assertEquals(10.0, $report['total_undertime']); // Absolute value
		$this->assertEquals(-10.0, $report['users'][0]['overtime_hours']);
	}

	/**
	 * Test generating absence report
	 */
	public function testGenerateAbsenceReport(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$absence1 = new Absence();
		$absence1->setId(1);
		$absence1->setUserId($userId);
		$absence1->setType(Absence::TYPE_VACATION);
		$absence1->setStatus(Absence::STATUS_APPROVED);
		$absence1->setStartDate(new \DateTime('2024-01-10'));
		$absence1->setEndDate(new \DateTime('2024-01-12'));
		$absence1->setDays(3.0);
		$absence1->setCreatedAt(new \DateTime());
		$absence1->setUpdatedAt(new \DateTime());

		$absence2 = new Absence();
		$absence2->setId(2);
		$absence2->setUserId($userId);
		$absence2->setType(Absence::TYPE_SICK_LEAVE);
		$absence2->setStatus(Absence::STATUS_APPROVED);
		$absence2->setStartDate(new \DateTime('2024-01-20'));
		$absence2->setEndDate(new \DateTime('2024-01-21'));
		$absence2->setDays(2.0);
		$absence2->setCreatedAt(new \DateTime());
		$absence2->setUpdatedAt(new \DateTime());

		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with($userId, $startDate, $endDate)
			->willReturn([$absence1, $absence2]);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Test User');
		$this->userManager->expects($this->atLeastOnce())
			->method('get')
			->with($userId)
			->willReturn($user);

		$report = $this->service->generateAbsenceReport($startDate, $endDate, $userId);

		$this->assertIsArray($report);
		$this->assertEquals('absence', $report['type']);
		$this->assertEquals(2, $report['total_absences']);
		$this->assertEquals(5, $report['total_days']); // 3 + 2
		$this->assertArrayHasKey('absences_by_type', $report);
		$this->assertEquals(1, $report['absences_by_type'][Absence::TYPE_VACATION]);
		$this->assertEquals(1, $report['absences_by_type'][Absence::TYPE_SICK_LEAVE]);
		$this->assertArrayHasKey('absences_by_status', $report);
		$this->assertEquals(2, $report['absences_by_status'][Absence::STATUS_APPROVED]);
		$this->assertCount(1, $report['users']); // One user with absences
	}

	/**
	 * Test generating team report
	 */
	public function testGenerateTeamReport(): void
	{
		$userIds = ['user1', 'user2'];
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$user1 = $this->createMock(IUser::class);
		$user1->method('getUID')->willReturn('user1');
		$user1->method('getDisplayName')->willReturn('User One');
		$user1->method('isEnabled')->willReturn(true);

		$user2 = $this->createMock(IUser::class);
		$user2->method('getUID')->willReturn('user2');
		$user2->method('getDisplayName')->willReturn('User Two');
		$user2->method('isEnabled')->willReturn(true);

		$this->userManager->expects($this->atLeastOnce())
			->method('get')
			->willReturnCallback(static function (string $uid) use ($user1, $user2): ?IUser {
				return match ($uid) {
					'user1' => $user1,
					'user2' => $user2,
					default => null,
				};
			});

		// Mock overtime data for both users
		$this->overtimeService->expects($this->exactly(2))
			->method('calculateOvertime')
			->willReturn([
				'total_hours_worked' => 160.0,
				'required_hours' => 160.0,
				'overtime_hours' => 0.0
			]);

		// Mock time entries
		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId('user1');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-02 08:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-02 16:45:00'));
		$entry->setBreaks(json_encode([[
			'start' => '2024-01-02T12:00:00+00:00',
			'end' => '2024-01-02T12:45:00+00:00',
		]]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->exactly(2))
			->method('findByUserAndDateRange')
			->willReturn([$entry]);

		// Mock violations
		$this->violationMapper->expects($this->exactly(2))
			->method('findByDateRange')
			->willReturn([]);

		// Mock absences
		$this->absenceMapper->expects($this->exactly(2))
			->method('findByUserAndDateRange')
			->willReturn([]);

		$report = $this->service->generateTeamReport($userIds, $startDate, $endDate);

		$this->assertIsArray($report);
		$this->assertEquals('team', $report['type']);
		$this->assertEquals(2, $report['team_size']);
		$this->assertEquals(2, $report['active_members']);
		$this->assertEquals(320.0, $report['total_hours']); // 160 + 160
		$this->assertCount(2, $report['members']);
	}

	/**
	 * Test generating team report with disabled user
	 */
	public function testGenerateTeamReportWithDisabledUser(): void
	{
		$userIds = ['user1', 'user2'];
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$user1 = $this->createMock(IUser::class);
		$user1->method('getUID')->willReturn('user1');
		$user1->method('getDisplayName')->willReturn('User One');
		$user1->method('isEnabled')->willReturn(true);

		$user2 = $this->createMock(IUser::class);
		$user2->method('getUID')->willReturn('user2');
		$user2->method('getDisplayName')->willReturn('User Two');
		$user2->method('isEnabled')->willReturn(false); // Disabled user

		$this->userManager->expects($this->atLeastOnce())
			->method('get')
			->willReturnCallback(static function (string $uid) use ($user1, $user2): ?IUser {
				return match ($uid) {
					'user1' => $user1,
					'user2' => $user2,
					default => null,
				};
			});

		// Mock overtime data only for user1
		$this->overtimeService->expects($this->once())
			->method('calculateOvertime')
			->with('user1', $startDate, $endDate)
			->willReturn([
				'total_hours_worked' => 160.0,
				'required_hours' => 160.0,
				'overtime_hours' => 0.0
			]);

		// Mock time entries only for user1
		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId('user1');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-02 08:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-02 16:45:00'));
		$entry->setBreaks(json_encode([[
			'start' => '2024-01-02T12:00:00+00:00',
			'end' => '2024-01-02T12:45:00+00:00',
		]]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with('user1', $startDate, $endDate)
			->willReturn([$entry]);

		// Mock violations only for user1
		$this->violationMapper->expects($this->once())
			->method('findByDateRange')
			->with($startDate, $endDate, 'user1')
			->willReturn([]);

		// Mock absences only for user1
		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->with('user1', $startDate, $endDate)
			->willReturn([]);

		$report = $this->service->generateTeamReport($userIds, $startDate, $endDate);

		$this->assertEquals(2, $report['team_size']);
		$this->assertEquals(1, $report['active_members']); // Only user1 is active
		$this->assertCount(1, $report['members']); // Only user1 in members list
	}

	/**
	 * Test generating daily report with violations
	 */
	public function testGenerateDailyReportWithViolations(): void
	{
		$userId = 'testuser';
		$date = new \DateTime('2024-01-15');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('Test User');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->expects($this->atLeastOnce())
			->method('get')
			->with($userId)
			->willReturn($user);

		// Mock time entries
		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 08:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 16:15:00')); // 8h work + 15m break
		$entry->setBreaks(json_encode([[
			'start' => '2024-01-15T12:00:00+00:00',
			'end' => '2024-01-15T12:15:00+00:00',
		]]));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$entry]);

		// Mock overtime data
		$this->overtimeService->expects($this->once())
			->method('getDailyOvertime')
			->willReturn([
				'total_hours_worked' => 8.0,
				'required_hours' => 8.0,
				'overtime_hours' => 0.0
			]);

		// Mock violations (missing break)
		$violation = $this->createMock(ComplianceViolation::class);
		$this->violationMapper->expects($this->once())
			->method('findByDateRange')
			->willReturn([$violation]);

		$report = $this->service->generateDailyReport($date, $userId);

		$this->assertEquals(1, $report['violations_count']);
		$this->assertEquals(1, $report['users'][0]['violations_count']);
	}
}
