<?php

declare(strict_types=1);

/**
 * Regression / contract tests for {@see TimeEntryCorrectionService}.
 *
 * Focus is on the security-critical and audit-critical behaviour:
 *  - approve() persists the proposal AND triggers ArbZG §4/§3 adjustments
 *  - reject() restores the original (incl. breaks JSON)
 *  - reject() of manual_create marks rejected (never completes hours)
 *  - cancelByEmployee() deletes manual_create rows / restores correction rows
 *  - applyBreaksJson semantics (15-minute floor, replace-not-merge)
 *  - applyManagerCorrection preserves the previous justification for traceability
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\MonthClosureGuard;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

class TimeEntryCorrectionServiceTest extends TestCase
{
	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;
	/** @var array<int, TimeEntry> */
	private array $entryStore = [];
	/** @var MonthClosureGuard|\PHPUnit\Framework\MockObject\MockObject */
	private $monthClosureGuard;
	/** @var ComplianceService|\PHPUnit\Framework\MockObject\MockObject */
	private $complianceService;
	/** @var TimeTrackingService|\PHPUnit\Framework\MockObject\MockObject */
	private $timeTrackingService;
	/** @var NotificationService|\PHPUnit\Framework\MockObject\MockObject */
	private $notificationService;
	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;
	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;
	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	private TimeEntryCorrectionService $service;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->monthClosureGuard = $this->createMock(MonthClosureGuard::class);
		$this->complianceService = $this->createMock(ComplianceService::class);
		$this->complianceService->method('checkRestPeriodForStartTime')->willReturn(['valid' => true]);
		$this->complianceService->method('blockingIssuesForCompletedEntry')->willReturn([]);
		$this->timeTrackingService = $this->createMock(TimeTrackingService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') {
				if ($key === 'app_timezone') {
					return 'UTC';
				}
				if ($key === 'realtime_compliance_check') {
					return '0';
				}
				return $default;
			}
		);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->entryStore = [];
		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);
		$this->timeEntryMapper->method('update')->willReturnCallback(
			function (TimeEntry $e): TimeEntry {
				$this->entryStore[$e->getId()] = $e;
				return $e;
			}
		);
		$this->timeEntryMapper->method('find')->willReturnCallback(
			function (int $id): ?TimeEntry {
				return $this->entryStore[$id] ?? null;
			}
		);
		$this->timeEntryMapper->method('updateIfPendingApproval')->willReturnCallback(
			function (TimeEntry $e): bool {
				$this->entryStore[$e->getId()] = $e;
				return true;
			}
		);

		$this->service = new TimeEntryCorrectionService(
			$this->timeEntryMapper,
			$this->monthClosureGuard,
			$this->complianceService,
			$this->timeTrackingService,
			$this->notificationService,
			$this->auditLogMapper,
			$this->config,
			$this->l10n,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
		);
	}

	private function buildEntry(): TimeEntry
	{
		$entry = new TimeEntry();
		$entry->setId(42);
		$entry->setUserId('alice');
		$entry->setStartTime(new \DateTime('2026-01-15T09:00:00+00:00'));
		$entry->setEndTime(new \DateTime('2026-01-15T17:00:00+00:00'));
		$entry->setDescription('original work');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		return $entry;
	}

	public function testApproveAppliesProposalAndArbzgAdjustments(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode([
			'justification' => 'Forgot to clock out on time, please correct.',
			'original' => [
				'startTime' => '2026-01-15T09:00:00+00:00',
				'endTime' => '2026-01-15T17:00:00+00:00',
				'description' => 'original work',
			],
			'proposed' => [
				'startTime' => '2026-01-15T08:00:00+00:00',
				'endTime' => '2026-01-15T19:00:00+00:00',
				'description' => 'corrected work',
			],
		]));

		// The proposal is a 11h shift -> ArbZG §4 mandates a break,
		// §3 caps daily to 10h. Both helpers MUST be invoked on the persisted entry.
		$this->timeTrackingService->expects($this->atLeastOnce())
			->method('calculateAndSetAutomaticBreak')
			->with($this->isInstanceOf(TimeEntry::class));
		$this->timeTrackingService->expects($this->atLeastOnce())
			->method('adjustEndTimeForDailyMaximum')
			->with($this->isInstanceOf(TimeEntry::class));

		$result = $this->service->approve($entry, 'manager1', 'Looks good');

		$this->assertSame(TimeEntry::STATUS_COMPLETED, $result->getStatus());
		$this->assertSame('manager1', $result->getApprovedByUserId());
		$this->assertSame('2026-01-15T08:00:00+0000', $result->getStartTime()->format('Y-m-d\TH:i:sO'));
		$this->assertSame('corrected work', $result->getDescription());
		$json = json_decode($result->getJustification(), true);
		$this->assertIsArray($json);
		$this->assertSame('Looks good', $json['approval_comment']);
		$this->assertSame('manager1', $json['approved_by']);
	}

	public function testAutoApproveRevalidatesProposalAndBlocksIllegalBreakSplit(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode([
			'justification' => 'Please accept this correction.',
			'proposed' => [
				'startTime' => '2026-01-15T08:00:00+00:00',
				'endTime' => '2026-01-15T15:00:00+00:00',
				'breaks' => [
					['start' => '2026-01-15T10:00:00+00:00', 'end' => '2026-01-15T10:20:00+00:00'],
					['start' => '2026-01-15T12:00:00+00:00', 'end' => '2026-01-15T12:10:00+00:00'],
				],
			],
		]));

		$this->complianceService = $this->createMock(ComplianceService::class);
		$this->complianceService->method('checkRestPeriodForStartTime')->willReturn(['valid' => true]);
		$this->complianceService->method('blockingIssuesForCompletedEntry')
			->willReturn(['Break requirement not met (AZG §11): portions must be continuous ≥30, 2×15, or 3×10.']);

		$service = new TimeEntryCorrectionService(
			$this->timeEntryMapper,
			$this->monthClosureGuard,
			$this->complianceService,
			$this->timeTrackingService,
			$this->notificationService,
			$this->auditLogMapper,
			$this->config,
			$this->l10n,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('AZG');
		$service->autoApprove($entry);
	}

	public function testAutoApproveAppliesProposalAndSetsSystemApproval(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode([
			'justification' => 'Forgot to clock out.',
			'proposed' => [
				'startTime' => '2026-01-15T09:00:00+00:00',
				'endTime' => '2026-01-15T12:00:00+00:00',
			],
		]));

		$this->complianceService->method('checkRestPeriodForStartTime')->willReturn(['valid' => true]);

		$result = $this->service->autoApprove($entry);
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $result->getStatus());
		$this->assertSame('2026-01-15T12:00:00+0000', $result->getEndTime()->format('Y-m-d\TH:i:sO'));
		$json = json_decode($result->getJustification(), true);
		$this->assertSame('system', $json['approved_by']);
		$this->assertArrayHasKey('approval_comment', $json);
	}

	public function testAutoApproveSurvivesComplianceAdjustmentFailure(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode([
			'justification' => 'x',
			'proposed' => [
				'startTime' => '2026-01-15T09:00:00+00:00',
				'endTime' => '2026-01-15T12:00:00+00:00',
			],
		]));

		$this->complianceService->method('checkRestPeriodForStartTime')->willReturn(['valid' => true]);
		// applyComplianceAdjustments catch arm: helper succeeds during
		// validateProposal but throws during the post-proposal apply -> warning
		// logged, approve still proceeds.
		$breakCalls = 0;
		$this->timeTrackingService->method('calculateAndSetAutomaticBreak')
			->willReturnCallback(static function () use (&$breakCalls): bool {
				$breakCalls++;
				if ($breakCalls > 1) {
					throw new \RuntimeException('break calc exploded');
				}
				return true;
			});

		$result = $this->service->autoApprove($entry);
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $result->getStatus());
	}

	public function testApplyComplianceAdjustmentsSkipsTimelessEntry(): void
	{
		// Null-times guard is unreachable via approve()/autoApprove() (the
		// proposal validator rejects start-less candidates) — pinned directly
		// so the defensive arm stays provable.
		$entry = $this->buildEntry();
		$entry->setStartTime(null);
		$entry->setEndTime(null);

		$this->timeTrackingService->expects($this->never())
			->method('calculateAndSetAutomaticBreak');
		$this->timeTrackingService->expects($this->never())
			->method('adjustEndTimeForDailyMaximum');

		$m = new \ReflectionMethod(TimeEntryCorrectionService::class, 'applyComplianceAdjustments');
		$m->invoke($this->service, $entry);
		$this->addToAssertionCount(1);
	}

	public function testAutoApproveWithInvalidJsonJustificationStillCompletes(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification('not-json{');

		$result = $this->service->autoApprove($entry);
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $result->getStatus());
		// invalid JSON -> no annotation written (justification kept verbatim)
		$this->assertSame('not-json{', $result->getJustification());
	}

	public function testRejectRestoresOriginalIncludingBreaksJson(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setStartTime(new \DateTime('2026-01-15T08:00:00+00:00'));
		$entry->setEndTime(new \DateTime('2026-01-15T19:00:00+00:00'));
		$entry->setDescription('proposed work');
		$entry->setJustification(json_encode([
			'justification' => 'Requested extension.',
			'original' => [
				'startTime' => '2026-01-15T09:00:00+00:00',
				'endTime' => '2026-01-15T17:00:00+00:00',
				'description' => 'original work',
				'breaks' => [
					['start' => '2026-01-15T12:00:00+00:00', 'end' => '2026-01-15T12:30:00+00:00'],
				],
			],
			'proposed' => [
				'startTime' => '2026-01-15T08:00:00+00:00',
				'endTime' => '2026-01-15T19:00:00+00:00',
			],
		]));

		$result = $this->service->reject($entry, 'manager1', 'Not approved.');

		$this->assertSame(TimeEntry::STATUS_COMPLETED, $result->getStatus());
		$this->assertNull($result->getApprovedByUserId());
		$this->assertSame('2026-01-15T09:00:00+0000', $result->getStartTime()->format('Y-m-d\TH:i:sO'));
		$this->assertSame('2026-01-15T17:00:00+0000', $result->getEndTime()->format('Y-m-d\TH:i:sO'));
		$this->assertSame('original work', $result->getDescription());

		$decodedBreaks = json_decode($result->getBreaks(), true);
		$this->assertIsArray($decodedBreaks);
		$this->assertCount(1, $decodedBreaks);

		$justification = json_decode($result->getJustification(), true);
		$this->assertSame('Not approved.', $justification['rejection_reason']);
		$this->assertSame('manager1', $justification['rejected_by']);
	}

	public function testCancelByEmployeeReturnsNullForManualCreate(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode([
			'type' => 'manual_create',
			'justification' => 'created entry retroactively',
			'proposed' => ['startTime' => '2026-01-15T09:00:00+00:00'],
		]));

		$result = $this->service->cancelByEmployee($entry);

		$this->assertNull($result, 'manual_create cancellations must signal a row delete');
	}

	/**
	 * Absolute No-Go regression: rejecting a four-eyes manual create must NOT
	 * complete the hours (empty original snapshot previously flipped status to completed).
	 */
	public function testRejectManualCreateMarksRejectedNotCompleted(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setIsManualEntry(true);
		$entry->setStartTime(new \DateTime('2026-09-17T09:00:00+02:00'));
		$entry->setEndTime(new \DateTime('2026-09-17T14:00:00+02:00'));
		$entry->setJustification(json_encode([
			'type' => 'manual_create',
			'justification' => 'estoy probando',
			'proposed' => [
				'startTime' => '2026-09-17T09:00:00+02:00',
				'endTime' => '2026-09-17T14:00:00+02:00',
				'description' => '',
			],
			'requested_at' => '2026-09-18T06:14:19+00:00',
		]));

		$result = $this->service->reject($entry, 'manager1', 'Not needed');

		$this->assertSame(TimeEntry::STATUS_REJECTED, $result->getStatus());
		$this->assertNotSame(TimeEntry::STATUS_COMPLETED, $result->getStatus());
		$this->assertNull($result->getApprovedByUserId());
		$this->assertNull($result->getApprovedAt());
		// Clocks stay for audit; they must not become countable completed work.
		$this->assertSame('2026-09-17T09:00:00+0200', $result->getStartTime()->format('Y-m-d\TH:i:sO'));
		$this->assertSame('2026-09-17T14:00:00+0200', $result->getEndTime()->format('Y-m-d\TH:i:sO'));

		$justification = json_decode((string)$result->getJustification(), true);
		$this->assertSame('manual_create', $justification['type']);
		$this->assertSame('Not needed', $justification['rejection_reason']);
		$this->assertSame('manager1', $justification['rejected_by']);
		$this->assertArrayHasKey('rejected_at', $justification);
	}

	public function testApproveManualCreateCompletesEntry(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setIsManualEntry(true);
		$entry->setStartTime(new \DateTime('2026-09-17T09:00:00+02:00'));
		$entry->setEndTime(new \DateTime('2026-09-17T14:00:00+02:00'));
		$entry->setJustification(json_encode([
			'type' => 'manual_create',
			'justification' => 'estoy probando',
			'proposed' => [
				'startTime' => '2026-09-17T09:00:00+02:00',
				'endTime' => '2026-09-17T14:00:00+02:00',
				'description' => '',
			],
		]));

		$this->timeTrackingService->expects($this->atLeastOnce())
			->method('calculateAndSetAutomaticBreak')
			->with($this->isInstanceOf(TimeEntry::class));
		$this->timeTrackingService->expects($this->atLeastOnce())
			->method('adjustEndTimeForDailyMaximum')
			->with($this->isInstanceOf(TimeEntry::class));

		$result = $this->service->approve($entry, 'manager1', 'OK');

		$this->assertSame(TimeEntry::STATUS_COMPLETED, $result->getStatus());
		$this->assertSame('manager1', $result->getApprovedByUserId());
		$this->assertNotNull($result->getApprovedAt());
		$justification = json_decode((string)$result->getJustification(), true);
		$this->assertSame('manual_create', $justification['type']);
		$this->assertSame('manager1', $justification['approved_by']);
		$this->assertSame('OK', $justification['approval_comment']);
	}

	public function testCancelByEmployeeRestoresOriginalForCorrection(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setStartTime(new \DateTime('2026-01-15T08:00:00+00:00'));
		$entry->setDescription('proposed work');
		$entry->setJustification(json_encode([
			'justification' => 'mistake',
			'original' => [
				'startTime' => '2026-01-15T09:00:00+00:00',
				'endTime' => '2026-01-15T17:00:00+00:00',
				'description' => 'original work',
			],
			'proposed' => ['startTime' => '2026-01-15T08:00:00+00:00'],
		]));

		$result = $this->service->cancelByEmployee($entry);

		$this->assertNotNull($result);
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $result->getStatus());
		$this->assertSame('2026-01-15T09:00:00+0000', $result->getStartTime()->format('Y-m-d\TH:i:sO'));
		$this->assertSame('original work', $result->getDescription());
		$this->assertNull($result->getJustification(), 'Justification must be cleared on cancel');
	}

	public function testCancelByEmployeeRestoresPausedWhenOriginalEndWasNull(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setEndTime(new \DateTime('2026-01-15T18:00:00+00:00'));
		$entry->setJustification(json_encode([
			'justification' => 'pause mistake',
			'original' => [
				'startTime' => '2026-01-15T09:00:00+00:00',
				'endTime' => null,
				'status' => TimeEntry::STATUS_PAUSED,
				'description' => 'paused work',
			],
			'proposed' => ['endTime' => '2026-01-15T18:00:00+00:00'],
		]));

		$result = $this->service->cancelByEmployee($entry);

		$this->assertNotNull($result);
		$this->assertSame(TimeEntry::STATUS_PAUSED, $result->getStatus());
		$this->assertNull($result->getEndTime(), 'Paused original must restore a null end');
		$this->assertSame('paused work', $result->getDescription());
	}

	public function testRejectRestoresPausedWhenOriginalEndWasNull(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setEndTime(new \DateTime('2026-01-15T18:00:00+00:00'));
		$entry->setJustification(json_encode([
			'justification' => 'pause mistake',
			'original' => [
				'startTime' => '2026-01-15T09:00:00+00:00',
				'endTime' => null,
				'status' => TimeEntry::STATUS_PAUSED,
			],
		]));

		$result = $this->service->reject($entry, 'manager1', 'Keep the pause');

		$this->assertSame(TimeEntry::STATUS_PAUSED, $result->getStatus());
		$this->assertNull($result->getEndTime());
	}

	public function testApplyManagerCorrectionPreservesPreviousJustification(): void
	{
		$entry = $this->buildEntry();
		$priorJustification = ['rejection_reason' => 'previous rejection', 'rejected_by' => 'manager0'];
		$entry->setJustification(json_encode($priorJustification));

		$proposal = ['startTime' => '2026-01-15T10:00:00+00:00', 'endTime' => '2026-01-15T16:00:00+00:00'];
		$result = $this->service->applyManagerCorrection($entry, $proposal, 'manager1', 'Adjusted retroactively per HR.');

		$json = json_decode($result->getJustification(), true);
		$this->assertTrue($json['manager_correction']);
		$this->assertSame('manager1', $json['corrected_by']);
		$this->assertSame('Adjusted retroactively per HR.', $json['reason']);
		$this->assertSame($priorJustification, $json['previous_justification']);
	}

	public function testValidateProposalReturnsErrorOnOverlap(): void
	{
		$entry = $this->buildEntry();
		$conflicting = new TimeEntry();
		$conflicting->setId(99);
		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->method('findOverlapping')->willReturn([$conflicting]);
		$mapper->method('update')->willReturnCallback(static fn (TimeEntry $e) => $e);

		$service = new TimeEntryCorrectionService(
			$mapper,
			$this->monthClosureGuard,
			$this->complianceService,
			$this->timeTrackingService,
			$this->notificationService,
			$this->auditLogMapper,
			$this->config,
			$this->l10n,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
		);

		$error = $service->validateProposal($entry, [
			'startTime' => '2026-01-15T10:00:00+00:00',
			'endTime' => '2026-01-15T16:00:00+00:00',
		]);

		$this->assertNotNull($error);
		$this->assertStringContainsString('overlap', strtolower((string)$error));
	}

	public function testApplyProposalDropsShortBreaks(): void
	{
		$entry = $this->buildEntry();
		$reflection = new \ReflectionMethod($this->service, 'applyProposal');
		$reflection->invoke($this->service, $entry, [
			'breaks' => [
				['start' => '2026-01-15T12:00:00+00:00', 'end' => '2026-01-15T12:05:00+00:00'], // 5min - dropped
				['start' => '2026-01-15T13:00:00+00:00', 'end' => '2026-01-15T13:30:00+00:00'], // 30min - kept
			],
		]);

		$decoded = json_decode($entry->getBreaks(), true);
		$this->assertCount(1, $decoded);
		$this->assertStringStartsWith('2026-01-15T13:00:00', $decoded[0]['start']);
	}

	public function testApplyProposalEmptyBreaksClearsAll(): void
	{
		$entry = $this->buildEntry();
		$entry->setBreaks(json_encode([
			['start' => '2026-01-15T12:00:00+00:00', 'end' => '2026-01-15T12:30:00+00:00'],
		]));

		$reflection = new \ReflectionMethod($this->service, 'applyProposal');
		$reflection->invoke($this->service, $entry, ['breaks' => []]);

		$this->assertNull($entry->getBreaks());
	}

	public function testApplyProposalStripsAllocatorOwnedPaidBreakFields(): void
	{
		$entry = $this->buildEntry();
		$entry->setPaidBreakSeconds(1800);

		$reflection = new \ReflectionMethod($this->service, 'applyProposal');
		$reflection->invoke($this->service, $entry, [
			'description' => 'new desc',
			'paid_break_seconds' => 99999,
			'paidBreakSeconds' => 99999,
			'paid_break_minutes' => 99999,
			'paidBreakMinutes' => 99999,
			'paid_duration_hours' => 99.9,
			'paidDurationHours' => 99.9,
		]);

		$this->assertSame(1800, $entry->getPaidBreakSeconds(), 'client payload must never write the snapshot');
		$this->assertSame('new desc', $entry->getDescription());
	}

	public function testValidateProposalBlocksIllegalAustrianBreakSplit(): void
	{
		$entry = $this->buildEntry();
		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->method('findOverlapping')->willReturn([]);

		$compliance = $this->createMock(ComplianceService::class);
		$compliance->method('checkRestPeriodForStartTime')->willReturn(['valid' => true]);
		$compliance->expects($this->once())
			->method('blockingIssuesForCompletedEntry')
			->willReturn(['Break requirement not met (AZG §11): portions must be continuous ≥30, 2×15, or 3×10.']);

		$service = new TimeEntryCorrectionService(
			$mapper,
			$this->monthClosureGuard,
			$compliance,
			$this->timeTrackingService,
			$this->notificationService,
			$this->auditLogMapper,
			$this->config,
			$this->l10n,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
		);

		$error = $service->validateProposal($entry, [
			'startTime' => '2026-01-15T08:00:00+00:00',
			'endTime' => '2026-01-15T15:00:00+00:00',
			'breaks' => [
				['start' => '2026-01-15T10:00:00+00:00', 'end' => '2026-01-15T10:20:00+00:00'],
				['start' => '2026-01-15T12:00:00+00:00', 'end' => '2026-01-15T12:10:00+00:00'],
			],
		], 'manager1');

		$this->assertNotNull($error);
		$this->assertStringContainsString('AZG', (string)$error);
	}

	public function testValidateProposalInfersCompletedStatusForNewEntries(): void
	{
		$entry = new TimeEntry();
		$entry->setUserId('bob');

		$error = $this->service->validateProposal($entry, [
			'startTime' => '2026-06-15T08:00:00+00:00',
			'endTime' => '2026-06-15T16:00:00+00:00',
		], 'manager1');

		$this->assertNull($error);
	}

	public function testCreateManagerRecordedEntryAcceptsEntryWithoutInitialStatus(): void
	{
		$entry = new TimeEntry();
		$entry->setUserId('bob');
		$entry->setIsManualEntry(true);
		$entry->setCreatedAt(new \DateTime('2026-06-15T08:00:00+00:00'));
		$entry->setUpdatedAt(new \DateTime('2026-06-15T08:00:00+00:00'));

		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->with($this->callback(static function (TimeEntry $saved): bool {
				return $saved->getStatus() === TimeEntry::STATUS_COMPLETED
					&& $saved->getUserId() === 'bob'
					&& $saved->getApprovedByUserId() === 'manager1'
					&& $saved->getStartTime()->format('Y-m-d H:i') === '2026-06-15 08:00'
					&& $saved->getEndTime()->format('Y-m-d H:i') === '2026-06-15 16:00';
			}))
			->willReturnCallback(function (TimeEntry $saved): TimeEntry {
				$saved->setId(101);
				$this->entryStore[101] = $saved;
				return $saved;
			});

		$result = $this->service->createManagerRecordedEntry($entry, [
			'startTime' => '2026-06-15T08:00:00+00:00',
			'endTime' => '2026-06-15T16:00:00+00:00',
		], 'manager1', 'Retroactive entry per HR audit.');

		$this->assertSame(TimeEntry::STATUS_COMPLETED, $result->getStatus());
		$this->assertSame('manager1', $result->getApprovedByUserId());
		$justification = json_decode($result->getJustification(), true);
		$this->assertTrue($justification['manager_created']);
		$this->assertSame('Retroactive entry per HR audit.', $justification['reason']);
	}

	public function testValidateProposalAllowsUnchangedProjectWhenAdminLinkingDisabled(): void
	{
		$entry = $this->buildEntry();
		$entry->setProjectCheckProjectId('42');

		$projectCheck = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class);
		$projectCheck->method('isProjectCheckAvailable')->willReturn(true);
		$projectCheck->expects($this->never())->method('userMayAttachProjectCheckProjectToOwnTime');
		$projectCheck->expects($this->never())->method('managerMayAttachProjectCheckProjectForEmployee');

		$service = new TimeEntryCorrectionService(
			$this->timeEntryMapper,
			$this->monthClosureGuard,
			$this->complianceService,
			$this->timeTrackingService,
			$this->notificationService,
			$this->auditLogMapper,
			$this->config,
			$this->l10n,
			$projectCheck,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
		);

		$error = $service->validateProposal($entry, [
			'startTime' => '2026-01-15T10:00:00+00:00',
			'endTime' => '2026-01-15T16:00:00+00:00',
		], 'manager1');

		$this->assertNull($error);
	}

	public function testApproveThrowsWhenPendingDecisionLostRace(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setIsManualEntry(true);
		$entry->setJustification(json_encode([
			'type' => 'manual_create',
			'justification' => 'estoy probando',
			'proposed' => [],
		]));

		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->method('findOverlapping')->willReturn([]);
		$mapper->expects($this->once())->method('updateIfPendingApproval')->willReturn(false);
		$mapper->expects($this->never())->method('update');

		$service = new TimeEntryCorrectionService(
			$mapper,
			$this->monthClosureGuard,
			$this->complianceService,
			$this->timeTrackingService,
			$this->notificationService,
			$this->auditLogMapper,
			$this->config,
			$this->l10n,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
		);

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException::class);
		$this->expectExceptionMessage('This time entry was already decided by another manager.');
		$service->approve($entry, 'manager2', null);
	}

	public function testRejectThrowsWhenPendingDecisionLostRace(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setIsManualEntry(true);
		$entry->setJustification(json_encode([
			'type' => 'manual_create',
			'justification' => 'estoy probando',
			'proposed' => [],
		]));

		$mapper = $this->createMock(TimeEntryMapper::class);
		$mapper->expects($this->once())->method('updateIfPendingApproval')->willReturn(false);

		$service = new TimeEntryCorrectionService(
			$mapper,
			$this->monthClosureGuard,
			$this->complianceService,
			$this->timeTrackingService,
			$this->notificationService,
			$this->auditLogMapper,
			$this->config,
			$this->l10n,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
		);

		$this->expectException(\OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException::class);
		$service->reject($entry, 'manager2', 'too late');
	}

	private function serviceWithProjectCheck(
		\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService $integration,
		\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService $sync,
		?IConfig $config = null,
		?ComplianceService $compliance = null,
	): TimeEntryCorrectionService {
		return new TimeEntryCorrectionService(
			$this->timeEntryMapper,
			$this->monthClosureGuard,
			$compliance ?? $this->complianceService,
			$this->timeTrackingService,
			$this->notificationService,
			$this->auditLogMapper,
			$config ?? $this->config,
			$this->l10n,
			$integration,
			$sync,
		);
	}

	public function testPrepareManualPendingSetsStatusAndJustification(): void
	{
		$entry = $this->buildEntry();
		$this->service->prepareManualPending($entry, 'forgot to clock in');

		$this->assertSame(TimeEntry::STATUS_PENDING_APPROVAL, $entry->getStatus());
		$just = json_decode($entry->getJustification(), true);
		$this->assertSame('manual_create', $just['type']);
		$this->assertSame('forgot to clock in', $just['justification']);
		$this->assertSame('original work', $just['proposed']['description']);
		$this->assertNotEmpty($just['proposed']['startTime']);
	}

	public function testSyncProjectCheckBillingUnavailableReturnsNull(): void
	{
		$integration = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class);
		$integration->method('isProjectCheckAvailable')->willReturn(false);
		$sync = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class);
		$sync->expects($this->never())->method('syncFromTimeEntry');

		$service = $this->serviceWithProjectCheck($integration, $sync);
		$this->assertNull($service->syncProjectCheckBilling($this->buildEntry(), 'alice'));
	}

	public function testSyncProjectCheckBillingFailureReturnsWarning(): void
	{
		$integration = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class);
		$integration->method('isProjectCheckAvailable')->willReturn(true);
		$sync = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class);
		$sync->method('syncFromTimeEntry')->willReturn(['success' => false]);

		$service = $this->serviceWithProjectCheck($integration, $sync);
		$this->assertStringContainsString('ProjectCheck', $service->syncProjectCheckBilling($this->buildEntry(), 'alice'));
	}

	public function testSyncProjectCheckBillingSuccessReturnsNull(): void
	{
		$integration = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class);
		$integration->method('isProjectCheckAvailable')->willReturn(true);
		$sync = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class);
		$sync->method('syncFromTimeEntry')->willReturn(['success' => true]);

		$service = $this->serviceWithProjectCheck($integration, $sync);
		$this->assertNull($service->syncProjectCheckBilling($this->buildEntry(), 'alice'));
	}

	public function testApplyProposalHandlesDateHoursBreakAndProjectFields(): void
	{
		$entry = $this->buildEntry();
		$this->service->applyProposal($entry, [
			'date' => '2026-02-10 08:00:00',
			'hours' => 4.5,
			'breakStartTime' => '2026-02-10 12:00:00',
			'breakEndTime' => '2026-02-10 12:30:00',
			'description' => 'updated desc',
			'projectCheckProjectId' => 'proj-9',
		]);
		$this->assertSame('2026-02-10 08:00:00', $entry->getStartTime()->format('Y-m-d H:i:s'));
		// 4.5h after start
		$this->assertSame('2026-02-10 12:30:00', $entry->getEndTime()->format('Y-m-d H:i:s'));
		$this->assertSame('2026-02-10 12:00:00', $entry->getBreakStartTime()->format('Y-m-d H:i:s'));
		$this->assertSame('2026-02-10 12:30:00', $entry->getBreakEndTime()->format('Y-m-d H:i:s'));
		$this->assertSame('updated desc', $entry->getDescription());
		$this->assertSame('proj-9', $entry->getProjectCheckProjectId());
		// truthy breakStartTime clears the breaks collection
		$this->assertNull($entry->getBreaks());

		// clearing arm: empty project id removes both ids; hours without date recomputes end
		$this->service->applyProposal($entry, [
			'projectCheckProjectId' => '',
			'breakStartTime' => null,
			'hours' => 2.0,
		]);
		$this->assertNull($entry->getProjectCheckProjectId());
		$this->assertNull($entry->getProjectCheckTimeEntryId());
		$this->assertNull($entry->getBreakStartTime());
		$this->assertSame('2026-02-10 10:00:00', $entry->getEndTime()->format('Y-m-d H:i:s'));
	}

	public function testApplyProposalConvertsExplicitOffsetInstantsToStorageWall(): void
	{
		// Storage zone Europe/Berlin: a 'Z'-suffixed (or foreign-offset) instant
		// must keep its instant and persist the Berlin wall clock — persisting
		// the raw UTC wall would rehydrate one hour early (audit: approved
		// end_time stored before start_time).
		$berlinConfig = $this->createMock(IConfig::class);
		$berlinConfig->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $key === 'app_timezone' ? 'Europe/Berlin' : $default
		);
		$service = new TimeEntryCorrectionService(
			$this->timeEntryMapper,
			$this->monthClosureGuard,
			$this->complianceService,
			$this->timeTrackingService,
			$this->notificationService,
			$this->auditLogMapper,
			$berlinConfig,
			$this->l10n,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
		);

		$entry = $this->buildEntry();
		$service->applyProposal($entry, ['endTime' => '2026-01-10T17:00:00+00:00']);

		$stored = $entry->getEndTime();
		$this->assertNotNull($stored);
		$this->assertSame('Europe/Berlin', $stored->getTimezone()->getName());
		// January is CET (+01:00): storage wall must be 18:00, not the raw UTC wall 17:00.
		$this->assertSame('2026-01-10 18:00:00', $stored->format('Y-m-d H:i:s'));
		$this->assertSame((new \DateTime('2026-01-10T17:00:00+00:00'))->getTimestamp(), $stored->getTimestamp());

		// A different explicit offset resolves to the same storage wall.
		$entry2 = $this->buildEntry();
		$service->applyProposal($entry2, ['endTime' => '2026-01-10T19:00:00+02:00']);
		$this->assertSame('2026-01-10 18:00:00', $entry2->getEndTime()->format('Y-m-d H:i:s'));
	}

	public function testApproveRunsRealtimeComplianceWhenEnabled(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => match ($key) {
				'app_timezone' => 'UTC',
				'realtime_compliance_check' => '1',
				'compliance_strict_mode' => '1',
				default => $default,
			}
		);
		$compliance = $this->createMock(ComplianceService::class);
		$compliance->method('checkRestPeriodForStartTime')->willReturn(['valid' => true]);
		$compliance->method('blockingIssuesForCompletedEntry')->willReturn([]);
		$compliance->expects($this->once())->method('checkComplianceForCompletedEntry')
			->with($this->anything(), true);

		$service = $this->serviceWithProjectCheck(
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
			$config,
			$compliance,
		);

		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode(['proposed' => ['description' => 'x']]));
		$service->approve($entry, 'boss', 'ok');
	}

	public function testApplyProposalSetsWorkLocation(): void
	{
		$entry = $this->buildEntry();
		$this->service->applyProposal($entry, ['workLocation' => 'home']);
		$this->assertSame('home', $entry->getWorkLocation());
	}

	public function testApplyProposalClearsWorkLocationOnNull(): void
	{
		$entry = $this->buildEntry();
		$entry->setWorkLocation('home');
		$this->service->applyProposal($entry, ['workLocation' => null]);
		$this->assertNull($entry->getWorkLocation());
	}

	public function testApplyProposalLeavesWorkLocationUntouchedWhenAbsent(): void
	{
		$entry = $this->buildEntry();
		$entry->setWorkLocation('external');
		$this->service->applyProposal($entry, ['description' => 'only text changed']);
		$this->assertSame('external', $entry->getWorkLocation());
	}

	public function testApplyProposalRejectsInvalidWorkLocation(): void
	{
		$entry = $this->buildEntry();
		$this->expectException(\InvalidArgumentException::class);
		$this->service->applyProposal($entry, ['workLocation' => 'moon']);
	}

	public function testRejectRestoresOriginalWorkLocation(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setWorkLocation('external');
		$entry->setJustification(json_encode([
			'justification' => 'Was actually at client site.',
			'original' => [
				'startTime' => '2026-01-15T09:00:00+00:00',
				'endTime' => '2026-01-15T17:00:00+00:00',
				'description' => 'original work',
				'status' => TimeEntry::STATUS_COMPLETED,
				'workLocation' => 'office',
			],
			'proposed' => ['workLocation' => 'external'],
		]));

		$result = $this->service->reject($entry, 'manager1', 'Not approved.');
		$this->assertSame('office', $result->getWorkLocation());
	}

	public function testRejectRestoresNullWorkLocationFromLegacySnapshot(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setWorkLocation('home');
		$entry->setJustification(json_encode([
			'justification' => 'x',
			'original' => [
				'startTime' => '2026-01-15T09:00:00+00:00',
				'endTime' => '2026-01-15T17:00:00+00:00',
				'status' => TimeEntry::STATUS_COMPLETED,
				'workLocation' => null,
			],
			'proposed' => ['workLocation' => 'home'],
		]));

		$result = $this->service->reject($entry, 'manager1', 'nope');
		$this->assertNull($result->getWorkLocation());
	}

	public function testPrepareManualPendingSnapshotsWorkLocation(): void
	{
		$entry = $this->buildEntry();
		$entry->setWorkLocation('external');
		$this->service->prepareManualPending($entry, 'forgot to track');

		$json = json_decode($entry->getJustification(), true);
		$this->assertSame('external', $json['proposed']['workLocation']);
	}

	private function failingBillingSyncService(): TimeEntryCorrectionService
	{
		$integration = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class);
		$integration->method('isProjectCheckAvailable')->willReturn(true);
		$sync = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class);
		$sync->method('syncFromTimeEntry')->willReturn(['success' => false]);
		return $this->serviceWithProjectCheck($integration, $sync);
	}

	public function testApproveAttachesBillingWarningToReturnedEntry(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode(['justification' => 'forgot to clock out']));

		$result = $this->failingBillingSyncService()->approve($entry, 'manager1');

		$this->assertNotNull(
			$result->getBillingSyncWarning(),
			'a failed billing sync must reach the caller — it must never be silently dropped'
		);
	}

	public function testApproveLeavesWarningNullWhenSyncSucceeds(): void
	{
		$integration = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class);
		$integration->method('isProjectCheckAvailable')->willReturn(true);
		$sync = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class);
		$sync->method('syncFromTimeEntry')->willReturn(['success' => true]);

		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode(['justification' => 'forgot to clock out']));

		$result = $this->serviceWithProjectCheck($integration, $sync)->approve($entry, 'manager1');

		$this->assertNull($result->getBillingSyncWarning());
	}

	public function testApplyManagerCorrectionAttachesBillingWarning(): void
	{
		$entry = $this->buildEntry();
		$proposal = ['startTime' => '2026-01-15T10:00:00+00:00', 'endTime' => '2026-01-15T16:00:00+00:00'];

		$result = $this->failingBillingSyncService()
			->applyManagerCorrection($entry, $proposal, 'manager1', 'Adjusted retroactively per HR.');

		$this->assertNotNull($result->getBillingSyncWarning());
	}

	public function testCreateManagerRecordedEntryAttachesBillingWarning(): void
	{
		$this->timeEntryMapper->method('insert')->willReturnCallback(
			function (TimeEntry $e): TimeEntry {
				$this->entryStore[$e->getId()] = $e;
				return $e;
			}
		);
		$entry = $this->buildEntry();
		$proposal = [
			'startTime' => '2026-01-15T10:00:00+00:00',
			'endTime' => '2026-01-15T16:00:00+00:00',
		];

		$result = $this->failingBillingSyncService()
			->createManagerRecordedEntry($entry, $proposal, 'manager1', 'Employee forgot to track.');

		$this->assertNotNull($result->getBillingSyncWarning());
	}

	/* ------------------------------------------------------------------
	 * Split-day overrides (spec §9.7 / §9.8; A17/A18 semantics)
	 * ------------------------------------------------------------------ */

	/**
	 * F1-shaped frozen context (spec §8 A04): blocks 06:00–10:00 + 13:30–16:30,
	 * scheduled interruption 10:00–13:30, cut-off 16:00, Europe/Berlin.
	 */
	private function splitContextArray(): array
	{
		return [
			'v' => 1,
			'model_id' => 1,
			'schedule_revision' => 'rev1',
			'timezone' => 'Europe/Berlin',
			'day' => ['blocks' => [['06:00', '10:00'], ['13:30', '16:30']]],
			'week' => [
				'mon' => [['06:00', '10:00'], ['13:30', '16:30']],
			],
			'split' => ['automatic' => true, 'cutoff' => '16:00', 'break_cover' => true],
			'overrides' => ['decision' => 'auto', 'worked' => []],
			'result' => [
				'state' => 'applied',
				'split' => true,
				'reason' => 'clock_out_after_cutoff',
				'excluded' => [['2026-11-16T10:00:00+01:00', '2026-11-16T13:30:00+01:00']],
				'segments' => [
					['2026-11-16T06:00:00+01:00', '2026-11-16T10:00:00+01:00'],
					['2026-11-16T13:30:00+01:00', '2026-11-16T16:30:00+01:00'],
				],
				'break_handling' => 'covered_by_interruption',
				'engine' => 1,
			],
		];
	}

	/** F1 entry: 2026-11-16 06:00–16:30 Europe/Berlin with the frozen context. */
	private function splitEntry(): TimeEntry
	{
		$entry = new TimeEntry();
		$entry->setId(77);
		$entry->setUserId('alice');
		$entry->setStartTime(new \DateTime('2026-11-16T06:00:00+01:00'));
		$entry->setEndTime(new \DateTime('2026-11-16T16:30:00+01:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setWorkContextJson(json_encode($this->splitContextArray()));
		$entry->setExcludedWorkSeconds(12600);
		return $entry;
	}

	/**
	 * Correction service whose split-recompute seam runs the REAL pure engine
	 * ({@see SplitServiceAccounting::resolveSplit}) — the same contract the
	 * sibling SplitServiceAccountingService implements.
	 */
	private function splitAwareService(?callable $recomputeFn = null): SplitOverrideCorrectionServiceDouble
	{
		$service = new SplitOverrideCorrectionServiceDouble(
			$this->timeEntryMapper,
			$this->monthClosureGuard,
			$this->complianceService,
			$this->timeTrackingService,
			$this->notificationService,
			$this->auditLogMapper,
			$this->config,
			$this->l10n,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
		);
		$service->recomputeFn = $recomputeFn ?? $this->engineRecompute();
		return $service;
	}

	private function engineRecompute(): callable
	{
		return static function (TimeEntry $e): void {
			$ctx = $e->getWorkContext();
			$r = \OCA\ArbeitszeitCheck\Support\SplitServiceAccounting::resolveSplit(
				$ctx,
				$e->getStartTime()->getTimestamp(),
				$e->getEndTime()->getTimestamp()
			);
			$ctx['result'] = [
				'state' => $r['state'],
				'split' => $r['split'],
				'reason' => $r['reason'],
				'error' => $r['error'],
				'excluded' => $r['excluded'],
				'segments' => $r['segments'],
				'break_handling' => 'covered_by_interruption',
				'engine' => $r['engine'],
			];
			$e->setWorkContextJson(json_encode($ctx));
			$e->setExcludedWorkSeconds($r['excludedSeconds']);
		};
	}

	private function pendingSplitEntry(array $proposed): TimeEntry
	{
		$entry = $this->splitEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode([
			'justification' => 'Please correct my split day.',
			'original' => [
				'startTime' => '2026-11-16T06:00:00+01:00',
				'endTime' => '2026-11-16T16:30:00+01:00',
				'status' => TimeEntry::STATUS_COMPLETED,
			],
			'proposed' => $proposed,
		]));
		return $entry;
	}

	public function testValidateProposalSplitOverrideNotApplicableWithoutContext(): void
	{
		$entry = $this->buildEntry();
		$error = $this->service->validateProposal($entry, [
			'splitOverride' => ['decision' => 'force_split'],
		]);
		$this->assertSame('SPLIT_OVERRIDE_NOT_APPLICABLE', $error);
	}

	public function testValidateProposalSplitOverrideNotApplicableOnCorruptContext(): void
	{
		$entry = $this->splitEntry();
		$entry->setWorkContextJson('{"v":1,');
		$this->assertTrue($entry->hasInvalidWorkContext());
		$error = $this->service->validateProposal($entry, [
			'splitOverride' => ['decision' => 'force_split'],
		]);
		$this->assertSame('SPLIT_OVERRIDE_NOT_APPLICABLE', $error);
	}

	public function testValidateProposalSplitOverrideInvalidDecision(): void
	{
		$entry = $this->splitEntry();
		$error = $this->service->validateProposal($entry, [
			'splitOverride' => ['decision' => 'splitify'],
		]);
		$this->assertSame('SPLIT_OVERRIDE_INVALID', $error);
	}

	public function testValidateProposalSplitOverrideWorkedOutsideGap(): void
	{
		$entry = $this->splitEntry();
		// 07:00–08:00 is inside R but inside a work block, not the interruption.
		$error = $this->service->validateProposal($entry, [
			'splitOverride' => [
				'decision' => 'force_split',
				'worked' => [['2026-11-16T07:00:00+01:00', '2026-11-16T08:00:00+01:00']],
			],
		]);
		$this->assertSame('SPLIT_OVERRIDE_INVALID', $error);
	}

	public function testValidateProposalSplitOverrideWorkedOutsideR(): void
	{
		$entry = $this->splitEntry();
		// Corrected clock-out 12:00, but the worked portion runs 12:30–13:00 —
		// inside the candidate gap yet outside the corrected range R.
		$error = $this->service->validateProposal($entry, [
			'endTime' => '2026-11-16T12:00:00+01:00',
			'splitOverride' => [
				'decision' => 'force_split',
				'worked' => [['2026-11-16T12:30:00+01:00', '2026-11-16T13:00:00+01:00']],
			],
		]);
		$this->assertSame('SPLIT_OVERRIDE_INVALID', $error);
	}

	public function testValidateProposalSplitOverrideMoreThanEightIntervals(): void
	{
		$entry = $this->splitEntry();
		// 9 disjoint 10-minute intervals inside the 3.5h gap
		$worked = [];
		for ($i = 0; $i < 9; $i++) {
			$s = new \DateTimeImmutable('2026-11-16T10:00:00+01:00');
			$s = $s->modify('+' . ($i * 20) . ' minutes');
			$e = $s->modify('+10 minutes');
			$worked[] = [$s->format('c'), $e->format('c')];
		}
		$error = $this->service->validateProposal($entry, [
			'splitOverride' => ['decision' => 'force_split', 'worked' => $worked],
		]);
		$this->assertSame('SPLIT_OVERRIDE_INVALID', $error);
	}

	public function testValidateProposalSplitOverrideUnparseableWorked(): void
	{
		$entry = $this->splitEntry();
		$error = $this->service->validateProposal($entry, [
			'splitOverride' => [
				'decision' => 'force_split',
				'worked' => [['not-a-date', '2026-11-16T11:00:00+01:00']],
			],
		]);
		$this->assertSame('SPLIT_OVERRIDE_INVALID', $error);
	}

	public function testValidateProposalSplitOverrideWorkedEndNotAfterStart(): void
	{
		$entry = $this->splitEntry();
		$error = $this->service->validateProposal($entry, [
			'splitOverride' => [
				'decision' => 'force_split',
				'worked' => [['2026-11-16T11:00:00+01:00', '2026-11-16T10:30:00+01:00']],
			],
		]);
		$this->assertSame('SPLIT_OVERRIDE_INVALID', $error);
	}

	public function testValidateProposalSplitOverrideOverlappingWorkedMerges(): void
	{
		$entry = $this->splitEntry();
		$error = $this->service->validateProposal($entry, [
			'splitOverride' => [
				'decision' => 'force_split',
				'worked' => [
					['2026-11-16T10:00:00+01:00', '2026-11-16T10:45:00+01:00'],
					['2026-11-16T10:30:00+01:00', '2026-11-16T11:00:00+01:00'],
				],
			],
		]);
		$this->assertNull($error);
	}

	public function testValidateProposalSplitOverrideForceContinuousRejectsWorked(): void
	{
		$entry = $this->splitEntry();
		$error = $this->service->validateProposal($entry, [
			'splitOverride' => [
				'decision' => 'force_continuous',
				'worked' => [['2026-11-16T10:00:00+01:00', '2026-11-16T11:00:00+01:00']],
			],
		]);
		$this->assertSame('SPLIT_OVERRIDE_INVALID', $error);
	}

	public function testValidateProposalSplitOverrideForceContinuousOk(): void
	{
		$entry = $this->splitEntry();
		$error = $this->service->validateProposal($entry, [
			'splitOverride' => ['decision' => 'force_continuous'],
		]);
		$this->assertNull($error);
	}

	public function testValidateProposalSplitOverrideBaseRevisionFormat(): void
	{
		$entry = $this->splitEntry();
		$error = $this->service->validateProposal($entry, [
			'splitOverride' => ['decision' => 'force_split', 'baseEntryRevision' => 'not-sha'],
		]);
		$this->assertSame('SPLIT_OVERRIDE_INVALID', $error);

		$error = $this->service->validateProposal($entry, [
			'splitOverride' => [
				'decision' => 'force_split',
				'baseEntryRevision' => str_repeat('a', 64),
			],
		]);
		$this->assertNull($error);
	}

	/**
	 * @dataProvider serverOwnedFieldProvider
	 */
	public function testValidateProposalRejectsServerOwnedFields(string $field): void
	{
		$entry = $this->splitEntry();
		$error = $this->service->validateProposal($entry, [$field => 'x']);
		$this->assertSame('SERVER_OWNED_FIELD', $error);
	}

	public static function serverOwnedFieldProvider(): array
	{
		return [
			'accounting' => ['accounting'],
			'work_context_json' => ['work_context_json'],
			'workContextJson' => ['workContextJson'],
			'excluded_work_seconds' => ['excluded_work_seconds'],
			'excludedWorkSeconds' => ['excludedWorkSeconds'],
			'excludedMinutes' => ['excludedMinutes'],
			'excluded_minutes' => ['excluded_minutes'],
		];
	}

	public function testApplyProposalThrowsOnServerOwnedField(): void
	{
		$entry = $this->splitEntry();
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('SERVER_OWNED_FIELD');
		$this->service->applyProposal($entry, ['excluded_work_seconds' => 42]);
	}

	public function testApplyProposalAutoDecisionClearsWorked(): void
	{
		$entry = $this->splitEntry();
		$this->service->applyProposal($entry, [
			'splitOverride' => [
				'decision' => 'auto',
				'worked' => [['2026-11-16T10:00:00+01:00', '2026-11-16T11:00:00+01:00']],
			],
		]);
		$overrides = $entry->getWorkContext()['overrides'];
		$this->assertSame('auto', $overrides['decision']);
		$this->assertSame([], $overrides['worked'], 'auto clears worked evidence');
	}

	public function testApplyProposalForceSplitNormalizesWorked(): void
	{
		$entry = $this->splitEntry();
		$this->service->applyProposal($entry, [
			'splitOverride' => [
				'decision' => 'force_split',
				'worked' => [
					['2026-11-16T10:30:00+01:00', '2026-11-16T11:00:00+01:00'],
					['2026-11-16T10:00:00+01:00', '2026-11-16T10:45:00+01:00'],
				],
			],
		]);
		$overrides = $entry->getWorkContext()['overrides'];
		$this->assertSame('force_split', $overrides['decision']);
		// Overlapping portions merged into one canonical ISO pair, context tz.
		$this->assertSame(
			[['2026-11-16T10:00:00+01:00', '2026-11-16T11:00:00+01:00']],
			$overrides['worked']
		);
	}

	/**
	 * A17: F1 + approved override worked 10:00–11:00 → excluded 9,000 s,
	 * segments 06:00–11:00 + 13:30–16:30.
	 */
	public function testApproveForceSplitOverrideRecomputesAccounting(): void
	{
		$entry = $this->pendingSplitEntry([
			'splitOverride' => [
				'decision' => 'force_split',
				'worked' => [['2026-11-16T10:00:00+01:00', '2026-11-16T11:00:00+01:00']],
			],
		]);
		$service = $this->splitAwareService();

		$result = $service->approve($entry, 'manager1', 'ok');

		$this->assertSame(1, $service->recomputeCalls, 'approval must recompute server-side');
		$this->assertSame(9000, $result->getExcludedWorkSeconds(), 'A17: X = 11:00–13:30 = 9,000 s');

		$ctx = $result->getWorkContext();
		$this->assertSame('force_split', $ctx['overrides']['decision']);
		$this->assertSame(
			[['2026-11-16T10:00:00+01:00', '2026-11-16T11:00:00+01:00']],
			$ctx['overrides']['worked']
		);
		$this->assertSame('override_split', $ctx['result']['reason']);
		$this->assertSame(
			[
				['2026-11-16T06:00:00+01:00', '2026-11-16T11:00:00+01:00'],
				['2026-11-16T13:30:00+01:00', '2026-11-16T16:30:00+01:00'],
			],
			$ctx['result']['segments']
		);

		// §9.7 audit: the justification stores the ORIGINAL context for
		// reconstruction alongside the proposed override.
		$just = json_decode($result->getJustification(), true);
		$this->assertArrayHasKey('original_work_context', $just);
		$this->assertSame(1, $just['original_work_context']['v']);
		$this->assertSame('auto', $just['original_work_context']['overrides']['decision']);
		$this->assertSame('force_split', $just['proposed']['splitOverride']['decision']);
	}

	/**
	 * §9.7 stale: the engine reports the override inapplicable after the new
	 * times → approval fails with the 409 seam exception, wire code
	 * SPLIT_OVERRIDE_STALE.
	 */
	public function testApproveThrowsSplitOverrideStale(): void
	{
		$entry = $this->pendingSplitEntry([
			'splitOverride' => ['decision' => 'force_split'],
		]);
		$service = $this->splitAwareService(static function (TimeEntry $e): void {
			$ctx = $e->getWorkContext();
			$ctx['result'] = [
				'state' => 'applied',
				'split' => false,
				'reason' => null,
				'error' => 'SPLIT_OVERRIDE_NOT_APPLICABLE',
				'excluded' => [],
				'segments' => [],
				'break_handling' => 'standard',
				'engine' => 1,
			];
			$e->setWorkContextJson(json_encode($ctx));
		});

		try {
			$service->approve($entry, 'manager1', null);
			$this->fail('expected SplitOverrideStaleException');
		} catch (\OCA\ArbeitszeitCheck\Exception\SplitOverrideStaleException $e) {
			$this->assertInstanceOf(
				\OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException::class,
				$e,
				'must ride the existing 409 approval seam'
			);
			$this->assertSame('SPLIT_OVERRIDE_STALE', $e->getReasonCode());
			$this->assertSame('SPLIT_OVERRIDE_STALE', $e->toHttpPayload()['error_code']);
		}
	}

	public function testApproveSkipsRecomputeForLegacyEntry(): void
	{
		$entry = $this->buildEntry();
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setJustification(json_encode([
			'justification' => 'x',
			'proposed' => ['description' => 'plain correction'],
		]));
		$service = $this->splitAwareService();

		$service->approve($entry, 'manager1', null);

		$this->assertSame(0, $service->recomputeCalls, 'NULL context must keep the byte-identical legacy path');
	}

	public function testApproveWithAdjustmentRecomputesAndStoresOverride(): void
	{
		$entry = $this->pendingSplitEntry([
			'splitOverride' => ['decision' => 'auto'],
		]);
		$service = $this->splitAwareService();

		$result = $service->approveWithAdjustment($entry, 'manager1', 'adjusted', [
			'splitOverride' => ['decision' => 'force_continuous'],
		]);

		$this->assertSame(1, $service->recomputeCalls);
		$ctx = $result->getWorkContext();
		$this->assertSame('force_continuous', $ctx['overrides']['decision']);
		$this->assertSame('override_continuous', $ctx['result']['reason']);
		$just = json_decode($result->getJustification(), true);
		$this->assertArrayHasKey('original_work_context', $just);
	}
}

/**
 * Test double: exposes the sibling-service recompute seam so tests can drive
 * the real pure engine (or a fault injection) while the parent's stale-check
 * orchestration stays under test.
 */
class SplitOverrideCorrectionServiceDouble extends TimeEntryCorrectionService
{
	/** @var callable|null */
	public $recomputeFn = null;

	public int $recomputeCalls = 0;

	protected function runSplitRecompute(TimeEntry $entry): void
	{
		$this->recomputeCalls++;
		if ($this->recomputeFn !== null) {
			($this->recomputeFn)($entry);
		}
	}
}