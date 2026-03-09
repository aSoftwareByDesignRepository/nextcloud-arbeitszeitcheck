<?php

declare(strict_types=1);

/**
 * Unit tests for ComplianceController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\ComplianceController;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Class ComplianceControllerTest
 */
class ComplianceControllerTest extends TestCase
{
	/** @var ComplianceController */
	private $controller;

	/** @var ComplianceService|\PHPUnit\Framework\MockObject\MockObject */
	private $complianceService;

	/** @var ComplianceViolationMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $violationMapper;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var PermissionService|\PHPUnit\Framework\MockObject\MockObject */
	private $permissionService;

	/** @var CSPService|\PHPUnit\Framework\MockObject\MockObject */
	private $cspService;

	/** @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject */
	private $urlGenerator;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	protected function setUp(): void
	{
		parent::setUp();

		$this->complianceService = $this->createMock(ComplianceService::class);
		$this->violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->permissionService->method('canViewUserCompliance')->willReturn(true);
		$this->permissionService->method('canResolveViolation')->willReturn(true);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->cspService = $this->createMock(CSPService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->urlGenerator->method('linkToRoute')->willReturnCallback(fn ($r, $p = []) => '/compliance');
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(fn ($s) => $s);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new ComplianceController(
			'arbeitszeitcheck',
			$this->request,
			$this->complianceService,
			$this->violationMapper,
			$this->permissionService,
			$this->userSession,
			$this->urlGenerator,
			$this->cspService,
			$this->l10n
		);
	}

	/**
	 * Test dashboard returns template
	 */
	public function testDashboardReturnsTemplate(): void
	{
		$response = $this->controller->dashboard();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getTemplateName());
		$this->assertEquals('compliance-dashboard', $response->getRenderAs());
	}

