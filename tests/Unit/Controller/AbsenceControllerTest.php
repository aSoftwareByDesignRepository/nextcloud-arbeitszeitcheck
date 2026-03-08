<?php

declare(strict_types=1);

/**
 * Unit tests for AbsenceController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\AbsenceController;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Class AbsenceControllerTest
 */
class AbsenceControllerTest extends TestCase
{
	/** @var AbsenceController */
	private $controller;

	/** @var AbsenceService|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceService;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var AbsenceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;

	/** @var PermissionService|\PHPUnit\Framework\MockObject\MockObject */
	private $permissionService;

	/** @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject */
	private $urlGenerator;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var CSPService|\PHPUnit\Framework\MockObject\MockObject */
	private $cspService;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	protected function setUp(): void
	{
		parent::setUp();

		$this->absenceService = $this->createMock(AbsenceService::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->cspService = $this->createMock(CSPService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(fn ($s) => $s);
		$this->request = $this->createMock(IRequest::class);

		$this->controller = new AbsenceController(
			'arbeitszeitcheck',
			$this->request,
			$this->absenceService,
			$this->absenceMapper,
			$this->permissionService,
			$this->userSession,
			$this->urlGenerator,
			$this->userManager,
			$this->cspService,
			$this->l10n
		);
	}

	/**
	 * Test index returns absences
	 */
	public function testIndexReturnsAbsences(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$absence = $this->createMock(Absence::class);
		$absence->method('getSummary')->willReturn(['id' => 1]);

		$this->absenceService->expects($this->once())
			->method('getAbsencesByUser')
			->with($userId, [], 25, 0)
			->willReturn([$absence]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absences', $data);
	}

	/**
	 * Test index applies filters
	 */
	public function testIndexAppliesFilters(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$absence = $this->createMock(Absence::class);
		$absence->method('getSummary')->willReturn(['id' => 1]);

		$this->absenceService->expects($this->once())
			->method('getAbsencesByUser')
			->with($userId, ['status' => 'pending', 'type' => 'vacation'], 10, 5)
			->willReturn([$absence]);

		$response = $this->controller->index('pending', 'vacation', 10, 5);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test show returns absence when found
	 */
	public function testShowReturnsAbsenceWhenFound(): void
	{
		$userId = 'testuser';
		$absenceId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$absence = $this->createMock(Absence::class);
		$absence->method('getSummary')->willReturn(['id' => $absenceId]);

		$this->absenceService->expects($this->once())
			->method('getAbsence')
			->with($absenceId, $userId)
			->willReturn($absence);

		$response = $this->controller->show($absenceId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absence', $data);
	}

	/**
	 * Test show returns not found when absence doesn't exist
	 */
	public function testShowReturnsNotFoundWhenAbsenceMissing(): void
	{
		$userId = 'testuser';
		$absenceId = 999;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->absenceService->expects($this->once())
			->method('getAbsence')
			->with($absenceId, $userId)
			->willReturn(null);

		$response = $this->controller->show($absenceId);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Absence not found', $data['error']);
	}

	/**
	 * Test store creates absence
	 */
	public function testStoreCreatesAbsence(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$absence = $this->createMock(Absence::class);
		$absence->method('getSummary')->willReturn(['id' => 1]);

		$this->absenceService->expects($this->once())
			->method('createAbsence')
			->with(
				[
					'type' => 'vacation',
					'start_date' => '2024-06-01',
					'end_date' => '2024-06-05',
					'reason' => 'Summer vacation'
				],
				$userId
			)
			->willReturn($absence);

		$response = $this->controller->store('vacation', '2024-06-01', '2024-06-05', 'Summer vacation');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absence', $data);
	}

	/**
	 * Test store handles service exceptions
	 */
	public function testStoreHandlesException(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->absenceService->expects($this->once())
			->method('createAbsence')
			->willThrowException(new \Exception('Overlapping absence'));

		$response = $this->controller->store('vacation', '2024-06-01', '2024-06-05');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Overlapping absence', $data['error']);
	}

	/**
	 * Test update modifies absence
	 */
	public function testUpdateModifiesAbsence(): void
	{
		$userId = 'testuser';
		$absenceId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$absence = $this->createMock(Absence::class);
		$absence->method('getSummary')->willReturn(['id' => $absenceId]);

		$this->absenceService->expects($this->once())
			->method('updateAbsence')
			->with(
				$absenceId,
				[
					'start_date' => '2024-06-02',
					'end_date' => '2024-06-06',
					'reason' => 'Updated reason'
				],
				$userId
			)
			->willReturn($absence);

		$response = $this->controller->update($absenceId, '2024-06-02', '2024-06-06', 'Updated reason');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absence', $data);
	}

	/**
	 * Test delete removes absence
	 */
	public function testDeleteRemovesAbsence(): void
	{
		$userId = 'testuser';
		$absenceId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->absenceService->expects($this->once())
			->method('deleteAbsence')
			->with($absenceId, $userId);

		$response = $this->controller->delete($absenceId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test approve approves absence when current user can manage the absence owner
	 */
	public function testApproveApprovesAbsence(): void
	{
		$userId = 'manager1';
		$absenceId = 1;
		$employeeId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

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
			->with($userId, $employeeId)
			->willReturn(true);
		$this->absenceService->expects($this->once())
			->method('approveAbsence')
			->with($absenceId, $userId, 'Approved')
			->willReturn($absence);

		$response = $this->controller->approve($absenceId, 'Approved');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absence', $data);
	}

	/**
	 * Test reject rejects absence when current user can manage the absence owner
	 */
	public function testRejectRejectsAbsence(): void
	{
		$userId = 'manager1';
		$absenceId = 1;
		$employeeId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

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
			->with($userId, $employeeId)
			->willReturn(true);
		$this->absenceService->expects($this->once())
			->method('rejectAbsence')
			->with($absenceId, $userId, 'Not enough vacation days')
			->willReturn($absence);

		$response = $this->controller->reject($absenceId, 'Not enough vacation days');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absence', $data);
	}

	/**
	 * Test approve returns 403 when current user cannot manage the absence owner
	 */
	public function testApproveReturns403WhenUserCannotManageEmployee(): void
	{
		$userId = 'otheruser';
		$absenceId = 1;
		$employeeId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($employeeId);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);
		$this->permissionService->expects($this->once())
			->method('canManageEmployee')
			->with($userId, $employeeId)
			->willReturn(false);
		$this->absenceService->expects($this->never())->method('approveAbsence');

		$response = $this->controller->approve($absenceId, 'Approved');
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test reject returns 403 when current user cannot manage the absence owner
	 */
	public function testRejectReturns403WhenUserCannotManageEmployee(): void
	{
		$userId = 'otheruser';
		$absenceId = 1;
		$employeeId = 'employee1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$absence = $this->createMock(Absence::class);
		$absence->method('getUserId')->willReturn($employeeId);

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);
		$this->permissionService->expects($this->once())
			->method('canManageEmployee')
			->with($userId, $employeeId)
			->willReturn(false);
		$this->absenceService->expects($this->never())->method('rejectAbsence');

		$response = $this->controller->reject($absenceId, 'Rejected');
		$data = $response->getData();

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test approve returns 404 when absence does not exist
	 */
	public function testApproveReturns404WhenAbsenceNotFound(): void
	{
		$userId = 'manager1';
		$absenceId = 999;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willThrowException(new DoesNotExistException('Absence not found'));
		$this->permissionService->expects($this->never())->method('canManageEmployee');
		$this->absenceService->expects($this->never())->method('approveAbsence');

		$response = $this->controller->approve($absenceId, 'Approved');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test reject returns 404 when absence does not exist
	 */
	public function testRejectReturns404WhenAbsenceNotFound(): void
	{
		$userId = 'manager1';
		$absenceId = 999;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willThrowException(new DoesNotExistException('Absence not found'));
		$this->permissionService->expects($this->never())->method('canManageEmployee');
		$this->absenceService->expects($this->never())->method('rejectAbsence');

		$response = $this->controller->reject($absenceId, 'Rejected');

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test index returns error when user not authenticated
	 */
	public function testIndexReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->index();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('not authenticated', $data['error']);
	}

	/**
	 * Test store returns error when user not authenticated
	 */
	public function testStoreReturnsErrorWhenNotAuthenticated(): void
	{
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$response = $this->controller->store('vacation', '2024-06-01', '2024-06-05');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}
}
