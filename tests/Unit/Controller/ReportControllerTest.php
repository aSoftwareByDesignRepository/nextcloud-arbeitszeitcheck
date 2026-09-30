<?php

declare(strict_types=1);

/**
 * Unit tests for ReportController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\ReportController;
use OCA\ArbeitszeitCheck\Db\TeamMember;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\ReportingService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeEntryExportTransformer;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Class ReportControllerTest
 */
class ReportControllerTest extends TestCase
{
	/** @var ReportController */
	private $controller;

	/** @var ReportingService|\PHPUnit\Framework\MockObject\MockObject */
	private $reportingService;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var PermissionService|\PHPUnit\Framework\MockObject\MockObject */
	private $permissionService;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var TeamResolverService|\PHPUnit\Framework\MockObject\MockObject */
	private $teamResolver;

	/** @var array<string, mixed> */
	private array $paramOverrides = [];

	/** @var callable */
	private $canViewUserReportHook;

	/** @var callable */
	private $isAdminHook;

	/** @var TeamMemberMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $teamMemberMapper;

	/** @var TeamManagerMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $teamManagerMapper;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $appConfig;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var MonthClosureService|\PHPUnit\Framework\MockObject\MockObject */
	private $monthClosureService;

	/** @var \OCA\ArbeitszeitCheck\Service\PremiumSurchargeService|\PHPUnit\Framework\MockObject\MockObject */
	private $premiumSurchargeService;

	protected function setUp(): void
	{
		parent::setUp();

		$this->canViewUserReportHook = static fn ($cur, $uid) => true;
		$this->isAdminHook = static fn ($uid) => false;

		$this->reportingService = $this->createMock(ReportingService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(fn ($s) => $s);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(function (string $name, $default = null) {
			if (array_key_exists($name, $this->paramOverrides)) {
				return $this->paramOverrides[$name];
			}
			return $default ?? '';
		});

		// Default to allowing self-report access unless a test overrides it.
		$this->permissionService->method('canViewUserReport')
			->willReturnCallback(fn ($cur, $uid) => ($this->canViewUserReportHook)($cur, $uid));
		$this->permissionService->method('isAdmin')
			->willReturnCallback(fn ($uid) => ($this->isAdminHook)($uid));
		$this->permissionService->method('logPermissionDenied');

		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->teamMemberMapper = $this->createMock(TeamMemberMapper::class);
		$this->teamManagerMapper = $this->createMock(TeamManagerMapper::class);
		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->appConfig = $this->createMock(IConfig::class);
		$this->appConfig->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = ''): string {
			if ($app === 'arbeitszeitcheck' && $key === 'export_midnight_split_enabled') {
				return '1';
			}
			return $default;
		});
		$this->userManager = $this->createMock(IUserManager::class);
		$this->monthClosureService = $this->createMock(MonthClosureService::class);
		$this->premiumSurchargeService = $this->createMock(\OCA\ArbeitszeitCheck\Service\PremiumSurchargeService::class);

