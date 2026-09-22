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
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IConfig;
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

	/** @var TeamResolverService|\PHPUnit\Framework\MockObject\MockObject */
	private $teamResolver;

	/** @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject */
	private $urlGenerator;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var CSPService|\PHPUnit\Framework\MockObject\MockObject */
	private $cspService;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var MonthClosureService|\PHPUnit\Framework\MockObject\MockObject */
	private $monthClosureService;

	/** @var \OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine|\PHPUnit\Framework\MockObject\MockObject */
	private $vacationEntitlementEngine;

	protected function setUp(): void
	{
		parent::setUp();

		$this->absenceService = $this->createMock(AbsenceService::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->cspService = $this->createMock(CSPService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(fn ($s) => $s);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturn('[]');
		$this->monthClosureService = $this->createMock(MonthClosureService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getHeader')->willReturnCallback(static function (string $name): string {
			if ($name === 'Accept') {
				return 'application/json';
			}
			if ($name === 'Content-Type') {
				return 'application/json';
			}
			return '';
		});

		$this->vacationEntitlementEngine = $this->createMock(\OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine::class);
		$vacationProrationService = $this->createMock(\OCA\ArbeitszeitCheck\Service\VacationProrationService::class);
		$vacationProrationService->method('prorateForYear')
			->willReturnCallback(static function (string $uid, int $year, float $full): array {
				return [
					'days' => $full,
					'full_days' => $full,
					'prorated' => false,
					'method' => 'twelfths',
					'months_covered' => 12,
					'covered_days' => 365,
					'days_in_year' => 365,
					'covered_from' => sprintf('%04d-01-01', $year),
					'covered_to' => sprintf('%04d-12-31', $year),
					'employment_start' => null,
					'employment_end' => null,
					'employed_in_year' => true,
					'algorithm_version' => 1,
				];
			});
		$localeFormat = $this->createMock(LocaleFormatService::class);
		$localeFormat->method('clientHints')->willReturn([]);
		$navigationFlags = new NavigationFlagsService(
			$this->absenceMapper,
			$this->permissionService,
			$this->config
		);
		$this->controller = new AbsenceController(
			'arbeitszeitcheck',
			$this->request,
			$this->absenceService,
			$this->absenceMapper,
			$this->permissionService,
			$this->teamResolver,
			$this->userSession,
			$this->urlGenerator,
			$this->userManager,
			$this->cspService,
			$this->l10n,
			$this->config,
			$this->monthClosureService,
			$this->vacationEntitlementEngine,
			$vacationProrationService,
			$localeFormat,
			$navigationFlags
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
	 * Test apiShow returns absence when found
	 */
	public function testApiShowReturnsAbsenceWhenFound(): void
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

		$response = $this->controller->apiShow($absenceId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('absence', $data);
	}

	/**
	 * Test apiShow returns not found when absence doesn't exist
	 */
	public function testApiShowReturnsNotFoundWhenAbsenceMissing(): void
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

		$response = $this->controller->apiShow($absenceId);

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

		$absence = new Absence();
		$absence->setId(1);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-06-01'));
		$absence->setEndDate(new \DateTime('2024-06-05'));
		$absence->setReason('Summer vacation');
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setCreatedAt(new \DateTime('2024-01-01T00:00:00Z'));
		$absence->setUpdatedAt(new \DateTime('2024-01-01T00:00:00Z'));

		$this->absenceService->expects($this->once())
			->method('createAbsence')
			->with(
				[
					'type' => 'vacation',
					'start_date' => '2024-06-01',
					'end_date' => '2024-06-05',
					'reason' => 'Summer vacation',
					'substitute_user_id' => null,
				],
				$userId
			)
			->willReturn($absence);

		$this->request->method('getParams')->willReturn([
			'type' => 'vacation',
			'start_date' => '2024-06-01',
			'end_date' => '2024-06-05',
			'reason' => 'Summer vacation',
		]);

		$response = $this->controller->store();

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

		$this->request->method('getParams')->willReturn([
			'type' => 'vacation',
			'start_date' => '2024-06-01',
			'end_date' => '2024-06-05',
		]);

		$response = $this->controller->store();

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

		$existing = new Absence();
		$existing->setId($absenceId);
		$existing->setUserId($userId);
		$existing->setStartDate(new \DateTime('2024-06-01'));
		$existing->setEndDate(new \DateTime('2024-06-05'));

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($existing);

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

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($userId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setStartDate(new \DateTime('2024-06-01'));
		$absence->setEndDate(new \DateTime('2024-06-05'));
		$absence->setCreatedAt(new \DateTime('2024-01-01T00:00:00Z'));
		$absence->setUpdatedAt(new \DateTime('2024-01-01T00:00:00Z'));

		$this->absenceMapper->expects($this->once())
			->method('find')
			->with($absenceId)
			->willReturn($absence);

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

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($employeeId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-01-01'));
		$absence->setEndDate(new \DateTime('2024-01-02'));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setCreatedAt(new \DateTime('2024-01-01T00:00:00Z'));
		$absence->setUpdatedAt(new \DateTime('2024-01-01T00:00:00Z'));

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

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($employeeId);
		$absence->setType(Absence::TYPE_VACATION);
		$absence->setStartDate(new \DateTime('2024-01-01'));
		$absence->setEndDate(new \DateTime('2024-01-02'));
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setCreatedAt(new \DateTime('2024-01-01T00:00:00Z'));
		$absence->setUpdatedAt(new \DateTime('2024-01-01T00:00:00Z'));

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

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($employeeId);
		$absence->setStatus(Absence::STATUS_PENDING);

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

		$absence = new Absence();
		$absence->setId($absenceId);
		$absence->setUserId($employeeId);
		$absence->setStatus(Absence::STATUS_PENDING);

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
		$this->assertEquals('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.', $data['error']);
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

	public function testShortenReturnsConflictWhenMonthFinalized(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'end_date' => '2024-06-05',
		]);
		$this->absenceService->expects($this->once())
			->method('shortenAbsence')
			->with(1, $userId, '2024-06-05')
			->willThrowException(new MonthFinalizedException('locked'));

		$response = $this->controller->shorten(1);
		$this->assertEquals(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('finalized', $data['error']);
	}

	public function testShortenFormRedirectsWithMonthFinalizedError(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')
			->with('end_date')
			->willReturn('2024-06-05');
		$this->urlGenerator->method('linkToRoute')
			->with('arbeitszeitcheck.absence.show', ['id' => 99])
			->willReturn('/apps/arbeitszeitcheck/absences/99');
		$this->absenceService->expects($this->once())
			->method('shortenAbsence')
			->with(99, $userId, '2024-06-05')
			->willThrowException(new MonthFinalizedException('locked'));

		$response = $this->controller->shortenForm(99);
		$this->assertEquals(Http::STATUS_SEE_OTHER, $response->getStatus());
		$this->assertStringContainsString('shorten_error=', $response->getRedirectURL());
	}

	public function testIndexApiAliasesDelegateToIndex(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->absenceService->method('getAbsencesByUser')->willReturn([]);

		foreach (['index_api'] as $method) {
			$response = $this->controller->$method();
			$this->assertTrue($response->getData()['success'], $method);
		}
	}

	public function testUsersReturnsColleagues(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getColleagueIds')->with('testuser')->willReturn(['bob']);
		$bob = $this->createMock(IUser::class);
		$bob->method('getUID')->willReturn('bob');
		$bob->method('getDisplayName')->willReturn('Bob');
		$bob->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->with('bob')->willReturn($bob);

		$response = $this->controller->users();
		$this->assertTrue($response->getData()['success']);
		$this->assertSame('bob', $response->getData()['users'][0]['userId']);
	}

	public function testStatsReturnsVacationStats(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->absenceService->method('getVacationStats')->with('testuser', 2026)->willReturn([
			'used' => 2.0,
			'entitlement' => 25.0,
			'total_available' => 25.0,
			'carryover_days' => 0,
			'carryover_usable' => 0,
			'carryover_expires_on' => null,
			'carryover_unused_locked_after_deadline' => false,
		]);

		$response = $this->controller->stats(2026);
		$this->assertTrue($response->getData()['success']);
		$this->assertSame(25.0, $response->getData()['vacationStats']['entitlement']);
	}

	public function testEntitlementTraceUnauthorized(): void
	{
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller->entitlementTrace();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}

	public function testEntitlementTraceHappy(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturnCallback(static function (string $name, $default = null) {
			return $name === 'asOfDate' ? '2026-06-01' : $default;
		});
		$this->vacationEntitlementEngine->method('computeForDate')->willReturn([
			'days' => 25.0,
			'trace' => ['step' => 1],
		]);
		$this->vacationEntitlementEngine->method('redactTraceForUser')->willReturn(['step' => 1]);

		$response = $this->controller->entitlementTrace();
		$this->assertTrue($response->getData()['success']);
	}

	public function testEstimateVacationHoursRequiresDates(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParam')->willReturn('');

		$response = $this->controller->estimateVacationHours('', '');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateShowEditTemplateSurfaces(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getColleagueIds')->willReturn([]);
		$this->absenceService->method('getAbsence')->willReturn(null);

		$this->assertInstanceOf(\OCP\AppFramework\Http\TemplateResponse::class, $this->controller->create());
		$this->assertInstanceOf(\OCP\AppFramework\Http\TemplateResponse::class, $this->controller->show(1));
		$this->assertInstanceOf(\OCP\AppFramework\Http\TemplateResponse::class, $this->controller->edit(1));
	}

	public function testUpdatePostDelegatesToUpdate(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([]);
		$this->absenceService->method('updateAbsence')->willThrowException(new \InvalidArgumentException('missing'));

		$response = $this->controller->updatePost(1);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertFalse($response->getData()['success']);
	}

	public function testApiStoreUpdateDeleteAndCancelSurfaces(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([]);

		$store = $this->controller->apiStore();
		$this->assertInstanceOf(JSONResponse::class, $store);

		$this->absenceMapper->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->apiUpdate(1));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->apiDelete(1));
		$cancel = $this->controller->cancel(1);
		$this->assertTrue(
			$cancel instanceof JSONResponse || $cancel instanceof \OCP\AppFramework\Http\RedirectResponse
		);
	}

	/**
	 * Object-level AuthZ negatives for path-param absence mutations (not license MW).
	 */
	public function testApiShowReturnsNotFoundWhenNotOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$this->absenceService->expects($this->once())
			->method('getAbsence')
			->with(55, 'testuser')
			->willReturn(null);

		$response = $this->controller->apiShow(55);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testApiUpdateReturnsNotFoundWhenNotOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'start_date' => '2024-06-01',
			'end_date' => '2024-06-05',
		]);

		$absence = new Absence();
		$absence->setId(55);
		$absence->setUserId('otheruser');
		$absence->setStartDate(new \DateTime('2024-06-01'));
		$absence->setEndDate(new \DateTime('2024-06-05'));
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceService->expects($this->never())->method('updateAbsence');

		$response = $this->controller->apiUpdate(55);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('Absence not found', $response->getData()['error']);
	}

	public function testApiDeleteReturnsNotFoundWhenNotOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$absence = new Absence();
		$absence->setId(55);
		$absence->setUserId('otheruser');
		$absence->setStartDate(new \DateTime('2024-06-01'));
		$absence->setEndDate(new \DateTime('2024-06-05'));
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceService->expects($this->never())->method('deleteAbsence');

		$response = $this->controller->apiDelete(55);
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('Absence not found', $response->getData()['error']);
	}

	public function testCancelReturnsErrorWhenNotOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$absence = new Absence();
		$absence->setId(55);
		$absence->setUserId('otheruser');
		$absence->setStartDate(new \DateTime('2099-06-01'));
		$absence->setEndDate(new \DateTime('2099-06-05'));
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceService->expects($this->once())
			->method('cancelAbsence')
			->with(55, 'testuser')
			->willThrowException(new \Exception('Absence not found'));

		$response = $this->controller->cancel(55);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('Absence not found', $response->getData()['error']);
	}

	public function testShortenReturnsErrorWhenNotOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn(['end_date' => '2024-06-03']);

		$this->absenceService->expects($this->once())
			->method('shortenAbsence')
			->with(55, 'testuser', '2024-06-03')
			->willThrowException(new \Exception('Absence not found'));

		$response = $this->controller->shorten(55);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('Absence not found', $response->getData()['error']);
	}
}
