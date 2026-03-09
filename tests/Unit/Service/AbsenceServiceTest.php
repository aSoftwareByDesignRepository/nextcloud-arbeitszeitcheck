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
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
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

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

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
		$this->userManager = $this->createMock(IUserManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->notificationService = $this->createMock(NotificationService::class);

		$this->l10n->method('t')
			->willReturnCallback(function ($text) {
				return $text;
			});

		$this->service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->teamResolver,
			$this->config,
			$this->userManager,
			$this->l10n,
			$this->notificationService
		);
	}

	/**
	 * Test creating a valid absence request
	 */
	public function testCreateAbsenceSuccess(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2024-06-01',
			'end_date' => '2024-06-05',
			'reason' => 'Summer vacation'
		];

		// Mock no overlapping absences
		$this->absenceMapper->expects($this->once())
			->method('findOverlapping')
			->with($userId, $this->isInstanceOf(\DateTime::class), $this->isInstanceOf(\DateTime::class), $this->anything())
			->willReturn([]);

		// Mock absence creation
		$absence = $this->createMock(Absence::class);
		$absence->method('calculateWorkingDays')->willReturn(5);
		$absence->method('getId')->willReturn(123);
		$absence->method('getSummary')->willReturn(['id' => 123, 'type' => Absence::TYPE_VACATION]);

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
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => $yesterday,
			'end_date' => '2024-12-31',
			'reason' => 'Past date'
		];

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Start date cannot be in the past');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test creating absence with overlapping dates
	 */
	public function testCreateAbsenceOverlapping(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2024-06-01',
			'end_date' => '2024-06-05',
			'reason' => 'Overlapping'
		];

		// Mock overlapping absence exists
		$existingAbsence = $this->createMock(Absence::class);
		$this->absenceMapper->expects($this->once())
			->method('findOverlapping')
			->with($userId, $this->isInstanceOf(\DateTime::class), $this->isInstanceOf(\DateTime::class), $this->anything())
			->willReturn([$existingAbsence]);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Absence overlaps with existing absence');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test creating vacation absence exceeding 30 days
	 */
	public function testCreateAbsenceVacationExceedsLimit(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_VACATION,
			'start_date' => '2024-06-01',
			'end_date' => '2024-07-05', // 35 days
			'reason' => 'Too long'
		];

		// Mock no overlapping absences
		$this->absenceMapper->expects($this->once())
			->method('findOverlapping')
			->willReturn([]);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Vacation cannot exceed 30 days');

		$this->service->createAbsence($data, $userId);
	}

	/**
	 * Test creating personal leave exceeding 5 days
	 */
	public function testCreateAbsencePersonalLeaveExceedsLimit(): void
	{
		$userId = 'testuser';
		$data = [
			'type' => Absence::TYPE_PERSONAL_LEAVE,
			'start_date' => '2024-06-01',
			'end_date' => '2024-06-10', // 10 days
			'reason' => 'Too long'
		];

		// Mock no overlapping absences
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

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($userId);

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

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($otherUserId);

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

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($userId);
		$absence->method('getStatus')->willReturn(Absence::STATUS_PENDING);
		$absence->method('getType')->willReturn(Absence::TYPE_VACATION);
		$absence->method('getStartDate')->willReturn(new \DateTime('2024-06-01'));
		$absence->method('getEndDate')->willReturn(new \DateTime('2024-06-05'));
		$absence->method('getReason')->willReturn('Original reason');
		$absence->method('calculateWorkingDays')->willReturn(5);
		$absence->method('getId')->willReturn($absenceId);
		$absence->method('getSummary')->willReturn(['id' => $absenceId]);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		// Mock no overlapping absences
		$this->absenceMapper->expects($this->once())
			->method('findOverlapping')
			->willReturn([]);

		$absence->expects($this->once())
			->method('setStartDate')
			->with($this->isInstanceOf(\DateTime::class));

		$absence->expects($this->once())
			->method('setEndDate')
			->with($this->isInstanceOf(\DateTime::class));

		$absence->expects($this->once())
			->method('setDays')
			->with(5);

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($absence)
			->willReturn($absence);

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($userId, 'absence_updated', 'absence', $absenceId, $this->isType('array'), $this->isType('array'));

		$updateData = [
			'start_date' => '2024-06-02',
			'end_date' => '2024-06-06'
		];

		$result = $this->service->updateAbsence($absenceId, $updateData, $userId);

		$this->assertSame($absence, $result);
	}

	/**
	 * Test updating a non-pending absence (should fail)
	 */
	public function testUpdateAbsenceNotPending(): void
	{
		$userId = 'testuser';
		$absenceId = 123;

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($userId);
		$absence->method('getStatus')->willReturn(Absence::STATUS_APPROVED);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Only pending absences can be updated');

		$this->service->updateAbsence($absenceId, ['start_date' => '2024-06-02'], $userId);
	}

	/**
	 * Test deleting a pending absence
	 */
	public function testDeleteAbsencePending(): void
	{
		$userId = 'testuser';
		$absenceId = 123;

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($userId);
		$absence->method('getStatus')->willReturn(Absence::STATUS_PENDING);
		$absence->method('getId')->willReturn($absenceId);
		$absence->method('getSummary')->willReturn(['id' => $absenceId]);

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

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($userId);
		$absence->method('getStatus')->willReturn(Absence::STATUS_APPROVED);

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

		$absence = $this->createMock(Absence::class);
		$absence->method('getStatus')->willReturn(Absence::STATUS_PENDING);
		$absence->method('getUserId')->willReturn($userId);
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
			->with(Absence::STATUS_APPROVED);

		$absence->expects($this->once())
			->method('setApproverComment')
			->with($comment);

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($absence)
			->willReturn($absence);

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($approverId, 'absence_approved', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $approverId);

		$this->notificationService->expects($this->once())
			->method('notifyAbsenceApproved')
			->with($userId, $this->isType('array'));

		$result = $this->service->approveAbsence($absenceId, $approverId, $comment);

		$this->assertSame($absence, $result);
	}

	/**
	 * Test approving a non-pending absence (should fail)
	 */
	public function testApproveAbsenceNotPending(): void
	{
		$approverId = 'manager';
		$absenceId = 123;

		$absence = $this->createMock(Absence::class);
		$absence->method('getStatus')->willReturn(Absence::STATUS_APPROVED);

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

		$absence = $this->createMock(Absence::class);
		$absence->method('getStatus')->willReturn(Absence::STATUS_PENDING);
		$absence->method('getUserId')->willReturn($userId);
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
			->with(Absence::STATUS_REJECTED);

		$absence->expects($this->once())
			->method('setApproverComment')
			->with($comment);

		$this->absenceMapper->expects($this->once())
			->method('update')
			->with($absence)
			->willReturn($absence);

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with($approverId, 'absence_rejected', 'absence', $absenceId, $this->isType('array'), $this->isType('array'), $approverId);

		$this->notificationService->expects($this->once())
			->method('notifyAbsenceRejected')
			->with($userId, $this->isType('array'), $comment);

		$result = $this->service->rejectAbsence($absenceId, $approverId, $comment);

		$this->assertSame($absence, $result);
	}

	/**
	 * Test getting vacation statistics
	 */
	public function testGetVacationStats(): void
	{
		$userId = 'testuser';
		$year = 2024;

		// Mock vacation days used
		$this->absenceMapper->expects($this->once())
			->method('getVacationDaysUsed')
			->with($userId, $year)
			->willReturn(15);

		// Mock sick leave days
		$this->absenceMapper->expects($this->once())
			->method('getSickLeaveDays')
			->with($userId, $year)
			->willReturn(3);

		// Mock vacation entitlement from user settings
		$this->userSettingsMapper->expects($this->once())
			->method('getIntegerSetting')
			->with($userId, 'vacation_days_per_year', 25)
			->willReturn(30);

		$stats = $this->service->getVacationStats($userId, $year);

		$this->assertIsArray($stats);
		$this->assertEquals($year, $stats['year']);
		$this->assertEquals(30, $stats['entitlement']);
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
			->method('getVacationDaysUsed')
			->willReturn(10);

		$this->absenceMapper->expects($this->once())
			->method('getSickLeaveDays')
			->willReturn(0);

		// Mock no user setting (returns default 25)
		$this->userSettingsMapper->expects($this->once())
			->method('getIntegerSetting')
			->with($userId, 'vacation_days_per_year', 25)
			->willReturn(25);

		$stats = $this->service->getVacationStats($userId, $year);

		$this->assertEquals(25, $stats['entitlement']);
		$this->assertEquals(10, $stats['used']);
		$this->assertEquals(15, $stats['remaining']); // 25 - 10 = 15
	}

	/**
	 * Test getting absences by user with filters
	 */
	public function testGetAbsencesByUserWithStatusFilter(): void
	{
		$userId = 'testuser';

		$pendingAbsence = $this->createMock(Absence::class);
		$pendingAbsence->method('getStatus')->willReturn(Absence::STATUS_PENDING);

		$approvedAbsence = $this->createMock(Absence::class);
		$approvedAbsence->method('getStatus')->willReturn(Absence::STATUS_APPROVED);

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
