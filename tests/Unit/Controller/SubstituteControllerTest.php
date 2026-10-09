<?php

declare(strict_types=1);

/**
 * Unit tests for SubstituteController
 * Covers substitute approval/decline workflow (Vertretungs-Freigabe)
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\SubstituteController;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\IConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Class SubstituteControllerTest
 */
class SubstituteControllerTest extends TestCase
{
	/** @var SubstituteController */
	private $controller;

	/** @var AbsenceService|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceService;

	/** @var AbsenceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	protected function setUp(): void
	{
		parent::setUp();

		$this->absenceService = $this->createMock(AbsenceService::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->request = $this->createMock(IRequest::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$cspService = $this->createMock(CSPService::class);
		$cspService->method('applyPolicyWithNonce')->willReturnCallback(static fn ($response, $context) => $response);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);
		$permissionService = $this->createMock(PermissionService::class);
		$config = $this->createMock(IConfig::class);
		$localeFormat = $this->createMock(LocaleFormatService::class);
		$localeFormat->method('clientHints')->willReturn([
			'locale' => 'en-US',
			'htmlLang' => 'en-US',
			'timezone' => 'Europe/Berlin',
		]);

		$this->controller = new SubstituteController(
			'arbeitszeitcheck',
			$this->request,
			$this->absenceService,
			$this->absenceMapper,
			$this->userSession,
			$this->userManager,
			$urlGenerator,
			$cspService,
			$localeFormat,
			$l10n,
			$permissionService,
			new NavigationFlagsService(
				$this->absenceMapper,
				$permissionService,
				$config
			),
		);
	}

	private function mockAuthenticatedUser(string $userId = 'substitute1'): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
	}

	/**
	 * Test index returns template (empty when no pending requests)
	 */
	public function testIndexReturnsTemplate(): void
	{
		$this->mockAuthenticatedUser('substitute1');
		$this->absenceMapper->expects($this->exactly(2))
			->method('findSubstitutePendingForUser')
			->willReturn([]);

		$response = $this->controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertEquals('arbeitszeitcheck', $response->getApp());
		$this->assertEquals('substitution-requests', $response->getTemplateName());
		$params = $response->getParams();
		$this->assertArrayHasKey('requests', $params);
		$this->assertIsArray($params['requests']);
	}

	/**
	 * Test index returns error when unauthenticated
	 */
	public function testIndexReturnsErrorWhenUnauthenticated(): void
	{
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->index();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$params = $response->getParams();
		$this->assertIsArray($params);
		$this->assertArrayHasKey('error', $params);
		$this->assertArrayHasKey('requests', $params);
		$this->assertEmpty($params['requests']);
	}

	/**
	 * Test getPending returns JSON success with requests array
	 */
	public function testGetPendingReturnsRequests(): void
	{
		$this->mockAuthenticatedUser('substitute1');
		$this->absenceMapper->expects($this->once())
			->method('findSubstitutePendingForUser')
			->with('substitute1', 50, 0)
			->willReturn([]);

		$response = $this->controller->getPending();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('requests', $data);
		$this->assertIsArray($data['requests']);
	}

