<?php

declare(strict_types=1);

/**
 * Unit tests for ManagerController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\ManagerController;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroup;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Class ManagerControllerTest
 */
class ManagerControllerTest extends TestCase
{
	/** @var ManagerController */
	private $controller;

	/** @var AbsenceService|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceService;

	/** @var TimeTrackingService|\PHPUnit\Framework\MockObject\MockObject */
	private $timeTrackingService;

	/** @var ComplianceService|\PHPUnit\Framework\MockObject\MockObject */
	private $complianceService;

	/** @var AbsenceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var PermissionService|\PHPUnit\Framework\MockObject\MockObject */
	private $permissionService;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var TeamResolverService|\PHPUnit\Framework\MockObject\MockObject */
	private $teamResolver;

	/** @var CSPService|\PHPUnit\Framework\MockObject\MockObject */
	private $cspService;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	protected function setUp(): void
	{
		parent::setUp();

		$this->absenceService = $this->createMock(AbsenceService::class);
		$this->timeTrackingService = $this->createMock(TimeTrackingService::class);
		$this->complianceService = $this->createMock(ComplianceService::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->cspService = $this->createMock(CSPService::class);
		$this->cspService->method('applyPolicyWithNonce')->willReturnCallback(static fn ($response) => $response);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(fn ($s) => $s);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new ManagerController(
			'arbeitszeitcheck',
			$this->request,
			$this->absenceService,
			$this->timeTrackingService,
			$this->complianceService,
			$this->absenceMapper,
			$this->teamResolver,
			$this->permissionService,
			$this->userSession,
			$this->userManager,
			$this->cspService,
			$this->l10n
		);
	}

	/**
	 * Test dashboard returns template when user has team or is admin
	 */
	public function testDashboardReturnsTemplate(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$employee = $this->createMock(IUser::class);
		$employee->method('getDisplayName')->willReturn('Employee One');
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canAccessManagerDashboard')->with('manager1')->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);
		$this->userManager->method('get')->with('employee1')->willReturn($employee);
		$this->timeTrackingService->method('getTodayHours')->willReturn(0.0);
		$this->timeTrackingService->method('getStatus')->willReturn(['status' => 'clocked_out']);
		$this->absenceService->method('getAbsencesByUser')->willReturn([]);

		$response = $this->controller->dashboard();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getApp());
		$this->assertEquals('manager-dashboard', $response->getTemplateName());
	}

	/**
	 * Test dashboard redirects non-managers (no team, not admin)
	 */
	public function testDashboardRedirectsNonManagers(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('employee1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canAccessManagerDashboard')->with('employee1')->willReturn(false);

		$response = $this->controller->dashboard();

		$this->assertInstanceOf(\OCP\AppFramework\Http\RedirectResponse::class, $response);
	}

