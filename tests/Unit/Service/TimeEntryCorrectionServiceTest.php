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

		$this->timeEntryMapper->method('findOverlapping')->willReturn([]);
		$this->timeEntryMapper->method('update')->willReturnCallback(static fn (TimeEntry $e): TimeEntry => $e);
		$this->timeEntryMapper->method('updateIfPendingApproval')->willReturnCallback(
			static function (TimeEntry $e): bool {
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
		$reflection->setAccessible(true);
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
		$reflection->setAccessible(true);
		$reflection->invoke($this->service, $entry, ['breaks' => []]);

		$this->assertNull($entry->getBreaks());
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
			->willReturnCallback(static function (TimeEntry $saved): TimeEntry {
				$saved->setId(101);
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
}