	/**
	 * Test violations page returns template
	 */
	public function testViolationsReturnsTemplate(): void
	{
		$response = $this->controller->violations();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getTemplateName());
		$this->assertEquals('compliance-violations', $response->getRenderAs());
	}

	/**
	 * Test reports page returns template
	 */
	public function testReportsReturnsTemplate(): void
	{
		$response = $this->controller->reports();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getTemplateName());
		$this->assertEquals('compliance-reports', $response->getRenderAs());
	}

	/**
	 * Test getViolations returns violations for user
	 */
	public function testGetViolationsReturnsViolations(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$violation = $this->createMock(ComplianceViolation::class);
		$violation->method('getSummary')->willReturn(['id' => 1]);
		$violation->method('getViolationType')->willReturn('missing_break');
		$violation->method('getSeverity')->willReturn('warning');

		$this->violationMapper->expects($this->once())
			->method('findByUser')
			->with($userId, null)
			->willReturn([$violation]);

		$response = $this->controller->getViolations();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('violations', $data);
		$this->assertEquals(1, $data['total']);
	}

	/**
	 * Test getViolations applies filters
	 */
	public function testGetViolationsAppliesFilters(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$violation1 = $this->createMock(ComplianceViolation::class);
		$violation1->method('getSummary')->willReturn(['id' => 1]);
		$violation1->method('getViolationType')->willReturn('missing_break');
		$violation1->method('getSeverity')->willReturn('warning');

		$violation2 = $this->createMock(ComplianceViolation::class);
		$violation2->method('getSummary')->willReturn(['id' => 2]);
		$violation2->method('getViolationType')->willReturn('excessive_hours');
		$violation2->method('getSeverity')->willReturn('error');

		$this->violationMapper->method('findByUser')
			->willReturn([$violation1, $violation2]);

		$response = $this->controller->getViolations(null, 'missing_break', null, 'warning');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['violations']); // Only missing_break violation
	}

	/**
	 * Test getViolations applies date range filter
	 */
	public function testGetViolationsAppliesDateRangeFilter(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$violation = $this->createMock(ComplianceViolation::class);
		$violation->method('getSummary')->willReturn(['id' => 1]);

		$this->violationMapper->expects($this->once())
			->method('findByDateRange')
			->with(
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class),
				$userId,
				null
			)
			->willReturn([$violation]);

		$response = $this->controller->getViolations(null, null, null, null, '2024-01-01', '2024-01-31');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['violations']);
	}

	/**
	 * Test getViolations applies pagination
	 */
	public function testGetViolationsAppliesPagination(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$violations = [];
		for ($i = 1; $i <= 10; $i++) {
			$violation = $this->createMock(ComplianceViolation::class);
			$violation->method('getSummary')->willReturn(['id' => $i]);
			$violation->method('getViolationType')->willReturn('missing_break');
			$violation->method('getSeverity')->willReturn('warning');
			$violations[] = $violation;
		}

		$this->violationMapper->method('findByUser')
			->willReturn($violations);

		$response = $this->controller->getViolations(null, null, null, null, null, null, 5, 0);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertCount(5, $data['violations']); // Limited to 5
		$this->assertEquals(10, $data['total']); // Total count
	}

	/**
	 * Test getViolation returns violation when found
	 */
	public function testGetViolationReturnsViolationWhenFound(): void
	{
		$userId = 'testuser';
		$violationId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$violation = $this->createMock(ComplianceViolation::class);
		$violation->method('getUserId')->willReturn($userId);
		$violation->method('getSummary')->willReturn(['id' => $violationId]);

		$this->violationMapper->expects($this->once())
			->method('find')
			->with($violationId)
			->willReturn($violation);

		$response = $this->controller->getViolation($violationId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('violation', $data);
	}

	/**
	 * Test getViolation returns not found when user doesn't own violation
	 */
	public function testGetViolationReturnsNotFoundWhenNotOwned(): void
	{
		$userId = 'testuser';
		$otherUserId = 'otheruser';
		$violationId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canViewUserCompliance')->with($userId, $otherUserId)->willReturn(false);

		$violation = $this->createMock(ComplianceViolation::class);
		$violation->method('getUserId')->willReturn($otherUserId);

		$this->violationMapper->method('find')->willReturn($violation);

		$response = $this->controller->getViolation($violationId);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Violation not found', $data['error']);
	}

	/**
	 * Test resolveViolation resolves violation
	 */
	public function testResolveViolationResolvesViolation(): void
	{
		$userId = 'testuser';
		$violationId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$violation = $this->createMock(ComplianceViolation::class);
		$violation->method('getUserId')->willReturn($userId);
		$violation->method('getResolved')->willReturn(false);

		$resolvedViolation = $this->createMock(ComplianceViolation::class);
		$resolvedViolation->method('getSummary')->willReturn(['id' => $violationId]);

		$this->violationMapper->method('find')->willReturn($violation);
		$this->violationMapper->expects($this->once())
			->method('resolveViolation')
			->with($violationId, $this->isType('int'))
			->willReturn($resolvedViolation);

		$response = $this->controller->resolveViolation($violationId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('violation', $data);
	}

	/**
	 * Test resolveViolation returns error when already resolved
	 */
	public function testResolveViolationReturnsErrorWhenAlreadyResolved(): void
	{
		$userId = 'testuser';
		$violationId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$violation = $this->createMock(ComplianceViolation::class);
		$violation->method('getUserId')->willReturn($userId);
		$violation->method('getResolved')->willReturn(true);

		$this->violationMapper->method('find')->willReturn($violation);

		$response = $this->controller->resolveViolation($violationId);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('already resolved', $data['error']);
	}

	/**
	 * Test getStatus returns compliance status
	 */
	public function testGetStatusReturnsComplianceStatus(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$status = [
			'compliant' => true,
			'violation_count' => 0,
			'critical_violations' => 0
		];

		$this->complianceService->expects($this->once())
			->method('getComplianceStatus')
			->with($userId)
			->willReturn($status);

		$response = $this->controller->getStatus();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('status', $data);
		$this->assertTrue($data['status']['compliant']);
	}

	/**
	 * Test getReport returns compliance report
	 */
	public function testGetReportReturnsComplianceReport(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$report = [
			'start_date' => '2024-01-01',
			'end_date' => '2024-01-31',
			'total_violations' => 5,
			'violations_by_type' => []
		];

		$this->complianceService->expects($this->once())
			->method('generateComplianceReport')
			->with(
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class),
				$userId
			)
			->willReturn($report);

		$response = $this->controller->getReport('2024-01-01', '2024-01-31');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('report', $data);
	}

	/**
	 * Test getReport uses default date range when not specified
	 */
	public function testGetReportUsesDefaultDateRange(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$report = [
			'start_date' => '2024-01-01',
			'end_date' => '2024-01-31',
			'total_violations' => 0
		];

		$this->complianceService->expects($this->once())
			->method('generateComplianceReport')
			->with(
				$this->isInstanceOf(\DateTime::class),
				$this->isInstanceOf(\DateTime::class),
				$userId
			)
			->willReturn($report);

		$response = $this->controller->getReport();
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test getViolations handles exceptions
	 */
	public function testGetViolationsHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->violationMapper->expects($this->once())
			->method('findByUser')
			->willThrowException(new \Exception('Database error'));

		$response = $this->controller->getViolations();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Database error', $data['error']);
	}

	/**
	 * Test getViolation handles DoesNotExistException
	 */
	public function testGetViolationHandlesDoesNotExistException(): void
	{
		$userId = 'testuser';
		$violationId = 999;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->violationMapper->expects($this->once())
			->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Violation not found'));

		$response = $this->controller->getViolation($violationId);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Violation not found', $data['error']);
	}

	/**
	 * Test resolveViolation returns not found when user doesn't own violation
	 */
	public function testResolveViolationReturnsNotFoundWhenNotOwned(): void
	{
		$userId = 'testuser';
		$otherUserId = 'otheruser';
		$violationId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('canResolveViolation')->with($userId, $otherUserId)->willReturn(false);

		$violation = $this->createMock(ComplianceViolation::class);
		$violation->method('getUserId')->willReturn($otherUserId);

		$this->violationMapper->method('find')->willReturn($violation);

		$response = $this->controller->resolveViolation($violationId);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test getStatus handles exceptions
	 */
	public function testGetStatusHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->complianceService->expects($this->once())
			->method('getComplianceStatus')
			->willThrowException(new \Exception('Service error'));

		$response = $this->controller->getStatus();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test getReport handles exceptions
	 */
	public function testGetReportHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->complianceService->expects($this->once())
			->method('generateComplianceReport')
			->willThrowException(new \Exception('Report generation failed'));

		$response = $this->controller->getReport();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test getViolations returns error when user not authenticated
	 */
	public function testGetViolationsReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->getViolations();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('not authenticated', $data['error']);
	}

	/**
	 * Test runCheck succeeds when admin
	 */
	public function testRunCheckSucceedsWhenAdmin(): void
	{
		$userId = 'admin';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('isAdmin')->with($userId)->willReturn(true);

		$stats = ['users_checked' => 5, 'violations_found' => 2];
		$this->complianceService->expects($this->once())
			->method('runDailyComplianceCheck')
			->willReturn($stats);

		$response = $this->controller->runCheck();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('stats', $data);
		$this->assertEquals($stats, $data['stats']);
	}

	/**
	 * Test runCheck returns forbidden when not admin
	 */
	public function testRunCheckReturnsForbiddenWhenNotAdmin(): void
	{
		$userId = 'regularuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->permissionService->method('isAdmin')->with($userId)->willReturn(false);

		$this->complianceService->expects($this->never())
			->method('runDailyComplianceCheck');

		$response = $this->controller->runCheck();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Admin', $data['error']);
	}

	/**
	 * Test runCheck returns forbidden when no user
	 */
	public function testRunCheckReturnsForbiddenWhenNoUser(): void
	{
		$this->userSession->method('getUser')->willReturn(null);
		$this->permissionService->expects($this->never())->method('isAdmin');

		$this->complianceService->expects($this->never())
			->method('runDailyComplianceCheck');

		$response = $this->controller->runCheck();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}
}
