<?php

declare(strict_types=1);

/**
 * Bulk-decision contract: payload validation, existence/scope-before-state
 * ordering with uniform 404, per-item decidability (409), transaction
 * boundaries, and one bulk audit entry per batch.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSegmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSnapshotMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripTimeEntryLinkMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper;
use OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserBusinessTripProfileMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\BulkDecisionException;
use OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\BusinessTripCalculationEngine;
use OCA\ArbeitszeitCheck\Service\BusinessTripCreditLedger;
use OCA\ArbeitszeitCheck\Service\BusinessTripRuleResolver;
use OCA\ArbeitszeitCheck\Service\BusinessTripService;
use OCA\ArbeitszeitCheck\Service\BusinessTripSnapshotService;
use OCA\ArbeitszeitCheck\Service\BusinessTripTimelineValidator;
use OCA\ArbeitszeitCheck\Service\ManagerBulkDecisionService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\PostCommitEffectQueue;
use OCA\ArbeitszeitCheck\Service\PolicyCohortResolver;
use OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService;
use OCA\ArbeitszeitCheck\Service\TravelComplianceClassifier;
use OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class ManagerBulkDecisionServiceTest extends TestCase
{
	private IDBConnection $db;
	private TimeEntryMapper $timeEntryMapper;
	private AbsenceMapper $absenceMapper;
	private BusinessTripMapper $businessTripMapper;
	private AuditLogMapper $auditLogMapper;
	private TimeEntryCorrectionService $correctionService;
	private AbsenceService $absenceService;
	private PermissionService $permissionService;
	private IL10N $l10n;
	private PostCommitEffectQueue $effectQueue;
	private bool $tripsEnabled = true;

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->businessTripMapper = $this->createMock(BusinessTripMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->correctionService = $this->createMock(TimeEntryCorrectionService::class);
		$this->absenceService = $this->createMock(AbsenceService::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $s): string => $s);
		$this->effectQueue = new PostCommitEffectQueue($this->db, $this->createMock(\Psr\Log\LoggerInterface::class));
	}

	private function service(): ManagerBulkDecisionService
	{
		$appConfig = $this->createMock(IConfig::class);
		$tripsEnabled = $this->tripsEnabled;
		$appConfig->method('getAppValue')->willReturnCallback(
			static fn (string $a, string $k, string $d): string => ($tripsEnabled && $k === 'business_trips_enabled') ? '1' : '0'
		);
		$config = new WorkforceSuiteConfig($appConfig);
		$tripService = new BusinessTripService(
			$this->createMock(IDBConnection::class),
			$this->createMock(ILockingProvider::class),
			$config,
			$this->businessTripMapper,
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
				new PolicyCohortResolver($config, $this->createMock(PolicyCohortMembershipMapper::class)),
			),
			new BusinessTripCalculationEngine(
				new \OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator(),
				new TravelComplianceClassifier(),
				$this->createMock(DailyWorkingHoursCalculator::class),
			),
			new BusinessTripSnapshotService($this->createMock(BusinessTripSnapshotMapper::class)),
			new BusinessTripCreditLedger($this->createMock(ILockingProvider::class), $this->createMock(BusinessTripCreditLedgerMapper::class)),
			$this->createMock(MonthClosureService::class),
			$this->auditLogMapper,
		);

		return new ManagerBulkDecisionService(
			$this->db,
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->businessTripMapper,
			$this->auditLogMapper,
			$this->correctionService,
			$this->absenceService,
			$tripService,
			$this->permissionService,
			$config,
			$this->l10n,
			$this->effectQueue,
		);
	}

	private function pendingEntry(int $id, string $userId = 'employee1'): TimeEntry
	{
		$entry = new TimeEntry();
		$entry->setId($id);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		return $entry;
	}

	private function pendingAbsence(int $id, string $userId = 'employee1'): Absence
	{
		$absence = new Absence();
		$absence->setId($id);
		$absence->setUserId($userId);
		$absence->setStatus(Absence::STATUS_PENDING);
		return $absence;
	}

	public function testRejectsInvalidAction(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->decide('manager', 'delete', [['type' => 'absence', 'id' => 1]]);
	}

	public function testRejectsEmptyItems(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->decide('manager', 'approve', []);
	}

	public function testRejectsTooManyItems(): void
	{
		$items = array_map(static fn (int $i): array => ['type' => 'absence', 'id' => $i], range(1, 201));
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->decide('manager', 'approve', $items);
	}

	public function testRejectsUnknownType(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->decide('manager', 'approve', [['type' => 'on_call_shift', 'id' => 1]]);
	}

	public function testRejectsNonIntegerId(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service()->decide('manager', 'approve', [['type' => 'absence', 'id' => '1']]);
	}

	public function testMissingItemCollapsesTo404(): void
	{
		$this->absenceMapper->method('find')->willThrowException(new DoesNotExistException('x'));
		$this->db->expects($this->never())->method('beginTransaction');
		try {
			$this->service()->decide('manager', 'approve', [['type' => 'absence', 'id' => 9]]);
			$this->fail('expected BulkDecisionException');
		} catch (BulkDecisionException $e) {
			$this->assertSame(404, $e->httpStatus());
			$this->assertSame('NOT_FOUND', $e->getReasonCode());
		}
	}

	public function testOutOfScopeCollapsesTo404AndLogsDenial(): void
	{
		$this->absenceMapper->method('find')->willReturn($this->pendingAbsence(7, 'foreign-user'));
		$this->permissionService->method('canManageEmployee')->willReturn(false);
		$this->permissionService->expects($this->once())->method('logPermissionDenied');
		try {
			$this->service()->decide('manager', 'approve', [['type' => 'absence', 'id' => 7]]);
			$this->fail('expected BulkDecisionException');
		} catch (BulkDecisionException $e) {
			$this->assertSame(404, $e->httpStatus());
			$this->assertSame('NOT_FOUND', $e->getReasonCode());
		}
	}

	public function testAlreadyDecidedItemFailsWith409BeforeMutation(): void
	{
		$entry = $this->pendingEntry(3);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->permissionService->method('canManageEmployee')->willReturn(true);
		$this->db->expects($this->never())->method('beginTransaction');
		try {
			$this->service()->decide('manager', 'approve', [['type' => 'time_entry', 'id' => 3]]);
			$this->fail('expected BulkDecisionException');
		} catch (BulkDecisionException $e) {
			$this->assertSame(409, $e->httpStatus());
			$this->assertSame('already_decided', $e->getReasonCode());
		}
	}

	public function testApproveMixedBatchCommitsOnceAndAudits(): void
	{
		$this->timeEntryMapper->method('find')->willReturn($this->pendingEntry(11));
		$this->absenceMapper->method('find')->willReturn($this->pendingAbsence(22));
		$this->permissionService->method('canManageEmployee')->willReturn(true);

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$this->correctionService->expects($this->once())->method('approve')->with($this->isInstanceOf(TimeEntry::class), 'manager', 'ok');
		$this->absenceService->expects($this->once())->method('approveAbsence')->with(22, 'manager', 'ok');
		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with('manager', 'manager_bulk_decision', 'bulk_decision');

		$results = $this->service()->decide('manager', 'approve', [
			['type' => 'time_entry', 'id' => 11],
			['type' => 'absence', 'id' => 22],
		], 'ok');

		$this->assertSame([
			['type' => 'time_entry', 'id' => 11, 'status' => 'approved'],
			['type' => 'absence', 'id' => 22, 'status' => 'approved'],
		], $results);
	}

	public function testMidBatchFailureRollsBackWholeBatch(): void
	{
		$this->timeEntryMapper->method('find')->willReturn($this->pendingEntry(11));
		$this->absenceMapper->method('find')->willReturn($this->pendingAbsence(22));
		$this->permissionService->method('canManageEmployee')->willReturn(true);

		$this->correctionService->method('approve')->willReturnCallback(static function (TimeEntry $e): TimeEntry {
			return $e;
		});
		$this->absenceService->method('approveAbsence')
			->willThrowException(new ConcurrentDecisionException('lost the race'));

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->auditLogMapper->expects($this->never())->method('logAction');

		try {
			$this->service()->decide('manager', 'approve', [
				['type' => 'time_entry', 'id' => 11],
				['type' => 'absence', 'id' => 22],
			]);
			$this->fail('expected BulkDecisionException');
		} catch (BulkDecisionException $e) {
			$this->assertSame(409, $e->httpStatus());
			$this->assertSame('already_decided', $e->getReasonCode());
			$this->assertSame(22, $e->getDetails()['id'] ?? null);
		}
	}

	public function testApproveTimeEntrySurfacesBillingWarningPerItem(): void
	{
		$this->timeEntryMapper->method('find')->willReturn($this->pendingEntry(11));
		$this->permissionService->method('canManageEmployee')->willReturn(true);
		$this->correctionService->method('approve')->willReturnCallback(static function (TimeEntry $e): TimeEntry {
			$e->setBillingSyncWarning('billing sync failed');
			return $e;
		});

		$results = $this->service()->decide('manager', 'approve', [['type' => 'time_entry', 'id' => 11]], 'ok');

		$this->assertSame('approved', $results[0]['status']);
		$this->assertSame('billing sync failed', $results[0]['billing_warning'] ?? null);
	}

	public function testRejectRoutesToRejectServices(): void
	{
		$this->timeEntryMapper->method('find')->willReturn($this->pendingEntry(5));
		$this->permissionService->method('canManageEmployee')->willReturn(true);
		$this->correctionService->expects($this->once())->method('reject')->with($this->isInstanceOf(TimeEntry::class), 'manager', 'nope');
		$this->correctionService->expects($this->never())->method('approve');

		$results = $this->service()->decide('manager', 'reject', [['type' => 'time_entry', 'id' => 5]], 'nope');
		$this->assertSame('rejected', $results[0]['status']);
	}

	public function testDeferredExternalEffectsRunOnlyAfterCommit(): void
	{
		$this->timeEntryMapper->method('find')->willReturn($this->pendingEntry(11));
		$this->absenceMapper->method('find')->willReturn($this->pendingAbsence(22));
		$this->permissionService->method('canManageEmployee')->willReturn(true);
		// Once the outer transaction is open, post-commit effects defer.
		$this->db->method('inTransaction')->willReturn(true);

		$order = [];
		$this->correctionService->method('approve')->willReturnCallback(static function (TimeEntry $e): TimeEntry {
			return $e;
		});
		// Simulate AbsenceService's post-commit mail routing through the queue.
		$this->absenceService->method('approveAbsence')->willReturnCallback(function (int $id) use (&$order): Absence {
			$this->effectQueue->runAfterCurrentCommit(static function () use (&$order): void {
				$order[] = 'external-effect';
			});
			return $this->pendingAbsence($id);
		});
		$this->db->method('commit')->willReturnCallback(static function () use (&$order): void {
			$order[] = 'commit';
		});

		$this->service()->decide('manager', 'approve', [
			['type' => 'time_entry', 'id' => 11],
			['type' => 'absence', 'id' => 22],
		]);

		$this->assertSame(
			['commit', 'external-effect'],
			$order,
			'external side effects must fire only after the batch commit succeeded'
		);
		$this->assertSame(0, $this->effectQueue->pendingCount());
	}

	public function testRolledBackBatchDiscardsDeferredExternalEffects(): void
	{
		$this->timeEntryMapper->method('find')->willReturn($this->pendingEntry(11));
		$this->absenceMapper->method('find')->willReturn($this->pendingAbsence(22));
		$this->permissionService->method('canManageEmployee')->willReturn(true);
		$this->db->method('inTransaction')->willReturn(true);

		$effectRan = false;
		$this->correctionService->method('approve')->willReturnCallback(function (TimeEntry $e) use (&$effectRan): TimeEntry {
			$this->effectQueue->runAfterCurrentCommit(static function () use (&$effectRan): void {
				$effectRan = true;
			});
			return $e;
		});
		$this->absenceService->method('approveAbsence')
			->willThrowException(new ConcurrentDecisionException('lost the race'));

		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		try {
			$this->service()->decide('manager', 'approve', [
				['type' => 'time_entry', 'id' => 11],
				['type' => 'absence', 'id' => 22],
			]);
			$this->fail('expected BulkDecisionException');
		} catch (BulkDecisionException) {
		}

		$this->assertFalse($effectRan, 'an external effect queued before a batch failure must never fire');
		$this->assertSame(0, $this->effectQueue->pendingCount());
	}

	public function testBusinessTripItemRequiresEnabledFeature(): void
	{
		$this->tripsEnabled = false;
		$trip = new \OCA\ArbeitszeitCheck\Db\BusinessTrip();
		$trip->setUserId('employee1');
		$this->businessTripMapper->method('find')->willReturn($trip);
		try {
			$this->service()->decide('manager', 'approve', [['type' => 'business_trip', 'id' => 1]]);
			$this->fail('expected BulkDecisionException');
		} catch (BulkDecisionException $e) {
			$this->assertSame(404, $e->httpStatus());
		}
	}
}
