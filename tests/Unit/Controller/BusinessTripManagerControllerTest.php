<?php

declare(strict_types=1);

/**
 * Request-boundary contract for the manager business-trip API:
 * feature gate, scope-before-state ordering, uniform 404 (no existence
 * oracle), CAS/snapshot param requirements, and payload shape.
 * The lifecycle itself is covered by BusinessTripServiceTest.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\BusinessTripManagerController;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSegmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSnapshotMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripTimeEntryLinkMapper;
use OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserBusinessTripProfileMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\BusinessTripCalculationEngine;
use OCA\ArbeitszeitCheck\Service\BusinessTripCreditLedger;
use OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator;
use OCA\ArbeitszeitCheck\Service\BusinessTripRuleResolver;
use OCA\ArbeitszeitCheck\Service\BusinessTripService;
use OCA\ArbeitszeitCheck\Service\BusinessTripSnapshotService;
use OCA\ArbeitszeitCheck\Service\BusinessTripTimelineValidator;
use OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\PolicyCohortResolver;
use OCA\ArbeitszeitCheck\Service\TravelComplianceClassifier;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class BusinessTripManagerControllerTest extends TestCase
{
	private BusinessTripMapper $tripMapper;
	private PermissionService $permissions;
	private BusinessTripManagerController $controller;

	protected function setUp(): void
	{
		$this->tripMapper = $this->createMock(BusinessTripMapper::class);
		$this->permissions = $this->createMock(PermissionService::class);
	}

	private function controller(array $params = [], bool $enabled = true, string $uid = 'manager'): BusinessTripManagerController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$appConfig = $this->createMock(IConfig::class);
		$appConfig->method('getAppValue')->willReturnCallback(
			static fn (string $a, string $k, string $d): string => ($enabled && $k === 'business_trips_enabled') ? '1' : '0'
		);
		$config = new WorkforceSuiteConfig($appConfig);
		// Real service over mocked mappers — final classes cannot be doubled
		// and the failure paths under test never reach the service anyway.
		$locks = $this->createMock(ILockingProvider::class);
		$cohorts = new PolicyCohortResolver($config, $this->createMock(PolicyCohortMembershipMapper::class));
		$service = new BusinessTripService(
			$this->createMock(IDBConnection::class),
			$locks,
			$config,
			$this->tripMapper,
			$this->createMock(BusinessTripSegmentMapper::class),
			$this->createMock(BusinessTripTimeEntryLinkMapper::class),
			$this->createMock(BusinessTripDayCalculationMapper::class),
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(UserBusinessTripProfileMapper::class),
			new BusinessTripTimelineValidator(),
			new BusinessTripRuleResolver(
				$this->createMock(BusinessTripRuleAssignmentMapper::class),
				$this->createMock(BusinessTripRuleSetMapper::class),
				$this->createMock(UserWorkingTimeModelMapper::class),
				$this->createMock(TeamMemberMapper::class),
				$this->createMock(TeamMapper::class),
				$cohorts,
			),
			new BusinessTripCalculationEngine(
				new BusinessTripRuleConfigValidator(),
				new TravelComplianceClassifier(),
				$this->createMock(DailyWorkingHoursCalculator::class),
			),
			new BusinessTripSnapshotService($this->createMock(BusinessTripSnapshotMapper::class)),
			new BusinessTripCreditLedger($locks, $this->createMock(BusinessTripCreditLedgerMapper::class)),
			$this->createMock(MonthClosureService::class),
			$this->createMock(AuditLogMapper::class),
		);
		return new BusinessTripManagerController(
			'arbeitszeitcheck',
			$request,
			$session,
			$service,
			$this->tripMapper,
			$this->permissions,
			$config,
		);
	}

	private function submittedTrip(int $id = 7, string $owner = 'alice'): BusinessTrip
	{
		$trip = new BusinessTrip();
		$trip->setId($id);
		$trip->setUserId($owner);
		$trip->setStatus(BusinessTrip::STATUS_SUBMITTED);
		$trip->setRevision(4);
		$trip->setStartsAtUtc(new \DateTime('2026-03-10 06:00:00'));
		$trip->setEndsAtUtc(new \DateTime('2026-03-11 18:00:00'));
		$trip->setOriginTimezone('Europe/Berlin');
		$trip->setDestinationTimezone('Europe/London');
		$trip->setEvaluationTimezone('Europe/Berlin');
		return $trip;
	}

	private function payload(JSONResponse $r): array
	{
		return $r->getData();
	}

	public function testIndexDisabledFeatureReturnsUniform404(): void
	{
		$r = $this->controller([], false)->index();
		$this->assertSame(Http::STATUS_NOT_FOUND, $r->getStatus());
		$p = $this->payload($r);
		$this->assertSame('NOT_FOUND', $p['error']);
		$this->assertFalse($p['success']);
	}

	public function testIndexScopesManagerToTeamMembers(): void
	{
		$in = $this->submittedTrip(7, 'alice');
		$out = $this->submittedTrip(8, 'mallory');
		$this->permissions->method('isAdmin')->with('manager')->willReturn(false);
		$this->permissions->method('canAccessManagerDashboard')->with('manager')->willReturn(true);
		$this->permissions->method('getManagedEmployeeIds')->with('manager')->willReturn(['alice', 'bob']);
		$this->tripMapper->method('findSubmitted')->willReturn([$in, $out]);
		$r = $this->controller()->index();
		$items = $this->payload($r)['items'];
		$this->assertCount(1, $items);
		$this->assertSame(7, $items[0]['id']);
	}

	public function testIndexAdminSeesAll(): void
	{
		$this->permissions->method('isAdmin')->with('manager')->willReturn(true);
		$this->tripMapper->method('findSubmitted')->willReturn([
			$this->submittedTrip(7, 'alice'),
			$this->submittedTrip(8, 'mallory'),
		]);
		$r = $this->controller()->index();
		$this->assertCount(2, $this->payload($r)['items']);
	}

	public function testIndexDeniesPlainEmployee(): void
	{
		// A collection route cannot lean on per-object scope checks — an empty
		// list is indistinguishable from denial. Non-managers must get 403.
		$this->permissions->method('isAdmin')->with('mallory')->willReturn(false);
		$this->permissions->method('canAccessManagerDashboard')->with('mallory')->willReturn(false);
		$this->permissions->expects($this->once())->method('logPermissionDenied')
			->with('mallory', 'list_business_trips', 'manager_api');
		$this->tripMapper->expects($this->never())->method('findSubmitted');
		$r = $this->controller([], true, 'mallory')->index();
		$this->assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
		$this->assertSame('Access denied.', $this->payload($r)['error']);
	}

	public function testShowForeignTripMatchesMissingIdExactly(): void
	{
		// Missing id → 404 canonical.
		$this->tripMapper->method('find')->willReturnCallback(static function (int $id) {
			if ($id === 7) {
				$t = new BusinessTrip();
				$t->setId(7);
				$t->setUserId('mallory');
				return $t;
			}
			throw new DoesNotExistException('x');
		});
		$this->permissions->method('canManageEmployee')->willReturn(false);
		$this->permissions->expects($this->once())->method('logPermissionDenied')
			->with('manager', 'view_business_trip', 'business_trip', '7');
		$missing = $this->controller()->show(99);
		$foreign = $this->controller()->show(7);
		// Identical wire bytes — a member cannot diff codes, messages, or
		// field shapes between "no such id" and "not yours".
		$this->assertSame(json_encode($missing->getData()), json_encode($foreign->getData()));
		$this->assertSame(Http::STATUS_NOT_FOUND, $foreign->getStatus());
		$this->assertSame('NOT_FOUND', $this->payload($foreign)['error']);
	}

	public function testApproveWithoutTokenIs409BeforeAnyLookup(): void
	{
		// Missing token must fail closed before touching the trip row —
		// constant response, no existence oracle via timing/DB side channels.
		$this->tripMapper->expects($this->never())->method('find');
		$r = $this->controller(['revision' => '4'])->approve(7);
		$this->assertSame(409, $r->getStatus());
		$this->assertSame('TRIP_SNAPSHOT_REQUIRED', $this->payload($r)['error_code']);
	}

	public function testApproveWithoutValidRevisionIs409(): void
	{
		$this->tripMapper->expects($this->never())->method('find');
		$this->permissions->method('canManageEmployee')->willReturn(true);
		$r = $this->controller(['snapshotToken' => 'tok', 'revision' => 'abc'])->approve(7);
		$this->assertSame(409, $r->getStatus());
		$this->assertSame('TRIP_REVISION_STALE', $this->payload($r)['error_code']);
	}

	public function testApproveOutOfScopeIsUniform404(): void
	{
		$this->tripMapper->method('find')->willReturn($this->submittedTrip(7, 'mallory'));
		$this->permissions->method('canDecideEntryApproval')->with('manager', 'mallory')->willReturn(false);
		$r = $this->controller(['snapshotToken' => 'tok', 'revision' => '4'])->approve(7);
		$this->assertSame(Http::STATUS_NOT_FOUND, $r->getStatus());
		$this->assertSame('NOT_FOUND', $this->payload($r)['error']);
	}

	public function testRejectReasonOverLimitIs400(): void
	{
		$r = $this->controller(['reason' => str_repeat('x', 501)])->reject(7);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		$this->assertSame('invalid', $this->payload($r)['fields']['reason'] ?? null);
	}

	public function testShowInScopeMergesDetailPayload(): void
	{
		$this->tripMapper->method('find')->willReturn($this->submittedTrip());
		$this->permissions->method('canManageEmployee')->with('manager', 'alice')->willReturn(true);
		$r = $this->controller()->show(7);
		$p = $this->payload($r);
		$this->assertTrue($p['success']);
		$this->assertSame(7, $p['trip']['id']);
		$this->assertSame('submitted', $p['trip']['status']);
		$this->assertSame(4, $p['trip']['revision']);
		// Detail merge: manager review cards need fact + day + token data.
		$this->assertArrayHasKey('segments', $p);
		$this->assertArrayHasKey('days', $p);
		$this->assertArrayHasKey('linkedTimeEntryIds', $p);
		$this->assertArrayHasKey('snapshotToken', $p);
	}
}
