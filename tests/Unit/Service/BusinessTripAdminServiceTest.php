<?php

declare(strict_types=1);

/**
 * Rollout-gate contract for the admin surface (Phase 6): flag flips are
 * audit-logged policy mutations; the legacy cutover refuses while the
 * preflight is red; legal review is bound to the confirmed config hash.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignment;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSegmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSnapshotMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripTimeEntryLinkMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper;
use OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserBusinessTripProfileMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCA\ArbeitszeitCheck\Service\BusinessTripAdminService;
use OCA\ArbeitszeitCheck\Service\BusinessTripCalculationEngine;
use OCA\ArbeitszeitCheck\Service\BusinessTripCreditLedger;
use OCA\ArbeitszeitCheck\Service\BusinessTripRolloutReadinessService;
use OCA\ArbeitszeitCheck\Service\BusinessTripRuleCatalog;
use OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator;
use OCA\ArbeitszeitCheck\Service\BusinessTripRuleResolver;
use OCA\ArbeitszeitCheck\Service\BusinessTripService;
use OCA\ArbeitszeitCheck\Service\BusinessTripSnapshotService;
use OCA\ArbeitszeitCheck\Service\BusinessTripTimelineValidator;
use OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\PolicyCohortResolver;
use OCA\ArbeitszeitCheck\Service\TravelComplianceClassifier;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class BusinessTripAdminServiceTest extends TestCase
{
	private IDBConnection $db;
	private WorkforceSuiteConfig $config;
	private BusinessTripRuleSetMapper $ruleSets;
	private BusinessTripRuleAssignmentMapper $assignments;
	private LoggerInterface $logger;
	private BusinessTripAdminService $service;

	/** @var list<int> counts returned in order for the readiness queries */
	private array $counts = [];
	/** @var list<array{message:string,context:array}> */
	private array $logged = [];

	private const VALID_CONFIG = '{"schemaVersion":1,"dayBasis":"trip_presence","operation":"minimum","thresholds":[{"fromMinutes":0,"targetMinutes":480}],"accountTreatment":{"actualWorkFactorBps":10000,"activeTravelFactorBps":10000,"passiveTravelFactorBps":0,"workDuringTravelFactorBps":10000}}';

	protected function setUp(): void
	{
		$this->counts = [];
		$this->logged = [];
		$this->db = $this->createMock(IDBConnection::class);
		$this->ruleSets = $this->createMock(BusinessTripRuleSetMapper::class);
		$this->assignments = $this->createMock(BusinessTripRuleAssignmentMapper::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->method('info')->willReturnCallback(function (string $m, array $c = []): void {
			$this->logged[] = ['message' => $m, 'context' => $c];
		});
		$this->config = $this->suiteConfig(['business_trips_enabled' => true]);
		$this->service = $this->makeService($this->config);
	}

	/** @param array<string,bool> $enabled */
	private function suiteConfig(array $enabled): WorkforceSuiteConfig
	{
		// Explicit $enabled overrides the shipped defaults; writes land in
		// $writes so updateFlags round-trips through isEnabled.
		$writes = [];
		$appConfig = $this->createMock(IConfig::class);
		$appConfig->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default) use (&$writes, $enabled): string {
				if (array_key_exists($key, $writes)) {
					return $writes[$key];
				}
				if (array_key_exists($key, $enabled)) {
					return $enabled[$key] ? '1' : '0';
				}
				return $default;
			}
		);
		$appConfig->method('setAppValue')->willReturnCallback(
			static function (string $app, string $key, string $value) use (&$writes): void {
				$writes[$key] = $value;
			}
		);
		return new WorkforceSuiteConfig($appConfig);
	}

	private function stubReadinessCounts(): void
	{
		$counts = &$this->counts;
		$this->db->method('getQueryBuilder')->willReturnCallback(function () use (&$counts) {
			$count = array_shift($counts) ?? 0;
			$result = $this->createMock(IResult::class);
			$result->method('fetchOne')->willReturn($count);
			$queryFn = $this->createMock(IQueryFunction::class);
			$func = $this->createMock(IFunctionBuilder::class);
			$func->method('count')->willReturn($queryFn);
			$composite = $this->createMock(ICompositeExpression::class);
			$expr = $this->createMock(IExpressionBuilder::class);
			foreach (['eq', 'in', 'lte', 'gte', 'isNull'] as $m) {
				$expr->method($m)->willReturn('expr');
			}
			$expr->method('orX')->willReturn($composite);
			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('func')->willReturn($func);
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturn(':p');
			foreach (['select', 'from', 'where', 'andWhere'] as $m) {
				$qb->method($m)->willReturn($qb);
			}
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});
	}

	private function makeService(WorkforceSuiteConfig $config): BusinessTripAdminService
	{
		$locking = $this->createMock(ILockingProvider::class);
		$profiles = $this->createMock(UserBusinessTripProfileMapper::class);
		$trips = $this->createMock(BusinessTripMapper::class);
		$userManager = $this->createMock(IUserManager::class);
		$cohortMemberships = $this->createMock(PolicyCohortMembershipMapper::class);
		$cohorts = new PolicyCohortResolver($config, $cohortMemberships);
		$resolver = new BusinessTripRuleResolver(
			$this->assignments,
			$this->ruleSets,
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(TeamMemberMapper::class),
			$this->createMock(TeamMapper::class),
			$cohorts,
		);
		$engine = new BusinessTripCalculationEngine(
			new BusinessTripRuleConfigValidator(),
			new TravelComplianceClassifier(),
			$this->createMock(DailyWorkingHoursCalculator::class),
		);
		$tripService = new BusinessTripService(
			$this->db,
			$locking,
			$config,
			$trips,
			$this->createMock(BusinessTripSegmentMapper::class),
			$this->createMock(BusinessTripTimeEntryLinkMapper::class),
			$this->createMock(BusinessTripDayCalculationMapper::class),
			$this->createMock(TimeEntryMapper::class),
			$profiles,
			new BusinessTripTimelineValidator(),
			$resolver,
			$engine,
			new BusinessTripSnapshotService($this->createMock(BusinessTripSnapshotMapper::class)),
			new BusinessTripCreditLedger($locking, $this->createMock(BusinessTripCreditLedgerMapper::class)),
			$this->createMock(MonthClosureService::class),
			$this->createMock(AuditLogMapper::class),
		);
		$catalog = new BusinessTripRuleCatalog(
			$this->db,
			$locking,
			$this->ruleSets,
			new BusinessTripRuleConfigValidator(),
		);
		$readiness = new BusinessTripRolloutReadinessService($this->db, $this->ruleSets, $this->assignments);
		return new BusinessTripAdminService(
			$config,
			$this->db,
			$locking,
			$this->ruleSets,
			$this->assignments,
			$profiles,
			$trips,
			$tripService,
			$catalog,
			new BusinessTripRuleConfigValidator(),
			$userManager,
			$readiness,
			$this->logger,
		);
	}

	private function greenPreflight(): void
	{
		$this->counts = [0, 0];
		$this->stubReadinessCounts();
		$set = new BusinessTripRuleSet();
		$set->setStatus(BusinessTripRuleSet::STATUS_ACTIVE);
		$set->setReviewedAt(new \DateTime());
		$set->setReviewedBy('legal@corp');
		$this->ruleSets->method('findAll')->willReturn([$set]);
		$asn = new BusinessTripRuleAssignment();
		$asn->setScopeType(BusinessTripRuleAssignment::SCOPE_ORG);
		$this->assignments->method('findEffective')->willReturn([$asn]);
	}

	public function testEnableFeatureFlipsFlagAndLogsActor(): void
	{
		$config = $this->suiteConfig(['business_trips_enabled' => false]);
		$service = $this->makeService($config);
		$service->enableFeature('admin-1');
		$this->assertTrue($config->isEnabled('business_trips_enabled'));
		$this->assertSame('admin-1', $this->logged[0]['context']['actor']);
	}

	public function testCutoverRefusedWhilePreflightRed(): void
	{
		$this->counts = [1, 0]; // one pending legacy request
		$this->stubReadinessCounts();
		$this->ruleSets->method('findAll')->willReturn([]);
		$this->assignments->method('findEffective')->willReturn([]);
		try {
			$this->service->disableLegacyTripAbsence('admin-1');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('TRIP_ROLLOUT_BLOCKED', $e->getReasonCode());
			$this->assertSame(409, $e->httpStatus());
		}
		$this->assertTrue($this->config->isEnabled('legacy_trip_absence_create_enabled')); // unchanged
	}

	public function testCutoverFlipsFlagAndLogsWhenGreen(): void
	{
		$this->greenPreflight();
		$result = $this->service->disableLegacyTripAbsence('admin-1');
		$this->assertFalse($result['legacy_trip_absence_create_enabled']);
		$this->assertFalse($this->config->isEnabled('legacy_trip_absence_create_enabled'));
		$this->assertSame('admin-1', $this->logged[0]['context']['actor']);
	}

	public function testRolloutStatusReportsFlagsAndPreflight(): void
	{
		$this->greenPreflight();
		$status = $this->service->rolloutStatus();
		$this->assertTrue($status['preflight']['ready']);
		$this->assertTrue($status['flags']['business_trips_enabled']);
		$this->assertTrue($status['flags']['legacy_trip_absence_create_enabled']);
	}

	public function testRolloutEndpointsRequireEnabledFeature(): void
	{
		$config = $this->suiteConfig(['business_trips_enabled' => false]);
		$service = $this->makeService($config);
		$this->counts = [0, 0];
		$this->stubReadinessCounts();
		try {
			$service->disableLegacyTripAbsence('admin-1');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('FEATURE_DISABLED', $e->getReasonCode());
		}
	}

	public function testMarkReviewedRejectsStaleConfigHash(): void
	{
		$set = new BusinessTripRuleSet();
		$set->setId(9);
		$set->setStatus(BusinessTripRuleSet::STATUS_DRAFT);
		$set->setConfigJson(self::VALID_CONFIG);
		$set->setConfigHash(hash('sha256', 'edited-after-review'));
		$this->ruleSets->method('find')->willReturn($set);
		$this->ruleSets->expects($this->never())->method('update');
		try {
			$this->service->markRuleSetReviewed(9, 'legal@corp', hash('sha256', self::VALID_CONFIG));
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('RULE_REVIEW_STALE', $e->getReasonCode());
		}
	}
}
