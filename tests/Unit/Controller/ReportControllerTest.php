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
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\ReportingService;
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

	protected function setUp(): void
	{
		parent::setUp();

		$this->reportingService = $this->createMock(ReportingService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(fn ($s) => $s);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new ReportController(
			'arbeitszeitcheck',
			$this->request,
			$this->reportingService,
			$this->permissionService,
			$this->userSession,
			$this->l10n
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
		$this->permissionService->method('canViewUserReport')->with($userId, $targetUserId)->willReturn(true);

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
		$this->assertEquals('Report generation failed', $data['error']);
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
			->with($this->isInstanceOf(\DateTime::class), $userId)
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
			}), $userId)
			->willReturn($reportData);

		$response = $this->controller->monthly('2024-02');
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
				$this->callback(function ($date) {
					return $date instanceof \DateTime && $date->format('Y-m-d') === '2024-06-30';
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
				$this->callback(function ($date) {
					return $date instanceof \DateTime && $date->format('Y-m-d') === '2024-06-30';
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
	 * Test team report returns error when no user IDs provided
	 */
	public function testTeamReportReturnsErrorWhenNoUserIds(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$response = $this->controller->team();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('User IDs must be provided', $data['error']);
	}

	/**
	 * Test team report returns error when empty user IDs
	 */
	public function testTeamReportReturnsErrorWhenEmptyUserIds(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$response = $this->controller->team(null, null, '   ,  ,  ');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('No user IDs provided', $data['error']);
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
		$this->assertEquals('Weekly report failed', $data['error']);
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
}
