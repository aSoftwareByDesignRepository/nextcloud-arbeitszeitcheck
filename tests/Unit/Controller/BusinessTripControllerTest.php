<?php

declare(strict_types=1);

/**
 * Request-boundary contract for the employee business-trip API:
 * feature gate, uniform 404 for foreign/missing trips, strict revision
 * and timestamp params, export format allowlist.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\BusinessTripController;
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
use OCA\ArbeitszeitCheck\Service\BusinessTripExportService;
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

final class BusinessTripControllerTest extends TestCase
{
	private BusinessTripMapper $tripMapper;

	protected function setUp(): void
	{
		$this->tripMapper = $this->createMock(BusinessTripMapper::class);
	}

	private function controller(array $params = [], bool $enabled = true, string $uid = 'alice'): BusinessTripController
	{
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$request->method('getParam')->willReturnCallback(
			static fn (string $k, $d = null) => $params[$k] ?? $d
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$appConfig = $this->createMock(IConfig::class);
		$appConfig->method('getAppValue')->willReturnCallback(
			static fn (string $a, string $k, string $d): string => ($enabled && $k === 'business_trips_enabled') ? '1' : '0'
		);
		$config = new WorkforceSuiteConfig($appConfig);
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
		$exportService = new BusinessTripExportService(
			$this->createMock(BusinessTripDayCalculationMapper::class),
			new BusinessTripSnapshotService($this->createMock(BusinessTripSnapshotMapper::class)),
		);
		return new BusinessTripController(
			'arbeitszeitcheck',
			$request,
			$session,
			$service,
			$config,
			$exportService,
		);
	}

	private function ownTrip(): BusinessTrip
	{
		$t = new BusinessTrip();
		$t->setId(7);
		$t->setUserId('alice');
		$t->setStatus(BusinessTrip::STATUS_DRAFT);
		$t->setRevision(3);
		$t->setStartsAtUtc(new \DateTime('2026-03-10 06:00:00'));
		$t->setEndsAtUtc(new \DateTime('2026-03-10 18:00:00'));
		return $t;
	}

	public function testDisabledFeatureIs404(): void
	{
		foreach ([
			$this->controller([], false)->index(),
			$this->controller([], false)->show(7),
			$this->controller([], false)->create(),
			$this->controller([], false)->submit(7),
		] as $r) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $r->getStatus());
			$this->assertSame('NOT_FOUND', $r->getData()['error']);
		}
	}

	public function testForeignTripIsIdenticalToMissing(): void
	{
		$this->tripMapper->method('find')->willReturnCallback(static function (int $id) {
			if ($id === 7) {
				$t = new BusinessTrip();
				$t->setId(7);
				$t->setUserId('mallory');
				return $t;
			}
			throw new DoesNotExistException('x');
		});
		$missing = $this->controller()->show(99);
		$foreign = $this->controller()->show(7);
		$this->assertSame(json_encode($missing->getData()), json_encode($foreign->getData()));
		$this->assertSame(Http::STATUS_NOT_FOUND, $foreign->getStatus());
	}

	public function testUpdateWithoutRevisionIs409(): void
	{
		$this->tripMapper->method('find')->willReturn($this->ownTrip());
		$r = $this->controller(['purpose' => 'x'])->update(7);
		$this->assertSame(409, $r->getStatus());
		$this->assertSame('TRIP_REVISION_STALE', $r->getData()['error_code']);
	}

	public function testCreateWithoutRequestKeyIs400(): void
	{
		$r = $this->controller([
			'startsAtUtc' => '2026-03-10T06:00:00Z',
			'endsAtUtc' => '2026-03-10T18:00:00Z',
			'originTimezone' => 'UTC',
			'destinationTimezone' => 'UTC',
			'evaluationTimezone' => 'UTC',
		])->create();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		$this->assertSame('invalid', $r->getData()['fields']['requestKey'] ?? null);
	}

	public function testCreateRejectsMalformedTimestamp(): void
	{
		$r = $this->controller([
			'startsAtUtc' => 'not-a-date',
			'endsAtUtc' => '2026-03-10T18:00:00Z',
			'originTimezone' => 'UTC',
			'destinationTimezone' => 'UTC',
			'evaluationTimezone' => 'UTC',
			'requestKey' => 'req-test-0001',
		])->create();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		$this->assertSame('invalid', $r->getData()['fields']['startsAtUtc'] ?? null);
	}

	public function testShowOwnTripMergesDetail(): void
	{
		$this->tripMapper->method('find')->willReturn($this->ownTrip());
		$r = $this->controller()->show(7);
		$d = $r->getData();
		$this->assertTrue($d['success']);
		$this->assertSame(7, $d['trip']['id']);
		$this->assertSame('draft', $d['trip']['status']);
		$this->assertArrayHasKey('segments', $d);
		$this->assertArrayHasKey('snapshotToken', $d);
	}
}
