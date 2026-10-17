<?php

declare(strict_types=1);

/**
 * Request-boundary contract for POST /api/manager/bulk-decision:
 * manager eligibility, payload shape, uniform 404 collapse and 409/400
 * mapping. The decision logic itself is covered by
 * ManagerBulkDecisionServiceTest.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\ManagerBulkDecisionController;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\BusinessTripService;
use OCA\ArbeitszeitCheck\Service\ManagerBulkDecisionService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

final class ManagerBulkDecisionControllerTest extends TestCase
{
	private PermissionService $permissions;
	private AbsenceMapper $absenceMapper;
	private AbsenceService $absenceService;
	private AuditLogMapper $auditLogMapper;
	private IL10N $l10n;

	protected function setUp(): void
	{
		$this->permissions = $this->createMock(PermissionService::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->absenceService = $this->createMock(AbsenceService::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
	}

	private function controller(array $params = [], string $uid = 'manager'): ManagerBulkDecisionController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		// BusinessTripService is final and never reached in these boundary
		// tests — provide an uninitialized instance for the constructor.
		$tripRef = new \ReflectionClass(BusinessTripService::class);
		/** @var BusinessTripService $tripService */
		$tripService = $tripRef->newInstanceWithoutConstructor();

		$service = new ManagerBulkDecisionService(
			$this->createMock(IDBConnection::class),
			$this->createMock(TimeEntryMapper::class),
			$this->absenceMapper,
			$this->createMock(BusinessTripMapper::class),
			$this->auditLogMapper,
			$this->createMock(TimeEntryCorrectionService::class),
			$this->absenceService,
			$tripService,
			$this->permissions,
			new WorkforceSuiteConfig($this->createMock(IConfig::class)),
			$this->l10n,
		);
		return new ManagerBulkDecisionController('arbeitszeitcheck', $request, $session, $service, $this->permissions, $this->l10n);
	}

	private function pendingAbsence(int $id, string $userId = 'employee1'): Absence
	{
		$absence = new Absence();
		$absence->setId($id);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		return $absence;
	}

	public function testNonManagerGets403(): void
	{
		$this->permissions->method('isAdmin')->willReturn(false);
		$this->permissions->method('canAccessManagerDashboard')->willReturn(false);
		$this->permissions->expects($this->once())->method('logPermissionDenied');

		$res = $this->controller(['action' => 'approve', 'items' => [['type' => 'absence', 'id' => 1]]])->decide();
		$this->assertSame(Http::STATUS_FORBIDDEN, $res->getStatus());
	}

	public function testInvalidActionShapeGets400(): void
	{
		$this->permissions->method('isAdmin')->willReturn(true);
		$res = $this->controller(['items' => []])->decide();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
	}

	public function testInvalidItemsShapeGets400(): void
	{
		$this->permissions->method('isAdmin')->willReturn(true);
		$res = $this->controller(['action' => 'approve', 'items' => 'nope'])->decide();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $res->getStatus());
	}

	public function testUnknownItemCollapsesToUniform404(): void
	{
		$this->permissions->method('isAdmin')->willReturn(true);
		$this->absenceMapper->method('find')->willThrowException(new DoesNotExistException('x'));
		$res = $this->controller(['action' => 'approve', 'items' => [['type' => 'absence', 'id' => 42]]])->decide();
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$this->assertSame('NOT_FOUND', $res->getData()['error_code']);
	}

	public function testOutOfScopeItemCollapsesToUniform404(): void
	{
		$this->permissions->method('isAdmin')->willReturn(true);
		$this->permissions->method('canManageEmployee')->willReturn(false);
		$this->absenceMapper->method('find')->willReturn($this->pendingAbsence(5, 'foreign-user'));
		$res = $this->controller(['action' => 'approve', 'items' => [['type' => 'absence', 'id' => 5]]])->decide();
		$this->assertSame(Http::STATUS_NOT_FOUND, $res->getStatus());
		$this->assertSame('NOT_FOUND', $res->getData()['error_code']);
	}

	public function testAlreadyDecidedMapsTo409(): void
	{
		$this->permissions->method('isAdmin')->willReturn(true);
		$this->permissions->method('canManageEmployee')->willReturn(true);
		$absence = $this->pendingAbsence(5);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$this->absenceMapper->method('find')->willReturn($absence);
		$res = $this->controller(['action' => 'approve', 'items' => [['type' => 'absence', 'id' => 5]]])->decide();
		$this->assertSame(Http::STATUS_CONFLICT, $res->getStatus());
		$this->assertSame('already_decided', $res->getData()['error_code']);
	}

	public function testSuccessReturnsResults(): void
	{
		$this->permissions->method('isAdmin')->willReturn(true);
		$this->permissions->method('canManageEmployee')->willReturn(true);
		$this->absenceMapper->method('find')->willReturn($this->pendingAbsence(1));
		$this->absenceService->method('approveAbsence')->willReturn($this->pendingAbsence(1));
		$res = $this->controller(['action' => 'approve', 'items' => [['type' => 'absence', 'id' => 1]]])->decide();
		$this->assertSame(Http::STATUS_OK, $res->getStatus());
		$this->assertTrue($res->getData()['success']);
		$this->assertSame(1, $res->getData()['applied']);
	}
}
