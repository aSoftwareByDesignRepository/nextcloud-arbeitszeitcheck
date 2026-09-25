<?php

declare(strict_types=1);

/**
 * Unit tests for AbsenceService
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\AbsenceNotificationMailService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/**
 * Class AbsenceServiceTest
 */
class AbsenceServiceTest extends TestCase
{
	/** @var AbsenceService */
	private $service;

	/** @var AbsenceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;

	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var UserSettingsMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userSettingsMapper;

	/** @var TeamResolverService|\PHPUnit\Framework\MockObject\MockObject */
	private $teamResolver;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var NotificationService|\PHPUnit\Framework\MockObject\MockObject */
	private $notificationService;

	/** @var HolidayService|\PHPUnit\Framework\MockObject\MockObject */
	private $holidayCalendarService;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var UserWorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userWorkingTimeModelMapper;

	/** @var VacationYearBalanceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $vacationYearBalanceMapper;

	/** @var VacationAllocationService|\PHPUnit\Framework\MockObject\MockObject */
	private $vacationAllocationService;

	/** @var array<string,mixed>|null Override for VacationAllocationService::computeYearAllocation return shape */
	private ?array $vacationAllocationStub = null;

	/** When true, prospective allocation (non-null dates) returns allocation_valid false */
	private bool $vacationAllocationFailProspective = false;

	/** Controls TeamResolverService::hasAssignableManagerForEmployee in unit tests */
	private bool $hasAssignableManagerForTests = true;

	/** @var IDBConnection|\PHPUnit\Framework\MockObject\MockObject */
	private $db;

	/** @var ILockingProvider|\PHPUnit\Framework\MockObject\MockObject */
	private $lockingProvider;

	/** @var MonthClosureService|\PHPUnit\Framework\MockObject\MockObject */
	private $monthClosureService;

