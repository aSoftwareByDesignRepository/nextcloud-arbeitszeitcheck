<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\MonthClosure;
use OCA\ArbeitszeitCheck\Db\MonthClosureMapper;
use OCA\ArbeitszeitCheck\Db\MonthClosureRevisionMapper;
use OCA\ArbeitszeitCheck\Db\OvertimePayout;
use OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\PremiumSurchargeService;
use OCA\ArbeitszeitCheck\Service\ReportingService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Deep-path coverage for MonthClosureService: the seal/reopen lifecycle
 * (transaction, revision insert, audit log, finalize lock) and the
 * auto-finalize sweeper including its skip/pending/error branches.
 *
 * All collaborator seams that tests vary are routed through mutable
 * properties — PHPUnit's first-registered stub wins, so re-configuring a
 * mocked method inside a test after setUp silently no-ops.
 */
final class MonthClosureServiceDeepTest extends TestCase
{
	private MonthClosureMapper&MockObject $closureMapper;
	private MonthClosureRevisionMapper&MockObject $revisionMapper;
	private ReportingService&MockObject $reportingService;
	private TimeEntryMapper&MockObject $timeEntryMapper;
	private AbsenceMapper&MockObject $absenceMapper;
	private AuditLogMapper&MockObject $auditLogMapper;
	private IDBConnection&MockObject $db;
	private IConfig&MockObject $config;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private PermissionService&MockObject $permissionService;
	private OvertimeBankService&MockObject $overtimeBankService;
	private OvertimePayoutMapper&MockObject $overtimePayoutMapper;
	private ILockingProvider&MockObject $lockingProvider;
	private PremiumSurchargeService&MockObject $premiumSurchargeService;
	private TimeZoneService $timeZoneService;

	/** @var array<string,string> */
	private array $appValues = [];

	// mutable collaborator returns
	/** @var list<TimeEntry> */
	private array $entries = [];
	/** @var null|callable(string,\\DateTime,\\DateTime):list<TimeEntry> */
	private $entriesHook = null;
	/** @var list<Absence> */
	private array $absences = [];
	private ?MonthClosure $closureRow = null;
	private ?MonthClosure $prevFinalizedRow = null;
	/** @var list<string> */
	private array $ymStrings = [];
	private bool $allowedByAccessGroups = true;
	private bool $bankEnabled = false;
	private array $bankSnapshot = [];
	private ?OvertimePayout $payoutRow = null;
	private ?array $payoutAuditArray = null;
	private ?array $premiumBlock = null;
	private ?array $bankAuditBlock = null;
	private array $reportPayload = ['total_hours' => 40.0];
	/** @var null|callable(MonthClosure):MonthClosure */
	private $insertHook = null;
	/** @var null|callable(MonthClosure):MonthClosure */
	private $updateHook = null;

	// captured writes
	/** @var list<MonthClosure> */
	private array $inserted = [];
	/** @var list<MonthClosure> */
	private array $updated = [];
	/** @var list<object> */
	private array $revisions = [];

	/** A month guaranteed fully ended regardless of wall clock. */
	private const PAST_Y = 2025;
	private const PAST_M = 1;

	protected function setUp(): void
	{
		parent::setUp();
		$this->closureMapper = $this->createMock(MonthClosureMapper::class);
		$this->revisionMapper = $this->createMock(MonthClosureRevisionMapper::class);
		$this->reportingService = $this->createMock(ReportingService::class);
		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->config = $this->createMock(IConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->overtimeBankService = $this->createMock(OvertimeBankService::class);
		$this->overtimePayoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->premiumSurchargeService = $this->createMock(PremiumSurchargeService::class);

		$this->appValues = [
			Constants::CONFIG_MONTH_CLOSURE_ENABLED => '1',
			Constants::CONFIG_MONTH_CLOSURE_GRACE_DAYS_AFTER_EOM => '5',
		];
		$this->config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, $default = null) => $this->appValues[$key] ?? $default
		);

		$this->timeZoneService = new TimeZoneService(
			$this->config,
			$this->createMock(IDateTimeZone::class),
			$this->createMock(IUserSession::class),
			$this->logger
		);