		$this->controller = new ReportController(
			'arbeitszeitcheck',
			$this->request,
			$this->reportingService,
			$this->permissionService,
			$this->teamResolver,
			$this->teamMemberMapper,
			$this->teamManagerMapper,
			$this->timeEntryMapper,
			new TimeEntryExportTransformer($this->appConfig),
			$this->appConfig,
			$this->userManager,
			$this->userSession,
			$this->l10n,
			$this->monthClosureService,
			$this->premiumSurchargeService
		);
	}

	/**
	 * Test daily report generation with default date
	 */
	public function testDailyReportWithDefaultDate(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$reportData = [
			'date' => '2024-01-15',
			'total_hours' => 8.0,
			'entries' => []
		];

		$this->reportingService->expects($this->once())
			->method('generateDailyReport')
			->with($this->isInstanceOf(\DateTime::class), $userId)
			->willReturn($reportData);

		$response = $this->controller->daily();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('report', $data);
		$this->assertEquals($reportData, $data['report']);
	}

	/**
	 * Test daily report generation with custom date
	 */
	public function testDailyReportWithCustomDate(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$reportData = [
			'date' => '2024-01-20',
			'total_hours' => 7.5,
			'entries' => []
		];

		$this->reportingService->expects($this->once())
			->method('generateDailyReport')
			->with($this->callback(function ($date) {
				return $date instanceof \DateTime && $date->format('Y-m-d') === '2024-01-20';
			}), $userId)
			->willReturn($reportData);

		$response = $this->controller->daily('2024-01-20');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertEquals($reportData, $data['report']);
	}

	/**
	 * Test daily report generation with custom user ID
	 */
	public function testDailyReportWithCustomUserId(): void
	{
		$userId = 'testuser';
		$targetUserId = 'otheruser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->canViewUserReportHook = static fn ($cur, $uid) => $cur === $userId && $uid === $targetUserId;

		$reportData = [
			'date' => '2024-01-15',
			'total_hours' => 8.0,
			'entries' => []
		];

		$this->reportingService->expects($this->once())
			->method('generateDailyReport')
			->with($this->anything(), $targetUserId)
			->willReturn($reportData);

		$response = $this->controller->daily(null, $targetUserId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test daily report handles exceptions
	 */
	public function testDailyReportHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->reportingService->expects($this->once())
			->method('generateDailyReport')
			->willThrowException(new \Exception('Report generation failed'));

		$response = $this->controller->daily();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.', $data['error']);
	}

	/**
	 * Test weekly report generation with default week
	 */
	public function testWeeklyReportWithDefaultWeek(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$reportData = [
			'week_start' => '2024-01-15',
			'total_hours' => 40.0,
			'entries' => []
		];

		$this->reportingService->expects($this->once())
			->method('generateWeeklyReport')
			->with($this->isInstanceOf(\DateTime::class), $userId)
			->willReturn($reportData);

		$response = $this->controller->weekly();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('report', $data);
	}

	/**
	 * Test weekly report generation with custom week start
	 */
	public function testWeeklyReportWithCustomWeekStart(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$reportData = [
			'week_start' => '2024-01-22',
			'total_hours' => 38.5,
			'entries' => []
		];

		$this->reportingService->expects($this->once())
			->method('generateWeeklyReport')
			->with($this->callback(function ($date) {
				return $date instanceof \DateTime && $date->format('Y-m-d') === '2024-01-22';
			}), $userId)
			->willReturn($reportData);

		$response = $this->controller->weekly('2024-01-22');
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test monthly report generation with default month
	 */
	public function testMonthlyReportWithDefaultMonth(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$reportData = [
			'month' => '2024-01',
			'total_hours' => 160.0,
			'entries' => []
		];

		$this->reportingService->expects($this->once())
			->method('generateMonthlyReport')
			->with($this->isInstanceOf(\DateTime::class), $userId, null, null)
			->willReturn($reportData);

		$response = $this->controller->monthly();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('report', $data);
	}

	/**
	 * Test monthly report generation with custom month
	 */
	public function testMonthlyReportWithCustomMonth(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$reportData = [
			'month' => '2024-02',
			'total_hours' => 152.0,
			'entries' => []
		];

		$this->reportingService->expects($this->once())
			->method('generateMonthlyReport')
			->with($this->callback(function ($date) {
				return $date instanceof \DateTime && $date->format('Y-m') === '2024-02';
			}), $userId, null, null)
			->willReturn($reportData);

		$response = $this->controller->monthly('2024-02');
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Monthly preview/export: startDate and endDate override the calendar month range.
	 */
	public function testMonthlyReportUsesStartEndDateOverride(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$reportData = [
			'month' => '2024-01',
			'total_hours' => 40.0,
			'entries' => [],
		];

		$this->reportingService->expects($this->once())
			->method('generateMonthlyReport')
			->with(
				$this->callback(fn ($m) => $m instanceof \DateTime && $m->format('Y-m') === '2024-01'),
				$userId,
				$this->callback(fn ($s) => $s instanceof \DateTime && $s->format('Y-m-d') === '2024-01-10'),
				$this->callback(fn ($e) => $e instanceof \DateTime && $e->format('Y-m-d') === '2024-01-25'),
			)
			->willReturn($reportData);

		$response = $this->controller->monthly('2024-01', null, '2024-01-10', '2024-01-25');
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test overtime report generation with default date range
	 */
	public function testOvertimeReportWithDefaultRange(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$reportData = [
			'start_date' => '2024-01-01',
			'end_date' => '2024-01-31',
			'overtime_hours' => 5.0,
			'entries' => []
		];

		$this->reportingService->expects($this->once())
			->method('generateOvertimeReport')
			->with(
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class),
				$userId
			)
			->willReturn($reportData);

		$response = $this->controller->overtime();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('report', $data);
	}

	/**
	 * Test overtime report generation with custom date range
	 */
	public function testOvertimeReportWithCustomRange(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$reportData = [
			'start_date' => '2024-06-01',
			'end_date' => '2024-06-30',
			'overtime_hours' => 10.0,
			'entries' => []
		];

		$this->reportingService->expects($this->once())
			->method('generateOvertimeReport')
			->with(
				$this->callback(function ($date) {
					return $date instanceof \DateTime && $date->format('Y-m-d') === '2024-06-01';
				}),
				// Controller passes an exclusive upper bound: midnight of the day after the
				// requested end date, so that DB queries using strict < include all of June 30.
				$this->callback(function ($date) {
					return $date instanceof \DateTime && $date->format('Y-m-d') === '2024-07-01';
				}),
				$userId
			)
			->willReturn($reportData);

		$response = $this->controller->overtime('2024-06-01', '2024-06-30');
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test absence report generation with default date range
	 */
	public function testAbsenceReportWithDefaultRange(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$reportData = [
			'start_date' => '2023-01-01',
			'end_date' => '2024-01-01',
			'absences' => [],
			'total_days' => 0
		];

		$this->reportingService->expects($this->once())
			->method('generateAbsenceReport')
			->with(
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class),
				$userId
			)
			->willReturn($reportData);

		$response = $this->controller->absence();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('report', $data);
	}

	/**
	 * Test absence report generation with custom date range
	 */
	public function testAbsenceReportWithCustomRange(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$reportData = [
			'start_date' => '2024-06-01',
			'end_date' => '2024-06-30',
			'absences' => [],
			'total_days' => 5
		];

		$this->reportingService->expects($this->once())
			->method('generateAbsenceReport')
			->with(
				$this->callback(function ($date) {
					return $date instanceof \DateTime && $date->format('Y-m-d') === '2024-06-01';
				}),
				// Controller passes an exclusive upper bound: midnight of the day after the
				// requested end date, so that DB queries using strict < include all of June 30.
				$this->callback(function ($date) {
					return $date instanceof \DateTime && $date->format('Y-m-d') === '2024-07-01';
				}),
				$userId
			)
			->willReturn($reportData);

		$response = $this->controller->absence('2024-06-01', '2024-06-30');
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test team report generation with user IDs
	 */
	public function testTeamReportWithUserIds(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$teamUserIds = ['user1', 'user2', 'user3'];
		$reportData = [
			'start_date' => '2024-01-01',
			'end_date' => '2024-01-31',
			'team_members' => [],
			'total_hours' => 0
		];

		$this->reportingService->expects($this->once())
			->method('generateTeamReport')
			->with(
				$teamUserIds,
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class)
			)
			->willReturn($reportData);

		$response = $this->controller->team(null, null, 'user1,user2,user3');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('report', $data);
	}

	/**
	 * Test team report returns error when no user IDs and no manager capability
	 */
	public function testTeamReportReturnsErrorWhenNoUserIds(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canAccessManagerDashboard')->with($userId)->willReturn(false);

		$response = $this->controller->team();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('No users to include', $data['error']);
	}

	/**
	 * Test team report returns error when empty user IDs provided
	 */
	public function testTeamReportReturnsErrorWhenEmptyUserIds(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canAccessManagerDashboard')->with($userId)->willReturn(false);

		$response = $this->controller->team(null, null, '   ,  ,  ');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('No users to include', $data['error']);
	}

	/**
	 * Test team report handles comma-separated user IDs with spaces
	 */
	public function testTeamReportHandlesSpacesInUserIds(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$reportData = [
			'start_date' => '2024-01-01',
			'end_date' => '2024-01-31',
			'team_members' => [],
			'total_hours' => 0
		];

		$this->reportingService->expects($this->once())
			->method('generateTeamReport')
			->with(
				$this->callback(function ($ids) {
					return $ids === ['user1', 'user2', 'user3'];
				}),
				$this->anything(),
				$this->anything()
			)
			->willReturn($reportData);

		$response = $this->controller->team(null, null, ' user1 , user2 , user3 ');
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test team report uses manager scope when userIds are not provided.
	 */
	public function testTeamReportUsesManagerScopeWhenNoUserIds(): void
	{
		$userId = 'manager1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canAccessManagerDashboard')->with($userId)->willReturn(true);
		$this->teamResolver->expects($this->once())
			->method('getTeamMemberIds')
			->with($userId)
			->willReturn(['userA', 'userB']);

		$reportData = [
			'start_date' => '2024-01-01',
			'end_date' => '2024-01-31',
			'team_members' => [],
			'total_hours' => 0
		];

		$this->reportingService->expects($this->once())
			->method('generateTeamReport')
			->with(
				['userA', 'userB'],
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class)
			)
			->willReturn($reportData);

		$response = $this->controller->team();
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Test team report rejects teamId outside manager scope.
	 */
	public function testTeamReportWithTeamIdDeniedWhenNotManaged(): void
	{
		$userId = 'manager1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->permissionService->method('canAccessManagerDashboard')->with($userId)->willReturn(true);
		$this->teamManagerMapper->expects($this->once())
			->method('getTeamIdsForManager')
			->with($userId)
			->willReturn([10, 11]);
		$this->reportingService->expects($this->never())->method('generateTeamReport');

		$response = $this->controller->team('2024-01-01', '2024-01-31', null, '99');
		$data = $response->getData();

		$this->assertFalse($data['success']);
		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertStringContainsString('Access denied', $data['error']);
	}

	/**
	 * Test team report resolves teamId members for managed team.
	 */
	public function testTeamReportWithTeamIdUsesTeamMembersWhenManaged(): void
	{
		$userId = 'manager1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$memberA = new TeamMember();
		$memberA->setUserId('userA');
		$memberB = new TeamMember();
		$memberB->setUserId('userB');

		$this->userSession->method('getUser')->willReturn($user);

		$this->permissionService->method('canAccessManagerDashboard')->with($userId)->willReturn(true);
		$this->teamManagerMapper->expects($this->once())
			->method('getTeamIdsForManager')
			->with($userId)
			->willReturn([10, 11]);
		$this->teamMemberMapper->expects($this->once())
			->method('findByTeamId')
			->with(11)
			->willReturn([$memberA, $memberB]);

		$reportData = [
			'start_date' => '2024-01-01',
			'end_date' => '2024-01-31',
			'team_members' => [],
			'total_hours' => 0
		];
		$this->reportingService->expects($this->once())
			->method('generateTeamReport')
			->with(
				['userA', 'userB'],
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class)
			)
			->willReturn($reportData);

		$response = $this->controller->team('2024-01-01', '2024-01-31', null, '11');
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Test manager scope can export team report as CSV download.
	 */
	public function testTeamReportManagerScopeDownloadCsv(): void
	{
		$userId = 'manager1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canAccessManagerDashboard')->with($userId)->willReturn(true);
		$this->teamResolver->expects($this->once())
			->method('getTeamMemberIds')
			->with($userId)
			->willReturn(['userA']);
		$this->canViewUserReportHook = static fn ($cur, $uid) => $uid === 'userA' || $cur === $uid;

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
			return match ($name) {
				'download' => '1',
				'format' => 'csv',
				'variant' => 'summary',
				'layout' => 'long',
				default => $default ?? '',
			};
		});

		$controller = new ReportController(
			'arbeitszeitcheck',
			$request,
			$this->reportingService,
			$this->permissionService,
			$this->teamResolver,
			$this->teamMemberMapper,
			$this->teamManagerMapper,
			$this->timeEntryMapper,
			new TimeEntryExportTransformer($this->appConfig),
			$this->appConfig,
			$this->userManager,
			$this->userSession,
			$this->l10n,
			$this->monthClosureService,
			$this->premiumSurchargeService
		);

		$reportData = [
			'type' => 'team',
			'members' => [[
				'user_id' => 'userA',
				'display_name' => 'User A',
				'total_hours' => 8.5,
				'required_hours' => 8.0,
				'overtime_hours' => 0.5,
				'break_hours' => 0.75,
				'violations_count' => 0,
				'absence_days' => 0,
				'entries_count' => 1,
			]],
		];
		$this->reportingService->expects($this->once())
			->method('generateTeamReport')
			->with(
				['userA'],
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class)
			)
			->willReturn($reportData);

		$response = $controller->team('2024-01-01', '2024-01-31');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);

		$csv = (string)$response->render();
		$this->assertStringContainsString('user_id,display_name,total_hours,required_hours,overtime_hours,break_hours,violations_count,absence_days,entries_count', $csv);
		$this->assertStringContainsString('userA,"User A"', $csv);
	}

	/**
	 * Team download with variant time_entries and no rows yields empty CSV message.
	 */
	public function testTeamReportTimeEntriesDownloadEmptyCsv(): void
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
			return match ($name) {
				'download' => '1',
				'format' => 'csv',
				'variant' => 'time_entries',
				'layout' => 'long',
				default => $default ?? '',
			};
		});

		$controller = new ReportController(
			'arbeitszeitcheck',
			$request,
			$this->reportingService,
			$this->permissionService,
			$this->teamResolver,
			$this->teamMemberMapper,
			$this->teamManagerMapper,
			$this->timeEntryMapper,
			new TimeEntryExportTransformer($this->appConfig),
			$this->appConfig,
			$this->userManager,
			$this->userSession,
			$this->l10n,
			$this->monthClosureService,
			$this->premiumSurchargeService
		);

		$userId = 'manager1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canAccessManagerDashboard')->with($userId)->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with($userId)->willReturn(['userA']);


		$this->timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);

		$ncUser = $this->createMock(IUser::class);
		$ncUser->method('getDisplayName')->willReturn('User A');
		$this->userManager->method('get')->with('userA')->willReturn($ncUser);

		$this->reportingService->expects($this->once())->method('generateTeamReport')->willReturn([
			'type' => 'team',
			'members' => [],
		]);

		$response = $controller->team('2024-01-01', '2024-01-31');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertStringContainsString('No data available', (string)$response->render());
	}

	/**
	 * Test weekly report handles exceptions
	 */
	public function testWeeklyReportHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->reportingService->expects($this->once())
			->method('generateWeeklyReport')
			->willThrowException(new \Exception('Weekly report failed'));

		$response = $this->controller->weekly();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.', $data['error']);
	}

	/**
	 * Test monthly report handles exceptions
	 */
	public function testMonthlyReportHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->reportingService->expects($this->once())
			->method('generateMonthlyReport')
			->willThrowException(new \Exception('Monthly report failed'));

		$response = $this->controller->monthly();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test overtime report handles exceptions
	 */
	public function testOvertimeReportHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->reportingService->expects($this->once())
			->method('generateOvertimeReport')
			->willThrowException(new \Exception('Overtime report failed'));

		$response = $this->controller->overtime();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test absence report handles exceptions
	 */
	public function testAbsenceReportHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->reportingService->expects($this->once())
			->method('generateAbsenceReport')
			->willThrowException(new \Exception('Absence report failed'));

		$response = $this->controller->absence();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test team report handles exceptions
	 */
	public function testTeamReportHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->reportingService->expects($this->once())
			->method('generateTeamReport')
			->willThrowException(new \Exception('Team report failed'));

		$response = $this->controller->team(null, null, 'user1,user2');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test daily report returns error when user not authenticated
	 */
	public function testDailyReportReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->daily();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('not authenticated', $data['error']);
	}

	public function testPremiumReportRejectsWhenDisabled(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->premiumSurchargeService->method('isEnabled')->willReturn(false);

		$response = $this->controller->premium('2026-08-01', '2026-08-31');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('PREMIUM_DISABLED', $data['code']);
	}

	public function testPremiumReportReturnsBucketsForSelf(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$user->method('getDisplayName')->willReturn('Ada');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->with('u1')->willReturn($user);
		$this->premiumSurchargeService->method('isEnabled')->willReturn(true);
		$this->premiumSurchargeService->expects($this->once())
			->method('buildPeriodReport')
			->willReturn([
				'type' => 'premium',
				'enabled' => true,
				'users' => [['user_id' => 'u1', 'buckets' => [['id' => 'sunday', 'hours' => 2.0]]]],
				'orthogonal_to_saldo' => true,
			]);

		$response = $this->controller->premium('2026-08-01', '2026-08-31');
		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('premium', $data['report']['type']);
		$this->assertTrue($data['report']['orthogonal_to_saldo']);
	}

	public function testPremiumCsvDownloadSanitisesFormulaInjection(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('u1');
		$user->method('getDisplayName')->willReturn('Ada');
		$this->userSession->method('getUser')->willReturn($user);
		$this->userManager->method('get')->with('u1')->willReturn($user);
		$this->premiumSurchargeService->method('isEnabled')->willReturn(true);
		$this->premiumSurchargeService->method('buildPeriodReport')->willReturn([
			'type' => 'premium',
			'enabled' => true,
			'period' => ['start' => '2026-08-01', 'end' => '2026-08-31'],
			'users' => [],
		]);
		$this->premiumSurchargeService->method('flattenReportToCsvRows')->willReturn([
			[
				'user_id' => 'u1',
				'display_name' => '=CMD|"/c calc"',
				'period_start' => '2026-08-01',
				'period_end' => '2026-08-31',
				'bucket_id' => 'sunday',
				'bucket_label' => 'Sunday',
				'hours' => 2.0,
				'rate' => 1.0,
				'valued_hours' => 2.0,
				'stacking' => 'max_single_rate',
				'policy_version' => 1,
			],
		]);

		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
			if ($name === 'download') {
				return '1';
			}
			if ($name === 'format') {
				return 'csv';
			}
			return $default ?? '';
		});
		$this->controller = new ReportController(
			'arbeitszeitcheck',
			$this->request,
			$this->reportingService,
			$this->permissionService,
			$this->teamResolver,
			$this->teamMemberMapper,
			$this->teamManagerMapper,
			$this->timeEntryMapper,
			new TimeEntryExportTransformer($this->appConfig),
			$this->appConfig,
			$this->userManager,
			$this->userSession,
			$this->l10n,
			$this->monthClosureService,
			$this->premiumSurchargeService
		);

		$response = $this->controller->premium('2026-08-01', '2026-08-31');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$body = (string)$response->render();
		$this->assertStringContainsString("'=CMD", $body);
		$this->assertDoesNotMatchRegularExpression('/(^|[\n;])=CMD/', $body);
	}

	private function primePremiumEnabled(): void
	{
		$this->premiumSurchargeService->method('isEnabled')->willReturn(true);
		$this->premiumSurchargeService->method('buildPeriodReport')->willReturn([
			'type' => 'premium',
			'enabled' => true,
			'users' => [],
		]);
	}

	public function testPremiumReportTeamIdResolvesManagedTeamMembers(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->primePremiumEnabled();
		$this->paramOverrides = ['teamId' => '11'];
		$this->permissionService->method('canAccessManagerDashboard')->willReturn(true);
		$this->teamManagerMapper->method('getTeamIdsForManager')->with('manager1')->willReturn([10, 11]);
		$memberA = new TeamMember();
		$memberA->setUserId('userA');
		$memberB = new TeamMember();
		$memberB->setUserId('userB');
		$this->teamMemberMapper->method('findByTeamId')->with(11)->willReturn([$memberA, $memberB]);

		$this->premiumSurchargeService->expects($this->once())
			->method('buildPeriodReport')
			->with(['userA', 'userB'])
			->willReturn(['type' => 'premium', 'users' => []]);

		$d = $this->controller->premium('2026-08-01', '2026-08-31')->getData();
		$this->assertTrue($d['success']);
	}

	public function testPremiumReportTeamIdRejectsUnmanagedTeam(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->primePremiumEnabled();
		$this->paramOverrides = ['teamId' => '99'];
		$this->permissionService->method('canAccessManagerDashboard')->willReturn(true);
		$this->teamManagerMapper->method('getTeamIdsForManager')->willReturn([10]);

		$r = $this->controller->premium('2026-08-01', '2026-08-31');
		$this->assertFalse($r->getData()['success']);
	}

	public function testPremiumReportInvalidTeamIdRejected(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->primePremiumEnabled();
		$this->paramOverrides = ['teamId' => 'abc'];

		$r = $this->controller->premium('2026-08-01', '2026-08-31');
		$this->assertFalse($r->getData()['success']);
	}

	public function testPremiumReportManagerScopeCollectsPermittedMembers(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('manager1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->primePremiumEnabled();
		$this->paramOverrides = ['managerScope' => '1'];
		$this->permissionService->method('canAccessManagerDashboard')->willReturn(true);
		$this->teamResolver->method('getTeamMemberIds')->with('manager1')->willReturn(['a', 'b']);
		// 'b' is not visible -> filtered out
		$this->canViewUserReportHook = static fn ($cur, $uid) => $uid === 'a';

		$this->premiumSurchargeService->expects($this->once())
			->method('buildPeriodReport')
			->with(['a'])
			->willReturn(['type' => 'premium', 'users' => []]);

		$d = $this->controller->premium('2026-08-01', '2026-08-31')->getData();
		$this->assertTrue($d['success']);
	}

	public function testPremiumReportEmptyUserIdRequiresAdminAndCollectsAllUsers(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin1');
		$this->userSession->method('getUser')->willReturn($user);
		$this->primePremiumEnabled();
		$this->isAdminHook = static fn ($uid) => $uid === 'admin1';
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);

		$enabled = $this->createMock(IUser::class);
		$enabled->method('isEnabled')->willReturn(true);
		$enabled->method('getUID')->willReturn('u-active');
		$disabled = $this->createMock(IUser::class);
		$disabled->method('isEnabled')->willReturn(false);
		$this->userManager->method('callForAllUsers')
			->willReturnCallback(static function ($cb) use ($enabled, $disabled) {
				$cb($enabled);
				$cb($disabled);
			});

		$this->premiumSurchargeService->expects($this->once())
			->method('buildPeriodReport')
			->with(['u-active'])
			->willReturn(['type' => 'premium', 'users' => []]);

		$d = $this->controller->premium('2026-08-01', '2026-08-31', '')->getData();
		$this->assertTrue($d['success']);
	}
}