	/**
	 * Test getPending returns 500 when unauthenticated
	 */
	public function testGetPendingReturns500WhenUnauthenticated(): void
	{
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->getPending();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test approve succeeds when substitute approves
	 */
	public function testApproveSuccess(): void
	{
		$this->mockAuthenticatedUser('substitute1');
		$absenceId = 42;
		$absence = $this->createMock(Absence::class);
		$absence->method('getSummary')->willReturn(['id' => $absenceId, 'status' => Absence::STATUS_PENDING]);

		$this->absenceService->expects($this->once())
			->method('approveBySubstitute')
			->with($absenceId, 'substitute1')
			->willReturn($absence);

		$response = $this->controller->approve($absenceId);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absence', $data);
		$this->assertEquals($absenceId, $data['absence']['id']);
	}

	/**
	 * Test approve returns 404 when absence not found
	 */
	public function testApproveReturns404WhenNotFound(): void
	{
		$this->mockAuthenticatedUser('substitute1');
		$this->absenceService->expects($this->once())
			->method('approveBySubstitute')
			->with(999, 'substitute1')
			->willThrowException(new DoesNotExistException('Absence not found'));

		$response = $this->controller->approve(999);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Absence not found', $data['error']);
	}

	/**
	 * Test approve returns 401 when unauthenticated
	 */
	public function testApproveReturnsUnauthorizedWhenNotLoggedIn(): void
	{
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->approve(1);

		$this->assertEquals(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Service-level business rule (e.g. wrong substitute) returns 400 with the
	 * localized error so the user can fix the problem, not 500.
	 */
	public function testApproveReturns400OnBusinessRule(): void
	{
		$this->mockAuthenticatedUser('substitute1');
		$this->absenceService->expects($this->once())
			->method('approveBySubstitute')
			->willThrowException(new \Exception('Absence is not awaiting substitute approval'));

		$response = $this->controller->approve(7);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Absence is not awaiting substitute approval', $data['error']);
	}

	/**
	 * Technical exceptions must collapse to a generic 500 message, never leak.
	 */
	public function testApproveCollapsesTechnicalExceptionToGeneric500(): void
	{
		$this->mockAuthenticatedUser('substitute1');
		$this->absenceService->expects($this->once())
			->method('approveBySubstitute')
			->willThrowException(new \RuntimeException('SQLSTATE[42S02]: leak'));

		$response = $this->controller->approve(7);

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringNotContainsString('SQLSTATE', $data['error']);
	}

	/**
	 * Test decline succeeds when substitute declines
	 */
	public function testDeclineSuccess(): void
	{
		$this->mockAuthenticatedUser('substitute1');
		$absenceId = 42;
		$absence = $this->createMock(Absence::class);
		$absence->method('getSummary')->willReturn(['id' => $absenceId, 'status' => Absence::STATUS_SUBSTITUTE_DECLINED]);

		$this->absenceService->expects($this->once())
			->method('declineBySubstitute')
			->with($absenceId, 'substitute1', '')
			->willReturn($absence);

		$response = $this->controller->decline($absenceId);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absence', $data);
	}

	/**
	 * Test decline with comment from parameter
	 */
	public function testDeclineWithCommentParameter(): void
	{
		$this->mockAuthenticatedUser('substitute1');
		$absenceId = 42;
		$absence = $this->createMock(Absence::class);
		$absence->method('getSummary')->willReturn(['id' => $absenceId]);

		$this->absenceService->expects($this->once())
			->method('declineBySubstitute')
			->with($absenceId, 'substitute1', 'I cannot cover these dates')
			->willReturn($absence);

		$response = $this->controller->decline($absenceId, 'I cannot cover these dates');

		$this->assertTrue($response->getData()['success']);
	}

	/**
	 * Test decline with comment from POST params
	 */
	public function testDeclineWithCommentFromPostParams(): void
	{
		$this->mockAuthenticatedUser('substitute1');
		$this->request->method('getParams')->willReturn(['comment' => 'Conflict with my schedule']);

		$absence = $this->createMock(Absence::class);
		$absence->method('getSummary')->willReturn(['id' => 1]);

		$this->absenceService->expects($this->once())
			->method('declineBySubstitute')
			->with(1, 'substitute1', 'Conflict with my schedule')
			->willReturn($absence);

		$response = $this->controller->decline(1, null);

		$this->assertTrue($response->getData()['success']);
	}

	/**
	 * Test decline returns 404 when absence not found
	 */
	public function testDeclineReturns404WhenNotFound(): void
	{
		$this->mockAuthenticatedUser('substitute1');
		$this->absenceService->expects($this->once())
			->method('declineBySubstitute')
			->willThrowException(new DoesNotExistException('Absence not found'));

		$response = $this->controller->decline(999);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Object-level AuthZ: a non-designated substitute must get the same 404
	 * "Absence not found" as a missing id — no existence oracle.
	 */
	public function testDeclineReturns404WhenNotDesignatedSubstitute(): void
	{
		$this->mockAuthenticatedUser('intruder');
		$this->absenceService->expects($this->once())
			->method('declineBySubstitute')
			->with(42, 'intruder', '')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Absence not found'));

		$response = $this->controller->decline(42);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('Absence not found', $data['error']);
	}

	public function testDeclineReturnsUnauthorizedWhenNotLoggedIn(): void
	{
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->decline(1);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testGetPendingEnrichesSummaryWithDisplayNameAndTypeLabel(): void
	{
		$this->mockAuthenticatedUser('substitute1');

		$absence = new \OCA\ArbeitszeitCheck\Db\Absence();
		$absence->setUserId('employee1');
		$absence->setType('sick_leave');
		$this->absenceMapper->method('findSubstitutePendingForUser')->willReturn([$absence]);

		$colleague = $this->createMock(IUser::class);
		$colleague->method('getDisplayName')->willReturn('Ada Employee');
		$this->userManager->method('get')->with('employee1')->willReturn($colleague);

		$d = $this->controller->getPending()->getData();
		$this->assertTrue($d['success']);
		$this->assertSame('Ada Employee', $d['requests'][0]['displayName']);
		$this->assertSame('Sick Leave', $d['requests'][0]['typeLabel']);
	}

	public function testGetPendingFallsBackToUserIdAndRawType(): void
	{
		$this->mockAuthenticatedUser('substitute1');

		$absence = new \OCA\ArbeitszeitCheck\Db\Absence();
		$absence->setUserId('ghost');
		$absence->setType('exotic_type');
		$this->absenceMapper->method('findSubstitutePendingForUser')->willReturn([$absence]);
		$this->userManager->method('get')->willReturn(null);

		$d = $this->controller->getPending()->getData();
		$this->assertTrue($d['success']);
		$this->assertSame('ghost', $d['requests'][0]['displayName']);
		$this->assertSame('exotic_type', $d['requests'][0]['typeLabel']);
	}
}