	/**
	 * Test getTeamOverview returns team members
	 */
	public function testGetTeamOverviewReturnsTeamMembers(): void
	{
		$managerId = 'manager1';
		$teamMemberId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$teamMember = $this->createMock(IUser::class);
		$teamMember->method('getUID')->willReturn($teamMemberId);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$teamMember]);

		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with($managerId)->willReturn([$teamMemberId]);
		$this->userManager->method('get')->with($teamMemberId)->willReturn($teamMember);
		$this->userManager->method('getDisplayName')->with($teamMemberId)->willReturn('Employee One');

		$this->timeTrackingService->method('getTodayHours')->willReturn(8.0);
		$this->timeTrackingService->method('getStatus')->willReturn(['status' => 'clocked_out']);

		$this->absenceService->method('getAbsencesByUser')
			->willReturn([]);

		$this->complianceService->method('getComplianceStatus')
			->willReturn(['compliant' => true]);

		// Mock OvertimeService via Server::get
		$overtimeService = $this->createMock(\OCA\ArbeitszeitCheck\Service\OvertimeService::class);
		$overtimeService->method('calculateOvertime')->willReturn([
			'total_hours_worked' => 40.0
		]);
		$overtimeService->method('getDailyOvertime')->willReturn([
			'overtime_hours' => 0.0
		]);

		// Use reflection to mock Server::get
		$reflection = new \ReflectionClass($this->controller);
		// Note: We can't easily mock Server::get in unit tests, so we'll test the logic that doesn't require it

		$response = $this->controller->getTeamOverview();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('teamMembers', $data);
	}

	/**
	 * Test getTeamOverview returns empty when no team members
	 */
	public function testGetTeamOverviewReturnsEmptyWhenNoTeamMembers(): void
	{
		$managerId = 'manager1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with($managerId)->willReturn([]);

		$response = $this->controller->getTeamOverview();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertEmpty($data['teamMembers']);
		$this->assertEquals(0, $data['total']);
	}

	/**
	 * Test getPendingApprovals returns absences and time entries
	 */
	public function testGetPendingApprovalsReturnsBothTypes(): void
	{
		$managerId = 'manager1';
		$teamMemberId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$teamMember = $this->createMock(IUser::class);
		$teamMember->method('getUID')->willReturn($teamMemberId);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$teamMember]);

		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with($managerId)->willReturn([$teamMemberId]);
		$this->userManager->method('getDisplayName')->willReturn('Employee One');

		$absence = $this->createMock(Absence::class);
		$absence->method('getId')->willReturn(1);
		$absence->method('getUserId')->willReturn($teamMemberId);
		$absence->method('getSummary')->willReturn(['id' => 1]);
		$absence->method('getCreatedAt')->willReturn(new \DateTime());

		$this->absenceMapper->method('findPendingForUsers')
			->willReturn([$absence]);

		$timeEntry = $this->createMock(TimeEntry::class);
		$timeEntry->method('getId')->willReturn(1);
		$timeEntry->method('getUserId')->willReturn($teamMemberId);
		$timeEntry->method('getStartTime')->willReturn(new \DateTime('2024-01-15 09:00:00'));
		$timeEntry->method('getEndTime')->willReturn(new \DateTime('2024-01-15 17:00:00'));
		$timeEntry->method('getDurationHours')->willReturn(8.0);
		$timeEntry->method('getDescription')->willReturn('Work');
		$timeEntry->method('getJustification')->willReturn(json_encode([
			'justification' => 'Correction needed',
			'original' => [],
			'proposed' => [],
			'requested_at' => '2024-01-15T10:00:00Z'
		]));
		$timeEntry->method('getCreatedAt')->willReturn(new \DateTime());

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('findPendingApprovalForUsers')
			->willReturn([$timeEntry]);

		// Mock Server::get for TimeEntryMapper
		\OC::$server = $this->createMock(\OCP\IServerContainer::class);
		\OC::$server->method('get')->willReturn($timeEntryMapper);

		$response = $this->controller->getPendingApprovals();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('pendingApprovals', $data);
	}

	/**
	 * Test getPendingApprovals filters by type
	 */
	public function testGetPendingApprovalsFiltersByType(): void
	{
		$managerId = 'manager1';
		$teamMemberId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$teamMember = $this->createMock(IUser::class);
		$teamMember->method('getUID')->willReturn($teamMemberId);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$teamMember]);

		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->with($managerId)->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with($managerId)->willReturn([$teamMemberId]);
		$this->userManager->method('getDisplayName')->willReturn('Employee One');

		$absence = $this->createMock(Absence::class);
		$absence->method('getId')->willReturn(1);
		$absence->method('getUserId')->willReturn($teamMemberId);
		$absence->method('getSummary')->willReturn(['id' => 1]);
		$absence->method('getCreatedAt')->willReturn(new \DateTime());

		$this->absenceMapper->expects($this->once())
			->method('findPendingForUsers')
			->willReturn([$absence]);

		$response = $this->controller->getPendingApprovals('absence');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['pendingApprovals']);
		$this->assertEquals('absence', $data['pendingApprovals'][0]['type']);
	}

	/**
	 * Test getTeamCompliance returns compliance overview
	 */
	public function testGetTeamComplianceReturnsOverview(): void
	{
		$managerId = 'manager1';
		$teamMemberId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$teamMember = $this->createMock(IUser::class);
		$teamMember->method('getUID')->willReturn($teamMemberId);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$teamMember]);

		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->with($managerId)->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with($managerId)->willReturn([$teamMemberId]);

		$this->complianceService->method('getComplianceStatus')
			->willReturn([
				'compliant' => true,
				'critical_violations' => 0,
				'violation_count' => 0
			]);

		$response = $this->controller->getTeamCompliance();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('compliance', $data);
		$this->assertEquals(1, $data['compliance']['totalMembers']);
		$this->assertEquals(1, $data['compliance']['compliantMembers']);
	}

	/**
	 * Test getTeamHoursSummary returns hours summary
	 */
	public function testGetTeamHoursSummaryReturnsSummary(): void
	{
		$managerId = 'manager1';
		$teamMemberId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$teamMember = $this->createMock(IUser::class);
		$teamMember->method('getUID')->willReturn($teamMemberId);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$teamMember]);

		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->with($managerId)->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with($managerId)->willReturn([$teamMemberId]);
		$this->userManager->method('getDisplayName')->willReturn('Employee One');

		$this->timeTrackingService->method('getTodayHours')->willReturn(8.0);

		$response = $this->controller->getTeamHoursSummary('today');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('summary', $data);
		$this->assertEquals(8.0, $data['summary']['totalHours']);
		$this->assertEquals(8.0, $data['summary']['averageHours']);
	}

	/**
	 * Test approveAbsence approves absence when employee is in manager's team
	 */
	public function testApproveAbsenceApprovesAbsence(): void
	{
		$managerId = 'manager1';
		$employeeId = 'employee1';
		$absenceId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$this->userSession->method('getUser')->willReturn($user);

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($employeeId);
		$absence->method('getSummary')->willReturn(['id' => $absenceId]);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);
		$this->permissionService->expects($this->once())
			->method('canManageEmployee')
			->with($managerId, $employeeId)
			->willReturn(true);
		$this->absenceService->expects($this->once())
			->method('approveAbsence')
			->with($absenceId, $managerId, 'Approved')
			->willReturn($absence);

		$response = $this->controller->approveAbsence($absenceId, 'Approved');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absence', $data);
	}

	/**
	 * Test rejectAbsence rejects absence when employee is in manager's team
	 */
	public function testRejectAbsenceRejectsAbsence(): void
	{
		$managerId = 'manager1';
		$employeeId = 'employee1';
		$absenceId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$this->userSession->method('getUser')->willReturn($user);

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($employeeId);
		$absence->method('getSummary')->willReturn(['id' => $absenceId]);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);
		$this->permissionService->expects($this->once())
			->method('canManageEmployee')
			->with($managerId, $employeeId)
			->willReturn(true);
		$this->absenceService->expects($this->once())
			->method('rejectAbsence')
			->with($absenceId, $managerId, 'Not enough vacation days')
			->willReturn($absence);

		$response = $this->controller->rejectAbsence($absenceId, 'Not enough vacation days');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absence', $data);
	}

	/**
	 * Test approveAbsence returns 403 when employee is not in manager's team
	 */
	public function testApproveAbsenceReturns403WhenEmployeeNotInTeam(): void
	{
		$managerId = 'manager1';
		$employeeId = 'otheruser';
		$absenceId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$this->userSession->method('getUser')->willReturn($user);

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($employeeId);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);
		$this->permissionService->expects($this->once())
			->method('canManageEmployee')
			->with($managerId, $employeeId)
			->willReturn(false);
		$this->absenceService->expects($this->never())->method('approveAbsence');

		$response = $this->controller->approveAbsence($absenceId, 'Approved');
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test rejectAbsence returns 403 when employee is not in manager's team
	 */
	public function testRejectAbsenceReturns403WhenEmployeeNotInTeam(): void
	{
		$managerId = 'manager1';
		$employeeId = 'otheruser';
		$absenceId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$this->userSession->method('getUser')->willReturn($user);

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($employeeId);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);
		$this->permissionService->expects($this->once())
			->method('canManageEmployee')
			->with($managerId, $employeeId)
			->willReturn(false);
		$this->absenceService->expects($this->never())->method('rejectAbsence');

		$response = $this->controller->rejectAbsence($absenceId, 'Rejected');
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test approveAbsence returns 404 when absence does not exist
	 */
	public function testApproveAbsenceReturns404WhenAbsenceNotFound(): void
	{
		$managerId = 'manager1';
		$absenceId = 999;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willThrowException(new DoesNotExistException('Absence not found'));
		$this->teamResolver->expects($this->never())->method('getTeamMemberIds');
		$this->absenceService->expects($this->never())->method('approveAbsence');

		$response = $this->controller->approveAbsence($absenceId, 'Approved');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test rejectAbsence returns 404 when absence does not exist
	 */
	public function testRejectAbsenceReturns404WhenAbsenceNotFound(): void
	{
		$managerId = 'manager1';
		$absenceId = 999;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willThrowException(new DoesNotExistException('Absence not found'));
		$this->teamResolver->expects($this->never())->method('getTeamMemberIds');
		$this->absenceService->expects($this->never())->method('rejectAbsence');

		$response = $this->controller->rejectAbsence($absenceId, 'Rejected');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test approveTimeEntryCorrection approves correction
	 */
	public function testApproveTimeEntryCorrectionApprovesCorrection(): void
	{
		$managerId = 'manager1';
		$teamMemberId = 'employee1';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$teamMember = $this->createMock(IUser::class);
		$teamMember->method('getUID')->willReturn($teamMemberId);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$teamMember]);

		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canManageEmployee')->with($managerId, $teamMemberId)->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with($managerId)->willReturn([$teamMemberId]);

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getStatus')->willReturn(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->method('getUserId')->willReturn($teamMemberId);
		$entry->method('getJustification')->willReturn(json_encode(['justification' => 'Correction']));
		$entry->method('setStatus')->willReturnSelf();
		$entry->method('setApprovedByUserId')->willReturnSelf();
		$entry->method('setApprovedBy')->willReturnSelf();
		$entry->method('setApprovedAt')->willReturnSelf();
		$entry->method('setUpdatedAt')->willReturnSelf();
		$entry->method('setJustification')->willReturnSelf();
		$entry->method('getSummary')->willReturn(['id' => $entryId]);

		$updatedEntry = $this->createMock(TimeEntry::class);
		$updatedEntry->method('getSummary')->willReturn(['id' => $entryId]);
		$updatedEntry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);
		$updatedEntry->method('getEndTime')->willReturn(new \DateTime());

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('find')->with($entryId)->willReturn($entry);
		$timeEntryMapper->method('update')->willReturn($updatedEntry);

		$auditLogMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class);
		$notificationService = $this->createMock(\OCA\ArbeitszeitCheck\Service\NotificationService::class);

		// Mock Server::get
		\OC::$server = $this->createMock(\OCP\IServerContainer::class);
		\OC::$server->method('get')
			->willReturnCallback(function ($class) use ($timeEntryMapper, $auditLogMapper, $notificationService) {
				if ($class === TimeEntryMapper::class) {
					return $timeEntryMapper;
				}
				if ($class === \OCA\ArbeitszeitCheck\Db\AuditLogMapper::class) {
					return $auditLogMapper;
				}
				if ($class === \OCA\ArbeitszeitCheck\Service\NotificationService::class) {
					return $notificationService;
				}
				if ($class === \OCP\IConfig::class) {
					$config = $this->createMock(\OCP\IConfig::class);
					$config->method('getAppValue')->willReturn('1');
					return $config;
				}
				return null;
			});

		$auditLogMapper->expects($this->once())->method('logAction');
		$notificationService->expects($this->once())->method('notifyTimeEntryCorrectionApproved');

		$response = $this->controller->approveTimeEntryCorrection($entryId, 'Approved');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('entry', $data);
		$this->assertArrayHasKey('message', $data);
	}

	/**
	 * Test approveTimeEntryCorrection returns error when not pending
	 */
	public function testApproveTimeEntryCorrectionReturnsErrorWhenNotPending(): void
	{
		$managerId = 'manager1';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getStatus')->willReturn(TimeEntry::STATUS_COMPLETED);

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('find')->willReturn($entry);

		\OC::$server = $this->createMock(\OCP\IServerContainer::class);
		\OC::$server->method('get')->willReturn($timeEntryMapper);

		$response = $this->controller->approveTimeEntryCorrection($entryId);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('not pending approval', $data['error']);
	}

	/**
	 * Test approveTimeEntryCorrection returns forbidden when not in team
	 */
	public function testApproveTimeEntryCorrectionReturnsForbiddenWhenNotInTeam(): void
	{
		$managerId = 'manager1';
		$otherUserId = 'otheruser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canManageEmployee')->with($managerId, $otherUserId)->willReturn(false);

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getStatus')->willReturn(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->method('getUserId')->willReturn($otherUserId);

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('find')->willReturn($entry);

		\OC::$server = $this->createMock(\OCP\IServerContainer::class);
		\OC::$server->method('get')->willReturn($timeEntryMapper);

		$response = $this->controller->approveTimeEntryCorrection($entryId);

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Access denied', $data['error']);
	}

	/**
	 * Test rejectTimeEntryCorrection rejects correction
	 */
	public function testRejectTimeEntryCorrectionRejectsCorrection(): void
	{
		$managerId = 'manager1';
		$teamMemberId = 'employee1';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$teamMember = $this->createMock(IUser::class);
		$teamMember->method('getUID')->willReturn($teamMemberId);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$teamMember]);

		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canManageEmployee')->with($managerId, $teamMemberId)->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with($managerId)->willReturn([$teamMemberId]);

		$entry = $this->createMock(TimeEntry::class);
		$entry->method('getStatus')->willReturn(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->method('getUserId')->willReturn($teamMemberId);
		$entry->method('getJustification')->willReturn(json_encode([
			'original' => [
				'date' => '2024-01-15',
				'hours' => 8.0,
				'description' => 'Original'
			]
		]));
		$entry->method('getStartTime')->willReturn(new \DateTime('2024-01-15 09:00:00'));
		$entry->method('setStartTime')->willReturnSelf();
		$entry->method('setEndTime')->willReturnSelf();
		$entry->method('setDescription')->willReturnSelf();
		$entry->method('setStatus')->willReturnSelf();
		$entry->method('setUpdatedAt')->willReturnSelf();
		$entry->method('setJustification')->willReturnSelf();
		$entry->method('getSummary')->willReturn(['id' => $entryId]);

		$updatedEntry = $this->createMock(TimeEntry::class);
		$updatedEntry->method('getSummary')->willReturn(['id' => $entryId]);

		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$timeEntryMapper->method('find')->willReturn($entry);
		$timeEntryMapper->method('update')->willReturn($updatedEntry);

		$auditLogMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class);
		$notificationService = $this->createMock(\OCA\ArbeitszeitCheck\Service\NotificationService::class);

		\OC::$server = $this->createMock(\OCP\IServerContainer::class);
		\OC::$server->method('get')
			->willReturnCallback(function ($class) use ($timeEntryMapper, $auditLogMapper, $notificationService) {
				if ($class === TimeEntryMapper::class) {
					return $timeEntryMapper;
				}
				if ($class === \OCA\ArbeitszeitCheck\Db\AuditLogMapper::class) {
					return $auditLogMapper;
				}
				if ($class === \OCA\ArbeitszeitCheck\Service\NotificationService::class) {
					return $notificationService;
				}
				return null;
			});

		$auditLogMapper->expects($this->once())->method('logAction');
		$notificationService->expects($this->once())->method('notifyTimeEntryCorrectionRejected');

		$response = $this->controller->rejectTimeEntryCorrection($entryId, 'Invalid correction');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('entry', $data);
		$this->assertArrayHasKey('message', $data);
	}

	/**
	 * Test getTeamAbsenceCalendar returns calendar data
	 */
	public function testGetTeamAbsenceCalendarReturnsCalendarData(): void
	{
		$managerId = 'manager1';
		$teamMemberId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$teamMember = $this->createMock(IUser::class);
		$teamMember->method('getUID')->willReturn($teamMemberId);

		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$teamMember]);

		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->with($managerId)->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with($managerId)->willReturn([$teamMemberId]);
		$this->userManager->method('getDisplayName')->willReturn('Employee One');

		$absence = $this->createMock(Absence::class);
		$absence->method('getId')->willReturn(1);
		$absence->method('getUserId')->willReturn($teamMemberId);
		$absence->method('getType')->willReturn('vacation');
		$absence->method('getStartDate')->willReturn(new \DateTime('2024-06-01'));
		$absence->method('getEndDate')->willReturn(new \DateTime('2024-06-05'));
		$absence->method('getDays')->willReturn(5);
		$absence->method('getStatus')->willReturn('approved');
		$absence->method('getSummary')->willReturn(['id' => 1]);

		$this->absenceMapper->method('findByUserAndDateRange')
			->willReturn([$absence]);

		$response = $this->controller->getTeamAbsenceCalendar('2024-06-01', '2024-06-30');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absences', $data);
		$this->assertCount(1, $data['absences']);
	}

	/**
	 * Test getTeamAbsenceCalendar returns empty when no team members
	 */
	public function testGetTeamAbsenceCalendarReturnsEmptyWhenNoTeamMembers(): void
	{
		$managerId = 'manager1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with($managerId)->willReturn([]);

		$response = $this->controller->getTeamAbsenceCalendar();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertEmpty($data['absences']);
	}

	/**
	 * Test getTeamOverview returns error when user not authenticated
	 */
	public function testGetTeamOverviewReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->getTeamOverview();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('not authenticated', $data['error']);
	}
}
