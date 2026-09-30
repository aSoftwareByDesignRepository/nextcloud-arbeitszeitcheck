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
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\ReportingService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
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

	/** @var HolidayService|\PHPUnit\Framework\MockObject\MockObject */
	private $holidayCalendarService;
	/** @var PermissionService|\PHPUnit\Framework\MockObject\MockObject */
	private $permissionService;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$this->overtimeService = $this->createMock(OvertimeService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->holidayCalendarService = $this->createMock(HolidayService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);
		$this->holidayCalendarService->method('isHolidayForUser')->willReturn(false);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(0.0);

		$overtimeBankService = $this->createMock(OvertimeBankService::class);
		$overtimeBankService->method('isEnabled')->willReturn(false);
		$overtimeBankService->method('getBankStatus')->willReturn(['enabled' => false]);

		$this->service = new ReportingService(
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->violationMapper,
			$this->overtimeService,
			$overtimeBankService,
			$this->userManager,
			$this->l10n,
			$this->holidayCalendarService,
			$this->permissionService
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

	public function testGenerateMonthlyReportPeriodOverridesAndMissingUser(): void
	{
		// period overrides take precedence over the month window
		$report = $this->service->generateMonthlyReport(
			new \DateTime('2024-01-15'),
			'ghost',
			new \DateTime('2024-02-10'),
			new \DateTime('2024-02-14'),
		);
		$this->assertSame('2024-02-10', $report['period']['start']);
		$this->assertSame('2024-02-14', $report['period']['end']);
		// ghost user not found -> single-user arm skipped, defaults stay
		$this->assertSame(0, $report['total_users']);
		$this->assertSame(0, $report['active_users']);
	}

	public function testGenerateMonthlyReportAggregatesAllEnabledUsers(): void
	{
		$u1 = $this->createMock(IUser::class);
		$u1->method('getUID')->willReturn('alice');
		$u1->method('isEnabled')->willReturn(true);
		$u1->method('getDisplayName')->willReturn('Alice');
		$u2 = $this->createMock(IUser::class);
		$u2->method('getUID')->willReturn('bob');
		$u2->method('isEnabled')->willReturn(true);
		$u2->method('getDisplayName')->willReturn('Bob');
		$u3 = $this->createMock(IUser::class);
		$u3->method('isEnabled')->willReturn(false); // disabled -> skipped

		$this->userManager->method('callForAllUsers')->willReturnCallback(
			static function (callable $cb) use ($u1, $u2, $u3): void {
				foreach ([$u1, $u2, $u3] as $u) {
					$cb($u);
				}
			}
		);
		$this->userManager->method('get')->willReturnMap([
			['alice', $u1],
			['bob', $u2],
		]);

		$this->overtimeService->method('calculateOvertime')
			->willReturnCallback(static fn (string $uid) => [
				'total_hours_worked' => $uid === 'alice' ? 100.0 : 0.0,
				'required_hours' => 160.0,
				'overtime_hours' => $uid === 'alice' ? 4.0 : 0.0,
			]);

		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId('alice');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-02 08:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-02 17:00:00'));
		$entry->setBreaks(json_encode([['start' => '2024-01-02T12:00:00+00:00', 'end' => '2024-01-02T13:00:00+00:00']]));

		$this->timeEntryMapper->method('findByUserAndDateRange')
			->willReturnCallback(static fn (string $uid) => $uid === 'alice' ? [$entry] : []);
		$this->violationMapper->method('findByDateRange')
			->willReturnCallback(static fn ($s, $e, string $uid) => $uid === 'alice' ? [new \OCA\ArbeitszeitCheck\Db\ComplianceViolation()] : []);

		$report = $this->service->generateMonthlyReport(new \DateTime('2024-01-15'));

		$this->assertSame(2, $report['total_users']);
		$this->assertSame(1, $report['active_users']);   // only alice has entries
		$this->assertSame(100.0, $report['total_hours']);
		$this->assertSame(4.0, $report['total_overtime']);
		$this->assertSame(1, $report['violations_count']);
		$this->assertSame(1.0, $report['total_break_hours']);
		$this->assertSame(100.0, $report['average_hours_per_user']);
		$this->assertCount(1, $report['users']);
		$this->assertSame('alice', $report['users'][0]['user_id']);
		$this->assertSame('Alice', $report['users'][0]['display_name']);
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
		$this->assertSame('date', $report['sort']);
		$this->assertEquals(2, $report['total_absences']);
		$this->assertEquals(5, $report['total_days']); // 3 + 2
		$this->assertArrayHasKey('absences_by_type', $report);
		$this->assertEquals(1, $report['absences_by_type'][Absence::TYPE_VACATION]);
		$this->assertEquals(1, $report['absences_by_type'][Absence::TYPE_SICK_LEAVE]);
		$this->assertArrayHasKey('absences_by_status', $report);
		$this->assertEquals(2, $report['absences_by_status'][Absence::STATUS_APPROVED]);
		$this->assertCount(1, $report['users']); // One user with absences
		// Default chronological: vacation (Jan 10) before sick (Jan 20)
		$rows = $report['users'][0]['absences'];
		$this->assertSame(Absence::TYPE_VACATION, $rows[0]['type']);
		$this->assertSame(Absence::TYPE_SICK_LEAVE, $rows[1]['type']);
	}

	public function testGenerateAbsenceReportSortByType(): void
	{
		$userId = 'testuser';
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');

		$vacation = new Absence();
		$vacation->setId(1);
		$vacation->setUserId($userId);
		$vacation->setType(Absence::TYPE_VACATION);
		$vacation->setStatus(Absence::STATUS_APPROVED);
		$vacation->setStartDate(new \DateTime('2024-01-10'));
		$vacation->setEndDate(new \DateTime('2024-01-12'));
		$vacation->setDays(3.0);
		$vacation->setCreatedAt(new \DateTime());
		$vacation->setUpdatedAt(new \DateTime());

		$sick = new Absence();
		$sick->setId(2);
		$sick->setUserId($userId);
		$sick->setType(Absence::TYPE_SICK_LEAVE);
		$sick->setStatus(Absence::STATUS_APPROVED);
		$sick->setStartDate(new \DateTime('2024-01-20'));
		$sick->setEndDate(new \DateTime('2024-01-21'));
		$sick->setDays(2.0);
		$sick->setCreatedAt(new \DateTime());
		$sick->setUpdatedAt(new \DateTime());

		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$vacation, $sick]);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Test User');
		$this->userManager->method('get')->with($userId)->willReturn($user);

		$report = $this->service->generateAbsenceReport($startDate, $endDate, $userId, 'type');
		$this->assertSame('type', $report['sort']);
		$rows = $report['users'][0]['absences'];
		$this->assertSame(Absence::TYPE_SICK_LEAVE, $rows[0]['type']);
		$this->assertSame(Absence::TYPE_VACATION, $rows[1]['type']);
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
