<?php

declare(strict_types=1);

/**
 * Unit tests for ManagerController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Constants;
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
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService;
use OCA\ArbeitszeitCheck\Service\MonthClosureGuard;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IGroup;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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

	/** @var MonthClosureGuard|\PHPUnit\Framework\MockObject\MockObject */
	private $monthClosureGuard;

	/** @var MonthClosureService|\PHPUnit\Framework\MockObject\MockObject */
	private $monthClosureService;

	/** @var TimeEntryCorrectionService|\PHPUnit\Framework\MockObject\MockObject */
	private $correctionService;

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
		$this->monthClosureGuard = $this->createMock(MonthClosureGuard::class);
		$this->monthClosureService = $this->createMock(MonthClosureService::class);
		$this->correctionService = $this->createMock(TimeEntryCorrectionService::class);
		$this->correctionService->method('reject')->willReturnCallback(static function (TimeEntry $entry): TimeEntry {
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
			return $entry;
		});
		$this->correctionService->method('approve')->willReturnCallback(static function (TimeEntry $entry): TimeEntry {
			$justificationData = json_decode($entry->getJustification() ?? '{}', true);
			$proposal = is_array($justificationData) ? ($justificationData['proposed'] ?? []) : [];
			if (isset($proposal['endTime'])) {
				$entry->setEndTime(new \DateTime((string)$proposal['endTime']));
			}
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
			return $entry;
		});

		// Build a real TimeZoneService so its contract (storage-TZ wall clock,
		// display-TZ formatting, day windows) is exercised end-to-end.
		$tzConfig = $this->createMock(IConfig::class);
		$tzConfig->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'app_timezone' => 'Europe/Berlin',
			default => $default,
		});
		$tzDateTime = $this->createMock(IDateTimeZone::class);
		$tzDateTime->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$tzUserSession = $this->createMock(IUserSession::class);
		$tzUserSession->method('getUser')->willReturn(null);
		$timeZoneService = new TimeZoneService($tzConfig, $tzDateTime, $tzUserSession, new NullLogger());
		$localeFormat = $this->createMock(\OCA\ArbeitszeitCheck\Service\LocaleFormatService::class);
		$localeFormat->method('clientHints')->willReturn([
			'locale' => 'en-US',
			'htmlLang' => 'en-US',
			'timezone' => 'Europe/Berlin',
		]);

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
			$this->createMock(\OCA\ArbeitszeitCheck\Service\OvertimeDisplayService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\OvertimeTrafficLightService::class),
			$this->auditLogMapper,
			$this->notificationService,
			$this->timeEntryMapper,
			$this->urlGenerator,
			$this->config,
			$this->monthClosureGuard,
			$this->monthClosureService,
			$timeZoneService,
			$this->correctionService,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class),
			$localeFormat,
			new NavigationFlagsService(
				$this->absenceMapper,
				$this->permissionService,
				$this->config
			),
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
		$timeEntryRows = array_values(array_filter(
			$data['pendingApprovals'],
			static fn(array $row): bool => ($row['type'] ?? '') === 'time_entry'
		));
		$this->assertCount(1, $timeEntryRows);
		$this->assertSame('correction', $timeEntryRows[0]['summary']['requestType']);
		$this->assertSame('Correction needed', $timeEntryRows[0]['summary']['justification']);
		$this->assertArrayHasKey('date', $timeEntryRows[0]['summary']);
		$this->assertArrayHasKey('startTime', $timeEntryRows[0]['summary']);
		$this->assertArrayHasKey('endTime', $timeEntryRows[0]['summary']);
	}

	/**
	 * Manual create pending approvals expose requestType=manual_create inside summary.
	 */
	public function testGetPendingApprovalsExposesManualCreateRequestType(): void
	{
		$managerId = 'manager1';
		$teamMemberId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with($managerId)->willReturn([$teamMemberId]);
		$this->userManager->method('getDisplayName')->willReturn('Iban Sesma');

		$this->absenceMapper->method('findPendingForUsers')->willReturn([]);

		$timeEntry = new TimeEntry();
		$timeEntry->setId(8);
		$timeEntry->setUserId($teamMemberId);
		$timeEntry->setStartTime(new \DateTime('2026-09-17 09:00:00'));
		$timeEntry->setEndTime(new \DateTime('2026-09-17 14:00:00'));
		$timeEntry->setBreakStartTime(null);
		$timeEntry->setBreakEndTime(null);
		$timeEntry->setBreaks(null);
		$timeEntry->setDescription('');
		$timeEntry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$timeEntry->setIsManualEntry(true);
		$timeEntry->setJustification(json_encode([
			'type' => 'manual_create',
			'justification' => 'estoy probando',
			'proposed' => [
				'startTime' => '2026-09-17T09:00:00+02:00',
				'endTime' => '2026-09-17T14:00:00+02:00',
				'description' => '',
			],
			'requested_at' => '2026-09-18T06:14:19+00:00',
		]));
		$timeEntry->setCreatedAt(new \DateTime('2026-09-18T06:14:19+00:00'));
		$timeEntry->setUpdatedAt(new \DateTime('2026-09-18T06:14:19+00:00'));

		$this->timeEntryMapper->method('findPendingApprovalForUsers')
			->willReturn([$timeEntry]);

		$response = $this->controller->getPendingApprovals('time_entry');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['pendingApprovals']);
		$row = $data['pendingApprovals'][0];
		$this->assertSame('time_entry', $row['type']);
		$this->assertSame('manual_create', $row['summary']['requestType']);
		$this->assertSame('estoy probando', $row['summary']['justification']);
		$this->assertSame([], $row['summary']['original']);
		$this->assertIsArray($row['summary']['proposed']);
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
				'warning_violations' => 0,
				'violation_count' => 0,
				'score' => 100,
			]);
		$this->userManager->method('getDisplayName')->with($teamMemberId)->willReturn('Employee One');
		$this->urlGenerator->method('linkToRoute')
			->with('arbeitszeitcheck.compliance.violations', ['userId' => $teamMemberId])
			->willReturn('/apps/arbeitszeitcheck/compliance/violations?userId=employee1');

		$response = $this->controller->getTeamCompliance();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('compliance', $data);
		$this->assertEquals(1, $data['compliance']['totalMembers']);
		$this->assertEquals(1, $data['compliance']['compliantMembers']);
		$this->assertArrayHasKey('members', $data['compliance']);
		$this->assertCount(1, $data['compliance']['members']);
		$this->assertEquals('employee1', $data['compliance']['members'][0]['userId']);
		$this->assertEquals('compliant', $data['compliance']['members'][0]['bucket']);
		$this->assertArrayHasKey('violationsUrl', $data['compliance']['members'][0]);
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

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$this->auditLogMapper->expects($this->once())->method('logAction');
		$this->notificationService->expects($this->once())->method('notifyTimeEntryCorrectionRejected');

		$response = $this->controller->rejectTimeEntryCorrection($entryId, 'Invalid correction');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('entry', $data);
		$this->assertArrayHasKey('message', $data);
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $data['entry']['status']);
	}

	public function testApproveTimeEntryCorrectionAppliesProposedValuesOnApproval(): void
	{
		$managerId = 'manager1';
		$teamMemberId = 'employee1';
		$entryId = 3;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canManageEmployee')->with($managerId, $teamMemberId)->willReturn(true);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($teamMemberId);
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setJustification(json_encode([
			'justification' => 'Adjust end time',
			'proposed' => [
				'startTime' => '2024-01-15T09:00:00Z',
				'endTime' => '2024-01-15T17:30:00Z',
			],
		]));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);
		$this->complianceService->method('checkRestPeriodForStartTime')->willReturn(['valid' => true, 'message' => 'ok']);
		$this->config->method('getAppValue')->willReturn('1');
		$this->auditLogMapper->expects($this->once())->method('logAction');
		$this->notificationService->expects($this->once())->method('notifyTimeEntryCorrectionApproved');

		$response = $this->controller->approveTimeEntryCorrection($entryId, 'Approved');
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $data['entry']['status']);
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

	public function testGetEmployeeTimeEntriesReturnsForbiddenForOutOfScopeEmployee(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);
		$this->userManager->method('get')->willReturn($employee);
		$this->userManager->method('getDisplayName')->willReturn('Employee One');

		$response = $this->controller->getEmployeeTimeEntries(
			'employee2',
			'2026-03-01',
			'2026-03-31',
		);
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($data['success']);
	}

	public function testGetEmployeeAbsencesRejectsDateRangeOverMaxDays(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);
		$this->userManager->method('get')->willReturn($employee);
		$this->userManager->method('getDisplayName')->willReturn('Employee One');
		$this->absenceMapper->expects($this->never())->method('findByUsersAndDateRange');

		$response = $this->controller->getEmployeeAbsences(
			'employee1',
			'2024-01-01',
			'2025-12-31',
		);
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($data['success']);
	}

	public function testGetEmployeeTimeEntriesRejectsDateRangeOverMaxDays(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['employee1']);
		$this->userManager->method('get')->willReturn($employee);
		$this->userManager->method('getDisplayName')->willReturn('Employee One');
		$this->timeEntryMapper->expects($this->never())->method('findByUsersAndDateRange');

		$response = $this->controller->getEmployeeTimeEntries(
			'employee1',
			'2024-01-01',
			'2025-12-31',
		);
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
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

	public function testRevisionPdfUsersForbiddenWhenNeitherAdminNorManager(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('nobody');
		$this->userSession->method('getUser')->willReturn($user);
		$this->canAccessManagerDashboard = false;
		$this->isAdminAccess = false;
		$this->config->method('getAppValue')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				if ($appId === 'arbeitszeitcheck' && $key === Constants::CONFIG_MONTH_CLOSURE_ENABLED) {
					return '1';
				}

				return $default;
			}
		);

		$response = $this->controller->revisionPdfUsers();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	public function testRevisionPdfUsersReturnsTeamMembersForManager(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->canAccessManagerDashboard = true;
		$this->isAdminAccess = false;
		$this->config->method('getAppValue')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				if ($appId === 'arbeitszeitcheck' && $key === Constants::CONFIG_MONTH_CLOSURE_ENABLED) {
					return '1';
				}

				return $default;
			}
		);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['emp1']);
		$employee = $this->createMock(IUser::class);
		$employee->method('getUID')->willReturn('emp1');
		$employee->method('getDisplayName')->willReturn('Emp One');
		$employee->method('getEMailAddress')->willReturn('e@example.org');
		$this->userManager->method('get')->with('emp1')->willReturn($employee);
		$this->permissionService->method('canManageEmployee')->willReturnCallback(
			static function (string $managerId, string $employeeId): bool {
				return $managerId === 'manager1' && $employeeId === 'emp1';
			}
		);

		$response = $this->controller->revisionPdfUsers(null, 25);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('users', $data);
		$this->assertCount(1, $data['users']);
		$this->assertSame('emp1', $data['users'][0]['userId']);
	}

	public function testRevisionPdfAvailableMonthsForManagerUsesTeamScope(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->canAccessManagerDashboard = true;
		$this->isAdminAccess = false;
		$this->config->method('getAppValue')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				if ($appId === 'arbeitszeitcheck' && $key === Constants::CONFIG_MONTH_CLOSURE_ENABLED) {
					return '1';
				}

				return $default;
			}
		);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['e1', 'e2']);
		$this->monthClosureService->expects($this->once())->method('listDistinctFinalizedYearMonthsForUserIds')->with(['e1', 'e2'])->willReturn([
			['year' => 2026, 'month' => 1],
		]);

		$response = $this->controller->revisionPdfAvailableMonths();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(2026, $data['months'][0]['year']);
		$this->assertSame(1, $data['months'][0]['month']);
	}

	public function testRevisionPdfUsersForMonthReturnsUsers(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->canAccessManagerDashboard = true;
		$this->isAdminAccess = false;
		$this->config->method('getAppValue')->willReturnCallback(
			static function (string $appId, string $key, string $default = ''): string {
				if ($appId === 'arbeitszeitcheck' && $key === Constants::CONFIG_MONTH_CLOSURE_ENABLED) {
					return '1';
				}

				return $default;
			}
		);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['emp1']);
		$this->monthClosureService->method('listUserIdsWithFinalizedMonth')->with(2026, 3, ['emp1'])->willReturn(['emp1']);
		$emp = $this->createMock(IUser::class);
		$emp->method('getUID')->willReturn('emp1');
		$emp->method('getDisplayName')->willReturn('Emp');
		$emp->method('getEMailAddress')->willReturn('e@test');
		$this->userManager->method('get')->with('emp1')->willReturn($emp);
		$this->permissionService->method('canManageEmployee')->willReturnCallback(
			static function (string $m, string $e): bool {
				return $m === 'manager1' && $e === 'emp1';
			}
		);

		$this->request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
			if ($name === 'year') {
				return '2026';
			}
			if ($name === 'month') {
				return '3';
			}

			return $default;
		});

		$response = $this->controller->revisionPdfUsersForMonth();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['users']);
		$this->assertSame('emp1', $data['users'][0]['userId']);
	}

	public function testGetScopedEmployeesRequiresMinSearchLength(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);

		$response = $this->controller->getScopedEmployees('a', 25);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame([], $data['users']);
		$this->assertSame(Constants::PICKER_MIN_SEARCH_LENGTH, $data['requiresMinSearch']);
	}

	public function testAdminEmployeeTimeEntriesAcceptsPickerUserOutsideListPreload(): void
	{
		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin1');
		$this->userSession->method('getUser')->willReturn($admin);
		$this->isAdminAccess = true;

		$this->userManager->method('search')->with('', Constants::MAX_LIST_LIMIT, 0)->willReturn([]);

		$remote = $this->createMock(IUser::class);
		$remote->method('getUID')->willReturn('remote-user');
		$remote->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->with('remote-user')->willReturn($remote);
		$this->userManager->method('getDisplayName')->with('remote-user')->willReturn('Remote User');

		$this->timeEntryMapper->expects($this->once())
			->method('findByUsersAndDateRange')
			->with(
				['remote-user'],
				$this->isInstanceOf(\DateTimeImmutable::class),
				$this->isInstanceOf(\DateTimeImmutable::class),
				null,
				$this->anything(),
				$this->anything(),
			)
			->willReturn([]);
		$this->timeEntryMapper->method('countByUsersAndDateRange')->willReturn(0);

		$response = $this->controller->getEmployeeTimeEntries(
			'remote-user',
			'2026-01-01',
			'2026-01-31',
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('remote-user', $data['employees'][0]['userId']);
	}

	public function testGetScopedEmployeesReturnsTeamMembersForManager(): void
	{
		$manager = $this->createMock(IUser::class);
		$manager->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($manager);
		$this->isAdminAccess = false;
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['alice', 'bob']);

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');
		$alice->method('getDisplayName')->willReturn('Alice Example');
		$alice->method('isEnabled')->willReturn(true);
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$bob->method('isEnabled')->willReturn(true);

		$this->userManager->method('get')->willReturnCallback(static function (string $uid) use ($alice, $bob) {
			return match ($uid) {
				'alice' => $alice,
				'bob' => $bob,
				default => null,
			};
		});
		$this->userManager->method('getDisplayName')->willReturnCallback(static fn (string $uid) => match ($uid) {
			'alice' => 'Alice Example',
			default => $uid,
		});

		$response = $this->controller->getScopedEmployees('ali', 25);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['users']);
		$this->assertSame('alice', $data['users'][0]['userId']);
	}

	/**
	 * Manager-recorded absence must read JSON via public IRequest::getParams() (NC 34).
	 */
	public function testCreateEmployeeAbsenceCreatesApprovedAbsenceFromGetParams(): void
	{
		$managerId = 'manager1';
		$employeeId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);
		$this->userSession->method('getUser')->willReturn($user);

		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->with($employeeId)->willReturn($employee);

		$this->permissionService->expects($this->once())
			->method('canManageEmployee')
			->with($managerId, $employeeId)
			->willReturn(true);

		$this->request->expects($this->once())
			->method('getParams')
			->willReturn([
				'userId' => $employeeId,
				'type' => Absence::TYPE_SICK_LEAVE,
				'startDate' => '2026-07-28',
				'endDate' => '2026-07-31',
				'reason' => 'Doctor note',
			]);

		$absence = new Absence();
		$absence->setId(42);
		$absence->setUserId($employeeId);
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStartDate(new \DateTime('2026-07-28'));
		$absence->setEndDate(new \DateTime('2026-07-31'));
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setApprovedByUserId($managerId);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

		$this->absenceService->expects($this->once())
			->method('createApprovedAbsenceForEmployeeByManager')
			->with(
				$managerId,
				$employeeId,
				$this->callback(static function (array $data): bool {
					return ($data['type'] ?? null) === Absence::TYPE_SICK_LEAVE
						&& ($data['start_date'] ?? null) === '2026-07-28'
						&& ($data['end_date'] ?? null) === '2026-07-31'
						&& ($data['reason'] ?? null) === 'Doctor note';
				})
			)
			->willReturn($absence);

		$response = $this->controller->createEmployeeAbsence();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(42, $data['absence']['id']);
		$this->assertSame($employeeId, $data['absence']['userId']);
		$this->assertSame(Absence::TYPE_SICK_LEAVE, $data['absence']['type']);
	}

	public function testCreateEmployeeAbsenceCreatesVacationWithHoursFlags(): void
	{
		$managerId = 'manager1';
		$employeeId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);
		$this->userSession->method('getUser')->willReturn($user);

		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->with($employeeId)->willReturn($employee);

		$this->permissionService->method('canManageEmployee')->willReturn(true);
		$this->request->method('getParams')->willReturn([
			'userId' => $employeeId,
			'type' => Absence::TYPE_VACATION,
			'startDate' => '2026-08-03',
			'endDate' => '2026-08-07',
			'durationHours' => 32.5,
			'requireDurationHours' => true,
			'serverMayFillHours' => true,
		]);

		$absence = new Absence();
		$absence->setId(7);
		$absence->setUserId($employeeId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2026-08-03'));
		$absence->setEndDate(new \DateTime('2026-08-07'));
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setApprovedByUserId($managerId);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

		$this->absenceService->expects($this->once())
			->method('createApprovedAbsenceForEmployeeByManager')
			->with(
				$managerId,
				$employeeId,
				$this->callback(static function (array $data): bool {
					return ($data['type'] ?? null) === Absence::TYPE_VACATION
						&& ($data['duration_hours'] ?? null) === '32.5'
						&& !empty($data['require_duration_hours'])
						&& !empty($data['server_may_fill_hours']);
				})
			)
			->willReturn($absence);

		$response = $this->controller->createEmployeeAbsence();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testCreateEmployeeAbsenceRejectsMissingRequiredFields(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'userId' => 'employee1',
			'type' => Absence::TYPE_VACATION,
			// missing dates
		]);
		$this->absenceService->expects($this->never())->method('createApprovedAbsenceForEmployeeByManager');

		$response = $this->controller->createEmployeeAbsence();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('required', strtolower($response->getData()['error']));
	}

	public function testCreateEmployeeAbsenceCoercesArrayParamsToScalars(): void
	{
		$managerId = 'manager1';
		$employeeId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);
		$this->userSession->method('getUser')->willReturn($user);

		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->with($employeeId)->willReturn($employee);
		$this->permissionService->method('canManageEmployee')->willReturn(true);

		$this->request->method('getParams')->willReturn([
			'userId' => [$employeeId, 'ignored'],
			'type' => [Absence::TYPE_SICK_LEAVE],
			'startDate' => ['2026-07-28'],
			'endDate' => ['2026-07-29'],
			'reason' => ['ok'],
		]);

		$absence = new Absence();
		$absence->setId(9);
		$absence->setUserId($employeeId);
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStartDate(new \DateTime('2026-07-28'));
		$absence->setEndDate(new \DateTime('2026-07-29'));
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setApprovedByUserId($managerId);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

		$this->absenceService->expects($this->once())
			->method('createApprovedAbsenceForEmployeeByManager')
			->with(
				$managerId,
				$employeeId,
				$this->callback(static function (array $data): bool {
					return ($data['type'] ?? null) === Absence::TYPE_SICK_LEAVE
						&& ($data['start_date'] ?? null) === '2026-07-28'
						&& ($data['end_date'] ?? null) === '2026-07-29'
						&& ($data['reason'] ?? null) === 'ok';
				})
			)
			->willReturn($absence);

		$response = $this->controller->createEmployeeAbsence();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateEmployeeAbsenceForbidsUnmanagedEmployee(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'userId' => 'outsider',
			'type' => Absence::TYPE_SICK_LEAVE,
			'startDate' => '2026-07-28',
			'endDate' => '2026-07-29',
		]);
		$this->permissionService->expects($this->once())
			->method('canManageEmployee')
			->with('manager1', 'outsider')
			->willReturn(false);
		$this->permissionService->expects($this->once())
			->method('logPermissionDenied')
			->with('manager1', 'create_employee_absence', 'user', 'outsider');
		$this->absenceService->expects($this->never())->method('createApprovedAbsenceForEmployeeByManager');

		$response = $this->controller->createEmployeeAbsence();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testCreateEmployeeAbsenceRejectsDisabledUser(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'userId' => 'employee1',
			'type' => Absence::TYPE_SICK_LEAVE,
			'startDate' => '2026-07-28',
			'endDate' => '2026-07-29',
		]);
		$this->permissionService->method('canManageEmployee')->willReturn(true);
		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(false);
		$this->userManager->method('get')->with('employee1')->willReturn($employee);
		$this->absenceService->expects($this->never())->method('createApprovedAbsenceForEmployeeByManager');

		$response = $this->controller->createEmployeeAbsence();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateEmployeeAbsenceRejectsInvalidDateFormat(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'userId' => 'employee1',
			'type' => Absence::TYPE_SICK_LEAVE,
			'startDate' => '28.07.2026',
			'endDate' => '2026-07-29',
		]);
		$this->permissionService->method('canManageEmployee')->willReturn(true);
		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->willReturn($employee);
		$this->absenceService->expects($this->never())->method('createApprovedAbsenceForEmployeeByManager');

		$response = $this->controller->createEmployeeAbsence();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('YYYY-MM-DD', $response->getData()['error']);
	}

	public function testCreateEmployeeAbsenceReturnsConflictWhenMonthFinalized(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'userId' => 'employee1',
			'type' => Absence::TYPE_SICK_LEAVE,
			'startDate' => '2026-07-28',
			'endDate' => '2026-07-29',
		]);
		$this->permissionService->method('canManageEmployee')->willReturn(true);
		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->willReturn($employee);
		$this->monthClosureService->method('assertDateRangeMutable')
			->willThrowException(new \OCA\ArbeitszeitCheck\Exception\MonthFinalizedException('finalized'));
		$this->absenceService->expects($this->never())->method('createApprovedAbsenceForEmployeeByManager');

		$response = $this->controller->createEmployeeAbsence();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testCreateEmployeeAbsenceSurfacesBusinessRuleErrorCode(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'userId' => 'employee1',
			'type' => Absence::TYPE_VACATION,
			'startDate' => '2026-07-28',
			'endDate' => '2026-07-29',
		]);
		$this->permissionService->method('canManageEmployee')->willReturn(true);
		$employee = $this->createMock(IUser::class);
		$employee->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->willReturn($employee);

		$ex = new \OCA\ArbeitszeitCheck\Exception\BusinessRuleException(
			'Please enter vacation hours.',
			'ABSENCE_HOURS_CLIENT_REQUIRED'
		);
		$this->absenceService->method('createApprovedAbsenceForEmployeeByManager')->willThrowException($ex);

		$response = $this->controller->createEmployeeAbsence();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('ABSENCE_HOURS_CLIENT_REQUIRED', $data['code']);
		$this->assertSame('ABSENCE_HOURS_CLIENT_REQUIRED', $data['error_code']);
	}

	public function testCreateEmployeeAbsenceDeniesNonManagers(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('employee1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->canAccessManagerDashboard = false;
		$this->isAdminAccess = false;
		$this->request->expects($this->never())->method('getParams');
		$this->absenceService->expects($this->never())->method('createApprovedAbsenceForEmployeeByManager');

		$response = $this->controller->createEmployeeAbsence();
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAtlasManagerPageSurfacesRedirectOrTemplate(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mgr');
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('isAdmin')->willReturn(false);
		$this->permissionService->method('canAccessManagerDashboard')->willReturn(false);
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/arbeitszeitcheck/');

		foreach (['employeeTimeEntriesPage', 'employeeAbsencesPage', 'monthClosuresPage'] as $method) {
			$res = $this->controller->$method();
			$this->assertTrue(
				$res instanceof \OCP\AppFramework\Http\TemplateResponse
				|| $res instanceof \OCP\AppFramework\Http\RedirectResponse,
				$method
			);
		}
	}

	public function testAtlasManagerRemainingApiSurfaces(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('mgr');
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('isAdmin')->willReturn(false);
		$this->permissionService->method('canAccessManagerDashboard')->willReturn(true);
		$this->permissionService->method('canManageEmployee')->willReturn(false);
		$this->request->method('getParams')->willReturn([]);
		$this->request->method('getParam')->willReturn(null);

		$this->assertInstanceOf(JSONResponse::class, $this->controller->getManagedTeams());
		$this->assertInstanceOf(JSONResponse::class, $this->controller->getTeamOvertimeAlerts());
		$this->assertInstanceOf(JSONResponse::class, $this->controller->getPendingTimeEntryCorrections());
		$this->assertInstanceOf(JSONResponse::class, $this->controller->estimateEmployeeVacationHours());
		$this->assertInstanceOf(JSONResponse::class, $this->controller->getManagerAssignableProjectcheckProjects('emp'));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->createEmployeeTimeEntry());
		$this->assertInstanceOf(JSONResponse::class, $this->controller->correctTimeEntry(1));
		$export = $this->controller->exportTeamOvertimeCsv();
		$this->assertTrue(
			$export instanceof JSONResponse || $export instanceof \OCP\AppFramework\Http\DataDownloadResponse
		);
	}

	public function testGetManagerAssignableProjectcheckProjectsReturnsForbiddenForOutOfScopeEmployee(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->canAccessManagerDashboard = true;
		$this->permissionService->method('canManageEmployee')->with('manager1', 'outsider')->willReturn(false);

		$response = $this->controller->getManagerAssignableProjectcheckProjects('outsider');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('Access denied', $response->getData()['error']);
	}

	public function testRejectTimeEntryCorrectionReturnsForbiddenWhenNotInTeam(): void
	{
		$managerId = 'manager1';
		$otherUserId = 'otheruser';
		$entryId = 11;
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

		$response = $this->controller->rejectTimeEntryCorrection($entryId, 'Nope');
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('Access denied', $response->getData()['error']);
	}

	public function testCorrectTimeEntryReturnsForbiddenWhenNotInTeam(): void
	{
		$managerId = 'manager1';
		$otherUserId = 'otheruser';
		$entryId = 12;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($managerId);
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canManageEmployee')->with($managerId, $otherUserId)->willReturn(false);
		$this->request->method('getParams')->willReturn([
			'reason' => 'Manager correction for testing',
		]);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($otherUserId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->correctTimeEntry($entryId);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('Access denied', $response->getData()['error']);
	}

}
