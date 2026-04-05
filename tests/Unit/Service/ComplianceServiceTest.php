<?php

declare(strict_types=1);

/**
 * Unit tests for ComplianceService
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\HolidayCalendarService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Class ComplianceServiceTest
 */
class ComplianceServiceTest extends TestCase
{
	/** @var ComplianceService */
	private $service;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var ComplianceViolationMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $violationMapper;

	/** @var WorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $workingTimeModelMapper;

	/** @var UserWorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userWorkingTimeModelMapper;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var NotificationService|\PHPUnit\Framework\MockObject\MockObject */
	private $notificationService;

	/** @var HolidayCalendarService|\PHPUnit\Framework\MockObject\MockObject */
	private $holidayCalendarService;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$this->workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->holidayCalendarService = $this->createMock(HolidayCalendarService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
			return $default;
		});

		// Setup l10n mock to return translation keys
		$this->l10n->method('t')
			->willReturnCallback(function ($text) {
				return $text;
			});

		$this->service = new ComplianceService(
			$this->timeEntryMapper,
			$this->violationMapper,
			$this->workingTimeModelMapper,
			$this->userWorkingTimeModelMapper,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			$this->holidayCalendarService,
			$this->config
		);
	}

	/**
	 * Test that checkComplianceBeforeClockIn returns no issues when compliant
	 */
	public function testCheckComplianceBeforeClockInCompliant(): void
	{
		$userId = 'testuser';

		// Mock no previous entry (first clock-in) - getLastCompletedEntry uses findByUser
		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUser')
			->with($userId)
			->willReturn([]);

		// Mock today's hours (under 10 hours) - called twice (once for daily, once for weekly check)
		$this->timeEntryMapper->expects($this->exactly(2))
			->method('getTotalHoursByUserAndDateRange')
			->willReturnOnConsecutiveCalls(7.5, 240.0); // 7.5 hours today, 240 hours over 6 months

		$issues = $this->service->checkComplianceBeforeClockIn($userId);

		$this->assertIsArray($issues);
		$this->assertEmpty($issues, 'Should return no compliance issues when compliant');
	}

	/**
	 * Test that checkComplianceBeforeClockIn detects insufficient rest period
	 */
	public function testCheckComplianceBeforeClockInInsufficientRest(): void
	{
		$userId = 'testuser';

		// Mock previous entry that ended less than 11 hours ago
		$endTime = new \DateTime();
		$endTime->modify('-10 hours'); // Only 10 hours ago
		$lastEntry = new TimeEntry();
		$lastEntry->setId(1);
		$lastEntry->setUserId($userId);
		$lastEntry->setStartTime((clone $endTime)->modify('-8 hours'));
		$lastEntry->setEndTime($endTime);
		$lastEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$lastEntry->setIsManualEntry(false);
		$lastEntry->setCreatedAt(new \DateTime());
		$lastEntry->setUpdatedAt(new \DateTime());

		// getLastCompletedEntry uses findByUser and filters for completed entries
		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUser')
			->with($userId)
			->willReturn([$lastEntry]);

		// Mock today's hours (under 10 hours) - called twice (once for daily, once for weekly check)
		$this->timeEntryMapper->expects($this->exactly(2))
			->method('getTotalHoursByUserAndDateRange')
			->willReturnOnConsecutiveCalls(7.5, 240.0);

		$issues = $this->service->checkComplianceBeforeClockIn($userId);

		$this->assertNotEmpty($issues, 'Should detect insufficient rest period');
		$this->assertCount(1, $issues);
		$this->assertEquals(ComplianceViolation::TYPE_INSUFFICIENT_REST_PERIOD, $issues[0]['type']);
		$this->assertEquals(ComplianceViolation::SEVERITY_ERROR, $issues[0]['severity']);
	}

	/**
	 * Test that checkComplianceBeforeClockIn detects daily hours limit exceeded
	 */
	public function testCheckComplianceBeforeClockInDailyHoursExceeded(): void
	{
		$userId = 'testuser';

		// Mock no previous entry
		$this->timeEntryMapper->expects($this->atLeastOnce())
			->method('findByUser')
			->with($userId)
			->willReturn([]);

		// Mock today's hours (10 hours already worked) - called twice (once for daily, once for weekly check)
		$this->timeEntryMapper->expects($this->exactly(2))
			->method('getTotalHoursByUserAndDateRange')
			->willReturnOnConsecutiveCalls(10.0, 240.0);

		$issues = $this->service->checkComplianceBeforeClockIn($userId);

		$this->assertNotEmpty($issues, 'Should detect daily hours limit exceeded');
		$this->assertCount(1, $issues);
		$this->assertEquals(ComplianceViolation::TYPE_DAILY_HOURS_LIMIT_EXCEEDED, $issues[0]['type']);
		$this->assertEquals(ComplianceViolation::SEVERITY_ERROR, $issues[0]['severity']);
	}

	/**
	 * Test that checkComplianceAfterClockOut detects missing 30-minute break
	 */
	public function testCheckComplianceAfterClockOutMissing30MinBreak(): void
	{
		$userId = 'testuser';
		$timeEntry = new TimeEntry();
		$timeEntry->setId(123);
		$timeEntry->setUserId($userId);
		$timeEntry->setStartTime(new \DateTime('2024-01-15 10:15:00')); // total 6h45m
		$timeEntry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$timeEntry->setBreaks(json_encode([[
			'start' => '2024-01-15T13:00:00+00:00',
			'end' => '2024-01-15T13:15:00+00:00',
		]]));
		$timeEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$timeEntry->setIsManualEntry(false);
		$timeEntry->setCreatedAt(new \DateTime());
		$timeEntry->setUpdatedAt(new \DateTime());

		// Mock violation creation
		$violation = new ComplianceViolation();
		$violation->setId(456);
		$this->violationMapper->expects($this->once())
			->method('createViolation')
			->with(
				$userId,
				ComplianceViolation::TYPE_MISSING_BREAK,
				$this->stringContains('30-minute break'),
				$this->isInstanceOf(\DateTime::class),
				123,
				ComplianceViolation::SEVERITY_ERROR
			)
			->willReturn($violation);

		// Mock notification
		$this->notificationService->expects($this->once())
			->method('notifyComplianceViolation')
			->with($userId, $this->isType('array'));

		$this->service->checkComplianceAfterClockOut($timeEntry);
	}

	/**
	 * Test that checkComplianceAfterClockOut detects missing 45-minute break
	 */
	public function testCheckComplianceAfterClockOutMissing45MinBreak(): void
	{
		$userId = 'testuser';
		$timeEntry = new TimeEntry();
		$timeEntry->setId(123);
		$timeEntry->setUserId($userId);
		$timeEntry->setStartTime(new \DateTime('2024-01-15 07:00:00')); // total 10h
		$timeEntry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$timeEntry->setBreaks(json_encode([[
			'start' => '2024-01-15T12:00:00+00:00',
			'end' => '2024-01-15T12:30:00+00:00',
		]]));
		$timeEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$timeEntry->setIsManualEntry(false);
		$timeEntry->setCreatedAt(new \DateTime());
		$timeEntry->setUpdatedAt(new \DateTime());

		// Mock violation creation
		$violation = new ComplianceViolation();
		$violation->setId(456);
		$this->violationMapper->expects($this->once())
			->method('createViolation')
			->with(
				$userId,
				ComplianceViolation::TYPE_MISSING_BREAK,
				$this->stringContains('45-minute break'),
				$this->isInstanceOf(\DateTime::class),
				123,
				ComplianceViolation::SEVERITY_ERROR
			)
			->willReturn($violation);

		// Mock notification
		$this->notificationService->expects($this->once())
			->method('notifyComplianceViolation')
			->with($userId, $this->isType('array'));

		$this->service->checkComplianceAfterClockOut($timeEntry);
	}

	/**
	 * Test that checkComplianceAfterClockOut detects excessive working hours
	 */
	public function testCheckComplianceAfterClockOutExcessiveHours(): void
	{
		$userId = 'testuser';
		$timeEntry = new TimeEntry();
		$timeEntry->setId(123);
		$timeEntry->setUserId($userId);
		$timeEntry->setStartTime(new \DateTime('2024-01-15 05:00:00')); // total 12h
		$timeEntry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$timeEntry->setBreaks(json_encode([[
			'start' => '2024-01-15T12:00:00+00:00',
			'end' => '2024-01-15T13:00:00+00:00',
		]]));
		$timeEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$timeEntry->setIsManualEntry(false);
		$timeEntry->setCreatedAt(new \DateTime());
		$timeEntry->setUpdatedAt(new \DateTime());

		// Mock violation creation (excessive hours + night work info)
		$violation = new ComplianceViolation();
		$violation->setId(456);
		$this->violationMapper->expects($this->exactly(2))
			->method('createViolation')
			->withConsecutive(
				[
					$userId,
					ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS,
					$this->stringContains('Working hours exceeded'),
					$this->isInstanceOf(\DateTime::class),
					123,
					ComplianceViolation::SEVERITY_ERROR
				],
				[
					$userId,
					ComplianceViolation::TYPE_NIGHT_WORK,
					$this->stringContains('Night work detected'),
					$this->isInstanceOf(\DateTime::class),
					123,
					ComplianceViolation::SEVERITY_INFO
				]
			)
			->willReturnOnConsecutiveCalls($violation, $violation);

		// Mock notification
		$this->notificationService->expects($this->once())
			->method('notifyComplianceViolation')
			->with($userId, $this->isType('array'));

		$this->service->checkComplianceAfterClockOut($timeEntry);
	}

	/**
	 * Test that checkComplianceAfterClockOut does not create violations when compliant
	 */
	public function testCheckComplianceAfterClockOutCompliant(): void
	{
		$userId = 'testuser';
		$timeEntry = new TimeEntry();
		$timeEntry->setId(123);
		$timeEntry->setUserId($userId);
		$timeEntry->setStartTime(new \DateTime('2024-01-15 08:00:00')); // total 8h45m
		$timeEntry->setEndTime(new \DateTime('2024-01-15 16:45:00'));
		$timeEntry->setBreaks(json_encode([[
			'start' => '2024-01-15T12:00:00+00:00',
			'end' => '2024-01-15T12:45:00+00:00',
		]]));
		$timeEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$timeEntry->setIsManualEntry(false);
		$timeEntry->setCreatedAt(new \DateTime());
		$timeEntry->setUpdatedAt(new \DateTime());

		// Should not create any violations
		$this->violationMapper->expects($this->never())
			->method('createViolation');

		$this->notificationService->expects($this->never())
			->method('notifyComplianceViolation');

		$this->service->checkComplianceAfterClockOut($timeEntry);
	}

	/**
	 * Test German public holiday detection
	 */
	public function testIsGermanPublicHoliday(): void
	{
		$this->holidayCalendarService->method('isHolidayForState')->willReturnCallback(
			static function (string $state, \DateTime $date): bool {
				$key = $state . '|' . $date->format('Y-m-d');
				$map = [
					'BY|2024-01-01' => true,
					'BE|2024-01-01' => true,
					'BW|2024-01-01' => true,

					'BY|2024-12-25' => true,
					'BE|2024-12-25' => true,

					'BY|2024-01-15' => false,
					'BE|2024-01-15' => false,

					'BY|2024-01-06' => true,
					'BE|2024-01-06' => false,
				];

				return $map[$key] ?? false;
			}
		);

		// Test New Year's Day (should be holiday in all states)
		$newYear = new \DateTime('2024-01-01');
		$this->assertTrue($this->service->isGermanPublicHoliday($newYear, 'BY'));
		$this->assertTrue($this->service->isGermanPublicHoliday($newYear, 'BE'));
		$this->assertTrue($this->service->isGermanPublicHoliday($newYear, 'BW'));

		// Test Christmas Day (should be holiday in all states)
		$christmas = new \DateTime('2024-12-25');
		$this->assertTrue($this->service->isGermanPublicHoliday($christmas, 'BY'));
		$this->assertTrue($this->service->isGermanPublicHoliday($christmas, 'BE'));

		// Test regular workday (should not be holiday)
		$regularDay = new \DateTime('2024-01-15'); // Monday
		$this->assertFalse($this->service->isGermanPublicHoliday($regularDay, 'BY'));
		$this->assertFalse($this->service->isGermanPublicHoliday($regularDay, 'BE'));

		// Test state-specific holiday (e.g., Epiphany in Bavaria)
		$epiphany = new \DateTime('2024-01-06');
		$this->assertTrue($this->service->isGermanPublicHoliday($epiphany, 'BY'));
		$this->assertFalse($this->service->isGermanPublicHoliday($epiphany, 'BE')); // Not a holiday in Berlin
	}

	/**
	 * Test Sunday work detection through checkComplianceAfterClockOut
	 */
	public function testCheckComplianceAfterClockOutSundayWork(): void
	{
		$userId = 'testuser';
		$timeEntry = new TimeEntry();

		// Mock time entry on Sunday (compliant hours and breaks)
		$sundayStart = new \DateTime('2024-01-07 08:00:00'); // Sunday
		$sundayEnd = new \DateTime('2024-01-07 17:00:00'); // Sunday

		$timeEntry->setId(123);
		$timeEntry->setUserId($userId);
		$timeEntry->setStartTime($sundayStart);
		$timeEntry->setEndTime($sundayEnd);
		$timeEntry->setBreaks(json_encode([[
			'start' => '2024-01-07T12:00:00+00:00',
			'end' => '2024-01-07T12:45:00+00:00',
		]]));
		$timeEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$timeEntry->setIsManualEntry(false);
		$timeEntry->setCreatedAt(new \DateTime());
		$timeEntry->setUpdatedAt(new \DateTime());

		// Mock violation creation for Sunday work
		$violation = new ComplianceViolation();
		$violation->setId(456);
		$this->violationMapper->expects($this->once())
			->method('createViolation')
			->with(
				$userId,
				ComplianceViolation::TYPE_SUNDAY_WORK,
				$this->stringContains('Sunday'),
				$sundayStart,
				123,
				ComplianceViolation::SEVERITY_WARNING
			)
			->willReturn($violation);

		$this->service->checkComplianceAfterClockOut($timeEntry);
	}

	/**
	 * Test night work detection through checkComplianceAfterClockOut
	 */
	public function testCheckComplianceAfterClockOutNightWork(): void
	{
		$userId = 'testuser';
		$timeEntry = new TimeEntry();

		// Mock time entry with night work (11 PM - 2 AM)
		$nightStart = new \DateTime('2024-01-15 23:00:00');
		$nightEnd = new \DateTime('2024-01-16 02:00:00');

		$timeEntry->setId(123);
		$timeEntry->setUserId($userId);
		$timeEntry->setStartTime($nightStart);
		$timeEntry->setEndTime($nightEnd);
		$timeEntry->setBreaks(json_encode([]));
		$timeEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$timeEntry->setIsManualEntry(false);
		$timeEntry->setCreatedAt(new \DateTime());
		$timeEntry->setUpdatedAt(new \DateTime());

		// Mock violation creation for night work
		$violation = new ComplianceViolation();
		$violation->setId(456);
		$this->violationMapper->expects($this->once())
			->method('createViolation')
			->with(
				$userId,
				ComplianceViolation::TYPE_NIGHT_WORK,
				$this->stringContains('Night work'),
				$nightEnd,
				123,
				ComplianceViolation::SEVERITY_INFO
			)
			->willReturn($violation);

		$this->service->checkComplianceAfterClockOut($timeEntry);
	}

	/**
	 * Test getComplianceStatus returns correct structure
	 */
	public function testGetComplianceStatus(): void
	{
		$userId = 'testuser';

		// Mock no violations (findByUser with false = unresolved only)
		$this->violationMapper->expects($this->once())
			->method('findByUser')
			->with($userId, false)
			->willReturn([]);

		$status = $this->service->getComplianceStatus($userId);

		$this->assertIsArray($status);
		$this->assertArrayHasKey('compliant', $status);
		$this->assertArrayHasKey('violation_count', $status);
		$this->assertArrayHasKey('critical_violations', $status);
		$this->assertArrayHasKey('warning_violations', $status);
		$this->assertArrayHasKey('info_violations', $status);
		$this->assertArrayHasKey('last_check', $status);
		$this->assertTrue($status['compliant'], 'Should be compliant when no violations');
		$this->assertEquals(0, $status['violation_count']);
		$this->assertEquals(0, $status['critical_violations']);
	}

	/**
	 * Test getComplianceStatus detects non-compliance
	 */
	public function testGetComplianceStatusNonCompliant(): void
	{
		$userId = 'testuser';

		// Mock violations
		$violation = new ComplianceViolation();
		$violation->setId(1);
		$violation->setUserId($userId);
		$violation->setViolationType(ComplianceViolation::TYPE_MISSING_BREAK);
		$violation->setSeverity(ComplianceViolation::SEVERITY_ERROR);

		$this->violationMapper->expects($this->once())
			->method('findByUser')
			->with($userId, false)
			->willReturn([$violation]);

		$status = $this->service->getComplianceStatus($userId);

		$this->assertFalse($status['compliant'], 'Should be non-compliant when violations exist');
		$this->assertEquals(1, $status['violation_count']);
		$this->assertEquals(1, $status['critical_violations']);
		$this->assertEquals(0, $status['warning_violations']);
		$this->assertEquals(0, $status['info_violations']);
	}

	/**
	 * Test generateComplianceReport returns correct structure
	 */
	public function testGenerateComplianceReport(): void
	{
		$startDate = new \DateTime('2024-01-01');
		$endDate = new \DateTime('2024-01-31');
		$userId = 'testuser';

		// Mock violations
		$violation1 = new ComplianceViolation();
		$violation1->setId(1);
		$violation1->setUserId($userId);
		$violation1->setViolationType(ComplianceViolation::TYPE_MISSING_BREAK);
		$violation1->setSeverity(ComplianceViolation::SEVERITY_ERROR);

		$violation2 = new ComplianceViolation();
		$violation2->setId(2);
		$violation2->setUserId($userId);
		$violation2->setViolationType(ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS);
		$violation2->setSeverity(ComplianceViolation::SEVERITY_WARNING);

		$this->violationMapper->expects($this->once())
			->method('findByDateRange')
			->with($startDate, $endDate, $userId)
			->willReturn([$violation1, $violation2]);

		$report = $this->service->generateComplianceReport($startDate, $endDate, $userId);

		$this->assertIsArray($report);
		$this->assertArrayHasKey('period', $report);
		$this->assertArrayHasKey('total_violations', $report);
		$this->assertArrayHasKey('violations_by_type', $report);
		$this->assertArrayHasKey('violations_by_severity', $report);
		$this->assertArrayHasKey('violations_by_user', $report);
		$this->assertEquals(2, $report['total_violations']);
		$this->assertEquals(1, $report['violations_by_type'][ComplianceViolation::TYPE_MISSING_BREAK]);
		$this->assertEquals(1, $report['violations_by_type'][ComplianceViolation::TYPE_EXCESSIVE_WORKING_HOURS]);
		$this->assertEquals(1, $report['violations_by_severity'][ComplianceViolation::SEVERITY_ERROR]);
		$this->assertEquals(1, $report['violations_by_severity'][ComplianceViolation::SEVERITY_WARNING]);
	}
}
