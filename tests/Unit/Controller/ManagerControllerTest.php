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
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroup;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
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
	private bool $isAdminAccess = false;
	private bool $canAccessManagerDashboard = true;

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

	/** @var TeamMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $teamMapper;

	/** @var TeamManagerMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $teamManagerMapper;

	/** @var OvertimeService|\PHPUnit\Framework\MockObject\MockObject */
	private $overtimeService;

	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var NotificationService|\PHPUnit\Framework\MockObject\MockObject */
	private $notificationService;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject */
	private $urlGenerator;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	protected function setUp(): void
	{
		parent::setUp();

		$this->absenceService = $this->createMock(AbsenceService::class);
		$this->timeTrackingService = $this->createMock(TimeTrackingService::class);
		$this->complianceService = $this->createMock(ComplianceService::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->teamMapper = $this->createMock(TeamMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->cspService = $this->createMock(CSPService::class);
		$this->cspService->method('applyPolicyWithNonce')->willReturnCallback(static fn ($response) => $response);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(fn ($s) => $s);
		$this->request = $this->createMock(IRequest::class);
		$this->teamManagerMapper = $this->createMock(TeamManagerMapper::class);
		$this->overtimeService = $this->createMock(OvertimeService::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->config = $this->createMock(IConfig::class);
		$this->permissionService->method('canAccessManagerDashboard')
			->willReturnCallback(function (string $userId): bool {
				return $this->canAccessManagerDashboard;
			});
		$this->permissionService->method('isAdmin')
			->willReturnCallback(function (string $userId): bool {
				return $this->isAdminAccess;
			});

		$this->controller = new ManagerController(
			'arbeitszeitcheck',
			$this->request,
			$this->absenceService,
			$this->timeTrackingService,
			$this->complianceService,
			$this->absenceMapper,
			$this->teamResolver,
			$this->permissionService,
			$this->teamMapper,
			$this->userSession,
			$this->userManager,
			$this->cspService,
			$this->l10n,
			$this->teamManagerMapper,
			$this->overtimeService,
			$this->auditLogMapper,
			$this->notificationService,
			$this->timeEntryMapper,
			$this->urlGenerator,
			$this->config
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
		$this->canAccessManagerDashboard = false;

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

		$this->overtimeService->method('calculateOvertime')->willReturn([
			'total_hours_worked' => 40.0,
			'overtime_hours' => 0.0,
		]);
		$this->overtimeService->method('getDailyOvertime')->willReturn([
			'overtime_hours' => 0.0
		]);

		$response = $this->controller->getTeamOverview();
		$data = $response->getData();

		$this->assertTrue($data['success'], json_encode($data));
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

		$this->assertTrue($data['success'], json_encode($data));
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

		$absence = new Absence();
		$absence->setId(1);
		$absence->setUserId($teamMemberId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-06-01'));
		$absence->setEndDate(new \DateTime('2024-06-05'));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

		$this->absenceMapper->method('findPendingForUsers')
			->willReturn([$absence]);

		$timeEntry = new TimeEntry();
		$timeEntry->setId(1);
		$timeEntry->setUserId($teamMemberId);
		$timeEntry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$timeEntry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$timeEntry->setBreakStartTime(null);
		$timeEntry->setBreakEndTime(null);
		$timeEntry->setBreaks(null);
		$timeEntry->setDescription('Work');
		$timeEntry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$timeEntry->setIsManualEntry(false);
		$timeEntry->setJustification(json_encode([
			'justification' => 'Correction needed',
			'original' => [],
			'proposed' => [],
			'requested_at' => '2024-01-15T10:00:00Z'
		]));
		$timeEntry->setCreatedAt(new \DateTime());
		$timeEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('findPendingApprovalForUsers')
			->willReturn([$timeEntry]);

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

		$absence = new Absence();
		$absence->setId(1);
		$absence->setUserId($teamMemberId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-06-01'));
		$absence->setEndDate(new \DateTime('2024-06-05'));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

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
		$this->overtimeService->method('getDailyOvertime')->willReturn(['overtime_hours' => 0.0]);

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

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($employeeId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-01-01'));
		$absence->setEndDate(new \DateTime('2024-01-02'));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

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

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($employeeId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-01-01'));
		$absence->setEndDate(new \DateTime('2024-01-02'));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

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

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($employeeId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-01-01'));
		$absence->setEndDate(new \DateTime('2024-01-02'));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

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

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($employeeId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-01-01'));
		$absence->setEndDate(new \DateTime('2024-01-02'));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

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

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($teamMemberId);
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setJustification(json_encode(['justification' => 'Correction']));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$updatedEntry = new TimeEntry();
		$updatedEntry->setId($entryId);
		$updatedEntry->setUserId($teamMemberId);
		$updatedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$updatedEntry->setStartTime($entry->getStartTime());
		$updatedEntry->setEndTime(new \DateTime());
		$updatedEntry->setCreatedAt(new \DateTime());
		$updatedEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->with($entryId)->willReturn($entry);
		$this->timeEntryMapper->method('update')->willReturn($updatedEntry);
		$this->config->method('getAppValue')->willReturn('1');

		$this->auditLogMapper->expects($this->once())->method('logAction');
		$this->notificationService->expects($this->once())->method('notifyTimeEntryCorrectionApproved');

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

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($managerId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

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

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($otherUserId);
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

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

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($teamMemberId);
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode([
			'original' => [
				'date' => '2024-01-15',
				'hours' => 8.0,
				'description' => 'Original'
			]
		]));
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setDescription('Correction');
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$updatedEntry = new TimeEntry();
		$updatedEntry->setId($entryId);
		$updatedEntry->setUserId($teamMemberId);
		$updatedEntry->setStatus(TimeEntry::STATUS_REJECTED);
		$updatedEntry->setStartTime($entry->getStartTime());
		$updatedEntry->setEndTime($entry->getEndTime());
		$updatedEntry->setDescription('Original');
		$updatedEntry->setCreatedAt(new \DateTime());
		$updatedEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->method('update')->willReturn($updatedEntry);

		$this->auditLogMapper->expects($this->once())->method('logAction');
		$this->notificationService->expects($this->once())->method('notifyTimeEntryCorrectionRejected');

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

		$absence = new Absence();
		$absence->setId(1);
		$absence->setUserId($teamMemberId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-06-01'));
		$absence->setEndDate(new \DateTime('2024-06-05'));
		$absence->setDays(5);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

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

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('An internal error occurred. Please contact your administrator.', $data['error']);
	}

	public function testGetTeamOverviewReturnsForbiddenWhenNoManagerAccess(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('employee1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->isAdminAccess = false;
		$this->canAccessManagerDashboard = false;

		$response = $this->controller->getTeamOverview();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($data['success']);
	}

	public function testGetEmployeeAbsencesRequiresDateRange(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);
		$this->userManager->method('get')->willReturn($employee);
		$this->userManager->method('getDisplayName')->willReturn('Employee One');

		$response = $this->controller->getEmployeeAbsences();
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertTrue($data['requiresFilters']);
		$this->assertEquals(0, $data['total']);
		$this->assertCount(1, $data['employees']);
	}

	public function testGetEmployeeAbsencesReturnsForbiddenForOutOfScopeEmployee(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);
		$this->userManager->method('get')->willReturn($employee);
		$this->userManager->method('getDisplayName')->willReturn('Employee One');

		$response = $this->controller->getEmployeeAbsences(
			'employee2',
			'2026-03-01',
			'2026-03-31',
			null,
			null,
			25,
			0
		);
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($data['success']);
	}

	public function testGetEmployeeAbsencesReturnsFilteredRows(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);
		$this->userManager->method('get')->willReturn($employee);
		$this->userManager->method('getDisplayName')->willReturn('Employee One');

		$absence = new Absence();
		$absence->setId(10);
		$absence->setUserId('employee1');
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2026-03-10'));
		$absence->setEndDate(new \DateTime('2026-03-12'));
		$absence->setDays(3.0);
		$absence->setReason('Family trip');
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setCreatedAt(new \DateTime('2026-03-01 09:00:00'));
		$absence->setUpdatedAt(new \DateTime('2026-03-01 09:00:00'));

		$this->absenceMapper->expects($this->once())
			->method('findByUsersAndDateRange')
			->willReturn([$absence]);
		$this->absenceMapper->expects($this->once())
			->method('countByUsersAndDateRange')
			->willReturn(1);

		$response = $this->controller->getEmployeeAbsences(
			'employee1',
			'2026-03-01',
			'2026-03-31',
			Absence::STATUS_APPROVED,
			Absence::TYPE_VACATION,
			25,
			0
		);
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['success'], json_encode($data));
		$this->assertFalse($data['requiresFilters']);
		$this->assertEquals(1, $data['total']);
		$this->assertCount(1, $data['entries']);
		$this->assertSame('employee1', $data['entries'][0]['userId']);
		$this->assertSame('vacation', $data['entries'][0]['type']);
	}

}
