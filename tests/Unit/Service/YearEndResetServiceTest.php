<?php

declare(strict_types=1);

/**
 * Unit tests for YearEndResetService — preview/execute guards, CAS and
 * boundary-blocker logic for the manual year-end reset (spec §9.5/§9.6).
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\YearResetItemMapper;
use OCA\ArbeitszeitCheck\Db\YearResetRefMapper;
use OCA\ArbeitszeitCheck\Db\YearResetRun;
use OCA\ArbeitszeitCheck\Db\YearResetRunMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\FinancialRevisionService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\OvertimeAdjustmentService;
use OCA\ArbeitszeitCheck\Service\WorkforcePolicyService;
use OCA\ArbeitszeitCheck\Service\YearEndResetService;
use OCA\ArbeitszeitCheck\Support\WorkforcePolicy;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class YearEndResetServiceTest extends TestCase
{
	private YearEndResetService $service;
	private $db;
	private $runMapper;
	private $itemMapper;
	private $refMapper;
	private $adjustmentService;
	private $adjustmentMapper;
	private $policyService;
	private $revisionService;
	private $absenceMapper;
	private $timeEntryMapper;
	private $monthClosureService;
	private $userManager;
	private $auditLogMapper;

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = $this->createMock(IDBConnection::class);
		$this->runMapper = $this->createMock(YearResetRunMapper::class);
		// execute() consults the durable operation key first; default to
		// "fresh operation" so tests exercise the preview-handle path.
		$this->runMapper->method('findByOperationKey')
			->willThrowException(new DoesNotExistException('none'));
		// Atomic preview claims succeed by default; tests asserting the lost
		// race override these to return false.
		$this->runMapper->method('claimPreviewForExecution')->willReturn(true);
		$this->runMapper->method('claimPreviewConsume')->willReturn(true);
		$this->itemMapper = $this->createMock(YearResetItemMapper::class);
		$this->refMapper = $this->createMock(YearResetRefMapper::class);
		$this->adjustmentService = $this->createMock(OvertimeAdjustmentService::class);
		$this->adjustmentMapper = $this->createMock(OvertimeAdjustmentMapper::class);
		$this->policyService = $this->createMock(WorkforcePolicyService::class);
		$this->revisionService = $this->createMock(FinancialRevisionService::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->monthClosureService = $this->createMock(MonthClosureService::class);
		$groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->makeService($groupManager);
	}

	private function makeService($groupManager = null): void
	{
		$this->service = new YearEndResetService(
			$this->db,
			$this->runMapper,
			$this->itemMapper,
			$this->refMapper,
			$this->adjustmentService,
			$this->adjustmentMapper,
			$this->policyService,
			$this->revisionService,
			$this->absenceMapper,
			$this->timeEntryMapper,
			$this->monthClosureService,
			$groupManager ?? $this->createMock(IGroupManager::class),
			$this->userManager,
			$this->auditLogMapper,
		);
	}

	private function policy(bool $manual = true): WorkforcePolicy
	{
		return WorkforcePolicy::fromArray([
			'yearEnd' => [
				'manualEnabled' => $manual,
				'resetPositive' => true,
				'waiveNegative' => true,
				'timezone' => 'UTC',
			],
		], 'UTC');
	}

	private function enableManual(int $revision = 3): void
	{
		$this->policyService->method('isYearEndManualEnabled')->willReturn(true);
		$this->policyService->method('revision')->willReturn($revision);
		$this->policyService->method('get')->willReturn($this->policy());
	}

	private function assertErrorCode(string $expected, callable $fn): void
	{
		try {
			$fn();
			$this->fail('Expected BusinessRuleException ' . $expected);
		} catch (BusinessRuleException $e) {
			$this->assertSame($expected, $e->getReasonCode());
		}
	}

	// ---------------------------------------------------------------- preview

	public function testPreviewDisabledRejected(): void
	{
		$this->policyService->method('isYearEndManualEnabled')->willReturn(false);
		$this->assertErrorCode(YearEndResetService::ERROR_FEATURE_DISABLED, fn () =>
			$this->service->createPreview('admin', 2025, ['u1'], true, false, 'valid reason here', 1));
	}

	public function testPreviewPolicyRevisionConflict(): void
	{
		$this->enableManual(3);
		$this->assertErrorCode(YearEndResetService::ERROR_REVISION_CONFLICT, fn () =>
			$this->service->createPreview('admin', 2025, ['u1'], true, false, 'valid reason here', 2));
	}

	public function testPreviewCurrentYearRejected(): void
	{
		$this->enableManual();
		$currentYear = (int)(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y');
		$this->assertErrorCode(YearEndResetService::ERROR_POLICY_INVALID, fn () =>
			$this->service->createPreview('admin', $currentYear, ['u1'], true, false, 'valid reason here', 3));
	}

	public function testPreviewAncientYearRejected(): void
	{
		$this->enableManual();
		$this->assertErrorCode(YearEndResetService::ERROR_POLICY_INVALID, fn () =>
			$this->service->createPreview('admin', 1999, ['u1'], true, false, 'valid reason here', 3));
	}

	public function testPreviewReasonTooShort(): void
	{
		$this->enableManual();
		$this->assertErrorCode(YearEndResetService::ERROR_REASON_REQUIRED, fn () =>
			$this->service->createPreview('admin', 2025, ['u1'], true, false, 'short', 3));
	}

	public function testPreviewEmptySelectionRejected(): void
	{
		$this->enableManual();
		$this->assertErrorCode(YearEndResetService::ERROR_POLICY_INVALID, fn () =>
			$this->service->createPreview('admin', 2025, [], true, false, 'valid reason here', 3));
	}

	public function testPreviewUnknownUserRejected(): void
	{
		$this->enableManual();
		$this->userManager->method('userExists')->willReturn(false);
		$this->assertErrorCode(YearEndResetService::ERROR_POLICY_INVALID, fn () =>
			$this->service->createPreview('admin', 2025, ['ghost'], true, false, 'valid reason here', 3));
	}

	public function testPreviewPositiveBalanceReset(): void
	{
		$this->enableManual(3);
		$this->userManager->method('userExists')->with('u1')->willReturn(true);
		$this->revisionService->method('sourceRevision')->willReturn('u1.p0.c0.h0.m0');
		$this->adjustmentService->method('getCurrentEffectiveBalance')->with('u1', $this->anything())->willReturn(5.0);
		$this->adjustmentMapper->method('findByUserAndYear')->willReturn([]);
		$this->monthClosureService->method('isMonthFinalized')->willReturn(false);
		$this->timeEntryMapper->method('findOpenEntryByUser')->willReturn(null);
		$this->absenceMapper->method('findCancelRequestedForUsers')->willReturn([]);
		$this->runMapper->method('insert')->willReturnCallback(static function (YearResetRun $run) {
			$run->setId(77);
			return $run;
		});
		$this->refMapper->expects($this->once())->method('insert');

		$result = $this->service->createPreview('admin', 2025, ['u1'], true, false, 'close out 2025', 3);

		$this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result['preview_id']);
		$this->assertSame(77, $result['run_id']);
		$this->assertSame(2025, $result['closing_year']);
		$this->assertSame(3, $result['policy_revision']);
		$this->assertCount(1, $result['targets']);
		$target = $result['targets'][0];
		$this->assertSame('u1', $target['user_id']);
		$this->assertSame(500, $target['before_centi_hours']);
		$this->assertSame(-500, $target['delta_centi_hours']);
		$this->assertSame('reset_positive', $target['action']);
		$this->assertNull($target['blocked']);
	}

	public function testPreviewNegativeBalanceWithoutWaiveIsNoOp(): void
	{
		$this->enableManual(3);
		$this->userManager->method('userExists')->willReturn(true);
		$this->revisionService->method('sourceRevision')->willReturn('u0.p0.c0.h0.m0');
		$this->adjustmentService->method('getCurrentEffectiveBalance')->willReturn(-2.0);
		$this->adjustmentMapper->method('findByUserAndYear')->willReturn([]);
		$this->monthClosureService->method('isMonthFinalized')->willReturn(false);
		$this->timeEntryMapper->method('findOpenEntryByUser')->willReturn(null);
		$this->absenceMapper->method('findCancelRequestedForUsers')->willReturn([]);
		$this->runMapper->method('insert')->willReturnCallback(static function (YearResetRun $run) {
			$run->setId(1);
			return $run;
		});

		// resetPositive=true but waiveNegative=false → negative stays.
		$result = $this->service->createPreview('admin', 2025, ['u1'], true, false, 'close out 2025', 3);
		$this->assertSame('none', $result['targets'][0]['action']);
		$this->assertSame(0, $result['targets'][0]['delta_centi_hours']);

		// With waive enabled the debt is cleared.
		$result = $this->service->createPreview('admin', 2025, ['u1'], false, true, 'close out 2025', 3);
		$this->assertSame('waive_negative', $result['targets'][0]['action']);
		$this->assertSame(200, $result['targets'][0]['delta_centi_hours']);
	}

	public function testPreviewBlockedByFinalizedDecember(): void
	{
		$this->enableManual(3);
		$this->userManager->method('userExists')->willReturn(true);
		$this->revisionService->method('sourceRevision')->willReturn('u0.p0.c0.h0.m0');
		$this->adjustmentService->method('getCurrentEffectiveBalance')->willReturn(1.0);
		$this->adjustmentMapper->method('findByUserAndYear')->willReturn([]);
		$this->monthClosureService->method('isMonthFinalized')->with('u1', 2025, 12)->willReturn(true);
		$this->runMapper->method('insert')->willReturnCallback(static function (YearResetRun $run) {
			$run->setId(1);
			return $run;
		});

		$result = $this->service->createPreview('admin', 2025, ['u1'], true, true, 'close out 2025', 3);
		$this->assertSame(YearEndResetService::ERROR_PERIOD_FINALIZED, $result['targets'][0]['blocked']);
		$this->assertSame('none', $result['targets'][0]['action']);
	}

	public function testPreviewBlockedByBoundarySpanningAbsence(): void
	{
		$this->enableManual(3);
		$this->userManager->method('userExists')->willReturn(true);
		$this->revisionService->method('sourceRevision')->willReturn('u0.p0.c0.h0.m0');
		$this->adjustmentService->method('getCurrentEffectiveBalance')->willReturn(1.0);
		$this->adjustmentMapper->method('findByUserAndYear')->willReturn([]);
		$this->monthClosureService->method('isMonthFinalized')->willReturn(false);
		$this->timeEntryMapper->method('findOpenEntryByUser')->willReturn(null);
		$absence = new Absence();
		$absence->setStartDate(new \DateTime('2025-12-30'));
		$absence->setEndDate(new \DateTime('2026-01-02'));
		$this->absenceMapper->method('findCancelRequestedForUsers')->willReturn([$absence]);
		$this->runMapper->method('insert')->willReturnCallback(static function (YearResetRun $run) {
			$run->setId(1);
			return $run;
		});

		$result = $this->service->createPreview('admin', 2025, ['u1'], true, true, 'close out 2025', 3);
		$this->assertSame(YearEndResetService::ERROR_CUTOFF_UNRESOLVED, $result['targets'][0]['blocked']);
	}

	public function testPreviewAbsenceOutsideBoundaryNotBlocked(): void
	{
		$this->enableManual(3);
		$this->userManager->method('userExists')->willReturn(true);
		$this->revisionService->method('sourceRevision')->willReturn('u0.p0.c0.h0.m0');
		$this->adjustmentService->method('getCurrentEffectiveBalance')->willReturn(1.0);
		$this->adjustmentMapper->method('findByUserAndYear')->willReturn([]);
		$this->monthClosureService->method('isMonthFinalized')->willReturn(false);
		$this->timeEntryMapper->method('findOpenEntryByUser')->willReturn(null);
		$absence = new Absence();
		$absence->setStartDate(new \DateTime('2025-06-01'));
		$absence->setEndDate(new \DateTime('2025-06-05'));
		$this->absenceMapper->method('findCancelRequestedForUsers')->willReturn([$absence]);
		$this->runMapper->method('insert')->willReturnCallback(static function (YearResetRun $run) {
			$run->setId(1);
			return $run;
		});

		$result = $this->service->createPreview('admin', 2025, ['u1'], true, true, 'close out 2025', 3);
		$this->assertNull($result['targets'][0]['blocked']);
	}

	// ---------------------------------------------------------------- execute

	public function testExecuteDisabledRejected(): void
	{
		$this->policyService->method('isYearEndManualEnabled')->willReturn(false);
		$this->assertErrorCode(YearEndResetService::ERROR_FEATURE_DISABLED, fn () =>
			$this->service->execute('admin', str_repeat('a', 64), '123e4567-e89b-42d3-a456-426614174000', 1));
	}

	public function testExecuteMalformedHandleRejected(): void
	{
		$this->enableManual();
		$this->assertErrorCode(YearEndResetService::ERROR_PREVIEW_EXPIRED, fn () =>
			$this->service->execute('admin', 'short', '123e4567-e89b-42d3-a456-426614174000', 3));
	}

	public function testExecuteUnknownHandleRejected(): void
	{
		$this->enableManual();
		$this->runMapper->method('findByPreviewKeyHash')
			->willThrowException(new DoesNotExistException('nope'));
		$this->assertErrorCode(YearEndResetService::ERROR_PREVIEW_EXPIRED, fn () =>
			$this->service->execute('admin', str_repeat('a', 64), '123e4567-e89b-42d3-a456-426614174000', 3));
	}

	public function testExecuteWrongActorRejected(): void
	{
		$this->enableManual();
		$run = new YearResetRun();
		$run->setActorUid('other-admin');
		$run->setPreviewExpiresAt(new \DateTime('+10 minutes'));
		$this->runMapper->method('findByPreviewKeyHash')->willReturn($run);
		$this->assertErrorCode(YearEndResetService::ERROR_PREVIEW_EXPIRED, fn () =>
			$this->service->execute('admin', str_repeat('a', 64), '123e4567-e89b-42d3-a456-426614174000', 3));
	}

	public function testExecuteExpiredPreviewRejected(): void
	{
		$this->enableManual();
		$run = new YearResetRun();
		$run->setActorUid('admin');
		$run->setPreviewExpiresAt(new \DateTime('-1 minute'));
		$this->runMapper->method('findByPreviewKeyHash')->willReturn($run);
		$this->assertErrorCode(YearEndResetService::ERROR_PREVIEW_EXPIRED, fn () =>
			$this->service->execute('admin', str_repeat('a', 64), '123e4567-e89b-42d3-a456-426614174000', 3));
	}

	public function testExecuteOperationIdMustBeUuid(): void
	{
		$this->enableManual();
		$run = new YearResetRun();
		$run->setActorUid('admin');
		$run->setPreviewExpiresAt(new \DateTime('+10 minutes'));
		$this->runMapper->method('findByPreviewKeyHash')->willReturn($run);
		$this->assertErrorCode(YearEndResetService::ERROR_POLICY_INVALID, fn () =>
			$this->service->execute('admin', str_repeat('a', 64), 'not-a-uuid', 3));
	}

	public function testExecutePolicyChangedRejected(): void
	{
		$this->enableManual(5);
		$run = new YearResetRun();
		$run->setActorUid('admin');
		$run->setPreviewExpiresAt(new \DateTime('+10 minutes'));
		$this->runMapper->method('findByPreviewKeyHash')->willReturn($run);
		$this->assertErrorCode(YearEndResetService::ERROR_POLICY_CHANGED, fn () =>
			$this->service->execute('admin', str_repeat('a', 64), '123e4567-e89b-42d3-a456-426614174000', 4));
	}

	public function testExecuteOperationKeyReplayReturnsReceipt(): void
	{
		$this->enableManual();
		$opId = '123e4567-e89b-42d3-a456-426614174000';
		$run = new YearResetRun();
		$run->setId(9);
		$run->setActorUid('admin');
		$run->setClosingYear(2025);
		$run->setState(YearResetRun::STATE_COMPLETED);
		$run->setOperationKey($opId);

		$this->runMapper = $this->createMock(YearResetRunMapper::class);
		$this->makeService();
		$this->runMapper->method('findByOperationKey')->with($opId)->willReturn($run);
		$this->runMapper->method('findById')->with(9)->willReturn($run);
		// The dead handle must never be consulted once the receipt resolves.
		$this->runMapper->expects($this->never())->method('findByPreviewKeyHash');
		$this->itemMapper->method('findByRun')->willReturn([]);
		$this->refMapper->method('findByRun')->willReturn([]);
		$this->itemMapper->method('countByState')->willReturn(['done' => 1]);

		$status = $this->service->execute('admin', 'a'.str_repeat('0', 63), $opId, 3);
		$this->assertSame(9, $status['id']);
		$this->assertSame('completed', $status['state']);
	}

	public function testExecuteOperationKeyOfForeignActorDenied(): void
	{
		$this->enableManual();
		$opId = '123e4567-e89b-42d3-a456-426614174000';
		$run = new YearResetRun();
		$run->setId(9);
		$run->setActorUid('other-admin');
		$run->setOperationKey($opId);

		$this->runMapper = $this->createMock(YearResetRunMapper::class);
		$this->makeService();
		$this->runMapper->method('findByOperationKey')->willReturn($run);
		$this->assertErrorCode(YearEndResetService::ERROR_PREVIEW_EXPIRED, fn () =>
			$this->service->execute('admin', str_repeat('a', 64), $opId, 3));
	}

	public function testExecuteLostClaimRaceIsConflictNotOverwrite(): void
	{
		// A concurrent execute already consumed the preview — the claim must
		// fail atomically instead of overwriting the winner's operation key.
		$this->enableManual();
		$run = new YearResetRun();
		$run->setId(11);
		$run->setActorUid('admin');
		$run->setState(YearResetRun::STATE_PREVIEWED);
		$run->setPreviewExpiresAt(new \DateTime('+10 minutes'));

		$this->runMapper = $this->createMock(YearResetRunMapper::class);
		$this->makeService();
		$this->runMapper->method('findByOperationKey')
			->willThrowException(new DoesNotExistException('none'));
		$this->runMapper->method('findByPreviewKeyHash')->willReturn($run);
		$this->runMapper->method('claimPreviewForExecution')->willReturn(false);
		$this->runMapper->expects($this->never())->method('update');

		$this->assertErrorCode(YearEndResetService::ERROR_OPERATION_CONFLICT, fn () =>
			$this->service->execute('admin', str_repeat('a', 64), '123e4567-e89b-42d3-a456-426614174000', 3));
	}

	public function testResumeLostClaimRaceIsConflictNotOverwrite(): void
	{
		$this->enableManual();
		$run = new YearResetRun();
		$run->setId(9);
		$run->setActorUid('admin');
		$run->setClosingYear(2025);
		$run->setState(YearResetRun::STATE_FAILED);
		$preview = new YearResetRun();
		$preview->setId(12);
		$preview->setActorUid('admin');
		$preview->setClosingYear(2025);
		$preview->setState(YearResetRun::STATE_PREVIEWED);
		$preview->setPreviewExpiresAt(new \DateTime('+10 minutes'));

		$this->runMapper = $this->createMock(YearResetRunMapper::class);
		$this->makeService();
		$this->runMapper->method('findByOperationKey')
			->willThrowException(new DoesNotExistException('none'));
		$this->runMapper->method('findById')->with(9)->willReturn($run);
		$this->runMapper->method('findByPreviewKeyHash')->willReturn($preview);
		$this->runMapper->method('claimPreviewConsume')->willReturn(false);
		$this->runMapper->expects($this->never())->method('update');
		$this->refMapper->method('findByRun')->willReturn([]);

		$this->assertErrorCode(YearEndResetService::ERROR_OPERATION_CONFLICT, fn () =>
			$this->service->resume('admin', 9, str_repeat('b', 64), '123e4567-e89b-42d3-a456-426614174000', 3));
	}

	// ----------------------------------------------------------------- purge

	public function testPurgeDeletesExpiredPreviewRunsWithRefs(): void
	{
		$expired = new YearResetRun();
		$expired->setId(7);
		$expired->setState(YearResetRun::STATE_PREVIEWED);
		$expired->setPreviewExpiresAt(new \DateTime('-1 hour'));

		$this->runMapper->method('findExpiredPreviews')->willReturn([$expired]);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$this->runMapper->expects($this->once())->method('deleteIfPreviewed')->with(7)->willReturn(true);
		$this->refMapper->expects($this->once())->method('deleteByRun')->with(7);
		$this->runMapper->expects($this->never())->method('delete');

		$this->assertSame(1, $this->service->purgeExpiredPreviews());
	}

	public function testPurgeSkipsRunClaimedConcurrently(): void
	{
		// execute() claimed the preview between the find and the delete —
		// the guarded delete loses and refs must survive (they now carry
		// execution outcomes).
		$claimed = new YearResetRun();
		$claimed->setId(9);
		$claimed->setState(YearResetRun::STATE_PREVIEWED);
		$claimed->setPreviewExpiresAt(new \DateTime('-1 hour'));

		$this->runMapper->method('findExpiredPreviews')->willReturn([$claimed]);
		$this->runMapper->method('deleteIfPreviewed')->with(9)->willReturn(false);
		$this->refMapper->expects($this->never())->method('deleteByRun');
		$this->runMapper->expects($this->never())->method('delete');
		$this->db->expects($this->once())->method('rollBack');

		$this->assertSame(0, $this->service->purgeExpiredPreviews());
	}

	public function testPurgeRefDeleteFailureRollsBackAndKeepsRun(): void
	{
		$expired = new YearResetRun();
		$expired->setId(8);
		$expired->setState(YearResetRun::STATE_PREVIEWED);
		$expired->setPreviewExpiresAt(new \DateTime('-1 hour'));

		$this->runMapper->method('findExpiredPreviews')->willReturn([$expired]);
		$this->runMapper->method('deleteIfPreviewed')->willReturn(true);
		$this->refMapper->method('deleteByRun')->willThrowException(new \RuntimeException('db gone'));
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->runMapper->expects($this->never())->method('delete');

		$this->expectException(\RuntimeException::class);
		$this->service->purgeExpiredPreviews();
	}

	public function testPurgeNothingExpiredIsNoOp(): void
	{
		$this->runMapper->method('findExpiredPreviews')->willReturn([]);
		$this->db->expects($this->never())->method('beginTransaction');
		$this->assertSame(0, $this->service->purgeExpiredPreviews());
	}

	// ---------------------------------------------------------------- status

	public function testRunStatusUnknownRunIsNotFound(): void
	{
		$this->runMapper->method('findById')
			->willThrowException(new DoesNotExistException('nope'));
		$this->assertErrorCode(YearEndResetService::ERROR_NOT_FOUND, fn () =>
			$this->service->runStatus(999));
	}

	public function testRunStatusEmptyRun(): void
	{
		$run = new YearResetRun();
		$run->setId(5);
		$run->setClosingYear(2025);
		$run->setOrigin(YearResetRun::ORIGIN_MANUAL);
		$run->setState(YearResetRun::STATE_COMPLETED);
		$this->runMapper->method('findById')->willReturn($run);
		$this->itemMapper->method('findByRun')->willReturn([]);
		$this->refMapper->method('findByRun')->willReturn([]);
		$this->itemMapper->method('countByState')->willReturn(['done' => 0]);

		$status = $this->service->runStatus(5);
		$this->assertSame(5, $status['id']);
		$this->assertSame(2025, $status['closing_year']);
		$this->assertSame('completed', $status['state']);
		$this->assertSame([], $status['items']);
	}
}