		$this->closureMapper->method('findByUserAndMonthOptional')->willReturnCallback(fn () => $this->closureRow);
		$this->closureMapper->method('findLatestFinalizedBefore')->willReturnCallback(fn () => $this->prevFinalizedRow);
		$this->closureMapper->method('insert')->willReturnCallback(function (MonthClosure $c): MonthClosure {
			$this->inserted[] = $c;
			$hook = $this->insertHook;
			return $hook === null ? $c : $hook($c);
		});
		$this->closureMapper->method('update')->willReturnCallback(function (MonthClosure $c): MonthClosure {
			$this->updated[] = $c;
			$hook = $this->updateHook;
			return $hook === null ? $c : $hook($c);
		});
		$this->revisionMapper->method('insert')->willReturnCallback(function ($r) {
			$this->revisions[] = $r;
			return $r;
		});
		$this->timeEntryMapper->method('findByUserAndDateRange')->willReturnCallback(function ($uid, $start, $end): array {
			$hook = $this->entriesHook;
			return $hook === null ? $this->entries : $hook($uid, $start, $end);
		});
		$this->timeEntryMapper->method('findDistinctYearMonthStringsForUser')->willReturnCallback(fn () => $this->ymStrings);
		$this->absenceMapper->method('findByUserAndDateRange')->willReturnCallback(fn () => $this->absences);
		$this->reportingService->method('generateMonthlyReport')->willReturnCallback(fn () => $this->reportPayload);
		$this->overtimePayoutMapper->method('findByUserAndMonth')->willReturnCallback(fn () => $this->payoutRow);
		$this->overtimeBankService->method('isEnabled')->willReturnCallback(fn () => $this->bankEnabled);
		$this->overtimeBankService->method('getMonthEndSnapshot')->willReturnCallback(fn () => $this->bankSnapshot);
		$this->overtimeBankService->method('buildClosureAuditBlock')->willReturnCallback(fn () => $this->bankAuditBlock);
		$this->overtimeBankService->method('payoutEntityToAuditArray')->willReturnCallback(fn () => $this->payoutAuditArray);
		$this->premiumSurchargeService->method('buildClosureAuditBlock')->willReturnCallback(fn () => $this->premiumBlock);
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturnCallback(fn () => $this->allowedByAccessGroups);
	}

	private function svc(): MonthClosureService
	{
		return new MonthClosureService(
			$this->closureMapper,
			$this->revisionMapper,
			$this->reportingService,
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->auditLogMapper,
			$this->db,
			$this->config,
			$this->userManager,
			$this->logger,
			$this->permissionService,
			$this->overtimeBankService,
			$this->overtimePayoutMapper,
			$this->timeZoneService,
			$this->lockingProvider,
			$this->premiumSurchargeService
		);
	}

	private function finalizedRow(string $uid = 'alice'): MonthClosure
	{
		$row = new MonthClosure();
		$row->setId(7);
		$row->setUserId($uid);
		$row->setYear(self::PAST_Y);
		$row->setMonth(self::PAST_M);
		$row->setStatus(MonthClosure::STATUS_FINALIZED);
		$row->setVersion(1);
		$row->setCanonicalPayload('{"schema":"v1","report":{"total_hours":40}}');
		return $row;
	}

	private function completedEntry(int $id = 5): TimeEntry
	{
		$e = new TimeEntry();
		$e->setId($id);
		$e->setStatus(TimeEntry::STATUS_COMPLETED);
		return $e;
	}

	// ---------------------------------------------------------------
	// tryAutomaticFinalize
	// ---------------------------------------------------------------

	public function testTryAutomaticFinalizeSkippedWhenFeatureDisabled(): void
	{
		$this->appValues[Constants::CONFIG_MONTH_CLOSURE_ENABLED] = '0';
		$this->assertSame('skipped', $this->svc()->tryAutomaticFinalize('alice', self::PAST_Y, self::PAST_M, new \DateTime('2025-06-01')));
	}

	public function testTryAutomaticFinalizeSkippedWhenNoGraceDays(): void
	{
		$this->appValues[Constants::CONFIG_MONTH_CLOSURE_GRACE_DAYS_AFTER_EOM] = '0';
		$this->assertSame('skipped', $this->svc()->tryAutomaticFinalize('alice', self::PAST_Y, self::PAST_M, new \DateTime('2025-06-01')));
	}

	public function testTryAutomaticFinalizeSkippedForFutureMonth(): void
	{
		$this->assertSame('skipped', $this->svc()->tryAutomaticFinalize('alice', 2099, 12, new \DateTime('2025-06-01')));
	}

	public function testTryAutomaticFinalizeSkippedBeforeDeadline(): void
	{
		// deadline for 2025-01 is 2025-02-05 (EOM + 5 grace days)
		$this->assertSame('skipped', $this->svc()->tryAutomaticFinalize('alice', self::PAST_Y, self::PAST_M, new \DateTime('2025-02-03')));
	}

	public function testTryAutomaticFinalizeSkippedWhenAlreadyFinalized(): void
	{
		$this->closureRow = $this->finalizedRow();
		$this->assertSame('skipped', $this->svc()->tryAutomaticFinalize('alice', self::PAST_Y, self::PAST_M, new \DateTime('2025-06-01')));
	}

	public function testTryAutomaticFinalizePendingCorrectionOnPendingApproval(): void
	{
		$entry = $this->completedEntry(1);
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$this->entries = [$entry];
		$this->assertSame('pending_correction', $this->svc()->tryAutomaticFinalize('alice', self::PAST_Y, self::PAST_M, new \DateTime('2025-06-01')));
	}

	public function testTryAutomaticFinalizePendingCorrectionOnOpenAbsence(): void
	{
		$absence = new Absence();
		$absence->setId(3);
		$absence->setStatus(Absence::STATUS_PENDING);
		$this->absences = [$absence];
		$this->assertSame('pending_correction', $this->svc()->tryAutomaticFinalize('alice', self::PAST_Y, self::PAST_M, new \DateTime('2025-06-01')));
	}

	public function testTryAutomaticFinalizePendingCorrectionOnUnpaidPayout(): void
	{
		$this->appValues[Constants::CONFIG_OVERTIME_BLOCK_MONTH_CLOSURE_PENDING_PAYOUT] = '1';
		$this->bankEnabled = true;
		$this->bankSnapshot = ['payout_eligible_hours' => 2.5];
		$this->assertSame('pending_correction', $this->svc()->tryAutomaticFinalize('alice', self::PAST_Y, self::PAST_M, new \DateTime('2025-06-01')));
	}

	public function testTryAutomaticFinalizeSealsMonthWithLockAndAudit(): void
	{
		$this->lockingProvider->expects($this->once())->method('acquireLock');
		$this->lockingProvider->expects($this->once())->method('releaseLock');
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'month_closure_auto_finalized', 'month_closure');

		$this->assertSame('finalized', $this->svc()->tryAutomaticFinalize('alice', self::PAST_Y, self::PAST_M, new \DateTime('2025-06-01')));

		$this->assertCount(1, $this->inserted);
		$c = $this->inserted[0];
		$this->assertSame(MonthClosure::STATUS_FINALIZED, $c->getStatus());
		$this->assertSame(1, $c->getVersion());
		$this->assertNotNull($c->getSnapshotHash());
		$this->assertSame(MonthClosureService::AUTO_FINALIZE_ACTOR_ID, $c->getFinalizedBy());
		$this->assertCount(1, $this->revisions);
		$this->assertSame(1, $this->revisions[0]->getVersion());
		$this->assertSame($c->getSnapshotHash(), $this->revisions[0]->getSnapshotHash());
	}

	public function testTryAutomaticFinalizeChainsHashFromPreviousSeal(): void
	{
		$prev = $this->finalizedRow();
		$prev->setMonth(12);
		$prev->setYear(2024);
		$prev->setSnapshotHash('prevhash');
		$this->prevFinalizedRow = $prev;

		$this->svc()->tryAutomaticFinalize('alice', self::PAST_Y, self::PAST_M, new \DateTime('2025-06-01'));
		$this->assertCount(1, $this->inserted);
		$this->assertSame('prevhash', $this->inserted[0]->getPrevSnapshotHash());
		$this->assertNotSame('prevhash', $this->inserted[0]->getSnapshotHash());
	}

	// ---------------------------------------------------------------
	// runAutomaticFinalizeForAllUsers
	// ---------------------------------------------------------------

	public function testRunAutomaticFinalizeReturnsZerosWhenDisabled(): void
	{
		$this->appValues[Constants::CONFIG_MONTH_CLOSURE_ENABLED] = '0';
		$this->userManager->expects($this->never())->method('callForAllUsers');
		$this->assertSame(
			['finalized' => 0, 'pending_correction' => 0, 'errors' => 0],
			$this->svc()->runAutomaticFinalizeForAllUsers(new \DateTime('2025-06-01'))
		);
	}

	public function testRunAutomaticFinalizeCountsFinalizeAndErrors(): void
	{
		$enabled = $this->createMock(IUser::class);
		$enabled->method('isEnabled')->willReturn(true);
		$enabled->method('getUID')->willReturn('alice');
		$disabled = $this->createMock(IUser::class);
		$disabled->method('isEnabled')->willReturn(false);
		$this->userManager->method('callForAllUsers')->willReturnCallback(
			static function (callable $cb) use ($enabled, $disabled): void {
				$cb($enabled);
				$cb($disabled);
			}
		);
		// fail the second persist to exercise the errors counter
		$calls = 0;
		$this->insertHook = static function (MonthClosure $c) use (&$calls): MonthClosure {
			$calls++;
			if ($calls === 2) {
				throw new \RuntimeException('db write failed');
			}
			return $c;
		};

		// 36 lookback months; the most recent one is still inside its grace
		// window -> skipped; of the remaining 35 attempts one fails.
		$stats = $this->svc()->runAutomaticFinalizeForAllUsers(new \DateTime('2025-06-01'));
		$this->assertSame(34, $stats['finalized']);
		$this->assertSame(1, $stats['errors']);
		$this->assertSame(0, $stats['pending_correction']);
	}

	public function testRunAutomaticFinalizeSkipsDisallowedUsers(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$user->method('getUID')->willReturn('mallory');
		$this->userManager->method('callForAllUsers')->willReturnCallback(
			static function (callable $cb) use ($user): void { $cb($user); }
		);
		$this->allowedByAccessGroups = false;

		$stats = $this->svc()->runAutomaticFinalizeForAllUsers(new \DateTime('2025-06-01'));
		$this->assertSame(0, $stats['finalized']);
		$this->assertCount(0, $this->inserted);
	}

	public function testRunAutomaticFinalizeCountsPendingCorrections(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$user->method('getUID')->willReturn('alice');
		$this->userManager->method('callForAllUsers')->willReturnCallback(
			static function (callable $cb) use ($user): void { $cb($user); }
		);
		$entry = $this->completedEntry(1);
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$this->entries = [$entry];

		$stats = $this->svc()->runAutomaticFinalizeForAllUsers(new \DateTime('2025-06-01'));
		// one skipped (still in grace), the rest flagged pending_correction
		$this->assertSame(0, $stats['finalized']);
		$this->assertSame(35, $stats['pending_correction']);
		$this->assertSame(0, $stats['errors']);
	}

	// ---------------------------------------------------------------
	// finalizeMonth
	// ---------------------------------------------------------------

	public function testFinalizeMonthThrowsWhenDisabled(): void
	{
		$this->appValues[Constants::CONFIG_MONTH_CLOSURE_ENABLED] = '0';
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('feature_disabled');
		$this->svc()->finalizeMonth('alice', 'alice', self::PAST_Y, self::PAST_M);
	}

	public function testFinalizeMonthForbiddenForOtherUser(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('forbidden');
		$this->svc()->finalizeMonth('alice', 'bob', self::PAST_Y, self::PAST_M);
	}

	public function testFinalizeMonthThrowsAlreadyFinalized(): void
	{
		$this->closureRow = $this->finalizedRow();
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('already_finalized');
		$this->svc()->finalizeMonth('alice', 'alice', self::PAST_Y, self::PAST_M);
	}

	public function testFinalizeMonthThrowsForFutureMonth(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('future_month');
		$this->svc()->finalizeMonth('alice', 'alice', 2099, 12);
	}

	public function testFinalizeMonthThrowsWhenMonthNotEnded(): void
	{
		$now = new \DateTimeImmutable();
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('month_not_ended');
		$this->svc()->finalizeMonth('alice', 'alice', (int)$now->format('Y'), (int)$now->format('n'));
	}

	public function testFinalizeMonthThrowsWithoutTimeEntries(): void
	{
		$this->entries = [];
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('no_time_entries');
		$this->svc()->finalizeMonth('alice', 'alice', self::PAST_Y, self::PAST_M);
	}

	public function testFinalizeMonthSuccessUpdatesExistingReopenedRow(): void
	{
		$existing = new MonthClosure();
		$existing->setId(11);
		$existing->setUserId('alice');
		$existing->setYear(self::PAST_Y);
		$existing->setMonth(self::PAST_M);
		$existing->setStatus(MonthClosure::STATUS_OPEN);
		$existing->setVersion(2);
		$this->closureRow = $existing;
		$this->entries = [$this->completedEntry()];

		$this->db->expects($this->once())->method('commit');
		$result = $this->svc()->finalizeMonth('alice', 'alice', self::PAST_Y, self::PAST_M);

		$this->assertSame(MonthClosure::STATUS_FINALIZED, $result->getStatus());
		$this->assertSame(3, $result->getVersion());
		$this->assertCount(1, $this->updated);
		$this->assertCount(1, $this->revisions);
	}

	public function testFinalizeMonthInsertPathRollsBackOnFailure(): void
	{
		$this->entries = [$this->completedEntry()];
		$this->insertHook = static function (): MonthClosure {
			throw new \RuntimeException('disk full');
		};

		$this->db->expects($this->once())->method('rollBack');
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('disk full');
		$this->svc()->finalizeMonth('alice', 'alice', self::PAST_Y, self::PAST_M);
	}

	// ---------------------------------------------------------------
	// reopenMonth
	// ---------------------------------------------------------------

	public function testReopenMonthRequiresReason(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('reason_required');
		$this->svc()->reopenMonth('admin', 'alice', self::PAST_Y, self::PAST_M, '');
	}

	public function testReopenMonthThrowsWhenNotFinalized(): void
	{
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not_finalized');
		$this->svc()->reopenMonth('admin', 'alice', self::PAST_Y, self::PAST_M, 'correction needed');
	}

	public function testReopenMonthClearsSealAndAudits(): void
	{
		$row = $this->finalizedRow();
		$row->setSnapshotHash('abc');
		$row->setFinalizedAt(new \DateTime());
		$row->setFinalizedBy('alice');
		$this->closureRow = $row;

		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'month_closure_reopened', 'month_closure');
		$this->db->expects($this->once())->method('commit');
		$this->lockingProvider->expects($this->once())->method('releaseLock');

		$result = $this->svc()->reopenMonth('admin', 'alice', self::PAST_Y, self::PAST_M, 'correction needed');

		$this->assertSame(MonthClosure::STATUS_OPEN, $result->getStatus());
		$this->assertNull($result->getSnapshotHash());
		$this->assertSame('correction needed', $result->getReopenReason());
		$this->assertSame('admin', $result->getReopenedBy());
		$this->assertNull($result->getFinalizedAt());
		$this->assertCount(1, $this->updated);
	}

	// ---------------------------------------------------------------
	// listing / guard helpers
	// ---------------------------------------------------------------

	public function testGetEligiblePeriodsWithTimeEntriesForUserFiltersMonths(): void
	{
		$this->ymStrings = ['2025-01', '2099-01', 'garbage', '2025-02'];
		$entry = $this->completedEntry(1);
		// entries exist only for 2025-01
		$this->entriesHook = static function ($uid, \DateTime $start) use ($entry): array {
			return $start->format('Y-m') === '2025-01' ? [$entry] : [];
		};

		$this->assertSame(
			[['year' => 2025, 'month' => 1]],
			$this->svc()->getEligiblePeriodsWithTimeEntriesForUser('alice')
		);
	}

	public function testGetManualFinalizeDeadlineDate(): void
	{
		$d = $this->svc()->getManualFinalizeDeadlineDate(self::PAST_Y, self::PAST_M);
		$this->assertSame('2025-02-05', $d->format('Y-m-d'));

		$this->appValues[Constants::CONFIG_MONTH_CLOSURE_GRACE_DAYS_AFTER_EOM] = '0';
		$this->assertNull($this->svc()->getManualFinalizeDeadlineDate(self::PAST_Y, self::PAST_M));
	}

	public function testIsPastAutoFinalizeDeadline(): void
	{
		$svc = $this->svc();
		$this->assertTrue($svc->isPastAutoFinalizeDeadline(self::PAST_Y, self::PAST_M, new \DateTime('2025-02-06')));
		$this->assertFalse($svc->isPastAutoFinalizeDeadline(self::PAST_Y, self::PAST_M, new \DateTime('2025-02-04')));
	}

	public function testAssertDateRangeMutableThrowsOnFinalizedMonth(): void
	{
		$this->closureRow = $this->finalizedRow();
		$this->expectException(MonthFinalizedException::class);
		$this->svc()->assertDateRangeMutable('alice', new \DateTime('2025-01-10'), new \DateTime('2025-01-20'));
	}

	public function testAssertDateRangeMutablePassesOnOpenMonth(): void
	{
		$this->svc()->assertDateRangeMutable('alice', new \DateTime('2025-01-10'), new \DateTime('2025-03-20'));
		$this->addToAssertionCount(1);
	}

	public function testMonthsOverlappingRange(): void
	{
		$months = $this->svc()->monthsOverlappingRange(new \DateTime('2025-01-15'), new \DateTime('2025-03-10'));
		$this->assertSame([[2025, 1], [2025, 2], [2025, 3]], $months);
	}

	public function testListFinalizedYearMonthsForUser(): void
	{
		$row = $this->finalizedRow();
		$this->closureMapper->method('findFinalizedByUserId')->willReturn([$row]);
		$this->assertSame([['year' => self::PAST_Y, 'month' => self::PAST_M]], $this->svc()->listFinalizedYearMonthsForUser('alice'));
	}

	public function testGetSnapshotReportArrayDecodesCanonicalPayload(): void
	{
		$this->closureRow = $this->finalizedRow();
		$snap = $this->svc()->getSnapshotReportArray('alice', self::PAST_Y, self::PAST_M);
		$this->assertSame(40, $snap['report']['total_hours']);
	}

	public function testGetSnapshotReportArrayReturnsNullForOpenMonth(): void
	{
		$row = $this->finalizedRow();
		$row->setStatus(MonthClosure::STATUS_OPEN);
		$this->closureRow = $row;
		$this->assertNull($this->svc()->getSnapshotReportArray('alice', self::PAST_Y, self::PAST_M));
	}

	public function testGetFinalizedMonthlyReportForUserFlagsSnapshot(): void
	{
		$this->closureRow = $this->finalizedRow();
		$report = $this->svc()->getFinalizedMonthlyReportForUser('alice', self::PAST_Y, self::PAST_M);
		$this->assertTrue($report['from_month_closure_snapshot']);
		$this->assertSame(1, $report['month_closure_version']);
	}

	public function testGetMonthFinalizeBlockReason(): void
	{
		$this->assertNull($this->svc()->getMonthFinalizeBlockReason('alice', self::PAST_Y, self::PAST_M));

		$entry = $this->completedEntry(1);
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$this->entries = [$entry];
		$this->assertSame('pending_workflow', $this->svc()->getMonthFinalizeBlockReason('alice', self::PAST_Y, self::PAST_M));
	}

	public function testEnrichSnapshotForPdfPayoutAttachesLivePayout(): void
	{
		$svc = $this->svc();
		$rm = new \ReflectionMethod(MonthClosureService::class, 'enrichSnapshotForPdfPayout');
		$rm->setAccessible(true);

		$snap = ['overtime_bank' => ['enabled' => true, 'payout_record' => null]];
		// no live payout row -> snapshot unchanged
		$this->assertSame($snap, $rm->invoke($svc, $snap, 'alice', self::PAST_Y, self::PAST_M));

		// live payout row -> supplementary audit block attached
		$this->payoutRow = new OvertimePayout();
		$this->payoutAuditArray = ['hours' => 2.5];
		$out = $rm->invoke($svc, $snap, 'alice', self::PAST_Y, self::PAST_M);
		$this->assertSame(['hours' => 2.5], $out['overtime_bank']['supplementary_payout']);

		// bank disabled -> unchanged even with live row
		$disabled = ['overtime_bank' => ['enabled' => false]];
		$this->assertSame($disabled, $rm->invoke($svc, $disabled, 'alice', self::PAST_Y, self::PAST_M));
	}

	public function testBuildPdfContentThrowsForNonFinalized(): void
	{
		$l = $this->createMock(\OCP\IL10N::class);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not_finalized');
		$this->svc()->buildPdfContent('alice', self::PAST_Y, self::PAST_M, 'Alice', $l);
	}

	// ---------------------------------------------------------------
	// buildPdfContent / releaseFinalizeLock
	// ---------------------------------------------------------------

	public function testBuildPdfContentThrowsWhenNotFinalized(): void
	{
		$this->closureRow = null;
		$l = $this->createMock(\OCP\IL10N::class);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('not_finalized');
		$this->svc()->buildPdfContent('alice', self::PAST_Y, self::PAST_M, 'Alice', $l);
	}

	public function testBuildPdfContentRendersFinalizedSnapshot(): void
	{
		$row = $this->finalizedRow();
		$row->setFinalizedBy('admin1');
		$row->setSnapshotHash(str_repeat('ab', 20));
		$row->setCanonicalPayload(json_encode([
			'schema' => 'v1',
			'month' => self::PAST_M,
			'year' => self::PAST_Y,
			'period' => ['start' => '2025-04-01', 'end' => '2025-04-30'],
			'report' => ['total_hours' => 40.0],
		]));
		$this->closureRow = $row;

		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $t, $p = []) => is_array($p) && $p !== [] ? vsprintf($t, $p) : $t);
		$l->method('getLanguageCode')->willReturn('de');

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getDisplayName')->willReturn('Admin One');
		$this->userManager->method('get')->with('admin1')->willReturn($user);

		$pdf = $this->svc()->buildPdfContent('alice', self::PAST_Y, self::PAST_M, 'Alice A.', $l);
		$this->assertStringStartsWith('%PDF-', $pdf);
		$this->assertStringContainsString('%%EOF', $pdf);
	}

	public function testBuildPdfContentAutoFinalizeLabel(): void
	{
		$row = $this->finalizedRow();
		$row->setFinalizedBy(MonthClosureService::AUTO_FINALIZE_ACTOR_ID);
		$row->setCanonicalPayload(json_encode([
			'schema' => 'v1', 'month' => self::PAST_M, 'year' => self::PAST_Y,
			'period' => ['start' => '2025-04-01', 'end' => '2025-04-30'],
			'report' => ['total_hours' => 0.0],
		]));
		$this->closureRow = $row;

		$l = $this->createMock(\OCP\IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $t, $p = []) => is_array($p) && $p !== [] ? vsprintf($t, $p) : $t);
		$l->method('getLanguageCode')->willReturn('en');
		// auto-finalized rows never resolve a user
		$this->userManager->expects($this->never())->method('get');

		$pdf = $this->svc()->buildPdfContent('alice', self::PAST_Y, self::PAST_M, 'Alice', $l);
		$this->assertStringStartsWith('%PDF-', $pdf);
	}

	public function testFinalizeLockReleaseFailureDoesNotPropagate(): void
	{
		$this->lockingProvider->method('releaseLock')
			->willThrowException(new \RuntimeException('lock store down'));
		$this->logger->expects($this->atLeastOnce())->method('warning');

		$this->assertSame(
			'finalized',
			$this->svc()->tryAutomaticFinalize('alice', self::PAST_Y, self::PAST_M, new \DateTime('2025-06-01'))
		);
	}
}