	protected function setUp(): void
	{
		parent::setUp();

		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->teamResolver->method('getColleagueIds')->willReturnCallback(function ($userId) {
			return ['colleague1', 'colleague2'];
		});
		$this->hasAssignableManagerForTests = true;
		$this->teamResolver->method('hasAssignableManagerForEmployee')->willReturnCallback(function () {
			return $this->hasAssignableManagerForTests;
		});
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default = '') {
			if ($app !== 'arbeitszeitcheck') {
				return $default;
			}
			if ($key === 'require_substitute_types') {
				return '[]';
			}
			if ($key === Constants::CONFIG_VACATION_YEAR_MODE) {
				return Constants::VACATION_YEAR_MODE_CALENDAR;
			}
			if ($key === Constants::CONFIG_VACATION_UNIT) {
				return Constants::VACATION_UNIT_DAYS;
			}
			return $default;
		});
		$this->db = $this->createMock(IDBConnection::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->lockingProvider->method('acquireLock');
		$this->lockingProvider->method('releaseLock');
		$this->monthClosureService = $this->createMock(MonthClosureService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')->willReturnCallback(function (string $uid) {
			if (in_array($uid, ['colleague1', 'colleague2', 'designated_substitute', 'substitute1'], true)) {
				$user = $this->createMock(\OCP\IUser::class);
				$user->method('isEnabled')->willReturn(true);
				$user->method('getUID')->willReturn($uid);
				$user->method('getDisplayName')->willReturn($uid);
				return $user;
			}
			return null;
		});
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->vacationYearBalanceMapper = $this->createMock(VacationYearBalanceMapper::class);
		$this->vacationYearBalanceMapper->method('getCarryoverDays')->willReturn(0.0);
		$this->l10n = $this->createMock(IL10N::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->holidayCalendarService = $this->createMock(HolidayService::class);
		$this->holidayCalendarService
			->method('computeWorkingDaysPerYearForUser')
			->willReturnCallback(static function (string $userId, \DateTime $start, \DateTime $end): array {
				unset($userId);
				$result = [];
				$cur = (clone $start)->setTime(0, 0, 0);
				$endNorm = (clone $end)->setTime(0, 0, 0);
				while ($cur <= $endNorm) {
					if ((int)$cur->format('N') < 6) {
						$y = (int)$cur->format('Y');
						$result[$y] = ($result[$y] ?? 0) + 1.0;
					}
					$cur->modify('+1 day');
				}
				return $result;
			});
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(2.0);

		$this->l10n->method('t')
			->willReturnCallback(function ($text) {
				return $text;
			});

		$this->vacationAllocationStub = null;
		$this->vacationAllocationFailProspective = false;
		$this->vacationAllocationService = $this->createMock(VacationAllocationService::class);
		$this->vacationAllocationService->method('isCarryoverUsableForNewRequests')->willReturn(true);
		$this->vacationAllocationService->method('getMaxCarryoverOpeningCap')->willReturn(null);
		$this->vacationAllocationService->method('computeYearAllocation')->willReturnCallback(function ($userId, $year, $exclude, $pStart, $pEnd, $asOf, $createdAt = null, $persistSnapshot = true) {
			unset($userId, $year, $exclude, $asOf, $createdAt, $persistSnapshot);
			if ($this->vacationAllocationStub !== null) {
				return $this->vacationAllocationStub;
			}
			if ($this->vacationAllocationFailProspective && $pStart !== null && $pEnd !== null) {
				return [
					'entitlement' => 25.0,
					'carryover_opening' => 0.0,
					'carryover_usable_for_new_requests' => 0.0,
					'carryover_expires_on' => null,
					'total_remaining_for_new_requests' => 0.0,
					'used_total_working_days' => 0.0,
					'allocation_valid' => false,
					'shortfall' => 4.0,
				];
			}
			return [
				'entitlement' => 25.0,
				'carryover_opening' => 0.0,
				'carryover_usable_for_new_requests' => 0.0,
				'carryover_expires_on' => null,
				'total_remaining_for_new_requests' => 25.0,
				'used_total_working_days' => 0.0,
				'allocation_valid' => true,
				'shortfall' => 0.0,
			];
		});

		$this->service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$this->lockingProvider,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService
		);
	}

	/**
	 * Test creating a valid absence request
	 */
	public function testCreateAbsenceSuccess(): void
	{
		$userId = 'testuser';
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+4 days');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'reason' => 'Summer vacation'
		];

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->with($userId, $this->isInstanceOf(\DateTime::class), $this->isInstanceOf(\DateTime::class), $this->anything())
			->willReturn([]);

		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')
			->with($userId, 'vacation_days_per_year', 25)->willReturn(25);

		$absence = new Absence();
		$absence->setId(123);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(5.0);

		$this->absenceMapper->expects($this->once())
			->method('lockUserAbsenceWindow');

		$this->absenceMapper->expects($this->once())
			->method('insert')
			->willReturn($absence);

		// Mock audit logging
		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_created', 'absence', 123, null, $this->isType('array'));

		$result = $this->service->createAbsence($data, $userId);

		$this->assertSame($absence, $result);
	}

	public function testCreateAbsenceTriggersHrRequestCreatedNotification(): void
	{
		$userId = 'testuser';
		// Pin to mid-week so the range always includes working days regardless of "today".
		$start = new \DateTime('next monday');
		$start->modify('+14 days');
		$end = (clone $start)->modify('+1 day');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'reason' => 'Vacation',
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$absence = new Absence();
		$absence->setId(124);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(2.0);
		$this->absenceMapper->method('insert')->willReturn($absence);

		$mailService = $this->createMock(AbsenceNotificationMailService::class);
		$mailService->expects($this->once())
			->method('sendHrOfficeNotification')
			->with($absence, 'request_created', $userId);

		$service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$this->lockingProvider,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			$mailService
		);

		$service->createAbsence($data, $userId);
	}

	/**
	 * Test creating absence with empty/whitespace date string – parseDate rejects
	 */
	public function testCreateAbsenceEmptyDateString(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => '   ',
			'end_date' => (new \DateTime())->modify('+7 days')->format('Y-m-d'),
			'reason' => 'Test'
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Date is required and cannot be empty');

		$this->service->createAbsence($data, $userId);
	}

	public function testCreateAbsenceRejectsRelativeDateString(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => 'next monday',
			'end_date' => (new \DateTime())->modify('+7 days')->format('Y-m-d'),
			'reason' => 'Test',
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Invalid date format. Expected yyyy-mm-dd or dd.mm.yyyy');

		$this->service->createAbsence($data, $userId);
	}

	public function testCreateAbsenceRejectsIsoDateTimeString(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2026-06-01T12:34:56Z',
			'end_date' => (new \DateTime())->modify('+7 days')->format('Y-m-d'),
			'reason' => 'Test',
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Invalid date format. Expected yyyy-mm-dd or dd.mm.yyyy');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test vacation with 0 working days (e.g. weekend-only) – must be rejected
	 */
	public function testCreateAbsenceVacationZeroWorkingDays(): void
	{
		$userId = 'testuser';
		$futureSat = (new \DateTime())->modify('next Saturday');
		$futureSun = (clone $futureSat)->modify('+1 day');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $futureSat->format('Y-m-d'),
			'end_date' => $futureSun->format('Y-m-d'),
			'reason' => 'Weekend only'
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$year = (int)$futureSat->format('Y');
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([$year => 0.0]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(0.0);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Vacation must include at least one working day');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Hours mode + weekend + posted duration_hours must still be rejected (no invented debit).
	 */
	public function testCreateAbsenceVacationHoursWeekendWithDurationRejected(): void
	{
		$userId = 'testuser';
		$futureSat = (new \DateTime())->modify('next Saturday');
		$futureSun = (clone $futureSat)->modify('+1 day');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $futureSat->format('Y-m-d'),
			'end_date' => $futureSun->format('Y-m-d'),
			'reason' => 'Weekend hours attempt',
			'duration_hours' => '8',
			'require_duration_hours' => true,
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$year = (int)$futureSat->format('Y');
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([$year => 0.0]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(0.0);

		$unitConfig = $this->createMock(IConfig::class);
		$unitConfig->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_VACATION_UNIT) {
					return Constants::VACATION_UNIT_HOURS;
				}
				if ($key === Constants::CONFIG_VACATION_HOURS_PER_DAY) {
					return '8';
				}
				return $default;
			}
		);
		$unit = new \OCA\ArbeitszeitCheck\Service\VacationUnitService($unitConfig);
		$debit = $this->createMock(\OCA\ArbeitszeitCheck\Service\VacationHoursDebitService::class);
		$debit->method('estimateForUserRange')->willReturn([
			'hours' => 0.0,
			'basis' => 'org_hours_per_day',
			'average_daily' => 8.0,
			'one_day_hours' => 8.0,
			'weekday_nets' => null,
		]);

		$ref = new \ReflectionClass($this->service);
		$unitProp = $ref->getProperty('vacationUnitService');
		$unitProp->setAccessible(true);
		$unitProp->setValue($this->service, $unit);
		$debitProp = $ref->getProperty('vacationHoursDebitService');
		$debitProp->setAccessible(true);
		$debitProp->setValue($this->service, $debit);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Vacation must include at least one working day');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test vacation when computeWorkingDaysPerYear returns empty – fallback to HolidayService
	 */
	public function testCreateAbsenceVacationFallbackWhenEmpty(): void
	{
		$userId = 'testuser';
		$futureStart = (new \DateTime())->modify('+14 days');
		$futureEnd = (clone $futureStart)->modify('+2 days');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $futureStart->format('Y-m-d'),
			'end_date' => $futureEnd->format('Y-m-d'),
			'reason' => 'Fallback test'
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')->willReturn([]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(2.0);

		$absence = new Absence();
		$absence->setId(125);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $futureStart);
		$absence->setEndDate(clone $futureEnd);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(2.0);
		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($absence);
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$result = $this->service->createAbsence($data, $userId);
		$this->assertSame($absence, $result);
	}

	/**
	 * Test creating absence with invalid date range (start after end)
	 */
	public function testCreateAbsenceInvalidDateRange(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2024-06-05',
			'end_date' => '2024-06-01', // End before start
			'reason' => 'Invalid range'
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Start date cannot be after end date');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test creating vacation with a past start date (migration/backfill records)
	 */
	public function testCreateAbsenceAllowsPastVacationDate(): void
	{
		$userId = 'testuser';
		$yesterday = (new \DateTime())->modify('-1 day')->format('Y-m-d');
		$tomorrow = (new \DateTime())->modify('+1 day')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $yesterday,
			'end_date' => $tomorrow,
			'reason' => 'Past date'
		];

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$absence = new Absence();
		$absence->setId(1234);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime($yesterday));
		$absence->setEndDate(new \DateTime($tomorrow));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(2.0);

		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($absence);
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$result = $this->service->createAbsence($data, $userId);
		$this->assertSame($absence, $result);
	}

	/**
	 * Test sick leave with start date within allowed past window (7 days) – should succeed
	 */
	public function testCreateAbsenceSickLeaveWithinPastWindow(): void
	{
		$userId = 'testuser';
		$threeDaysAgo = (new \DateTime())->modify('-3 days')->format('Y-m-d');
		$tomorrow = (new \DateTime())->modify('+1 day')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $threeDaysAgo,
			'end_date' => $tomorrow,
			'reason' => 'Sick'
		];

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$absence = new Absence();
		$absence->setId(124);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStartDate(new \DateTime($threeDaysAgo));
		$absence->setEndDate(new \DateTime($tomorrow));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(3.0);
		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($absence);
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$result = $this->service->createAbsence($data, $userId);
		$this->assertSame($absence, $result);
	}

	/**
	 * Test sick leave with start date more than 7 days in past – should succeed for backfill/migration.
	 */
	public function testCreateAbsenceSickLeaveAllowsOlderPastStartDate(): void
	{
		$userId = 'testuser';
		$tenDaysAgo = (new \DateTime())->modify('-10 days')->format('Y-m-d');
		$tomorrow = (new \DateTime())->modify('+1 day')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $tenDaysAgo,
			'end_date' => $tomorrow,
			'reason' => 'Sick'
		];

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$absence = new Absence();
		$absence->setId(125);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStartDate(new \DateTime($tenDaysAgo));
		$absence->setEndDate(new \DateTime($tomorrow));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setDays(8.0);
		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($absence);
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$result = $this->service->createAbsence($data, $userId);
		$this->assertSame($absence, $result);
	}

	/**
	 * Test creating absence with overlapping dates
	 */
	public function testCreateAbsenceOverlapping(): void
	{
		$userId = 'testuser';
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+4 days');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'reason' => 'Overlapping'
		];

		$existingAbsence = new Absence();
		$existingAbsence->setType(Absence::TYPE_VACATION);
		$existingAbsence->setStartDate($start);
		$existingAbsence->setEndDate($end);

		$this->absenceMapper->expects($this->once())
			->method('findOverlapping')
			->with($userId, $this->isInstanceOf(\DateTime::class), $this->isInstanceOf(\DateTime::class), $this->anything())
			->willReturn([$existingAbsence]);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('This period overlaps with an existing');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test creating vacation absence exceeding 30 days
	 */
	public function testCreateAbsenceVacationExceedsLimit(): void
	{
		$userId = 'testuser';
		$futureStart = (new \DateTime())->modify('+14 days')->format('Y-m-d');
		$futureEnd = (new \DateTime())->modify('+49 days')->format('Y-m-d'); // 35 days span
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $futureStart,
			'end_date' => $futureEnd,
			'reason' => 'Too long'
		];

		$this->absenceMapper->expects($this->once())
			->method('findOverlapping')
			->willReturn([]);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Vacation cannot exceed 30 days');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test creating vacation absence when entitlement exceeded
	 */
	public function testCreateAbsenceVacationEntitlementExceeded(): void
	{
		$userId = 'testuser';
		$futureStart = (new \DateTime())->modify('+14 days')->format('Y-m-d');
		$futureEnd = (new \DateTime())->modify('+18 days')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $futureStart,
			'end_date' => $futureEnd,
			'reason' => 'No days left'
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')
			->with($userId, 'vacation_days_per_year', 25)->willReturn(25);
		$year = (int)(new \DateTime($futureStart))->format('Y');
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([$year => 4.0]);

		$this->vacationAllocationFailProspective = true;

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Not enough vacation remaining');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test creating personal leave exceeding 5 days
	 */
	public function testCreateAbsencePersonalLeaveExceedsLimit(): void
	{
		$userId = 'testuser';
		$futureStart = (new \DateTime())->modify('+14 days')->format('Y-m-d');
		$futureEnd = (new \DateTime())->modify('+24 days')->format('Y-m-d'); // 10 days span
		$data = [
			'type' => Absence::TYPE_PERSONAL_LEAVE,
			'start_date' => $futureStart,
			'end_date' => $futureEnd,
			'reason' => 'Too long'
		];

		$this->absenceMapper->expects($this->once())
			->method('findOverlapping')
			->willReturn([]);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Personal leave cannot exceed 5 days');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test getting absence by ID (user owns it)
	 */
	public function testGetAbsenceOwnedByUser(): void
	{
		$userId = 'testuser';
		$absenceId = 123;

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$result = $this->service->getAbsence($absenceId, $userId);

		$this->assertSame($absence, $result);
	}

	/**
	 * Test getting absence by ID (user does not own it)
	 */
	public function testGetAbsenceNotOwnedByUser(): void
	{
		$userId = 'testuser';
		$otherUserId = 'otheruser';
		$absenceId = 123;

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($otherUserId);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$result = $this->service->getAbsence($absenceId, $userId);

		$this->assertNull($result, 'User should not access absences they do not own');
	}

	/**
	 * Test getting non-existent absence
	 */
	public function testGetAbsenceNotFound(): void
	{
		$userId = 'testuser';
		$absenceId = 999;

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willThrowException(new DoesNotExistException('Absence not found'));

		$result = $this->service->getAbsence($absenceId, $userId);

		$this->assertNull($result);
	}

	/**
	 * Test updating a pending absence
	 */
	public function testUpdateAbsencePending(): void
	{
		$userId = 'testuser';
		$absenceId = 123;
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+4 days');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);
		$absence->setReason('Original reason');
		$absence->setDays(5.0);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->willReturn([]);

		$newStart = (clone $start)->modify('+1 day');
		$newEnd = (clone $end)->modify('+1 day');
		$year = (int)$newStart->format('Y');
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([$year => 5.0]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$this->absenceMapper->expects($this->once())
			->method('lockUserAbsenceWindow');

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($this->isInstanceOf(Absence::class))
			->willReturnCallback(function (Absence $a) {
				return $a;
			});

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_updated', 'absence', $absenceId, $this->isType('array'), $this->isType('array'));

		$updateData = [
			'start_date' => $newStart->format('Y-m-d'),
			'end_date' => $newEnd->format('Y-m-d'),
		];

		$result = $this->service->updateAbsence($absenceId, $updateData, $userId);

		$this->assertInstanceOf(Absence::class, $result);
	}

	public function testUpdateAbsenceWithNewSubstituteTransitionsToSubstitutePending(): void
	{
		$userId = 'testuser';
		$absenceId = 223;
		$start = (new \DateTime())->modify('+10 days');
		$end = (clone $start)->modify('+2 days');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);
		$absence->setDays(3.0);
		$absence->setSubstituteUserId(null);

		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([(int)$start->format('Y') => 3.0]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(3.0);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->auditLogMapper->expects($this->once())->method('logAction');
		$this->notificationService->expects($this->once())->method('notifySubstitutionRequest');

		$result = $this->service->updateAbsence($absenceId, ['substitute_user_id' => 'colleague1'], $userId);
		$this->assertSame(Absence::STATUS_SUBSTITUTE_PENDING, $result->getStatus());
	}

	public function testUpdateAbsenceRemovingSubstituteTransitionsToPending(): void
	{
		$userId = 'testuser';
		$absenceId = 224;
		$start = (new \DateTime())->modify('+10 days');
		$end = (clone $start)->modify('+2 days');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);
		$absence->setDays(3.0);
		$absence->setSubstituteUserId('colleague1');

		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->holidayCalendarService->method('computeWorkingDaysPerYearForUser')
			->willReturn([(int)$start->format('Y') => 3.0]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(3.0);
		$this->absenceMapper->method('update')->willReturnCallback(static fn (Absence $a) => $a);
		$this->auditLogMapper->expects($this->once())->method('logAction');
		$this->notificationService->expects($this->never())->method('notifySubstitutionRequest');

		$result = $this->service->updateAbsence($absenceId, ['substitute_user_id' => null], $userId);
		$this->assertSame(Absence::STATUS_PENDING, $result->getStatus());
	}

	/**
	 * Test updating a non-pending absence (should fail)
	 */
	public function testUpdateAbsenceNotPending(): void
	{
		$userId = 'testuser';
		$absenceId = 123;
		$futureDate = (new \DateTime())->modify('+14 days')->format('Y-m-d');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_APPROVED);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Only pending absences can be updated');

		$this->service->updateAbsence($absenceId, ['start_date' => $futureDate], $userId);
	}

	/**
	 * Test deleting a pending absence
	 */
	public function testDeleteAbsencePending(): void
	{
		$userId = 'testuser';
		$absenceId = 123;

		$start = (new \DateTime())->modify('+7 days');
		$end = (clone $start)->modify('+2 days');
		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setStartDate($start);
		$absence->setEndDate($end);
		$absence->setDays(2.0);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->absenceMapper->expects($this->once())
			->method('delete')
			->with($absence);

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_deleted', 'absence', $absenceId, $this->isType('array'), null);

		$this->service->deleteAbsence($absenceId, $userId);
	}

	/**
	 * Test deleting a non-pending absence (should fail)
	 */
	public function testDeleteAbsenceNotPending(): void
	{
		$userId = 'testuser';
		$absenceId = 123;

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_APPROVED);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Only pending absences can be deleted');

		$this->service->deleteAbsence($absenceId, $userId);
	}

	/**
	 * Test approving an absence request
	 */
	public function testApproveAbsence(): void
	{
		$userId = 'employee';
		$approverId = 'manager';
		$absenceId = 123;
		$comment = 'Approved for summer vacation';
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+4 days');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate($start);
		$absence->setEndDate($end);
		$absence->setDays(5.0);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($this->isInstanceOf(Absence::class))
			->willReturnCallback(function (Absence $a) {
				return $a;
			});

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_approved', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $approverId);

		$this->notificationService->expects($this->once())
			->method('notifyAbsenceApproved')
			->with($userId, $this->isType('array'));

		$result = $this->service->approveAbsence($absenceId, $approverId, $comment);

		$this->assertInstanceOf(Absence::class, $result);
		$this->assertSame(Absence::STATUS_APPROVED, $result->getStatus());
	}

	/**
	 * Test approving a non-pending absence (should fail)
	 */
	public function testApproveAbsenceNotPending(): void
	{
		$approverId = 'manager';
		$absenceId = 123;

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId('employee');
		$absence->setStatus(Absence::STATUS_APPROVED);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException::class);
		$this->expectExceptionMessage('This absence was already decided by another manager.');

		$this->service->approveAbsence($absenceId, $approverId);
	}

	public function testApproveAbsenceMapsLockedExceptionToConcurrentDecision(): void
	{
		$approverId = 'manager';
		$absenceId = 124;

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId('employee');
		$absence->setStatus(Absence::STATUS_PENDING);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$lockingProvider = $this->createMock(ILockingProvider::class);
		$lockingProvider->method('acquireLock')
			->willThrowException(new \OCP\Lock\LockedException('azc/ab/test'));
		$this->service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->userWorkingTimeModelMapper,
			$this->config,
			$this->db,
			$lockingProvider,
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null,
			$this->monthClosureService
		);

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException::class);
		$this->expectExceptionMessage('This absence was already decided by another manager.');

		$this->service->approveAbsence($absenceId, $approverId);
	}

	public function testApproveAbsenceBlockedWhenMonthFinalized(): void
	{
		$approverId = 'manager';
		$absenceId = 321;
		$start = new \DateTime('2026-04-01');
		$end = new \DateTime('2026-04-03');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId('employee');
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setStartDate(clone $start);
		$absence->setEndDate(clone $end);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->monthClosureService->expects($this->once())
			->method('assertDateRangeMutable')
			->with(
				'employee',
				$this->isInstanceOf(\DateTimeInterface::class),
				$this->isInstanceOf(\DateTimeInterface::class)
			)
			->willThrowException(new \Exception('Month is finalized'));

		$this->absenceMapper->expects($this->never())->method('update');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Month is finalized');

		$this->service->approveAbsence($absenceId, $approverId);
	}

	/**
	 * Test rejecting an absence request
	 */
	public function testRejectAbsence(): void
	{
		$userId = 'employee';
		$approverId = 'manager';
		$absenceId = 123;
		$comment = 'Not enough vacation days remaining';
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+4 days');

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate($start);
		$absence->setEndDate($end);
		$absence->setDays(5.0);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($this->isInstanceOf(Absence::class))
			->willReturnCallback(function (Absence $a) {
				return $a;
			});

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_rejected', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $approverId);

		$this->notificationService->expects($this->once())
			->method('notifyAbsenceRejected')
			->with($userId, $this->isType('array'), $comment);

		$result = $this->service->rejectAbsence($absenceId, $approverId, $comment);

		$this->assertInstanceOf(Absence::class, $result);
		$this->assertSame(Absence::STATUS_REJECTED, $result->getStatus());
	}

	/**
	 * Test getting vacation statistics
	 */
	public function testGetVacationStats(): void
	{
		$userId = 'testuser';
		$year = 2024;

		$this->absenceMapper->expects($this->once())
			->method('getSickLeaveDays')
			->with($userId, $year)
			->willReturn(3.0);

		$this->vacationAllocationStub = [
			'entitlement' => 30.0,
			'carryover_opening' => 0.0,
			'carryover_usable_for_new_requests' => 0.0,
			'carryover_expires_on' => null,
			'total_remaining_for_new_requests' => 15.0,
			'used_total_working_days' => 15.0,
			'allocation_valid' => true,
			'shortfall' => 0.0,
		];

		$stats = $this->service->getVacationStats($userId, $year);

		$this->assertIsArray($stats);
		$this->assertEquals($year, $stats['year']);
		$this->assertEquals(30, $stats['entitlement']);
		$this->assertEquals(0.0, $stats['carryover_days']);
		$this->assertEquals(30.0, $stats['total_available']);
		$this->assertEquals(15, $stats['used']);
		$this->assertEquals(15, $stats['remaining']); // 30 - 15 = 15
		$this->assertEquals(3, $stats['sick_days']);
	}

	/**
	 * Test getting vacation stats with default entitlement
	 */
	public function testGetVacationStatsDefaultEntitlement(): void
	{
		$userId = 'testuser';
		$year = 2024;

		$this->absenceMapper->expects($this->once())
			->method('getSickLeaveDays')
			->willReturn(0.0);

		$this->vacationAllocationStub = [
			'entitlement' => 25.0,
			'carryover_opening' => 0.0,
			'carryover_usable_for_new_requests' => 0.0,
			'carryover_expires_on' => null,
			'total_remaining_for_new_requests' => 15.0,
			'used_total_working_days' => 10.0,
			'allocation_valid' => true,
			'shortfall' => 0.0,
		];

		$stats = $this->service->getVacationStats($userId, $year);

		$this->assertEquals(25, $stats['entitlement']);
		$this->assertEquals(0.0, $stats['carryover_days']);
		$this->assertEquals(25.0, $stats['total_available']);
		$this->assertEquals(10, $stats['used']);
		$this->assertEquals(15, $stats['remaining']); // 25 - 10 = 15
	}

	/**
	 * Test getting absences by user with filters
	 */
	public function testGetAbsencesByUserWithStatusFilter(): void
	{
		$userId = 'testuser';

		$pendingAbsence = new Absence();
		$pendingAbsence->setStatus(Absence::STATUS_PENDING);

		$approvedAbsence = new Absence();
		$approvedAbsence->setStatus(Absence::STATUS_APPROVED);

		$this->absenceMapper->expects($this->once())
			->method('findByUser')
			->with($userId, null, null)
			->willReturn([$pendingAbsence, $approvedAbsence]);

		$filters = ['status' => Absence::STATUS_PENDING];
		$result = $this->service->getAbsencesByUser($userId, $filters);

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertSame($pendingAbsence, $result[0]);
	}

	/**
	 * Test substitute approves absence (Vertretungs-Freigabe)
	 */
	public function testApproveBySubstitute(): void
	{
		$absenceId = 123;
		$substituteUserId = 'substitute1';
		$employeeUserId = 'employee1';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId', 'getId', 'getType', 'getStartDate', 'getEndDate', 'getDays', 'setStatus', 'setApproverComment'])
			->onlyMethods(['getSummary'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->method('getSubstituteUserId')->willReturn($substituteUserId);
		$absence->method('getUserId')->willReturn($employeeUserId);
		$absence->method('getId')->willReturn($absenceId);
		$absence->method('getType')->willReturn(Absence::TYPE_VACATION);
		$absence->method('getStartDate')->willReturn(new \DateTime('2024-06-01'));
		$absence->method('getEndDate')->willReturn(new \DateTime('2024-06-05'));
		$absence->method('getDays')->willReturn(5);
		$absence->method('getSummary')->willReturn(['id' => $absenceId]);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$absence->expects($this->once())
			->method('setStatus')
			->with(Absence::STATUS_PENDING);

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($absence)
			->willReturn($absence);

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($employeeUserId, 'absence_substitute_approved', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $substituteUserId);

		$this->notificationService->expects($this->once())
			->method('notifySubstituteApproved')
			->with($employeeUserId, $substituteUserId, $this->isType('array'));

		$result = $this->service->approveBySubstitute($absenceId, $substituteUserId);

		$this->assertSame($absence, $result);
	}

	/**
	 * Test approveBySubstitute rejects when wrong substitute
	 */
	public function testApproveBySubstituteRejectsWrongSubstitute(): void
	{
		$absenceId = 123;
		$wrongSubstituteId = 'wrong_substitute';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->method('getSubstituteUserId')->willReturn('designated_substitute');
		$absence->method('getUserId')->willReturn('employee1');

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('You are not the designated substitute for this absence');

		$this->service->approveBySubstitute($absenceId, $wrongSubstituteId);
	}

	/**
	 * Test approveBySubstitute rejects when status is not substitute_pending
	 */
	public function testApproveBySubstituteRejectsWrongStatus(): void
	{
		$absenceId = 123;
		$substituteUserId = 'substitute1';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_PENDING);
		$absence->method('getSubstituteUserId')->willReturn($substituteUserId);
		$absence->method('getUserId')->willReturn('employee1');

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Absence is not awaiting substitute approval');

		$this->service->approveBySubstitute($absenceId, $substituteUserId);
	}

	public function testApproveBySubstituteBlockedWhenMonthFinalized(): void
	{
		$absenceId = 456;
		$substituteUserId = 'substitute1';

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId('employee1');
		$absence->setStatus(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->setSubstituteUserId($substituteUserId);
		$absence->setStartDate(new \DateTime('2026-04-05'));
		$absence->setEndDate(new \DateTime('2026-04-06'));

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->monthClosureService->expects($this->once())
			->method('assertDateRangeMutable')
			->with(
				'employee1',
				$this->isInstanceOf(\DateTimeInterface::class),
				$this->isInstanceOf(\DateTimeInterface::class)
			)
			->willThrowException(new \Exception('Month is finalized'));

		$this->absenceMapper->expects($this->never())->method('update');

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Month is finalized');

		$this->service->approveBySubstitute($absenceId, $substituteUserId);
	}

	/**
	 * Test substitute declines absence
	 */
	public function testDeclineBySubstitute(): void
	{
		$absenceId = 123;
		$substituteUserId = 'substitute1';
		$employeeUserId = 'employee1';
		$comment = 'I cannot cover these dates';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId', 'getId', 'getType', 'getStartDate', 'getEndDate', 'getDays', 'setStatus', 'setApproverComment'])
			->onlyMethods(['getSummary'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->method('getSubstituteUserId')->willReturn($substituteUserId);
		$absence->method('getUserId')->willReturn($employeeUserId);
		$absence->method('getId')->willReturn($absenceId);
		$absence->method('getType')->willReturn(Absence::TYPE_VACATION);
		$absence->method('getStartDate')->willReturn(new \DateTime('2024-06-01'));
		$absence->method('getEndDate')->willReturn(new \DateTime('2024-06-05'));
		$absence->method('getDays')->willReturn(5);
		$absence->method('getSummary')->willReturn(['id' => $absenceId]);

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$absence->expects($this->once())
			->method('setStatus')
			->with(Absence::STATUS_SUBSTITUTE_DECLINED);

		$absence->expects($this->once())
			->method('setApproverComment')
			->with($comment);

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($absence)
			->willReturn($absence);

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($employeeUserId, 'absence_substitute_declined', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $substituteUserId);

		$this->notificationService->expects($this->once())
			->method('notifySubstituteDeclined')
			->with($employeeUserId, $substituteUserId, $this->isType('array'));

		$result = $this->service->declineBySubstitute($absenceId, $substituteUserId, $comment);

		$this->assertSame($absence, $result);
	}

	/**
	 * Test declineBySubstitute rejects when wrong substitute
	 */
	public function testDeclineBySubstituteRejectsWrongSubstitute(): void
	{
		$absenceId = 123;
		$wrongSubstituteId = 'wrong_substitute';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->method('getSubstituteUserId')->willReturn('designated_substitute');
		$absence->method('getUserId')->willReturn('employee1');

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('You are not the designated substitute for this absence');

		$this->service->declineBySubstitute($absenceId, $wrongSubstituteId, 'comment');
	}

	/**
	 * BOLA oracle regression (Atlas web_api 2026-09-23): the designated-substitute
	 * check must run BEFORE the substitute-pending status check, otherwise a
	 * non-designated caller can distinguish exists+awaiting-substitute
	 * ("not the designated substitute") from exists+other-status
	 * ("not awaiting substitute approval") from absent ("Absence not found").
	 */
	public function testApproveBySubstituteDeniesBeforeStatusCheck(): void
	{
		$absenceId = 123;
		$wrongSubstituteId = 'wrong_substitute';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId'])
			->getMock();
		// Already decided — must still report "not the designated substitute",
		// never reveal the real status to a non-designated caller.
		$absence->method('getStatus')->willReturn(Absence::STATUS_APPROVED);
		$absence->method('getSubstituteUserId')->willReturn('designated_substitute');
		$absence->method('getUserId')->willReturn('employee1');

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('You are not the designated substitute for this absence');

		$this->service->approveBySubstitute($absenceId, $wrongSubstituteId);
	}

	public function testDeclineBySubstituteDeniesBeforeStatusCheck(): void
	{
		$absenceId = 123;
		$wrongSubstituteId = 'wrong_substitute';

		$absence = $this->getMockBuilder(Absence::class)
			->addMethods(['getStatus', 'getSubstituteUserId', 'getUserId'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_CANCELLED);
		$absence->method('getSubstituteUserId')->willReturn('designated_substitute');
		$absence->method('getUserId')->willReturn('employee1');

		$this->absenceMapper->expects($this->exactly(2))
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('You are not the designated substitute for this absence');

		$this->service->declineBySubstitute($absenceId, $wrongSubstituteId, 'comment');
	}

	/**
	 * When no assignable manager exists, requests without substitute are auto-approved at creation.
	 */
	public function testCreateAbsenceAutoApprovesWhenNoAssignableManager(): void
	{
		$this->hasAssignableManagerForTests = false;

		$userId = 'solo';
		$start = (new \DateTime())->modify('+14 days');
		$end = (clone $start)->modify('+2 days');
		$data = [
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'reason' => 'Test',
		];

		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(3.0);

		$inserted = new Absence();
		$inserted->setId(500);
		$inserted->setUserId($userId);
		$inserted->setType(Absence::TYPE_SICK_LEAVE);
		$inserted->setStartDate(clone $start);
		$inserted->setEndDate(clone $end);
		$inserted->setStatus(Absence::STATUS_PENDING);
		$inserted->setDays(3.0);
		$inserted->setCreatedAt(new \DateTime());
		$inserted->setUpdatedAt(new \DateTime());

		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($inserted);
		$this->absenceMapper->expects($this->once())->method('update')
			->willReturnCallback(function ($a) {
				$this->assertSame(Absence::STATUS_APPROVED, $a->getStatus());
				return $a;
			});
		$this->auditLogMapper->expects($this->exactly(2))->method('logAction');
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('rollBack');

		$this->notificationService->expects($this->once())
			->method('notifyAbsenceApproved')
			->with(
				$userId,
				$this->callback(static function (array $payload): bool {
					return ($payload['days'] ?? null) === 3.0
						&& ($payload['type'] ?? '') === Absence::TYPE_SICK_LEAVE;
				})
			);

		$result = $this->service->createAbsence($data, $userId);
		$this->assertSame(Absence::STATUS_APPROVED, $result->getStatus());
	}

	public function testCreateApprovedAbsenceForEmployeeByManager(): void
	{
		$managerId = 'mgr1';
		$targetId = 'emp1';
		$start = (new \DateTime())->modify('-40 days');
		$end = (clone $start)->modify('+2 days');
		$data = [
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => $start->format('Y-m-d'),
			'end_date' => $end->format('Y-m-d'),
			'reason' => 'HR migration',
		];

		$this->absenceMapper->expects($this->exactly(2))->method('findOverlapping')->willReturn([]);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(3.0);

		$inserted = new Absence();
		$inserted->setId(9001);
		$inserted->setUserId($targetId);
		$inserted->setType(Absence::TYPE_SICK_LEAVE);
		$inserted->setStartDate(clone $start);
		$inserted->setEndDate(clone $end);
		$inserted->setStatus(Absence::STATUS_APPROVED);
		$inserted->setDays(3.0);
		$inserted->setCreatedAt(new \DateTime());

		$this->absenceMapper->expects($this->once())->method('lockUserAbsenceWindow');
		$this->absenceMapper->expects($this->once())->method('insert')->willReturn($inserted);
		$this->auditLogMapper->expects($this->once())->method('logAction')->with(
			$targetId,
			'absence_manager_recorded',
			'absence',
			9001,
			null,
			$this->isType('array'),
			$managerId
		);
		$this->db->method('beginTransaction');
		$this->db->method('commit');
		$this->db->method('rollBack');

		$this->notificationService->expects($this->once())->method('notifyAbsenceApproved');

		$result = $this->service->createApprovedAbsenceForEmployeeByManager($managerId, $targetId, $data);
		$this->assertSame(Absence::STATUS_APPROVED, $result->getStatus());
		$this->assertSame($targetId, $result->getUserId());
	}

	public function testCreateApprovedAbsenceForEmployeeByManagerRejectsSelf(): void
	{
		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('You cannot record an absence for yourself with this action.');

		$this->service->createApprovedAbsenceForEmployeeByManager('u1', 'u1', [
			'type' => Absence::TYPE_SICK_LEAVE,
			'start_date' => '2024-01-02',
			'end_date' => '2024-01-03',
		]);
	}

	/**
	 * Historical entries (end date strictly before today) must never enter the
	 * SUBSTITUTE_PENDING workflow even if the caller submits a substitute_user_id.
	 * Vertretung is a forward-looking workflow and is meaningless once the period
	 * has elapsed. The substitute reference is silently dropped and the status
	 * routes directly into PENDING (or AUTO_APPROVED downstream, depending on the
	 * team configuration). This guards against API consumers (mobile app, scripts)
	 * accidentally putting historical migration records into a pending-substitute
	 * limbo that no one can resolve.
	 */
	public function testCreateAbsencePastDateDropsSubstituteAndStaysPending(): void
	{
		$userId = 'testuser';
		$tenDaysAgo = (new \DateTime())->modify('-10 days')->format('Y-m-d');
		$fiveDaysAgo = (new \DateTime())->modify('-5 days')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $tenDaysAgo,
			'end_date' => $fiveDaysAgo,
			'reason' => 'Migrated vacation',
			'substitute_user_id' => 'colleague1',
		];

		$this->absenceMapper->expects($this->exactly(2))
			->method('findOverlapping')
			->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$persisted = null;
		$this->absenceMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (Absence $absence) use (&$persisted) {
				$absence->setId(9001);
				$persisted = $absence;
				return $absence;
			});
		$this->auditLogMapper->expects($this->once())->method('logAction');
		/* The substitute notification path must never fire for historical entries.
		 * If it did, the substitute would receive a misleading "please approve a
		 * shift that already happened" notification. */
		$this->notificationService->expects($this->never())->method('notifySubstitutionRequest');

		$result = $this->service->createAbsence($data, $userId);

		$this->assertNotNull($result);
		$this->assertInstanceOf(Absence::class, $persisted);
		/** @var Absence $persisted */
		$this->assertNull($persisted->getSubstituteUserId(),
			'Substitute reference must be cleared for historical entries.');
		$this->assertNotSame(Absence::STATUS_SUBSTITUTE_PENDING, $persisted->getStatus(),
			'Historical entries must never enter the substitute-pending workflow.');
	}

	/**
	 * Sanity check that a future absence with a substitute still routes through
	 * the substitute approval workflow. Regression guard for the past-aware
	 * branch above so we do not accidentally short-circuit live requests.
	 */
	public function testCreateAbsenceFutureDateWithSubstituteStillRoutesToSubstitutePending(): void
	{
		$userId = 'testuser';
		$start = (new \DateTime())->modify('+14 days')->format('Y-m-d');
		$end = (new \DateTime())->modify('+18 days')->format('Y-m-d');
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $start,
			'end_date' => $end,
			'reason' => 'Future trip',
			'substitute_user_id' => 'colleague1',
		];

		$this->absenceMapper->expects($this->exactly(2))->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);

		$persisted = null;
		$this->absenceMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (Absence $absence) use (&$persisted) {
				$absence->setId(9002);
				$persisted = $absence;
				return $absence;
			});
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$this->service->createAbsence($data, $userId);

		$this->assertInstanceOf(Absence::class, $persisted);
		/** @var Absence $persisted */
		$this->assertSame('colleague1', $persisted->getSubstituteUserId());
		$this->assertSame(Absence::STATUS_SUBSTITUTE_PENDING, $persisted->getStatus());
	}

	/**
	 * The absence summary exposes server-computed temporal flags so the frontend
	 * does not have to re-derive "today" in JS where iPadOS / Chrome have been
	 * observed to drift up to a calendar day due to DST and timezone offsets.
	 * Exercising the entity directly keeps this fast and free of test doubles.
	 */
	public function testAbsenceSummaryExposesPastCurrentFutureFlags(): void
	{
		$yesterday = (new \DateTime())->modify('-1 day');
		$twoDaysAgo = (new \DateTime())->modify('-2 days');
		$tomorrow = (new \DateTime())->modify('+1 day');
		$inTwoDays = (new \DateTime())->modify('+2 days');
		$today = new \DateTime('today');

		$past = new Absence();
		$past->setUserId('u1');
		$past->setType(Absence::TYPE_VACATION);
		$past->setStartDate($twoDaysAgo);
		$past->setEndDate($yesterday);
		$past->setStatus(Absence::STATUS_APPROVED);
		$past->setDays(2.0);

		$current = new Absence();
		$current->setUserId('u1');
		$current->setType(Absence::TYPE_VACATION);
		$current->setStartDate($yesterday);
		$current->setEndDate($tomorrow);
		$current->setStatus(Absence::STATUS_APPROVED);
		$current->setDays(3.0);

		$future = new Absence();
		$future->setUserId('u1');
		$future->setType(Absence::TYPE_VACATION);
		$future->setStartDate($tomorrow);
		$future->setEndDate($inTwoDays);
		$future->setStatus(Absence::STATUS_PENDING);
		$future->setDays(2.0);

		$pastSummary = $past->getSummary();
		$currentSummary = $current->getSummary();
		$futureSummary = $future->getSummary();

		$this->assertTrue($pastSummary['isPast']);
		$this->assertFalse($pastSummary['isCurrent']);
		$this->assertFalse($pastSummary['isFuture']);

		$this->assertFalse($currentSummary['isPast']);
		$this->assertTrue($currentSummary['isCurrent']);
		$this->assertFalse($currentSummary['isFuture']);

		$this->assertFalse($futureSummary['isPast']);
		$this->assertFalse($futureSummary['isCurrent']);
		$this->assertTrue($futureSummary['isFuture']);

		// Sanity check on unused `today` reference to silence static analysis
		// while keeping the assertion intent explicit.
		$this->assertInstanceOf(\DateTime::class, $today);
	}

	/**
	 * `isManagerRecorded` is the server-side heuristic the manager-record API
	 * uses (and emits as the `absence_manager_recorded` audit-log action). It
	 * encodes "approved by a human approver other than the absence owner and
	 * other than the system." Clients can rely on this flag to render a clear
	 * "Recorded by manager" badge without re-implementing the heuristic in JS.
	 */
	public function testAbsenceSummaryDetectsManagerRecorded(): void
	{
		$start = (new \DateTime())->modify('-5 days');
		$end = (new \DateTime())->modify('-1 day');

		$managerRecorded = new Absence();
		$managerRecorded->setUserId('emp1');
		$managerRecorded->setType(Absence::TYPE_VACATION);
		$managerRecorded->setStartDate($start);
		$managerRecorded->setEndDate($end);
		$managerRecorded->setStatus(Absence::STATUS_APPROVED);
		$managerRecorded->setApprovedByUserId('manager1');
		$managerRecorded->setDays(4.0);

		$autoApprovedBySystem = new Absence();
		$autoApprovedBySystem->setUserId('emp2');
		$autoApprovedBySystem->setType(Absence::TYPE_VACATION);
		$autoApprovedBySystem->setStartDate($start);
		$autoApprovedBySystem->setEndDate($end);
		$autoApprovedBySystem->setStatus(Absence::STATUS_APPROVED);
		$autoApprovedBySystem->setApprovedByUserId('system');
		$autoApprovedBySystem->setDays(4.0);

		$selfApproved = new Absence();
		$selfApproved->setUserId('emp3');
		$selfApproved->setType(Absence::TYPE_VACATION);
		$selfApproved->setStartDate($start);
		$selfApproved->setEndDate($end);
		$selfApproved->setStatus(Absence::STATUS_APPROVED);
		$selfApproved->setApprovedByUserId('emp3');
		$selfApproved->setDays(4.0);

		$pendingNoApprover = new Absence();
		$pendingNoApprover->setUserId('emp4');
		$pendingNoApprover->setType(Absence::TYPE_VACATION);
		$pendingNoApprover->setStartDate($start);
		$pendingNoApprover->setEndDate($end);
		$pendingNoApprover->setStatus(Absence::STATUS_PENDING);
		$pendingNoApprover->setDays(4.0);

		$this->assertTrue($managerRecorded->getSummary()['isManagerRecorded']);
		$this->assertFalse($autoApprovedBySystem->getSummary()['isManagerRecorded']);
		$this->assertFalse($selfApproved->getSummary()['isManagerRecorded']);
		$this->assertFalse($pendingNoApprover->getSummary()['isManagerRecorded']);
	}
}
