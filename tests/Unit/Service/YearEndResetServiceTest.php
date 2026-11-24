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
			$groupManager,
			$this->userManager,
			$this->auditLogMapper,
		);
	}

	private function policy(bool $manual = true): WorkforcePolicy
	{
		return WorkforcePolicy::fromArray([
			'yearEnd' => ['manualEnabled' => $manual, 'timezone' => 'UTC'],
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
		$this->adjustmentService->method('getCurrentEffectiveBalance')->with('u1')->willReturn(5.0);
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
