<?php

declare(strict_types=1);

/**
 * BusinessTripService lifecycle tests — the security-critical core of the
 * business-trip domain: CAS revisions, state machine, lock ordering,
 * snapshot binding, self-decision block, closed-month guard, idempotent
 * create and uniform not-found behavior.
 *
 * The collaborators are real where they are the thing under test's blast
 * radius (timeline validator, rule config validator, classifier, engine,
 * snapshot hash chain, credit ledger, resolver) and mocked at the mapper
 * boundary — a wrong hash or a wrong transition is a real bug, not a mock
 * artifact.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerEntry;
use OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignment;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSegment;
use OCA\ArbeitszeitCheck\Db\BusinessTripSegmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSnapshot;
use OCA\ArbeitszeitCheck\Db\BusinessTripSnapshotMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripTimeEntryLink;
use OCA\ArbeitszeitCheck\Db\BusinessTripTimeEntryLinkMapper;
use OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserBusinessTripProfileMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Service\BusinessTripCalculationEngine;
use OCA\ArbeitszeitCheck\Service\BusinessTripCreditLedger;
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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class BusinessTripServiceTest extends TestCase
{
	private IDBConnection $db;
	private ILockingProvider $locks;
	private WorkforceSuiteConfig $config;
	private BusinessTripMapper $trips;
	private BusinessTripSegmentMapper $segments;
	private BusinessTripTimeEntryLinkMapper $links;
	private BusinessTripDayCalculationMapper $dayCalcs;
	private TimeEntryMapper $timeEntries;
	private UserBusinessTripProfileMapper $profiles;
	private BusinessTripRuleAssignmentMapper $assignments;
	private BusinessTripRuleSetMapper $ruleSets;
	private UserWorkingTimeModelMapper $userModels;
	private TeamMemberMapper $teamMembers;
	private TeamMapper $teams;
	private PolicyCohortMembershipMapper $cohortMemberships;
	private BusinessTripSnapshotMapper $snapshotMapper;
	private BusinessTripCreditLedgerMapper $ledgerMapper;
	private DailyWorkingHoursCalculator $workingHours;
	private MonthClosureService $monthClosures;
	private AuditLogMapper $audit;
	private BusinessTripService $service;
	private BusinessTripSnapshotService $snapshots;

	/** @var list<string> */
	private array $acquiredKeys = [];
	/** @var list<string> */
	private array $releasedKeys = [];
	/** @var list<string> payload hashes captured at freeze time */
	private array $frozenHashes = [];
	/** @var array<string,BusinessTripSnapshot> latest frozen snapshot per kind */
	private array $lastFrozenByKind = [];
	/** @var list<BusinessTripCreditLedgerEntry> */
	private array $ledgerRows = [];

	private const CONFIG_JSON = '{"schemaVersion":1,"dayBasis":"trip_presence","operation":"minimum","thresholds":[{"fromMinutes":0,"targetMinutes":480}],"accountTreatment":{"actualWorkFactorBps":10000,"activeTravelFactorBps":10000,"passiveTravelFactorBps":0,"workDuringTravelFactorBps":10000}}';

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->locks = $this->createMock(ILockingProvider::class);
		$this->acquiredKeys = [];
		$this->releasedKeys = [];
		$this->frozenHashes = [];
		$this->ledgerRows = [];
		$this->locks->method('acquireLock')->willReturnCallback(function (string $key): void {
			$this->acquiredKeys[] = $key;
		});
		$this->locks->method('releaseLock')->willReturnCallback(function (string $key): void {
			$this->releasedKeys[] = $key;
		});
		$this->config = $this->suiteConfig(['business_trips_enabled' => true, 'business_trip_credit_enabled' => true]);
		$this->trips = $this->createMock(BusinessTripMapper::class);
		$this->segments = $this->createMock(BusinessTripSegmentMapper::class);
		$this->links = $this->createMock(BusinessTripTimeEntryLinkMapper::class);
		$this->dayCalcs = $this->createMock(BusinessTripDayCalculationMapper::class);
		$this->timeEntries = $this->createMock(TimeEntryMapper::class);
		$this->profiles = $this->createMock(UserBusinessTripProfileMapper::class);
		$this->assignments = $this->createMock(BusinessTripRuleAssignmentMapper::class);
		$this->ruleSets = $this->createMock(BusinessTripRuleSetMapper::class);
		$this->userModels = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->teamMembers = $this->createMock(TeamMemberMapper::class);
		$this->teams = $this->createMock(TeamMapper::class);
		$this->cohortMemberships = $this->createMock(PolicyCohortMembershipMapper::class);
		$this->snapshotMapper = $this->createMock(BusinessTripSnapshotMapper::class);
		$this->snapshotMapper->method('insert')->willReturnCallback(function ($snapshot) {
			$this->frozenHashes[] = (string)$snapshot->getPayloadHash();
			$snapshot->setId(count($this->frozenHashes));
			$this->lastFrozenByKind[$snapshot->getKind()] = $snapshot;
			return $snapshot;
		});
		$this->snapshotMapper->method('findLatestForTrip')->willReturnCallback(
			fn (int $tripId, string $kind): ?BusinessTripSnapshot => $this->lastFrozenByKind[$kind] ?? null
		);
		$this->ledgerMapper = $this->createMock(BusinessTripCreditLedgerMapper::class);
		$ledgerMapper = $this->ledgerMapper;
		$this->ledgerMapper->method('insert')->willReturnCallback(function (BusinessTripCreditLedgerEntry $e) {
			$this->ledgerRows[] = $e;
			$e->setId(count($this->ledgerRows));
			return $e;
		});
		$this->ledgerMapper->method('findForTrip')->willReturnCallback(fn () => $this->ledgerRows);
		$this->workingHours = $this->createMock(DailyWorkingHoursCalculator::class);
		$this->monthClosures = $this->createMock(MonthClosureService::class);
		$this->monthClosures->method('monthsOverlappingRange')->willReturnCallback(
			static function (\DateTime $a, \DateTime $b): array {
				$out = [];
				$cur = (clone $a)->modify('first day of this month');
				$last = (clone $b)->modify('first day of this month');
				while ($cur <= $last) {
					$out[] = [(int)$cur->format('Y'), (int)$cur->format('n')];
					$cur->modify('+1 month');
				}
				return $out;
			}
		);
		$this->audit = $this->createMock(AuditLogMapper::class);
		$this->service = $this->makeService($this->config);
	}

	/** @param array<string,bool> $enabled */
	private function suiteConfig(array $enabled): WorkforceSuiteConfig
	{
		$appConfig = $this->createMock(IConfig::class);
		$appConfig->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => ($enabled[$key] ?? false) ? '1' : '0'
		);
		return new WorkforceSuiteConfig($appConfig);
	}

	private function makeService(WorkforceSuiteConfig $config, ?ILockingProvider $locks = null, ?\OCA\ArbeitszeitCheck\Service\PermissionService $permissions = null): BusinessTripService
	{
		$locking = $locks ?? $this->locks;
		// Real collaborators over mocked mapper boundaries: the hash chain,
		// engine math and ledger idempotency are the security surface.
		$cohorts = new PolicyCohortResolver($config, $this->cohortMemberships);
		$resolver = new BusinessTripRuleResolver(
			$this->assignments,
			$this->ruleSets,
			$this->userModels,
			$this->teamMembers,
			$this->teams,
			$cohorts,
		);
		$engine = new BusinessTripCalculationEngine(
			new BusinessTripRuleConfigValidator(),
			new TravelComplianceClassifier(),
			$this->workingHours,
		);
		$this->snapshots = new BusinessTripSnapshotService($this->snapshotMapper);
		$ledger = new BusinessTripCreditLedger($locking, $this->ledgerMapper);
		return new BusinessTripService(
			$this->db,
			$locking,
			$config,
			$this->trips,
			$this->segments,
			$this->links,
			$this->dayCalcs,
			$this->timeEntries,
			$this->profiles,
			new BusinessTripTimelineValidator(),
			$resolver,
			$engine,
			$this->snapshots,
			$ledger,
			$this->monthClosures,
			$this->audit,
			$permissions,
		);
	}

	private function draftTrip(int $id = 7, string $userId = 'alice', int $revision = 3): BusinessTrip
	{
		$trip = new BusinessTrip();
		$trip->setId($id);
		$trip->setUserId($userId);
		$trip->setStartsAtUtc(new \DateTime('2026-03-10 06:00:00'));
		$trip->setEndsAtUtc(new \DateTime('2026-03-11 18:00:00'));
		$trip->setOriginTimezone('Europe/Berlin');
		$trip->setDestinationTimezone('Europe/London');
		$trip->setEvaluationTimezone('Europe/Berlin');
		$trip->setStatus(BusinessTrip::STATUS_DRAFT);
		$trip->setRevision($revision);
		return $trip;
	}

	/**
	 * Wire the resolver so every day resolves to an active org-scoped rule
	 * set — required by submit/approve which recalculate under lock.
	 */
	private function resolveOrgRule(): void
	{
		$ruleSet = new BusinessTripRuleSet();
		$ruleSet->setId(5);
		$ruleSet->setStatus(BusinessTripRuleSet::STATUS_ACTIVE);
		$ruleSet->setJurisdiction(BusinessTripRuleSet::JURISDICTION_DE);
		$ruleSet->setConfigJson(self::CONFIG_JSON);
		$ruleSet->setConfigHash('cfg-hash-5');
		$this->ruleSets->method('find')->willReturn($ruleSet);

		$assignment = new BusinessTripRuleAssignment();
		$assignment->setId(9);
		$assignment->setRuleSetId(5);
		$assignment->setScopeType(BusinessTripRuleAssignment::SCOPE_ORG);
		$assignment->setScopeId(BusinessTripRuleAssignment::ORG_SCOPE_ID);
		$assignment->setPriority(0);
		$this->assignments->method('findEffective')->willReturn([$assignment]);
		$this->userModels->method('findByUserAndDate')->willReturn(null);
		$this->teamMembers->method('findByUserId')->willReturn([]);
		$this->teams->method('getParentMap')->willReturn([]);
	}

	/** CAS-bump updateIfRevision mirroring the real mapper. */
	private function stubCasWrite(): void
	{
		$this->trips->method('updateIfRevision')->willReturnCallback(static function (BusinessTrip $t): bool {
			$t->setRevision((int)$t->getRevision() + 1);
			return true;
		});
	}

	// ---------- feature flag ----------

	public function testDisabledFeatureCollapsesTo404(): void
	{
		$service = $this->makeService($this->suiteConfig([]));
		foreach ([
			fn () => $service->createDraft('alice', new \DateTime('2026-03-10T06:00:00Z'), new \DateTime('2026-03-10T18:00:00Z'), 'UTC', 'UTC', 'UTC', null, 'req-test-0001', 'alice'),
			fn () => $service->detail($this->draftTrip()),
			fn () => $service->submit($this->draftTrip(), 3, 'alice'),
			fn () => $service->preview($this->draftTrip(), 'alice'),
		] as $call) {
			try {
				$call();
				$this->fail('expected BusinessTripException');
			} catch (BusinessTripException $e) {
				$this->assertSame(404, $e->httpStatus());
				$this->assertSame('FEATURE_DISABLED', $e->getReasonCode());
			}
		}
	}

	// ---------- create ----------

	public function testCreateIsIdempotentOnRequestKey(): void
	{
		$existing = $this->draftTrip();
		$this->trips->expects($this->once())
			->method('findByRequestKey')->willReturn($existing);
		$this->trips->expects($this->never())->method('insert');
		$result = $this->service->createDraft(
			'alice', new \DateTime('2026-03-10T06:00:00Z'), new \DateTime('2026-03-10T18:00:00Z'),
			'UTC', 'UTC', 'UTC', null, 'req-test-0001', 'alice',
		);
		$this->assertSame($existing, $result);
	}

	public function testCreateRejectsInvertedWindow(): void
	{
		$this->expectException(BusinessTripException::class);
		$this->service->createDraft(
			'alice', new \DateTime('2026-03-10T18:00:00Z'), new \DateTime('2026-03-10T06:00:00Z'),
			'UTC', 'UTC', 'UTC', null, 'req-test-0001', 'alice',
		);
	}

	public function testCreateRejectsUnknownTimezone(): void
	{
		try {
			$this->service->createDraft(
				'alice', new \DateTime('2026-03-10T06:00:00Z'), new \DateTime('2026-03-10T18:00:00Z'),
				'Mars/Olympus', 'UTC', 'UTC', null, 'req-test-0001', 'alice',
			);
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(400, $e->httpStatus());
			$this->assertSame('invalid', $e->fields()['originTimezone'] ?? null);
		}
	}

	public function testCreateOverlapConflict(): void
	{
		$this->trips->method('findByRequestKey')->willReturn(null);
		$this->trips->method('findOverlapping')->willReturn([$this->draftTrip(9)]);
		$this->trips->expects($this->never())->method('insert');
		try {
			$this->service->createDraft(
				'alice', new \DateTime('2026-03-10T06:00:00Z'), new \DateTime('2026-03-10T18:00:00Z'),
				'UTC', 'UTC', 'UTC', null, 'req-test-0001', 'alice',
			);
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(409, $e->httpStatus());
			$this->assertSame('TRIP_OVERLAP', $e->getReasonCode());
		}
		// Locks released even on conflict — no leaks.
		$this->assertEqualsCanonicalizing($this->acquiredKeys, $this->releasedKeys);
	}

	public function testCreateBlockedInFinalizedMonth(): void
	{
		$this->trips->method('findByRequestKey')->willReturn(null);
		$this->monthClosures->method('assertDateRangeMutable')
			->willThrowException(new MonthFinalizedException('month_finalized'));
		$this->trips->expects($this->never())->method('insert');
		try {
			$this->service->createDraft(
				'alice', new \DateTime('2026-03-10T06:00:00Z'), new \DateTime('2026-03-10T18:00:00Z'),
				'UTC', 'UTC', 'UTC', null, 'req-test-0001', 'alice',
			);
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(409, $e->httpStatus());
			$this->assertSame('MONTH_FINALIZED', $e->getReasonCode());
		}
		$this->assertEqualsCanonicalizing($this->acquiredKeys, $this->releasedKeys);
	}

	public function testCreateInsertsDraft(): void
	{
		$this->trips->method('findByRequestKey')->willReturn(null);
		$this->trips->method('findOverlapping')->willReturn([]);
		$this->trips->expects($this->once())
			->method('insert')
			->willReturnCallback(static function (BusinessTrip $t): BusinessTrip {
				$t->setId(11);
				return $t;
			});
		$trip = $this->service->createDraft(
			'alice', new \DateTime('2026-03-10T06:00:00Z'), new \DateTime('2026-03-10T18:00:00Z'),
			'UTC', 'UTC', 'UTC', null, 'req-test-0001', 'alice',
		);
		$this->assertSame(11, (int)$trip->getId());
		$this->assertSame(BusinessTrip::STATUS_DRAFT, $trip->getStatus());
		$this->assertSame(1, (int)$trip->getRevision());
		$this->assertEqualsCanonicalizing($this->acquiredKeys, $this->releasedKeys);
	}

	// ---------- update ----------

	public function testUpdateStaleRevisionRejected(): void
	{
		$fresh = $this->draftTrip(7, 'alice', 5);
		$this->trips->method('find')->willReturn($fresh);
		try {
			$this->service->updateDraft($this->draftTrip(7, 'alice', 3), 3, ['purpose' => 'x'], 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(409, $e->httpStatus());
			$this->assertSame('TRIP_REVISION_STALE', $e->getReasonCode());
		}
	}

	public function testUpdateNonDraftRejected(): void
	{
		$trip = $this->draftTrip();
		$trip->setStatus(BusinessTrip::STATUS_SUBMITTED);
		try {
			$this->service->updateDraft($trip, 3, ['purpose' => 'x'], 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(409, $e->httpStatus());
		}
	}

	public function testUpdateShrinkRefusesOrphanedSegment(): void
	{
		$fresh = $this->draftTrip(7, 'alice', 3);
		$this->trips->method('find')->willReturn($fresh);
		$seg = new BusinessTripSegment();
		$seg->setStartsAtUtc(new \DateTime('2026-03-10 06:00:00'));
		$seg->setEndsAtUtc(new \DateTime('2026-03-10 08:00:00'));
		$this->segments->method('findForTrip')->willReturn([$seg]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([]);
		// Shrink the start past the segment start → orphan.
		try {
			$this->service->updateDraft($this->draftTrip(7, 'alice', 3), 3, [
				'startsAtUtc' => '2026-03-10T07:00:00+00:00',
			], 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(400, $e->httpStatus());
			$this->assertSame('outside_window', $e->fields()['segments'] ?? null);
		}
	}

	public function testUpdateShrinkRefusesOrphanedLink(): void
	{
		$fresh = $this->draftTrip(7, 'alice', 3);
		$this->trips->method('find')->willReturn($fresh);
		$this->segments->method('findForTrip')->willReturn([]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([42]);
		$entry = new TimeEntry();
		$entry->setUserId('alice');
		$entry->setStartTime(new \DateTime('2026-03-10 06:30:00'));
		$entry->setEndTime(new \DateTime('2026-03-10 12:00:00'));
		$this->timeEntries->method('find')->willReturn($entry);
		try {
			$this->service->updateDraft($this->draftTrip(7, 'alice', 3), 3, [
				'startsAtUtc' => '2026-03-10T07:00:00+00:00',
			], 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(400, $e->httpStatus());
			$this->assertSame('outside_window', $e->fields()['timeEntryId'] ?? null);
		}
	}

	public function testUpdateAppliesFieldsAndCas(): void
	{
		$fresh = $this->draftTrip(7, 'alice', 3);
		$this->trips->method('find')->willReturn($fresh);
		$this->trips->method('findOverlapping')->willReturn([]);
		$this->segments->method('findForTrip')->willReturn([]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([]);
		$this->trips->expects($this->once())->method('updateContentIfRevision')->willReturn(true);
		$result = $this->service->updateDraft($this->draftTrip(7, 'alice', 3), 3, ['purpose' => 'Conference'], 'alice');
		$this->assertSame('Conference', $result->getPurpose());
		$this->assertEqualsCanonicalizing($this->acquiredKeys, $this->releasedKeys);
	}

	public function testUpdateCasWriteFailureRejected(): void
	{
		$fresh = $this->draftTrip(7, 'alice', 3);
		$this->trips->method('find')->willReturn($fresh);
		$this->trips->method('findOverlapping')->willReturn([]);
		$this->segments->method('findForTrip')->willReturn([]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([]);
		$this->trips->method('updateContentIfRevision')->willReturn(false);
		try {
			$this->service->updateDraft($this->draftTrip(7, 'alice', 3), 3, ['purpose' => 'x'], 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(409, $e->httpStatus());
		}
	}

	// ---------- links ----------

	public function testLinkForeignTimeEntryIsUniform404(): void
	{
		$this->trips->method('find')->willReturn($this->draftTrip());
		$entry = new TimeEntry();
		$entry->setUserId('bob'); // foreign
		$entry->setStartTime(new \DateTime('2026-03-10 07:00:00'));
		$this->timeEntries->method('find')->willReturn($entry);
		try {
			$this->service->linkTimeEntry($this->draftTrip(), 42, 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(404, $e->httpStatus());
			$this->assertSame('TIME_ENTRY_NOT_FOUND', $e->getReasonCode());
		}
	}

	public function testLinkEntryOutsideWindowRejected(): void
	{
		$this->trips->method('find')->willReturn($this->draftTrip());
		$entry = new TimeEntry();
		$entry->setUserId('alice');
		$entry->setStartTime(new \DateTime('2026-03-09 07:00:00')); // before trip
		$entry->setEndTime(new \DateTime('2026-03-09 15:00:00'));
		$this->timeEntries->method('find')->willReturn($entry);
		try {
			$this->service->linkTimeEntry($this->draftTrip(), 42, 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(400, $e->httpStatus());
		}
	}

	public function testLinkTakenRejected(): void
	{
		$this->trips->method('find')->willReturn($this->draftTrip());
		$entry = new TimeEntry();
		$entry->setUserId('alice');
		$entry->setStartTime(new \DateTime('2026-03-10 07:00:00'));
		$entry->setEndTime(new \DateTime('2026-03-10 12:00:00'));
		$this->timeEntries->method('find')->willReturn($entry);
		$link = new BusinessTripTimeEntryLink();
		$link->setTripId(7);
		$this->links->method('findByTimeEntryId')->willReturn($link);
		try {
			$this->service->linkTimeEntry($this->draftTrip(), 42, 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(409, $e->httpStatus());
			$this->assertSame('already_linked', $e->fields()['timeEntryId'] ?? null);
		}
	}

	public function testUnlinkForeignLinkIs404(): void
	{
		$this->trips->method('find')->willReturn($this->draftTrip());
		$link = new BusinessTripTimeEntryLink();
		$link->setTripId(999); // belongs to a different trip
		$this->links->method('findByTimeEntryId')->willReturn($link);
		$this->links->expects($this->never())->method('delete');
		try {
			$this->service->unlinkTimeEntry($this->draftTrip(), 42, 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(404, $e->httpStatus());
		}
	}

	public function testUnlinkDeletesOwnLink(): void
	{
		$this->trips->method('find')->willReturn($this->draftTrip());
		$link = new BusinessTripTimeEntryLink();
		$link->setTripId(7);
		$this->links->method('findByTimeEntryId')->willReturn($link);
		$this->links->expects($this->once())->method('delete')->with($link);
		$this->service->unlinkTimeEntry($this->draftTrip(), 42, 'alice');
	}

	// ---------- preview ----------

	public function testPreviewNeverBumpsRevision(): void
	{
		$trip = $this->draftTrip(7, 'alice', 3);
		$this->resolveOrgRule();
		$this->segments->method('findForTrip')->willReturn([]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([]);
		$this->profiles->method('findEffectiveForUser')->willReturn(null);
		// The bug being guarded: updateIfRevision bumps revision — a preview is
		// a read surface and must only move the advisory snapshot pointer.
		$this->trips->expects($this->never())->method('updateIfRevision');
		$this->trips->expects($this->once())
			->method('setSnapshotPointer')->with(7, 1, 3);
		$result = $this->service->preview($trip, 'alice');
		$this->assertNotSame('', $result['snapshotToken']);
		$this->assertCount(1, $this->frozenHashes);
		$this->assertSame($this->frozenHashes[0], $result['snapshotToken']);
		$this->assertSame(3, (int)$trip->getRevision());
		// Real engine: two Berlin days, presence-only basis, 480 target each.
		$this->assertCount(2, $result['days']);
		$this->assertSame('2026-03-10', $result['days'][0]['localDate']);
	}

	public function testIdenticalPreviewsDoNotGrowSnapshotTable(): void
	{
		$trip = $this->draftTrip(7, 'alice', 3);
		$this->resolveOrgRule();
		$this->segments->method('findForTrip')->willReturn([]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([]);
		$this->profiles->method('findEffectiveForUser')->willReturn(null);
		$this->service->preview($trip, 'alice');
		$this->service->preview($trip, 'alice');
		// Identical advisory payloads reuse the frozen row — preview polling
		// must not append unboundedly (submit/approve stay append-only).
		$this->assertCount(1, $this->frozenHashes);
	}

	// ---------- transitions ----------

	public function testSubmitRejectsStaleRevision(): void
	{
		$this->trips->method('find')->willReturn($this->draftTrip(7, 'alice', 9));
		try {
			$this->service->submit($this->draftTrip(7, 'alice', 3), 3, 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(409, $e->httpStatus());
		}
		$this->assertEqualsCanonicalizing($this->acquiredKeys, $this->releasedKeys);
	}

	public function testSubmitIllegalTransition(): void
	{
		$approved = $this->draftTrip();
		$approved->setStatus(BusinessTrip::STATUS_APPROVED);
		$this->trips->method('find')->willReturn($approved);
		try {
			$this->service->submit($approved, 3, 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('TRIP_STATE_INVALID', $e->getReasonCode());
		}
	}

	public function testSubmitWithoutRuleSetFails(): void
	{
		$fresh = $this->draftTrip(7, 'alice', 3);
		$this->trips->method('find')->willReturn($fresh);
		$this->trips->method('findOverlapping')->willReturn([]);
		$this->assignments->method('findEffective')->willReturn([]);
		$this->userModels->method('findByUserAndDate')->willReturn(null);
		$this->teamMembers->method('findByUserId')->willReturn([]);
		$this->teams->method('getParentMap')->willReturn([]);
		$this->segments->method('findForTrip')->willReturn([]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([]);
		$this->trips->expects($this->never())->method('updateIfRevision');
		try {
			$this->service->submit($fresh, 3, 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('TRIP_RULE_UNRESOLVED', $e->getReasonCode());
			$this->assertSame(409, $e->httpStatus());
		}
		// Rolled back — no snapshot, no day rows, no status write.
		$this->assertCount(0, $this->frozenHashes);
	}

	public function testSubmitWritesSnapshotAndDayCalcs(): void
	{
		$fresh = $this->draftTrip(7, 'alice', 3);
		$this->trips->method('find')->willReturn($fresh);
		$this->trips->method('findOverlapping')->willReturn([]);
		$this->stubCasWrite();
		$this->resolveOrgRule();
		$this->segments->method('findForTrip')->willReturn([]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([]);
		$this->profiles->method('findEffectiveForUser')->willReturn(null);
		$this->dayCalcs->expects($this->once())->method('deleteForTrip')->with(7);
		$this->dayCalcs->expects($this->exactly(2))->method('insert');
		$result = $this->service->submit($fresh, 3, 'alice');
		$this->assertSame(BusinessTrip::STATUS_SUBMITTED, $result->getStatus());
		$this->assertSame(1, (int)$result->getCurrentSnapshotId());
		$this->assertCount(1, $this->frozenHashes);
		$this->assertSame(4, (int)$result->getRevision()); // CAS bumped
	}

	public function testSubmitBlockedByFinalizedMonth(): void
	{
		$fresh = $this->draftTrip(7, 'alice', 3);
		$this->trips->method('find')->willReturn($fresh);
		$this->monthClosures->method('assertDateRangeMutable')
			->willThrowException(new MonthFinalizedException('month_finalized'));
		$this->trips->expects($this->never())->method('updateIfRevision');
		try {
			$this->service->submit($fresh, 3, 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('MONTH_FINALIZED', $e->getReasonCode());
			$this->assertSame(409, $e->httpStatus());
		}
	}

	public function testSubmitDetectsConcurrentOverlap(): void
	{
		$fresh = $this->draftTrip(7, 'alice', 3);
		$this->trips->method('find')->willReturn($fresh);
		// A second active trip appeared after the caller read state — the
		// under-lock re-check must catch it.
		$this->trips->method('findOverlapping')->willReturn([$this->draftTrip(9, 'alice')]);
		$this->trips->expects($this->never())->method('updateIfRevision');
		try {
			$this->service->submit($fresh, 3, 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('TRIP_OVERLAP', $e->getReasonCode());
		}
	}

	public function testApproveRejectsStaleSnapshotToken(): void
	{
		$submitted = $this->draftTrip(7, 'alice', 4);
		$submitted->setStatus(BusinessTrip::STATUS_SUBMITTED);
		$this->trips->method('find')->willReturn($submitted);
		$this->trips->method('findOverlapping')->willReturn([]);
		$this->resolveOrgRule();
		$this->segments->method('findForTrip')->willReturn([]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([]);
		$this->profiles->method('findEffectiveForUser')->willReturn(null);
		$this->trips->expects($this->never())->method('updateIfRevision');
		try {
			$this->service->approve($submitted, 4, 'stale-client-token', 'manager');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame('TRIP_SNAPSHOT_STALE', $e->getReasonCode());
			$this->assertSame(409, $e->httpStatus());
		}
		$this->assertEqualsCanonicalizing($this->acquiredKeys, $this->releasedKeys);
	}

	public function testApproveCommitsOnMatchingTokenAndPostsTopUps(): void
	{
		$submitted = $this->draftTrip(7, 'alice', 4);
		$submitted->setStatus(BusinessTrip::STATUS_SUBMITTED);
		$this->trips->method('find')->willReturn($submitted);
		$this->trips->method('findOverlapping')->willReturn([]);
		$this->stubCasWrite();
		$this->resolveOrgRule();
		$this->segments->method('findForTrip')->willReturn([]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([]);
		$this->profiles->method('findEffectiveForUser')->willReturn(null);

		// Learn the real payload hash via a submit first on a second draft —
		// same facts → deterministic hash → valid approval token.
		$draft = $this->draftTrip(7, 'alice', 3);
		$tripsMock = $this->createMock(BusinessTripMapper::class);
		$tripsMock->method('find')->willReturn($draft);
		$tripsMock->method('findOverlapping')->willReturn([]);
		$tripsMock->method('updateIfRevision')->willReturnCallback(static function (BusinessTrip $t): bool {
			$t->setRevision((int)$t->getRevision() + 1);
			return true;
		});
		$saved = $this->trips;
		$this->trips = $tripsMock;
		$service = $this->makeService($this->config);
		$service->submit($draft, 3, 'alice');
		$token = $this->frozenHashes[0];
		$this->assertNotSame('', $token);
		// Same fact set → approval token matches.
		$result = $service->approve($draft, 4, $token, 'manager');
		$this->assertSame(BusinessTrip::STATUS_APPROVED, $result->getStatus());
		// Top-ups posted: 480 target per day, nothing worked → 2 ledger rows.
		$topUps = array_filter($this->ledgerRows, static fn (BusinessTripCreditLedgerEntry $e): bool => $e->getKind() === BusinessTripCreditLedgerEntry::KIND_TOP_UP);
		$this->assertCount(2, $topUps);
		foreach ($topUps as $row) {
			$this->assertSame(480, (int)$row->getMinutes());
			$this->assertNotSame('', (string)$row->getEventKey());
		}
		$this->trips = $saved;
	}

	public function testApproveSkipsLedgerWhenCreditsDisabled(): void
	{
		$config = $this->suiteConfig(['business_trips_enabled' => true]);
		$submitted = $this->draftTrip(7, 'alice', 4);
		$submitted->setStatus(BusinessTrip::STATUS_SUBMITTED);
		$this->trips->method('find')->willReturn($submitted);
		$this->trips->method('findOverlapping')->willReturn([]);
		$this->stubCasWrite();
		$this->resolveOrgRule();
		$this->segments->method('findForTrip')->willReturn([]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([]);
		$this->profiles->method('findEffectiveForUser')->willReturn(null);
		$service = $this->makeService($config);
		// Learn the token via preview freeze.
		$service->preview($submitted, 'alice');
		$token = $this->frozenHashes[0];
		$result = $service->approve($submitted, 4, $token, 'manager');
		$this->assertSame(BusinessTrip::STATUS_APPROVED, $result->getStatus());
		$this->assertCount(0, $this->ledgerRows);
	}

	public function testOwnerCannotApproveOrRejectOwnTrip(): void
	{
		$submitted = $this->draftTrip(7, 'alice', 4);
		$submitted->setStatus(BusinessTrip::STATUS_SUBMITTED);
		foreach ([
			fn () => $this->service->approve($submitted, 4, 'any', 'alice'),
			fn () => $this->service->reject($submitted, 4, 'alice', 'nope'),
		] as $call) {
			try {
				$call();
				$this->fail();
			} catch (BusinessTripException $e) {
				$this->assertSame('TRIP_SELF_DECISION', $e->getReasonCode());
				$this->assertSame(403, $e->httpStatus());
			}
		}
		$this->assertCount(0, $this->acquiredKeys); // blocked before locking
	}

	public function testAppAdminCanDecideOwnTrip(): void
	{
		$submitted = $this->draftTrip(7, 'alice', 4);
		$submitted->setStatus(BusinessTrip::STATUS_SUBMITTED);
		$this->trips->method('find')->willReturn($submitted);
		$this->trips->method('findOverlapping')->willReturn([]);
		$this->stubCasWrite();
		$this->resolveOrgRule();
		$this->segments->method('findForTrip')->willReturn([]);
		$this->links->method('findTimeEntryIdsForTrip')->willReturn([]);
		$this->profiles->method('findEffectiveForUser')->willReturn(null);

		$permissions = $this->createMock(\OCA\ArbeitszeitCheck\Service\PermissionService::class);
		$permissions->method('isAdmin')->with('alice')->willReturn(true);
		$service = $this->makeService($this->config, null, $permissions);
		$service->preview($submitted, 'alice');
		$token = $this->frozenHashes[0];
		$result = $service->approve($submitted, 4, $token, 'alice');
		$this->assertSame(BusinessTrip::STATUS_APPROVED, $result->getStatus());
	}

	public function testCancelApprovedReversesLedgerExactlyOnce(): void
	{
		$approved = $this->draftTrip(7, 'alice', 5);
		$approved->setStatus(BusinessTrip::STATUS_APPROVED);
		$this->trips->method('find')->willReturn($approved);
		$this->stubCasWrite();
		// Seed one existing positive top-up row.
		$seed = new BusinessTripCreditLedgerEntry();
		$seed->setId(1);
		$seed->setTripId(7);
		$seed->setKind(BusinessTripCreditLedgerEntry::KIND_TOP_UP);
		$seed->setMinutes(480);
		$seed->setLocalDate(new \DateTime('2026-03-10'));
		$this->ledgerRows[] = $seed;
		$result = $this->service->cancel($approved, 5, 'alice', 'plan changed');
		$this->assertSame(BusinessTrip::STATUS_CANCELLED, $result->getStatus());
		$reversals = array_filter($this->ledgerRows, static fn (BusinessTripCreditLedgerEntry $e): bool => $e->getKind() === BusinessTripCreditLedgerEntry::KIND_REVERSAL);
		$this->assertCount(1, $reversals);
		$reversal = array_values($reversals)[0];
		$this->assertSame(-480, (int)$reversal->getMinutes());
		$this->assertSame(1, (int)$reversal->getReversesId());
	}

	public function testCancelDoesNotDoubleReverseCoveredTopUps(): void
	{
		// A reversal already covers the top-up (partial-commit recovery path) —
		// cancel must not mint a second reversal for it.
		$approved = $this->draftTrip(7, 'alice', 5);
		$approved->setStatus(BusinessTrip::STATUS_APPROVED);
		$this->trips->method('find')->willReturn($approved);
		$this->stubCasWrite();
		$seed = new BusinessTripCreditLedgerEntry();
		$seed->setId(1);
		$seed->setTripId(7);
		$seed->setKind(BusinessTripCreditLedgerEntry::KIND_TOP_UP);
		$seed->setMinutes(480);
		$seed->setLocalDate(new \DateTime('2026-03-10'));
		$this->ledgerRows[] = $seed;
		$existingReversal = new BusinessTripCreditLedgerEntry();
		$existingReversal->setId(2);
		$existingReversal->setTripId(7);
		$existingReversal->setKind(BusinessTripCreditLedgerEntry::KIND_REVERSAL);
		$existingReversal->setMinutes(-480);
		$existingReversal->setLocalDate(new \DateTime('2026-03-10'));
		$existingReversal->setReversesId(1);
		$this->ledgerRows[] = $existingReversal;
		$this->ledgerMapper->expects($this->never())->method('insert');
		$this->service->cancel($approved, 5, 'alice', 'plan changed');
		$this->assertCount(2, $this->ledgerRows);
	}

	public function testCancelSubmittedDoesNotTouchLedger(): void
	{
		$submitted = $this->draftTrip(7, 'alice', 4);
		$submitted->setStatus(BusinessTrip::STATUS_SUBMITTED);
		$this->trips->method('find')->willReturn($submitted);
		$this->stubCasWrite();
		$this->service->cancel($submitted, 4, 'alice');
		$this->assertCount(0, $this->ledgerRows);
	}

	public function testLockReleasedOnPartialAcquisitionFailure(): void
	{
		$locks = $this->createMock(ILockingProvider::class);
		$acquired = [];
		$released = [];
		$locks->method('acquireLock')->willReturnCallback(function (string $key) use (&$acquired): void {
			if (count($acquired) === 2) {
				throw new \OCP\Lock\LockedException('busy');
			}
			$acquired[] = $key;
		});
		$locks->method('releaseLock')->willReturnCallback(function (string $key) use (&$released): void {
			$released[] = $key;
		});
		$service = $this->makeService($this->config, $locks);
		try {
			$service->submit($this->draftTrip(), 3, 'alice');
			$this->fail();
		} catch (\OCP\Lock\LockedException) {
		}
		$this->assertEqualsCanonicalizing($acquired, $released);
		$this->assertCount(2, $acquired);
	}

	// ---------- delete / read ----------

	public function testDeleteRejectedForNonDraft(): void
	{
		$trip = $this->draftTrip();
		$trip->setStatus(BusinessTrip::STATUS_SUBMITTED);
		$this->trips->method('find')->willReturn($trip);
		try {
			$this->service->delete($trip, 3, 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(409, $e->httpStatus());
		}
		$this->assertEqualsCanonicalizing($this->acquiredKeys, $this->releasedKeys);
	}

	public function testDeleteRechecksStaleRevisionUnderLock(): void
	{
		// Controller supplied rev 3; under lock the row is already rev 8.
		$fresh = $this->draftTrip(7, 'alice', 8);
		$this->trips->method('find')->willReturn($fresh);
		$this->trips->expects($this->never())->method('delete');
		try {
			$this->service->delete($this->draftTrip(7, 'alice', 3), 3, 'alice');
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(409, $e->httpStatus());
		}
	}

	public function testDeleteRemovesDraftCascade(): void
	{
		$trip = $this->draftTrip(7, 'alice', 3);
		$this->trips->method('find')->willReturn($trip);
		$this->links->expects($this->once())->method('deleteForTrip')->with(7);
		$this->segments->expects($this->once())->method('deleteForTrip')->with(7);
		$this->dayCalcs->expects($this->once())->method('deleteForTrip')->with(7);
		$this->trips->expects($this->once())->method('delete')->with($trip);
		$this->service->delete($trip, 3, 'alice');
	}

	public function testFindForUserOrFailUniform404(): void
	{
		$this->trips->method('find')->willThrowException(new DoesNotExistException('x'));
		try {
			$this->service->findForUserOrFail('alice', 5);
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(404, $e->httpStatus());
			$this->assertSame('TRIP_NOT_FOUND', $e->getReasonCode());
		}
		// Foreign-owned trip → identical 404 (no existence oracle).
		$trips = $this->createMock(BusinessTripMapper::class);
		$trips->method('find')->willReturn($this->draftTrip(5, 'bob'));
		$this->trips = $trips;
		$service = $this->makeService($this->config);
		try {
			$service->findForUserOrFail('alice', 5);
			$this->fail();
		} catch (BusinessTripException $e) {
			$this->assertSame(404, $e->httpStatus());
			$this->assertSame('TRIP_NOT_FOUND', $e->getReasonCode());
		}
	}
}
