<?php

declare(strict_types=1);

/**
 * Unit tests for the optional-workforce surface of AbsenceService:
 * completed-absence corrections (request/withdraw/decide/direct),
 * hour-mode coverage share, and the owner/manager action flags.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLog;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\PaidAbsencePlannedHoursCreditService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\WorkforcePolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class AbsenceServiceOptionalWorkforceTest extends TestCase
{
	private AbsenceService $service;
	private $absenceMapper;
	private $auditLogMapper;
	private $monthClosureService;
	private $timeZoneService;
	private $workforcePolicyService;
	private $db;
	private $notificationService;
	private $teamResolver;
	private $userSettingsMapper;
	private $userWorkingTimeModelMapper;
	private $holidayCalendarService;

	private const UUID = '123e4567-e89b-42d3-a456-426614174000';

	protected function setUp(): void
	{
		parent::setUp();

		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSettingsMapper = $this->userSettingsMapper;
		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->teamResolver->method('getColleagueIds')->willReturn([]);
		$this->teamResolver->method('getManagerIdsForEmployee')->willReturn([]);
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$userWorkingTimeModelMapper = $this->userWorkingTimeModelMapper;
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default = '') => $default
		);
		$this->db = $this->createMock(IDBConnection::class);
		$lockingProvider = $this->createMock(ILockingProvider::class);
		$userManager = $this->createMock(IUserManager::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($text) => $text);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->holidayCalendarService = $this->createMock(HolidayService::class);
		$holidayCalendarService = $this->holidayCalendarService;
		$vacationYearBalanceMapper = $this->createMock(VacationYearBalanceMapper::class);
		$vacationAllocationService = $this->createMock(VacationAllocationService::class);
		$this->monthClosureService = $this->createMock(MonthClosureService::class);
		$this->monthClosureService->method('monthsOverlappingRange')->willReturn([[2026, 10]]);
		// TimeZoneService is final — construct it on UTC like the other suites.
		$tzConfig = $this->createMock(IConfig::class);
		$tzConfig->method('getAppValue')->willReturn('UTC');
		$tzDateTime = $this->createMock(\OCP\IDateTimeZone::class);
		$tzDateTime->method('getTimeZone')->willReturn(new \DateTimeZone('UTC'));
		$tzUserSession = $this->createMock(\OCP\IUserSession::class);
		$tzUserSession->method('getUser')->willReturn(null);
		$this->timeZoneService = new TimeZoneService(
			$tzConfig,
			$tzDateTime,
			$tzUserSession,
			new \Psr\Log\NullLogger()
		);
		$timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->workforcePolicyService = $this->createMock(WorkforcePolicyService::class);

		$this->service = new AbsenceService(
			$this->absenceMapper,
			$this->auditLogMapper,
			$userSettingsMapper,
			$this->teamResolver,
			$userWorkingTimeModelMapper,
			$config,
			$this->db,
			$lockingProvider,
			$userManager,
			$l10n,
			$this->notificationService,
			null,
			$holidayCalendarService,
			$vacationYearBalanceMapper,
			$vacationAllocationService,
			null,
			$this->monthClosureService,
			$this->timeZoneService,
			null,
			null,
			null,
			null,
			$timeEntryMapper,
			null,
			null,
			null,
			null,
			$this->workforcePolicyService
		);
	}

	/** Inject a mock into the lazily-resolved service properties. */
	private function injectLazy(string $property, object $mock): void
	{
		$prop = new \ReflectionProperty(AbsenceService::class, $property);
		$prop->setValue($this->service, $mock);
	}

	private function completedApprovedAbsence(string $owner = 'employee1'): Absence
	{
		$absence = new Absence();
		$absence->setId(42);
		$absence->setUserId($owner);
		$absence->setType(Absence::TYPE_SICK_LEAVE);
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setStartDate(new \DateTime('-10 days'));
		$absence->setEndDate(new \DateTime('-8 days'));
		$absence->setDays(3.0);
		$absence->setUpdatedAt(new \DateTime('2026-10-01T12:00:00+00:00'));
		return $absence;
	}

	private function validRequestData(Absence $absence): array
	{
		return [
			'reason' => 'Was recorded by mistake',
			'expectedUpdatedAt' => $absence->getUpdatedAt()->format('c'),
			'operation_key' => self::UUID,
		];
	}

	// ------------------------------------------------------------------
	// hourModeCoverageShare
	// ------------------------------------------------------------------

	private function absenceWithMinutes(?int $minutes, ?\DateTime $start = null): Absence
	{
		$absence = new Absence();
		$absence->setUserId('employee1');
		if ($minutes !== null) {
			$absence->setDurationMinutes($minutes);
		}
		if ($start !== null) {
			$absence->setStartDate($start);
		}
		return $absence;
	}

	public function testCoverageShareNullMinutesIsFull(): void
	{
		$this->assertSame(1.0, $this->service->hourModeCoverageShare('u', $this->absenceWithMinutes(null)));
	}

	public function testCoverageShareZeroMinutesIsFull(): void
	{
		$this->assertSame(1.0, $this->service->hourModeCoverageShare('u', $this->absenceWithMinutes(0, new \DateTime())));
	}

	public function testCoverageShareNoStartDateIsFull(): void
	{
		$this->assertSame(1.0, $this->service->hourModeCoverageShare('u', $this->absenceWithMinutes(60)));
	}

	public function testCoverageShareFractionOfPlanned(): void
	{
		$planned = $this->createMock(PaidAbsencePlannedHoursCreditService::class);
		$planned->method('plannedNetHoursForDay')->willReturn(8.0);
		$this->injectLazy('paidAbsencePlannedHoursService', $planned);
		$this->assertSame(0.25, $this->service->hourModeCoverageShare('u', $this->absenceWithMinutes(120, new \DateTime())));
	}

	public function testCoverageShareClampsAtOne(): void
	{
		$planned = $this->createMock(PaidAbsencePlannedHoursCreditService::class);
		$planned->method('plannedNetHoursForDay')->willReturn(4.0);
		$this->injectLazy('paidAbsencePlannedHoursService', $planned);
		$this->assertSame(1.0, $this->service->hourModeCoverageShare('u', $this->absenceWithMinutes(600, new \DateTime())));
	}

	public function testCoverageShareZeroPlannedIsFull(): void
	{
		$planned = $this->createMock(PaidAbsencePlannedHoursCreditService::class);
		$planned->method('plannedNetHoursForDay')->willReturn(0.0);
		$this->injectLazy('paidAbsencePlannedHoursService', $planned);
		$this->assertSame(1.0, $this->service->hourModeCoverageShare('u', $this->absenceWithMinutes(60, new \DateTime())));
	}

	// ------------------------------------------------------------------
	// requestAbsenceCorrection — gate ordering
	// ------------------------------------------------------------------

	public function testRequestCorrectionAbsenceNotFound(): void
	{
		$this->absenceMapper->method('find')->willThrowException(new DoesNotExistException('nope'));
		$this->expectException(DoesNotExistException::class);
		$this->service->requestAbsenceCorrection(42, [], 'employee1');
	}

	public function testRequestCorrectionForeignAbsenceNotFound(): void
	{
		$this->absenceMapper->method('find')->willReturn($this->completedApprovedAbsence('other'));
		$this->expectException(DoesNotExistException::class);
		$this->service->requestAbsenceCorrection(42, [], 'employee1');
	}

	public function testRequestCorrectionFeatureDisabled(): void
	{
		$this->absenceMapper->method('find')->willReturn($this->completedApprovedAbsence());
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(false);
		$this->expectExceptionMessage('feature_disabled');
		$this->service->requestAbsenceCorrection(42, [], 'employee1');
	}

	public function testRequestCorrectionNotApprovedRejected(): void
	{
		$absence = $this->completedApprovedAbsence();
		$absence->setStatus(Absence::STATUS_PENDING);
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$this->expectExceptionMessage('not_completed');
		$this->service->requestAbsenceCorrection(42, [], 'employee1');
	}

	public function testRequestCorrectionRunningAbsenceRejected(): void
	{
		$absence = $this->completedApprovedAbsence();
		$absence->setEndDate(new \DateTime('+2 days'));
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$this->expectExceptionMessage('not_completed');
		$this->service->requestAbsenceCorrection(42, [], 'employee1');
	}

	public function testRequestCorrectionAbsenceEndingTodayRejected(): void
	{
		$absence = $this->completedApprovedAbsence();
		$absence->setEndDate(new \DateTime('today'));
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$this->expectExceptionMessage('not_completed');
		$this->service->requestAbsenceCorrection(42, [], 'employee1');
	}

	public function testRequestCorrectionPendingRequestRejected(): void
	{
		$absence = $this->completedApprovedAbsence();
		$absence->setCancelRequestedAt(new \DateTime('-1 day'));
		$absence->setCancelRequestedBy('employee1');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$this->expectExceptionMessage('cancel_request_pending');
		$this->service->requestAbsenceCorrection(42, [], 'employee1');
	}

	public function testRequestCorrectionReasonRequired(): void
	{
		$absence = $this->completedApprovedAbsence();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$this->expectExceptionMessage('reason_required');
		$this->service->requestAbsenceCorrection(42, ['reason' => '   '], 'employee1');
	}

	public function testRequestCorrectionReasonTooLong(): void
	{
		$absence = $this->completedApprovedAbsence();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$this->expectExceptionMessage('reason_required');
		$this->service->requestAbsenceCorrection(42, ['reason' => str_repeat('x', 501)], 'employee1');
	}

	public function testRequestCorrectionCasMissing(): void
	{
		$absence = $this->completedApprovedAbsence();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$this->expectExceptionMessage('revision_stale');
		$this->service->requestAbsenceCorrection(42, ['reason' => 'valid reason'], 'employee1');
	}

	public function testRequestCorrectionCasMismatch(): void
	{
		$absence = $this->completedApprovedAbsence();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$this->expectExceptionMessage('revision_stale');
		$this->service->requestAbsenceCorrection(
			42,
			['reason' => 'valid reason', 'expectedUpdatedAt' => '2020-01-01T00:00:00+00:00'],
			'employee1'
		);
	}

	public function testRequestCorrectionOperationKeyRequired(): void
	{
		$absence = $this->completedApprovedAbsence();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$this->expectExceptionMessage('operation_required');
		$this->service->requestAbsenceCorrection(
			42,
			['reason' => 'valid reason', 'expectedUpdatedAt' => $absence->getUpdatedAt()->format('c')],
			'employee1'
		);
	}

	public function testRequestCorrectionOperationKeyMustBeUuid(): void
	{
		$absence = $this->completedApprovedAbsence();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$data = $this->validRequestData($absence);
		$data['operation_key'] = 'not-a-uuid';
		$this->expectExceptionMessage('operation_required');
		$this->service->requestAbsenceCorrection(42, $data, 'employee1');
	}

	public function testRequestCorrectionReplayReturnsStoredReceipt(): void
	{
		$absence = $this->completedApprovedAbsence();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$data = $this->validRequestData($absence);

		$receipt = new AuditLog();
		$receipt->setPerformedBy('employee1');
		// Digest must match correctionRequestDigest('correction_request', …)
		$digest = hash('sha256', (string)json_encode([
			'action' => 'correction_request',
			'target' => 42,
			'actor' => 'employee1',
			'reason' => 'Was recorded by mistake',
			'comment' => '',
			'expected' => $absence->getUpdatedAt()->format('c'),
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		$receipt->setRequestDigest($digest);
		$receipt->setNewValues((string)json_encode(['id' => 42, 'status' => 'approved']));
		$this->auditLogMapper->method('findByOperationKey')->willReturn($receipt);

		$result = $this->service->requestAbsenceCorrection(42, $data, 'employee1');
		$this->assertIsArray($result);
		$this->assertTrue($result['replayed']);
		$this->assertSame(42, $result['absence']['id']);
	}

	public function testRequestCorrectionReplayKeyReuseConflict(): void
	{
		$absence = $this->completedApprovedAbsence();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$data = $this->validRequestData($absence);

		$receipt = new AuditLog();
		$receipt->setPerformedBy('other-actor');
		$receipt->setRequestDigest('different-digest');
		$this->auditLogMapper->method('findByOperationKey')->willReturn($receipt);

		$this->expectExceptionMessage('operation_conflict');
		$this->service->requestAbsenceCorrection(42, $data, 'employee1');
	}

	public function testRequestCorrectionSuccess(): void
	{
		$absence = $this->completedApprovedAbsence();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnArgument(0);
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$this->auditLogMapper->method('findByOperationKey')
			->willThrowException(new DoesNotExistException('no receipt'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with(
				'employee1',
				'absence_correction_requested',
				'absence',
				42,
				$this->isType('array'),
				$this->isType('array'),
				'employee1',
				self::UUID,
				$this->isType('string')
			);

		$result = $this->service->requestAbsenceCorrection(42, $this->validRequestData($absence), 'employee1');
		$this->assertInstanceOf(Absence::class, $result);
		$this->assertNotNull($result->getCancelRequestedAt());
		$this->assertSame('employee1', $result->getCancelRequestedBy());
		$this->assertSame('Was recorded by mistake', $result->getCancelReason());
	}

	// ------------------------------------------------------------------
	// withdrawAbsenceCorrectionRequest
	// ------------------------------------------------------------------

	public function testWithdrawWithoutOpenRequestRejected(): void
	{
		$absence = $this->completedApprovedAbsence();
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->expectExceptionMessage('no_cancel_request');
		$this->service->withdrawAbsenceCorrectionRequest(42, [], 'employee1');
	}

	public function testWithdrawSuccess(): void
	{
		$absence = $this->completedApprovedAbsence();
		$absence->setCancelRequestedAt(new \DateTime('-1 day'));
		$absence->setCancelRequestedBy('employee1');
		$absence->setCancelReason('mistake');
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->absenceMapper->method('update')->willReturnArgument(0);
		$this->auditLogMapper->method('findByOperationKey')
			->willThrowException(new DoesNotExistException('no receipt'));
		$this->db->expects($this->once())->method('commit');
		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with('employee1', 'absence_correction_withdrawn', 'absence', 42);

		$result = $this->service->withdrawAbsenceCorrectionRequest(
			42,
			[
				'expectedUpdatedAt' => $absence->getUpdatedAt()->format('c'),
				'operation_key' => self::UUID,
			],
			'employee1'
		);
		$this->assertInstanceOf(Absence::class, $result);
		$this->assertNull($result->getCancelRequestedAt());
		$this->assertNull($result->getCancelRequestedBy());
	}

	// ------------------------------------------------------------------
	// approve / decline / direct correct — authorization
	// ------------------------------------------------------------------

	private function absenceWithOpenRequest(): Absence
	{
		$absence = $this->completedApprovedAbsence();
		$absence->setCancelRequestedAt(new \DateTime('-1 day'));
		$absence->setCancelRequestedBy('employee1');
		$absence->setCancelReason('mistake');
		return $absence;
	}

	public function testApproveSelfApprovalForbiddenEvenForOwner(): void
	{
		$this->absenceMapper->method('find')->willReturn($this->absenceWithOpenRequest());
		$this->workforcePolicyService->method('isPastCorrectionEnabled')->willReturn(true);
		// AC-37: owner == requester -> banned before any permission check.
		$this->expectExceptionMessage('self_approval_forbidden');
		$this->service->approveAbsenceCorrectionRequest(42, [], 'employee1');
	}

	public function testApproveUnauthorizedActorGetsUniform404(): void
	{
		$this->absenceMapper->method('find')->willReturn($this->absenceWithOpenRequest());
		$this->workforcePolicyService->method('isPastCorrectionEnabled')->willReturn(true);
		$this->workforcePolicyService->method('isScopedManagerCorrectionEnabled')->willReturn(false);
		$permission = $this->createMock(PermissionService::class);
		$permission->method('isAdmin')->willReturn(false);
		$permission->method('canDecideEntryApproval')->willReturn(true);
		$this->injectLazy('correctionPermissionService', $permission);
		$this->expectException(DoesNotExistException::class);
		$this->service->approveAbsenceCorrectionRequest(42, [], 'intruder');
	}

	public function testApproveScopedManagerPassesAuthorization(): void
	{
		$this->absenceMapper->method('find')->willReturn($this->absenceWithOpenRequest());
		$this->workforcePolicyService->method('isPastCorrectionEnabled')->willReturn(true);
		$this->workforcePolicyService->method('isScopedManagerCorrectionEnabled')->willReturn(true);
		$permission = $this->createMock(PermissionService::class);
		$permission->method('isAdmin')->willReturn(false);
		$permission->method('canDecideEntryApproval')->with('manager1', 'employee1')->willReturn(true);
		$this->injectLazy('correctionPermissionService', $permission);
		// Authorization passed; fail fast at the next gate (missing CAS).
		$this->expectExceptionMessage('revision_stale');
		$this->service->approveAbsenceCorrectionRequest(42, ['reason' => 'x'], 'manager1');
	}

	public function testApproveNoOpenRequestRejected(): void
	{
		$this->absenceMapper->method('find')->willReturn($this->completedApprovedAbsence());
		$this->expectExceptionMessage('no_cancel_request');
		$this->service->approveAbsenceCorrectionRequest(42, [], 'manager1');
	}

	public function testManagerCorrectFeatureDisabled(): void
	{
		$this->absenceMapper->method('find')->willReturn($this->completedApprovedAbsence());
		$this->workforcePolicyService->method('isPastCorrectionEnabled')->willReturn(false);
		$this->expectExceptionMessage('feature_disabled');
		$this->service->managerCorrectCompletedAbsence(42, [], 'manager1');
	}

	public function testManagerCorrectSelfBanAppliesToAdminsToo(): void
	{
		$this->absenceMapper->method('find')->willReturn($this->completedApprovedAbsence());
		$this->workforcePolicyService->method('isPastCorrectionEnabled')->willReturn(true);
		// Self-correction is banned even for admins — the permission service
		// is never consulted (throws first).
		$this->expectExceptionMessage('self_approval_forbidden');
		$this->service->managerCorrectCompletedAbsence(42, [], 'employee1');
	}

	public function testManagerCorrectRunningAbsenceRejected(): void
	{
		$absence = $this->completedApprovedAbsence();
		$absence->setEndDate(new \DateTime('+1 day'));
		$this->absenceMapper->method('find')->willReturn($absence);
		$this->workforcePolicyService->method('isPastCorrectionEnabled')->willReturn(true);
		$this->expectExceptionMessage('not_completed');
		$this->service->managerCorrectCompletedAbsence(42, [], 'manager1');
	}

	public function testManagerCorrectRequiresReason(): void
	{
		$this->absenceMapper->method('find')->willReturn($this->completedApprovedAbsence());
		$this->workforcePolicyService->method('isPastCorrectionEnabled')->willReturn(true);
		$permission = $this->createMock(PermissionService::class);
		$permission->method('isAdmin')->willReturn(true);
		$this->injectLazy('correctionPermissionService', $permission);
		$this->expectExceptionMessage('reason_required');
		$this->service->managerCorrectCompletedAbsence(42, ['reason' => ''], 'manager1');
	}

	// ------------------------------------------------------------------
	// Action flags (ADR-04 — clients render straight from server truth)
	// ------------------------------------------------------------------

	public function testOwnerFlagsCanRequestCorrection(): void
	{
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$flags = $this->service->ownerAbsenceActionFlags($this->completedApprovedAbsence());
		$this->assertTrue($flags['canRequestCorrection']);
		$this->assertFalse($flags['canRequestCancel']);
	}

	public function testOwnerFlagsCorrectionGatedByPolicy(): void
	{
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(false);
		$flags = $this->service->ownerAbsenceActionFlags($this->completedApprovedAbsence());
		$this->assertFalse($flags['canRequestCorrection']);
	}

	public function testOwnerFlagsCorrectionHiddenWithOpenRequest(): void
	{
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$absence = $this->absenceWithOpenRequest();
		$flags = $this->service->ownerAbsenceActionFlags($absence);
		$this->assertFalse($flags['canRequestCorrection']);
	}

	public function testOwnerFlagsCorrectionHiddenForRunning(): void
	{
		$this->workforcePolicyService->method('isEmployeeCorrectionRequestEnabled')->willReturn(true);
		$absence = $this->completedApprovedAbsence();
		$absence->setStartDate(new \DateTime('-2 days'));
		$absence->setEndDate(new \DateTime('+2 days'));
		$flags = $this->service->ownerAbsenceActionFlags($absence);
		$this->assertFalse($flags['canRequestCorrection']);
		$this->assertTrue($flags['canRequestCancel']);
	}

	public function testManagerFlagsCorrectionSplitFromCancel(): void
	{
		$this->workforcePolicyService->method('isPastCorrectionEnabled')->willReturn(true);
		$flags = $this->service->managerAbsenceActionFlags($this->absenceWithOpenRequest());
		// Open request on a COMPLETED absence is a correction decision,
		// never a cancel decision (the cancel path would dead-end).
		$this->assertTrue($flags['canDecideCorrectionRequest']);
		$this->assertFalse($flags['canDecideCancelRequest']);
		$this->assertTrue($flags['canManagerCorrect']);
	}

	public function testManagerFlagsCorrectionGatedByPolicy(): void
	{
		$this->workforcePolicyService->method('isPastCorrectionEnabled')->willReturn(false);
		$flags = $this->service->managerAbsenceActionFlags($this->absenceWithOpenRequest());
		$this->assertFalse($flags['canDecideCorrectionRequest']);
		$this->assertFalse($flags['canManagerCorrect']);
	}

	public function testManagerFlagsRunningAbsenceKeepsCancelDecide(): void
	{
		$this->workforcePolicyService->method('isPastCorrectionEnabled')->willReturn(true);
		$absence = $this->completedApprovedAbsence();
		$absence->setStartDate(new \DateTime('-2 days'));
		$absence->setEndDate(new \DateTime('+2 days'));
		$absence->setCancelRequestedAt(new \DateTime('-1 day'));
		$absence->setCancelRequestedBy('employee1');
		$flags = $this->service->managerAbsenceActionFlags($absence);
		$this->assertTrue($flags['canDecideCancelRequest']);
		$this->assertFalse($flags['canDecideCorrectionRequest']);
		$this->assertFalse($flags['canManagerCorrect']);
	}

	// ------------------------------------------------------------------
	// Hour-mode requests (§9.2) — validation via createAbsence
	// ------------------------------------------------------------------

	private function hourRequest(array $overrides = []): array
	{
		$tomorrow = (new \DateTime('+1 day'))->format('Y-m-d');
		return array_merge([
			'type' => Absence::TYPE_UNPAID_LEAVE,
			'start_date' => $tomorrow,
			'end_date' => $tomorrow,
			'duration_mode' => 'hours',
			'duration_minutes' => 120,
			'reason' => 'Dentist',
		], $overrides);
	}

	private function stubPlannedHours(float $hours): void
	{
		$planned = $this->createMock(PaidAbsencePlannedHoursCreditService::class);
		$planned->method('plannedNetHoursForDay')->willReturn($hours);
		$this->injectLazy('paidAbsencePlannedHoursService', $planned);
	}

	private function assertHourlyError(string $code, callable $fn): void
	{
		try {
			$fn();
			$this->fail('Expected BusinessRuleException ' . $code);
		} catch (\OCA\ArbeitszeitCheck\Exception\BusinessRuleException $e) {
			$this->assertSame($code, $e->getReasonCode());
		}
	}

	public function testHourModeRejectsNonHourlyType(): void
	{
		$this->stubPlannedHours(8.0);
		$this->assertHourlyError('HOURLY_SHAPE_CONFLICT', fn () =>
			$this->service->createAbsence($this->hourRequest(['type' => Absence::TYPE_SICK_LEAVE]), 'employee1'));
	}

	public function testHourModeRejectsDisabledType(): void
	{
		$this->workforcePolicyService->method('isHourlyAbsenceTypeEnabled')->willReturn(false);
		$this->assertHourlyError('FEATURE_DISABLED', fn () =>
			$this->service->createAbsence($this->hourRequest(), 'employee1'));
	}

	public function testHourModeRejectsMultiDay(): void
	{
		$this->workforcePolicyService->method('isHourlyAbsenceTypeEnabled')->willReturn(true);
		$this->stubPlannedHours(8.0);
		$this->assertHourlyError('HOURLY_SINGLE_DATE_REQUIRED', fn () =>
			$this->service->createAbsence($this->hourRequest([
				'end_date' => (new \DateTime('+2 days'))->format('Y-m-d'),
			]), 'employee1'));
	}

	public function testHourModeRejectsDayShapeFields(): void
	{
		$this->workforcePolicyService->method('isHourlyAbsenceTypeEnabled')->willReturn(true);
		$this->stubPlannedHours(8.0);
		$this->assertHourlyError('HOURLY_SHAPE_CONFLICT', fn () =>
			$this->service->createAbsence($this->hourRequest(['day_fraction' => '0.5']), 'employee1'));
	}

	public function testHourModeRejectsMissingMinutes(): void
	{
		$this->workforcePolicyService->method('isHourlyAbsenceTypeEnabled')->willReturn(true);
		$this->stubPlannedHours(8.0);
		$data = $this->hourRequest();
		unset($data['duration_minutes']);
		$this->assertHourlyError('HOURLY_DURATION_INVALID', fn () =>
			$this->service->createAbsence($data, 'employee1'));
	}

	public function testHourModeRejectsOutOfRangeMinutes(): void
	{
		$this->workforcePolicyService->method('isHourlyAbsenceTypeEnabled')->willReturn(true);
		$this->stubPlannedHours(8.0);
		foreach ([0, -5, 1441, 'abc', '12.5'] as $bad) {
			$this->assertHourlyError('HOURLY_DURATION_INVALID', fn () =>
				$this->service->createAbsence($this->hourRequest(['duration_minutes' => $bad]), 'employee1'));
		}
	}

	public function testHourModeRejectsZeroPlannedTime(): void
	{
		$this->workforcePolicyService->method('isHourlyAbsenceTypeEnabled')->willReturn(true);
		$this->stubPlannedHours(0.0);
		$this->assertHourlyError('NO_PLANNED_TIME', fn () =>
			$this->service->createAbsence($this->hourRequest(), 'employee1'));
	}

	public function testHourModeRejectsOrphanedModeFields(): void
	{
		// duration_minutes without duration_mode='hours' must not silently
		// morph into a day-mode request.
		$data = $this->hourRequest();
		unset($data['duration_mode']);
		$this->assertHourlyError('HOURLY_SHAPE_CONFLICT', fn () =>
			$this->service->createAbsence($data, 'employee1'));
	}

	public function testHourModeCreateSuccess(): void
	{
		$this->workforcePolicyService->method('isHourlyAbsenceTypeEnabled')
			->with(Absence::TYPE_UNPAID_LEAVE)->willReturn(true);
		$this->stubPlannedHours(8.0); // 480 planned minutes
		$this->teamResolver->method('hasAssignableManagerForEmployee')->willReturn(true);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->absenceMapper->method('getSickLeaveDays')->willReturn(0.0);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(1.0);
		$this->absenceMapper->expects($this->once())->method('lockUserAbsenceWindow');
		$inserted = null;
		$this->absenceMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(static function (Absence $a) use (&$inserted) {
				$a->setId(500);
				$inserted = $a;
				return $a;
			});
		$this->auditLogMapper->expects($this->once())->method('logAction');
		$this->db->expects($this->once())->method('commit');

		$result = $this->service->createAbsence($this->hourRequest(), 'employee1');
		$this->assertSame('hours', $inserted->getDurationMode());
		$this->assertSame(120, $inserted->getDurationMinutes());
		// 120 of 480 planned minutes = 0.25 share × day weight 1.0
		$this->assertSame(0.25, $inserted->getDays());
		$this->assertInstanceOf(Absence::class, $result);
	}

	public function testHourModeFullDayMinutesDebit(): void
	{
		$this->workforcePolicyService->method('isHourlyAbsenceTypeEnabled')->willReturn(true);
		$this->stubPlannedHours(8.0);
		$this->teamResolver->method('hasAssignableManagerForEmployee')->willReturn(true);
		$this->absenceMapper->method('findOverlapping')->willReturn([]);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->userSettingsMapper->method('getIntegerSetting')->willReturn(25);
		$this->holidayCalendarService->method('computeWorkingDaysForUser')->willReturn(1.0);
		$this->absenceMapper->method('lockUserAbsenceWindow');
		$inserted = null;
		$this->absenceMapper->method('insert')->willReturnCallback(static function (Absence $a) use (&$inserted) {
			$inserted = $a;
			return $a;
		});
		$this->auditLogMapper->method('logAction');

		// 1440 minutes (boundary) on an 8h day → clamped full-day share.
		$this->service->createAbsence($this->hourRequest(['duration_minutes' => 1440]), 'employee1');
		$this->assertSame(1440, $inserted->getDurationMinutes());
		$this->assertSame(1.0, $inserted->getDays());
	}
}
