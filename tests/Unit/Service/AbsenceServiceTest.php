<?php

declare(strict_types=1);

/**
 * Unit tests for AbsenceService
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
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

	/** @var IDBConnection|\PHPUnit\Framework\MockObject\MockObject */
	private $db;

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
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->with('arbeitszeitcheck', 'require_substitute_types', '[]')->willReturn('[]');
		$this->db = $this->createMock(IDBConnection::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->vacationYearBalanceMapper = $this->createMock(VacationYearBalanceMapper::class);
		$this->vacationYearBalanceMapper->method('getCarryoverDays')->willReturn(0.0);
		$this->l10n = $this->createMock(IL10N::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->holidayCalendarService = $this->createMock(HolidayService::class);

		$this->l10n->method('t')
			->willReturnCallback(function ($text) {
				return $text;
			});

		$this->vacationAllocationStub = null;
		$this->vacationAllocationFailProspective = false;
		$this->vacationAllocationService = $this->createMock(VacationAllocationService::class);
		$this->vacationAllocationService->method('computeYearAllocation')->willReturnCallback(function ($userId, $year, $exclude, $pStart, $pEnd, $asOf, $createdAt = null) {
			unset($userId, $year, $exclude, $asOf, $createdAt);
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
			$this->userManager,
			$this->l10n,
			$this->notificationService,
			null,
			$this->holidayCalendarService,
			$this->vacationYearBalanceMapper,
			$this->vacationAllocationService,
			null
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

		$this->absenceMapper->expects($this->once())
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
			->method('insert')
			->willReturn($absence);

		// Mock audit logging
		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_created', 'absence', 123, null, $this->isType('array'));

		$result = $this->service->createAbsence($data, $userId);

		$this->assertSame($absence, $result);
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
	 * Test creating absence with past start date
	 */
	public function testCreateAbsencePastStartDate(): void
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

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Start date cannot be in the past');

		$this->service->createAbsence($data, $userId);
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

		$this->absenceMapper->expects($this->once())
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
	 * Test sick leave with start date more than 7 days in past – should fail
	 */
	public function testCreateAbsenceSickLeaveTooFarInPast(): void
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

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Sick leave start date cannot be more than');

		$this->service->createAbsence($data, $userId);
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
		$this->expectExceptionMessage('Not enough vacation days remaining');

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

		$this->absenceMapper->expects($this->once())
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

		$this->absenceMapper->expects($this->once())
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

		$this->absenceMapper->expects($this->once())
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

		$this->absenceMapper->expects($this->once())
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
			->with($approverId, 'absence_approved', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $approverId);

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
		$absence->setStatus(Absence::STATUS_APPROVED);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Absence is not pending approval');

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

		$this->absenceMapper->expects($this->once())
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
			->with($approverId, 'absence_rejected', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $approverId);

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

		$this->absenceMapper->expects($this->once())
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
			->with($substituteUserId, 'absence_substitute_approved', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $substituteUserId);

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
			->addMethods(['getStatus', 'getSubstituteUserId'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->method('getSubstituteUserId')->willReturn('designated_substitute');

		$this->absenceMapper->expects($this->once())
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
			->addMethods(['getStatus', 'getSubstituteUserId'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_PENDING);
		$absence->method('getSubstituteUserId')->willReturn($substituteUserId);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Absence is not awaiting substitute approval');

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

		$this->absenceMapper->expects($this->once())
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
			->with($substituteUserId, 'absence_substitute_declined', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $substituteUserId);

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
			->addMethods(['getStatus', 'getSubstituteUserId'])
			->getMock();
		$absence->method('getStatus')->willReturn(Absence::STATUS_SUBSTITUTE_PENDING);
		$absence->method('getSubstituteUserId')->willReturn('designated_substitute');

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('You are not the designated substitute for this absence');

		$this->service->declineBySubstitute($absenceId, $wrongSubstituteId, 'comment');
	}
}
