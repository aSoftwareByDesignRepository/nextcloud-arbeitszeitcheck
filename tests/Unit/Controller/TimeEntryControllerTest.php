<?php

declare(strict_types=1);

/**
 * Unit tests for TimeEntryController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Controller\TimeEntryController;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\MonthClosureGuard;
use OCA\ArbeitszeitCheck\Service\TimeEntryDeletionPolicy;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IDateTimeZone;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Class TimeEntryControllerTest
 */
class TimeEntryControllerTest extends TestCase
{
	/** @var TimeEntryController */
	private $controller;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var IUserSession|\PHPUnit\Framework\MockObject\MockObject */
	private $userSession;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;

	/** @var OvertimeService|\PHPUnit\Framework\MockObject\MockObject */
	private $overtimeService;

	/** @var IURLGenerator|\PHPUnit\Framework\MockObject\MockObject */
	private $urlGenerator;

	/** @var IL10N|\PHPUnit\Framework\MockObject\MockObject */
	private $l10n;

	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var IConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var CSPService|\PHPUnit\Framework\MockObject\MockObject */
	private $cspService;

	/** @var ComplianceService|\PHPUnit\Framework\MockObject\MockObject */
	private $complianceService;

	/** @var TimeTrackingService|\PHPUnit\Framework\MockObject\MockObject */
	private $timeTrackingService;

	/** @var TeamResolverService|\PHPUnit\Framework\MockObject\MockObject */
	private $teamResolver;

	/** @var NotificationService|\PHPUnit\Framework\MockObject\MockObject */
	private $notificationService;

	/** @var MonthClosureGuard|\PHPUnit\Framework\MockObject\MockObject */
	private $monthClosureGuard;

	/** @var AbsenceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $absenceMapper;

	/** @var PermissionService|\PHPUnit\Framework\MockObject\MockObject */
	private $permissionService;

	/** @var TimeCaptureMethodService|\PHPUnit\Framework\MockObject\MockObject */
	private $timeCaptureMethodService;

	/** @var TimeEntryCorrectionService|\PHPUnit\Framework\MockObject\MockObject */
	private $correctionService;

	/** @var array<string, string> */
	private $appConfigValues = [];

	private bool $hasAssignableManager = false;

	/** @var array<int, TimeEntry> */
	private array $overlappingEntries = [];

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->request = $this->createMock(IRequest::class);
		$this->overtimeService = $this->createMock(OvertimeService::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(static fn ($s, $p = []) => $p ? (string)vsprintf($s, $p) : $s);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->auditLogMapper->method('logAction')->willReturn(new \OCA\ArbeitszeitCheck\Db\AuditLog());
		$this->config = $this->createMock(IConfig::class);
		$this->cspService = $this->createMock(CSPService::class);
		$this->cspService->method('applyPolicyWithNonce')->willReturnCallback(static fn ($r) => $r);
		$this->complianceService = $this->createMock(ComplianceService::class);
		$this->complianceService->method('checkRestPeriodForStartTime')->willReturn([
			'valid' => true,
			'message' => '',
		]);
		$this->timeTrackingService = $this->createMock(TimeTrackingService::class);
		$this->timeTrackingService->method('calculateAndSetAutomaticBreak');
		$this->timeTrackingService->method('adjustEndTimeForDailyMaximum');
		$this->timeTrackingService->method('withUserMutationLock')->willReturnCallback(
			static function (string $userId, callable $fn) {
				unset($userId);
				return $fn();
			}
		);
		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->teamResolver->method('getColleagueIds')->willReturn([]);
		$this->teamResolver->method('hasAssignableManagerForEmployee')->willReturnCallback(
			fn () => $this->hasAssignableManager
		);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->monthClosureGuard = $this->createMock(MonthClosureGuard::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->absenceMapper->method('findSubstitutePendingForUser')->willReturn([]);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->permissionService->method('canAccessManagerDashboard')->willReturn(false);
		$this->permissionService->method('isAdmin')->willReturn(false);

		$this->timeEntryMapper->method('findOverlapping')->willReturnCallback(
			fn () => $this->overlappingEntries
		);

		$tzConfig = $this->createMock(IConfig::class);
		$tzConfig->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default) => match ($key) {
			'app_timezone' => 'Europe/Berlin',
			default => $default,
		});
		$tzDateTimeZone = $this->createMock(IDateTimeZone::class);
		$tzDateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$tzUserSession = $this->createMock(IUserSession::class);
		$tzUserSession->method('getUser')->willReturn(null);
		$this->timeZoneService = new TimeZoneService($tzConfig, $tzDateTimeZone, $tzUserSession, new NullLogger());
		$timeZoneService = $this->timeZoneService;

		$overtimeBankService = $this->createMock(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class);
		$overtimeBankService->method('isEnabled')->willReturn(false);
		$overtimeBankService->method('getBankStatus')->willReturn(['enabled' => false]);

		$this->localeFormat = $this->createMock(LocaleFormatService::class);
		$this->localeFormat->method('clientHints')->willReturn([]);
		$localeFormat = $this->localeFormat;
		$this->navigationFlags = new NavigationFlagsService(
			$this->absenceMapper,
			$this->permissionService,
			$this->config
		);
		$navigationFlags = $this->navigationFlags;

		$this->projectCheckIntegration = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class);
		$this->projectCheckIntegration->method('isProjectCheckAvailable')->willReturn(false);
		$this->projectCheckIntegration->method('getAvailableProjects')->willReturn([]);
		$this->projectCheckIntegration->method('userMayAttachProjectCheckProjectToOwnTime')->willReturn(true);
		$projectCheckIntegration = $this->projectCheckIntegration;

		$this->timeCaptureMethodService = $this->createMock(TimeCaptureMethodService::class);
		$this->timeCaptureMethodService->method('getSettings')->willReturn([
			'clockStampingEnabled' => true,
			'manualTimeEntryEnabled' => true,
		]);
		$this->timeCaptureMethodService->method('isManualTimeEntryEnabled')->willReturn(true);

		$this->config->method('getAppValue')->willReturnCallback(
			fn ($app, $key, $default) => $this->appConfigValues[$key] ?? $default
		);

		$deletionPolicy = new TimeEntryDeletionPolicy(
			$this->config,
			$this->monthClosureGuard,
			$this->l10n,
		);

		$this->db = $this->createMock(\OCP\IDBConnection::class);

		$this->correctionService = $this->createMock(TimeEntryCorrectionService::class);
		$this->correctionService->method('prepareManualPending')->willReturnCallback(
			static function (TimeEntry $entry, string $text): void {
				$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
				$entry->setJustification(json_encode([
					'type' => 'manual_create',
					'justification' => $text,
				]));
			}
		);
		$this->correctionService->method('autoApprove')->willReturnCallback(
			static function (TimeEntry $entry): TimeEntry {
				$entry->setStatus(TimeEntry::STATUS_COMPLETED);
				return $entry;
			}
		);

		$this->controller = new TimeEntryController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->userSession,
			$this->overtimeService,
			$overtimeBankService,
			$this->urlGenerator,
			$this->l10n,
			$this->auditLogMapper,
			$this->config,
			$this->cspService,
			$this->complianceService,
			$this->timeTrackingService,
			$this->teamResolver,
			$this->notificationService,
			$this->monthClosureGuard,
			$this->absenceMapper,
			$this->permissionService,
			$timeZoneService,
			$this->correctionService,
			$localeFormat,
			$navigationFlags,
			$projectCheckIntegration,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
			$this->timeCaptureMethodService,
			$deletionPolicy,
			$this->db,
		);
	}

	/**
	 * Test index returns time entries with filters
	 */
	public function testIndexReturnsEntries(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->once())
			->method('count')
			->willReturn(1);

		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$entry]);

		$response = $this->controller->index('2024-01-01', '2024-01-31');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('entries', $data);
		$this->assertEquals(1, $data['total']);
	}

	/**
	 * Test index applies status filter
	 */
	public function testIndexAppliesStatusFilter(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$completedEntry = new TimeEntry();
		$completedEntry->setId(1);
		$completedEntry->setUserId($userId);
		$completedEntry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$completedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$completedEntry->setIsManualEntry(false);
		$completedEntry->setCreatedAt(new \DateTime());
		$completedEntry->setUpdatedAt(new \DateTime());

		$activeEntry = new TimeEntry();
		$activeEntry->setId(2);
		$activeEntry->setUserId($userId);
		$activeEntry->setStartTime(new \DateTime('2024-01-16 09:00:00'));
		$activeEntry->setStatus(TimeEntry::STATUS_ACTIVE);
		$activeEntry->setIsManualEntry(false);
		$activeEntry->setCreatedAt(new \DateTime());
		$activeEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('count')->willReturn(1);
		$this->timeEntryMapper->method('findByUser')
			->willReturn([$completedEntry]);

		$response = $this->controller->index(null, null, 'completed');
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['entries']); // Only completed entry
	}

	/**
	 * Test index applies pagination
	 */
	public function testIndexAppliesPagination(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entries = [];
		for ($i = 1; $i <= 10; $i++) {
			$entry = new TimeEntry();
			$entry->setId($i);
			$entry->setUserId($userId);
			$entry->setStartTime(new \DateTime('2024-01-01 09:00:00'));
			$entry->setStatus(TimeEntry::STATUS_COMPLETED);
			$entry->setIsManualEntry(false);
			$entry->setCreatedAt(new \DateTime());
			$entry->setUpdatedAt(new \DateTime());
			$entries[] = $entry;
		}

		$this->timeEntryMapper->method('count')->willReturn(10);
		$this->timeEntryMapper->method('findByUser')
			->willReturn($entries);

		$response = $this->controller->index(null, null, null, 5, 0);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertCount(5, $data['entries']); // Limited to 5
	}

	/**
	 * Test show returns entry when user owns it
	 */
	public function testShowReturnsEntryWhenOwned(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->once())
			->method('find')
			->with($entryId)
			->willReturn($entry);

		$response = $this->controller->show($entryId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('entry', $data);
	}

	/**
	 * Test show returns forbidden when user doesn't own entry
	 */
	public function testShowReturnsForbiddenWhenNotOwned(): void
	{
		$userId = 'testuser';
		$otherUserId = 'otheruser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($otherUserId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->show($entryId);

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Access denied', $data['error']);
	}

	/**
	 * Test store creates manual time entry
	 */
	public function testStoreCreatesManualEntry(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$savedEntry = $this->createMock(TimeEntry::class);
		$savedEntry->method('getSummary')->willReturn(['id' => 1]);

		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function ($entry) use ($userId) {
				return $entry instanceof TimeEntry
					&& $entry->getUserId() === $userId
					&& $entry->getIsManualEntry() === true
					&& $entry->getStatus() === TimeEntry::STATUS_COMPLETED;
			}))
			->willReturn($savedEntry);

		$response = $this->controller->store('2024-01-15', 8.0, 'Work description');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('entry', $data);
	}

	/**
	 * Test store calculates end time from hours
	 */
	public function testStoreCalculatesEndTimeFromHours(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$savedEntry = $this->createMock(TimeEntry::class);
		$savedEntry->method('getSummary')->willReturn(['id' => 1]);

		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function ($entry) {
				$startTime = $entry->getStartTime();
				$endTime = $entry->getEndTime();
				if (!$startTime || !$endTime) {
					return false;
				}
				// End time should be 8 hours after start time (9:00 + 8 hours = 17:00)
				$diff = $endTime->getTimestamp() - $startTime->getTimestamp();
				return abs($diff - (8 * 3600)) < 60; // Allow 1 minute tolerance
			}))
			->willReturn($savedEntry);

		$response = $this->controller->store('2024-01-15', 8.0);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test update modifies entry when user owns it
	 */
	public function testUpdateModifiesEntryWhenOwned(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setIsManualEntry(true);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setJustification('Initial manual entry justification');
		$entryStart = (new \DateTime())->modify('-1 day')->setTime(9, 0, 0);
		$entryEnd = (clone $entryStart)->modify('+8 hours');
		$entry->setStartTime($entryStart);
		$entry->setEndTime($entryEnd);
		$entry->setDescription('Original description');
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$updatedEntry = new TimeEntry();
		$updatedEntry->setId($entryId);
		$updatedEntry->setUserId($userId);
		$updatedEntry->setIsManualEntry(true);
		$updatedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$updatedEntry->setJustification('Initial manual entry justification');
		$updatedStart = (new \DateTime())->modify('-1 day')->setTime(9, 0, 0);
		$updatedEnd = (clone $updatedStart)->modify('+7 hours 30 minutes');
		$updatedEntry->setStartTime($updatedStart);
		$updatedEntry->setEndTime($updatedEnd);
		$updatedEntry->setDescription('Updated description');
		$updatedEntry->setCreatedAt(new \DateTime());
		$updatedEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->request->method('getParams')->willReturn([
			'justification' => 'Fix manual entry',
		]);
		$this->timeEntryMapper->expects($this->once())
			->method('update')
			->willReturn($updatedEntry);

		$response = $this->controller->update($entryId, $updatedStart->format('Y-m-d'), 7.5, 'Updated description');
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Unexpected response: ' . json_encode($data));
		$this->assertArrayHasKey('entry', $data);
	}


	/**
	 * Build an owned, editable entry (completed, within the 14-day window).
	 */
	private function editableEntry(string $userId = 'testuser', ?string $status = null): TimeEntry
	{
		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId($userId);
		$entry->setIsManualEntry(true);
		$entry->setStatus($status ?? TimeEntry::STATUS_COMPLETED);
		$entry->setJustification('justification');
		$entry->setStartTime((new \DateTime())->modify('-1 day')->setTime(9, 0, 0));
		$entry->setEndTime((new \DateTime())->modify('-1 day')->setTime(17, 0, 0));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());
		return $entry;
	}

	private function signIn(string $userId = 'testuser'): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testUpdateReturnsConflictWhenMonthFinalized(): void
	{
		$this->signIn();
		$this->timeEntryMapper->method('find')->willReturn($this->editableEntry());
		$this->monthClosureGuard->method('assertTimeEntryMutable')
			->willThrowException(new \OCA\ArbeitszeitCheck\Exception\MonthFinalizedException('finalized'));

		$r = $this->controller->update(1, '2026-03-01', 8.0);
		$this->assertSame(Http::STATUS_CONFLICT, $r->getStatus());
		$this->assertSame('month_finalized', $r->getData()['error_code']);
	}

	public function testUpdateRejectedWhenEntryLockedForEmployeeEdit(): void
	{
		$this->signIn();
		$entry = $this->editableEntry();
		$entry->setApprovedByUserId('manager1');
		$entry->setApprovedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->expects($this->never())->method('update');

		$r = $this->controller->update(1, '2026-03-01', 8.0);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		$this->assertStringContainsString('Request Correction', $r->getData()['error']);
	}

	public function testUpdatePausedEntryWithOnlyEndTimeAllowedWhenApprovalRequired(): void
	{
		$this->signIn();
		$this->appConfigValues[Constants::CONFIG_TIME_ENTRY_CHANGES_REQUIRE_APPROVAL] = '1';
		$entry = $this->editableEntry('testuser', TimeEntry::STATUS_PAUSED);
		$entry->setEndTime(null);
		$this->timeEntryMapper->method('find')->willReturn($entry);
		// Completion-only payload: only the allowed keys pass the gate.
		$this->request->method('getParams')->willReturn([
			'endTime' => '17:00',
		]);
		$this->timeEntryMapper->expects($this->once())->method('update')
			->willReturnCallback(static fn (TimeEntry $e) => $e);

		$r = $this->controller->update(1);
		$data = $r->getData();
		$this->assertTrue($data['success'], 'Unexpected: ' . json_encode($data));
		// NOTE: endTime alone is gate-allowed but not written by update()
		// (the write branch requires startTime+endTime together). The entry
		// stays paused — one-click finalisation lives in complete().
		$this->assertSame(TimeEntry::STATUS_PAUSED, $entry->getStatus());
	}

	public function testUpdatePausedEntryWithDescriptionForbiddenWhenApprovalRequired(): void
	{
		$this->signIn();
		$this->appConfigValues[Constants::CONFIG_TIME_ENTRY_CHANGES_REQUIRE_APPROVAL] = '1';
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/arbeitszeitcheck/timeentries');
		$entry = $this->editableEntry('testuser', TimeEntry::STATUS_PAUSED);
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->request->method('getParams')->willReturn([
			'date' => (new \DateTime())->modify('-1 day')->format('Y-m-d'),
			'startTime' => '09:00',
			'endTime' => '17:00',
			'description' => 'changed', // non-completion field -> not a completion-only update
		]);
		$this->timeEntryMapper->expects($this->never())->method('update');

		$r = $this->controller->update(1);
		$this->assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
		$this->assertSame('correction_required', $r->getData()['error_code']);
		$this->assertSame('/apps/arbeitszeitcheck/timeentries', $r->getData()['correction_url']);
	}

	public function testUpdateRejectsTooLongProjectCheckId(): void
	{
		$this->signIn();
		$this->timeEntryMapper->method('find')->willReturn($this->editableEntry());
		$this->request->method('getParams')->willReturn([]);
		$this->timeEntryMapper->expects($this->never())->method('update');

		$r = $this->controller->update(1, null, null, null, str_repeat('x', TimeEntry::PROJECT_CHECK_PROJECT_ID_MAX_LENGTH + 1));
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
	}

	public function testUpdateRejectsProjectCheckAttachWhenForbidden(): void
	{
		$this->signIn();
		$this->timeEntryMapper->method('find')->willReturn($this->editableEntry());

		// rebuild controller seam: projectCheck denies the attach
		$pc = (function (): void {
			$rp = new \ReflectionProperty($this->controller, 'projectCheckIntegration');
			$rp->setAccessible(true);
			$mock = $this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService::class);
			$mock->method('userMayAttachProjectCheckProjectToOwnTime')->willReturn(false);
			$rp->setValue($this->controller, $mock);
		})();
		unset($pc);
		$this->request->method('getParams')->willReturn([
			'project_check_project_id' => '42',
		]);
		$this->timeEntryMapper->expects($this->never())->method('update');

		$r = $this->controller->update(1, '2026-03-01', 8.0);
		$this->assertSame(Http::STATUS_FORBIDDEN, $r->getStatus());
		$this->assertFalse($r->getData()['success']);
	}

	public function testUpdateRejectsOverlapWithLocalizedClockTimes(): void
	{
		$this->signIn();
		$this->timeEntryMapper->method('find')->willReturn($this->editableEntry());
		$this->request->method('getParams')->willReturn([]);

		$other = new TimeEntry();
		$other->setId(2);
		$other->setUserId('testuser');
		$other->setStartTime((new \DateTime())->modify('-1 day')->setTime(10, 0, 0));
		$other->setEndTime((new \DateTime())->modify('-1 day')->setTime(12, 0, 0));
		$this->overlappingEntries = [$other];
		$this->timeEntryMapper->expects($this->never())->method('update');

		$r = $this->controller->update(1, '2026-03-01', 8.0);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		$this->assertStringContainsString('overlaps', $r->getData()['error']);
		// displayClock rendered HH:MM for both ends
		$this->assertMatchesRegularExpression('/\d{2}:\d{2} - \d{2}:\d{2}/', $r->getData()['error']);
	}

	public function testUpdateAppliesBreaksJsonAndClearsSingleBreakFields(): void
	{
		$this->signIn();
		$entry = $this->editableEntry();
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$yesterday = (new \DateTime())->modify('-1 day')->format('Y-m-d');
		$this->request->method('getParams')->willReturn([
			'date' => $yesterday,
			'startTime' => '09:00',
			'endTime' => '17:30',
			// 30-minute break passes the DE 15-min floor; 5-minute break is dropped
			'breaks' => json_encode([
				['start' => '12:00', 'end' => '12:30'],
				['start' => '15:00', 'end' => '15:05'],
			]),
		]);
		$this->timeEntryMapper->expects($this->once())->method('update')
			->willReturnCallback(static fn (TimeEntry $e) => $e);

		$r = $this->controller->update(1);
		$data = $r->getData();
		$this->assertTrue($data['success'], 'Unexpected: ' . json_encode($data));
		$breaks = json_decode((string)$entry->getBreaks(), true);
		$this->assertCount(1, $breaks, 'short break must be filtered by the countable floor');
		$this->assertNull($entry->getBreakStartTime());
		$this->assertNull($entry->getBreakEndTime());
	}

	public function testUpdateOvernightShiftRollsEndToNextDay(): void
	{
		$this->signIn();
		$entry = $this->editableEntry();
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$yesterday = (new \DateTime())->modify('-1 day')->format('Y-m-d');
		$this->request->method('getParams')->willReturn([
			'date' => $yesterday,
			'startTime' => '22:00',
			'endTime' => '02:00',
		]);
		$this->timeEntryMapper->expects($this->once())->method('update')
			->willReturnCallback(static fn (TimeEntry $e) => $e);

		$r = $this->controller->update(1);
		$data = $r->getData();
		$this->assertTrue($data['success'], 'Unexpected: ' . json_encode($data));
		$this->assertSame('22:00', $entry->getStartTime()->format('H:i'));
		$this->assertSame('02:00', $entry->getEndTime()->format('H:i'));
		$this->assertSame(
			(new \DateTime())->format('Y-m-d'),
			$entry->getEndTime()->format('Y-m-d'),
			'end must roll to the next calendar day for overnight shifts'
		);
	}

	public function testUpdateRejectsInvalidLegacyHours(): void
	{
		$this->signIn();
		$this->timeEntryMapper->method('find')->willReturn($this->editableEntry());
		$this->request->method('getParams')->willReturn([]);
		$this->timeEntryMapper->expects($this->never())->method('update');

		$r = $this->controller->update(1, '2026-03-01', 25.0);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
	}

	public function testGetOvertimeCustomPeriodUsesExplicitRange(): void
	{
		$this->signIn();
		$this->overtimeService->expects($this->once())
			->method('calculateOvertime')
			->with('testuser', $this->callback(
				static fn ($d) => $d instanceof \DateTimeInterface && $d->format('Y-m-d') === '2026-01-01'
			), $this->callback(
				static fn ($d) => $d instanceof \DateTimeInterface && $d->format('Y-m-d') === '2026-02-01'
			))
			->willReturn(['total_hours_worked' => 10.0]);

		$r = $this->controller->getOvertime('custom', '2026-01-01', '2026-01-31');
		$this->assertTrue($r->getData()['success']);
		$this->assertSame(10.0, $r->getData()['overtime']['total_hours_worked']);
	}

	public function testGetOvertimeCustomPeriodValidatesRange(): void
	{
		$this->signIn();
		$this->overtimeService->expects($this->never())->method('calculateOvertime');

		// missing dates
		$r = $this->controller->getOvertime('custom', null, null);
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $r->getStatus());
		$this->assertFalse($r->getData()['success']);

		// inverted range
		$r = $this->controller->getOvertime('custom', '2026-02-01', '2026-01-01');
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $r->getStatus());
	}

	/**
	 * Test update returns forbidden when user doesn't own entry
	 */
	public function testUpdateReturnsForbiddenWhenNotOwned(): void
	{
		$userId = 'testuser';
		$otherUserId = 'otheruser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($otherUserId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(true);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->update($entryId, '2024-01-16');

		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Mobile/API aliases must enforce the same ownership gate (BOLA).
	 */
	public function testApiShowReturnsForbiddenWhenNotOwned(): void
	{
		$userId = 'testuser';
		$otherUserId = 'otheruser';
		$entryId = 42;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($otherUserId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->apiShow($entryId);
		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertEquals('Access denied', $response->getData()['error']);
	}

	public function testApiDeleteReturnsForbiddenWhenNotOwned(): void
	{
		$userId = 'testuser';
		$otherUserId = 'otheruser';
		$entryId = 42;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($otherUserId);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(true);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->expects($this->never())->method('delete');

		$response = $this->controller->apiDelete($entryId);
		$this->assertEquals(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	/**
	 * Test update returns error when entry is not manual
	 */
	public function testUpdateReturnsErrorWhenNotManual(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setIsManualEntry(false);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->update($entryId, '2024-01-16');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Only entries from the last 2 weeks', $data['error']);
	}

	public function testRequestCorrectionAcceptsIsoTimesAndBreaks(): void
	{
		$this->signIn();
		$entry = $this->editableEntry();
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->request->method('getParams')->willReturn([
			'justification' => 'Clock was wrong, correcting times.',
			'startTime' => '2026-03-01T08:00:00Z',
			'endTime' => '2026-03-01T16:30:00Z',
			'breakStartTime' => '2026-03-01T12:00:00Z',
			'breakEndTime' => '2026-03-01T12:30:00Z',
			'breaks' => [['start' => '2026-03-01T12:00:00Z', 'end' => '2026-03-01T12:30:00Z']],
			'description' => 'corrected',
		]);
		$this->hasAssignableManager = true; // keep it pending (no auto-approve)
		$this->correctionService->method('validateProposal')->willReturn(null);
		$this->timeEntryMapper->expects($this->once())->method('update')
			->willReturnCallback(static fn (TimeEntry $e) => $e);

		$r = $this->controller->requestCorrection(1);
		$data = $r->getData();
		$this->assertTrue($data['success'], 'Unexpected: ' . json_encode($data));
		$this->assertSame(TimeEntry::STATUS_PENDING_APPROVAL, $entry->getStatus());
		$just = json_decode((string)$entry->getJustification(), true);
		$this->assertSame('2026-03-01T08:00:00+00:00', $just['proposed']['startTime']);
		$this->assertSame('2026-03-01T12:30:00+00:00', $just['proposed']['breakEndTime']);
		$this->assertSame('corrected', $just['proposed']['description']);
	}

	public function testRequestCorrectionRejectsInvalidIsoStartTime(): void
	{
		$this->signIn();
		$this->timeEntryMapper->method('find')->willReturn($this->editableEntry());
		$this->request->method('getParams')->willReturn([
			'justification' => 'Clock was wrong, correcting times.',
			'startTime' => 'not-a-date',
			'endTime' => '2026-03-01T16:30:00Z',
		]);
		$this->timeEntryMapper->expects($this->never())->method('update');

		$r = $this->controller->requestCorrection(1);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
		$this->assertStringContainsString('start_time', $r->getData()['error']);
	}

	public function testEditRendersFormForOwnedEntry(): void
	{
		$this->signIn();
		$this->timeEntryMapper->method('find')->willReturn($this->editableEntry());
		$this->urlGenerator->method('linkToRoute')->willReturn('/apps/arbeitszeitcheck/x');

		$r = $this->controller->edit(1);
		$params = $r->getParams();
		$this->assertSame('edit', $params['mode']);
		$this->assertSame(1, $params['entry']->getId());
		$this->assertTrue($params['timeCapture']['manualTimeEntryEnabled']);
	}

	public function testEditRendersListWithErrorWhenLocked(): void
	{
		$this->signIn();
		$entry = $this->editableEntry();
		$entry->setApprovedByUserId('manager1');
		$entry->setApprovedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);

		$r = $this->controller->edit(1);
		$params = $r->getParams();
		$this->assertSame('list', $params['mode']);
		$this->assertStringContainsString('Request Correction', $params['error']);
	}

	public function testEditFallsBackToSafeParamsWhenLookupThrows(): void
	{
		$this->signIn();
		$this->timeEntryMapper->method('find')
			->willThrowException(new \RuntimeException('db gone'));

		$r = $this->controller->edit(1);
		$params = $r->getParams();
		$this->assertSame('list', $params['mode']);
		// fallback params shape (user context unavailable)
		$this->assertFalse($params['monthClosureEnabled']);
		$this->assertFalse($params['showAdminNav']);
		$this->assertArrayHasKey('error', $params);
	}

	public function testEditUsesSharedFallbackParamsWhenUserLookupAlsoFails(): void
	{
		// find() throws AND getUserId() inside the catch also throws ->
		// only getTimeEntriesSharedTemplateParamsFallback() output survives.
		$this->userSession->method('getUser')->willReturn(null);
		$this->timeEntryMapper->method('find')
			->willThrowException(new \RuntimeException('db gone'));

		$r = $this->controller->edit(1);
		$params = $r->getParams();
		$this->assertSame('list', $params['mode']);
		$this->assertFalse($params['monthClosureEnabled']);
		$this->assertFalse($params['showSubstitutionLink']);
		$this->assertFalse($params['showManagerLink']);
		$this->assertFalse($params['showReportsLink']);
		$this->assertFalse($params['showAdminNav']);
	}

	/**
	 * Test requestCorrection creates correction request
	 */
	public function testRequestCorrectionCreatesRequest(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getColleagueIds')->willReturn(['manager1']);
		$this->hasAssignableManager = true;
		$this->request->expects($this->once())->method('getParams')->willReturn([
			'justification' => 'Wrong time recorded',
			'newDate' => '2024-01-16',
			'newHours' => 7.5,
			'newDescription' => 'Corrected description',
		]);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setDescription('Original');
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$updatedEntry = new TimeEntry();
		$updatedEntry->setId($entryId);
		$updatedEntry->setUserId($userId);
		$updatedEntry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$updatedEntry->setStartTime(new \DateTime('2024-01-16 09:00:00'));
		$updatedEntry->setEndTime(new \DateTime('2024-01-16 16:30:00'));
		$updatedEntry->setDescription('Corrected description');
		$updatedEntry->setJustification('Wrong time recorded');
		$updatedEntry->setIsManualEntry(false);
		$updatedEntry->setCreatedAt(new \DateTime());
		$updatedEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->method('update')
			->willReturn($updatedEntry);

		$response = $this->controller->requestCorrection($entryId);
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Unexpected response: ' . json_encode($data));
		$this->assertArrayHasKey('message', $data);
	}

	public function testRequestCorrectionRejectsJustificationWithoutProposedChange(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->request->expects($this->once())->method('getParams')->willReturn([
			'justification' => 'Hab mich um ne halbe Stunde verspätet',
		]);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->requestCorrection($entryId);
		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('proposed_change_required', $data['error_code']);
		$this->assertStringContainsString('At least one proposed change is required', $data['error']);
	}

	public function testRequestCorrectionAcceptsDateAndClockFields(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);
		$this->teamResolver->method('getColleagueIds')->willReturn(['manager1']);
		$this->hasAssignableManager = true;
		$this->request->expects($this->once())->method('getParams')->willReturn([
			'justification' => 'Hab mich um ne halbe Stunde verspätet',
			'date' => '2024-01-15',
			'startTime' => '09:30',
			'endTime' => '17:00',
		]);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setDescription('Original');
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->method('update')->willReturnCallback(static function (TimeEntry $updated) {
			$payload = json_decode((string)$updated->getJustification(), true);
			self::assertIsArray($payload);
			self::assertNotEmpty($payload['proposed'] ?? null);
			self::assertArrayHasKey('startTime', $payload['proposed']);
			self::assertSame(TimeEntry::STATUS_PENDING_APPROVAL, $updated->getStatus());
			return $updated;
		});

		$response = $this->controller->requestCorrection($entryId);
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Unexpected response: ' . json_encode($data));
	}

	/**
	 * Test requestCorrection returns error when already pending
	 */
	public function testRequestCorrectionReturnsErrorWhenPending(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->requestCorrection($entryId, 'Justification');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('already pending', $data['error']);
	}

	/**
	 * Test requestCorrection requires justification
	 */
	public function testRequestCorrectionRequiresJustification(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setIsManualEntry(false);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->requestCorrection($entryId, '');

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Justification is required', $data['error']);
	}

	/**
	 * Test delete removes manual entry
	 */
	public function testDeleteRemovesManualEntry(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setIsManualEntry(true);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->expects($this->once())
			->method('delete')
			->with($entry);

		$response = $this->controller->delete($entryId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test delete removes stamped entry within the edit window
	 */
	public function testDeleteRemovesStampedEntryWithinEditWindow(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setIsManualEntry(false);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('-2 days 09:00:00'));
		$entry->setEndTime(new \DateTime('-2 days 17:00:00'));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->expects($this->once())
			->method('delete')
			->with($entry);

		$response = $this->controller->delete($entryId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test delete returns error when stamped entry is outside the edit window
	 */
	public function testDeleteReturnsErrorWhenStampedOutsideEditWindow(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setIsManualEntry(false);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->delete($entryId);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('edit_window_expired', $data['error_code']);
	}

	// NOTE: A legacy `stats()` endpoint existed previously but is no longer part of `TimeEntryController`.

	/**
	 * Test getOvertime returns overtime data
	 */
	public function testGetOvertimeReturnsData(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$response = $this->controller->getOvertime('monthly');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('overtime', $data);
	}

	/**
	 * Test getOvertimeBalance returns balance
	 */
	public function testGetOvertimeBalanceReturnsBalance(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$response = $this->controller->getOvertimeBalance();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('balance', $data);
	}

	/**
	 * Test apiStore creates entry from JSON body
	 */
	public function testApiStoreCreatesEntryFromJson(): void
	{
		$this->complianceService->method('blockingIssuesForCompletedEntry')->willReturn([]);

		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')
			->willReturn([
				'date' => '2024-01-15',
				'hours' => 8.0,
				'description' => 'Work',
				'projectCheckProjectId' => 'project123'
			]);

		$savedEntry = new TimeEntry();
		$savedEntry->setId(1);
		$savedEntry->setUserId($userId);
		$savedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$savedEntry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$savedEntry->setEndTime(new \DateTime('2024-01-15 17:00:00'));

		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->willReturn($savedEntry);

		$response = $this->controller->apiStore();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Unexpected response: ' . json_encode($data));
	}

	/**
	 * Test apiStore returns error when date or hours missing
	 */
	public function testApiStoreReturnsErrorWhenMissingRequired(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')
			->willReturn(['date' => '2024-01-15']); // Missing hours

		$response = $this->controller->apiStore();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Either (date and hours) or (startTime and endTime) are required', $data['error']);
	}

	public function testApiStoreBlocksCompletedEntryWithoutMandatoryBreak(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-15',
			'startTime' => '08:00',
			'endTime' => '15:00',
		]);

		$this->complianceService->expects($this->once())
			->method('blockingIssuesForCompletedEntry')
			->willReturn(['Mandatory 30-minute break missing after 6 hours of work (ArbZG §4).']);
		$this->complianceService->method('checkComplianceForCompletedEntry')->willReturn([]);

		$this->timeEntryMapper->expects($this->never())->method('insert');

		$response = $this->controller->apiStore();
		$data = $response->getData();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('compliance_blocked', $data['error_code']);
		$this->assertStringContainsString('30-minute', $data['error']);
	}

	public function testGetOvertimeBankHappyAndErrorArms(): void
	{
		$bank = $this->createMock(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);
		$bank->method('getBankStatus')->willReturn(['enabled' => true, 'balance' => 4.5]);
		$ctrl = $this->controllerWithOvertimeBank($bank);
		$r = $ctrl->getOvertimeBank();
		$this->assertTrue($r->getData()['success']);
		$this->assertSame(4.5, $r->getData()['bank']['balance']);

		$bankFail = $this->createMock(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class);
		$bankFail->method('getBankStatus')
			->willThrowException(new \RuntimeException('db gone'));
		$ctrlFail = $this->controllerWithOvertimeBank($bankFail);
		$r = $ctrlFail->getOvertimeBank();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $r->getStatus());
		$this->assertFalse($r->getData()['success']);
	}

	private function controllerWithOvertimeBank(\OCA\ArbeitszeitCheck\Service\OvertimeBankService $bank): TimeEntryController
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		return new TimeEntryController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->userSession,
			$this->overtimeService,
			$bank,
			$this->urlGenerator,
			$this->l10n,
			$this->auditLogMapper,
			$this->config,
			$this->cspService,
			$this->complianceService,
			$this->timeTrackingService,
			$this->teamResolver,
			$this->notificationService,
			$this->monthClosureGuard,
			$this->absenceMapper,
			$this->permissionService,
			$this->timeZoneService,
			$this->correctionService,
			$this->localeFormat,
			$this->navigationFlags,
			$this->projectCheckIntegration,
			$this->createMock(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
			$this->timeCaptureMethodService,
			new TimeEntryDeletionPolicy($this->config, $this->monthClosureGuard, $this->l10n),
			$this->db,
		);
	}

	public function testApiStoreRealTimeComplianceCheckArms(): void
	{
		$this->appConfigValues['realtime_compliance_check'] = '1';
		$this->complianceService->method('blockingIssuesForCompletedEntry')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-15',
			'startTime' => '09:00',
			'endTime' => '17:00',
			'breaks' => [['start' => '12:00', 'end' => '12:30']],
		]);
		$savedEntry = new TimeEntry();
		$savedEntry->setId(1);
		$savedEntry->setUserId('testuser');
		$savedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$savedEntry->setStartTime(new \DateTime('2024-01-15T09:00:00'));
		$savedEntry->setEndTime(new \DateTime('2024-01-15T17:00:00'));
		$this->timeEntryMapper->method('insert')->willReturn($savedEntry);

		// arm: violations found -> info-logged, store still succeeds (warning mode)
		$this->complianceService->method('checkComplianceForCompletedEntry')
			->willReturn([['type' => 'rest_period']]);
		$this->assertSame(Http::STATUS_CREATED, $this->controller->apiStore()->getStatus());
	}

	public function testApiStoreRealTimeComplianceExceptionRethrowsInStrictMode(): void
	{
		$this->appConfigValues['realtime_compliance_check'] = '1';
		$this->appConfigValues['compliance_strict_mode'] = '1';
		$this->complianceService->method('blockingIssuesForCompletedEntry')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-15',
			'startTime' => '09:00',
			'endTime' => '17:00',
			'breaks' => [['start' => '12:00', 'end' => '12:30']],
		]);
		$savedEntry = new TimeEntry();
		$savedEntry->setId(1);
		$savedEntry->setUserId('testuser');
		$savedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$savedEntry->setStartTime(new \DateTime('2024-01-15T09:00:00'));
		$savedEntry->setEndTime(new \DateTime('2024-01-15T17:00:00'));
		$this->timeEntryMapper->method('insert')->willReturn($savedEntry);

		// pre-save gate (persist=false) passes; the post-persist check (persist=true)
		// throws -> strict rethrow inside the atomic block -> rollBack, not commit.
		$calls = 0;
		$this->complianceService->method('checkComplianceForCompletedEntry')
			->willReturnCallback(static function () use (&$calls) {
				$calls++;
				if ($calls === 1) {
					return [];
				}
				throw new \RuntimeException('compliance engine down');
			});

		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$response = $this->controller->apiStore();
		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testApiStoreRealTimeComplianceWarningModeLogsAndCommits(): void
	{
		$this->appConfigValues['realtime_compliance_check'] = '1';
		$this->appConfigValues['compliance_strict_mode'] = '0';
		$this->complianceService->method('blockingIssuesForCompletedEntry')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-15',
			'startTime' => '09:00',
			'endTime' => '17:00',
			'breaks' => [['start' => '12:00', 'end' => '12:30']],
		]);
		$savedEntry = new TimeEntry();
		$savedEntry->setId(1);
		$savedEntry->setUserId('testuser');
		$savedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$savedEntry->setStartTime(new \DateTime('2024-01-15T09:00:00'));
		$savedEntry->setEndTime(new \DateTime('2024-01-15T17:00:00'));
		$this->timeEntryMapper->method('insert')->willReturn($savedEntry);

		$this->complianceService->method('checkComplianceForCompletedEntry')
			->willThrowException(new \RuntimeException('compliance engine down'));

		// warning mode -> check error is logged, entry stays committed
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');

		$this->assertSame(Http::STATUS_CREATED, $this->controller->apiStore()->getStatus());
	}

	public function testApiStoreAcceptsBreaksArrayPayload(): void
	{
		$this->complianceService->method('blockingIssuesForCompletedEntry')->willReturn([]);

		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-15',
			'startTime' => '09:00',
			'endTime' => '17:00',
			'breaks' => [
				['start' => '12:00', 'end' => '12:30'],
			],
		]);

		$savedEntry = new TimeEntry();
		$savedEntry->setId(1);
		$savedEntry->setUserId($userId);
		$savedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$savedEntry->setIsManualEntry(true);
		$savedEntry->setStartTime(new \DateTime('2024-01-15T09:00:00'));
		$savedEntry->setEndTime(new \DateTime('2024-01-15T17:00:00'));
		$savedEntry->setCreatedAt(new \DateTime());
		$savedEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->willReturn($savedEntry);

		$response = $this->controller->apiStore();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
	}

	public function testApiStoreReturns403WhenManualTimeEntryDisabled(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$this->replaceTimeCaptureMethodService(
			$this->createManualEntryDisabledCaptureService(),
		);

		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-15',
			'startTime' => '09:00',
			'endTime' => '17:00',
		]);

		$this->timeEntryMapper->expects($this->never())->method('insert');

		$response = $this->controller->apiStore();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('manual_time_entry_disabled', $data['error_code']);
		$this->assertStringContainsString('Manual time entries are not enabled', $data['error']);
	}


	public function testApiStoreStartEndRequiresJustificationWhenFourEyesEnabled(): void
	{
		$this->appConfigValues[Constants::CONFIG_MANUAL_TIME_ENTRIES_REQUIRE_APPROVAL] = '1';
		$this->hasAssignableManager = true;

		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-15',
			'startTime' => '09:00',
			'endTime' => '17:00',
			'justification' => 'too short',
		]);
		$this->timeEntryMapper->expects($this->never())->method('insert');

		$response = $this->controller->apiStore();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('justification_too_short', $data['error_code']);
	}

	public function testApiStoreStartEndCreatesPendingWhenFourEyesAndManagerExist(): void
	{
		$this->complianceService->method('blockingIssuesForCompletedEntry')->willReturn([]);
		$this->appConfigValues[Constants::CONFIG_MANUAL_TIME_ENTRIES_REQUIRE_APPROVAL] = '1';
		$this->hasAssignableManager = true;

		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-15',
			'startTime' => '09:00',
			'endTime' => '17:00',
			'breaks' => [
				['start' => '12:00', 'end' => '12:30'],
			],
			'justification' => 'Forgot to stamp yesterday after a site visit.',
		]);

		$savedEntry = new TimeEntry();
		$savedEntry->setId(1);
		$savedEntry->setUserId($userId);
		$savedEntry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$savedEntry->setIsManualEntry(true);
		$savedEntry->setStartTime(new \DateTime('2024-01-15T09:00:00'));
		$savedEntry->setEndTime(new \DateTime('2024-01-15T17:00:00'));
		$savedEntry->setCreatedAt(new \DateTime());
		$savedEntry->setUpdatedAt(new \DateTime());

		$this->correctionService->expects($this->once())->method('prepareManualPending');
		$this->correctionService->expects($this->never())->method('autoApprove');
		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->willReturn($savedEntry);

		$response = $this->controller->apiStore();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertTrue($data['success']);
		$this->assertStringContainsString('approval', strtolower((string)$data['message']));
	}

	public function testApiStoreLegacyDateHoursReturns403WhenManualTimeEntryDisabled(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$this->replaceTimeCaptureMethodService(
			$this->createManualEntryDisabledCaptureService(),
		);

		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-15',
			'hours' => 8.0,
		]);

		$this->timeEntryMapper->expects($this->never())->method('insert');

		$response = $this->controller->apiStore();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('manual_time_entry_disabled', $data['error_code']);
	}

	public function testStoreReturns403WhenManualTimeEntryDisabled(): void
	{
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$this->replaceTimeCaptureMethodService(
			$this->createManualEntryDisabledCaptureService(),
		);
		$this->timeEntryMapper->expects($this->never())->method('insert');

		$response = $this->controller->store('2024-01-15', 8.0, 'Work');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('manual_time_entry_disabled', $response->getData()['error_code']);
	}

	private function createManualEntryDisabledCaptureService(): TimeCaptureMethodService
	{
		$service = $this->createMock(TimeCaptureMethodService::class);
		$service->method('getSettings')->willReturn([
			'clockStampingEnabled' => true,
			'manualTimeEntryEnabled' => false,
		]);
		$service->method('isManualTimeEntryEnabled')->willReturn(false);

		return $service;
	}

	private function replaceTimeCaptureMethodService(TimeCaptureMethodService $service): void
	{
		$ref = new \ReflectionClass($this->controller);
		$prop = $ref->getProperty('timeCaptureMethodService');
		$prop->setAccessible(true);
		$prop->setValue($this->controller, $service);
	}

	public function testUpdateAcceptsGermanDateFormatInLegacyMode(): void
	{
		$userId = 'testuser';
		$entryId = 1;
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId($entryId);
		$entry->setUserId($userId);
		$entry->setIsManualEntry(true);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setJustification('Initial manual entry justification');
		$entry->setStartTime((new \DateTime())->modify('-1 day')->setTime(9, 0, 0));
		$entry->setEndTime((new \DateTime())->modify('-1 day')->setTime(17, 0, 0));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->request->method('getParams')->willReturn([]);
		$this->timeEntryMapper->expects($this->once())
			->method('update')
			->willReturn($entry);

		$response = $this->controller->update($entryId, (new \DateTime('yesterday'))->format('d.m.Y'), 8.0);
		$this->assertTrue($response->getData()['success']);
	}

	public function testIndexApiAliasesDelegateToIndex(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->timeEntryMapper->method('findByUser')->willReturn([]);
		$this->timeEntryMapper->method('countByUser')->willReturn(0);

		foreach (['index_api', 'apiIndex'] as $method) {
			$response = $this->controller->$method();
			$this->assertTrue($response->getData()['success'], $method);
		}
	}

	public function testApiAssignableProjectcheckProjectsWhenDisabled(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$response = $this->controller->apiAssignableProjectcheckProjects();
		$this->assertTrue($response->getData()['success']);
		$this->assertArrayHasKey('enabled', $response->getData());
	}

	public function testGetOvertimeBankReturnsStatus(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$response = $this->controller->getOvertimeBank();
		$this->assertTrue($response->getData()['success']);
		$this->assertArrayHasKey('bank', $response->getData());
	}

	public function testGetStatsReturnsPayload(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->timeTrackingService->method('getWorkingHoursForPeriod')->willReturn(0.0);
		$this->timeEntryMapper->method('getTotalBreakHoursByUserAndDateRange')->willReturn(0.0);
		$this->timeEntryMapper->method('countByUser')->willReturn(0);
		$this->overtimeService->method('calculateOvertime')->willReturn([
			'overtime_hours' => 0.0,
			'required_hours' => 0.0,
			'total_hours_worked' => 0.0,
			'cumulative_balance_after' => 0.0,
		]);

		$response = $this->controller->getStats('2026-01-01', '2026-01-31');
		$this->assertTrue($response->getData()['success']);
	}

	public function testGetDeletionImpactForbiddenWhenNotOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId(9);
		$entry->setUserId('other');
		$entry->setUpdatedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->getDeletionImpact(9);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testGetDeletionImpactOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId(9);
		$entry->setUserId('testuser');
		$entry->setIsManualEntry(true);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime((new \DateTime())->modify('-1 day')->setTime(9, 0, 0));
		$entry->setEndTime((new \DateTime())->modify('-1 day')->setTime(17, 0, 0));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->getDeletionImpact(9);
		$this->assertTrue($response->getData()['success']);
		$this->assertArrayHasKey('impact', $response->getData());
	}

	public function testCancelCorrectionForbiddenWhenNotOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId(3);
		$entry->setUserId('other');
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setUpdatedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);

		$response = $this->controller->cancelCorrection(3);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testUpdatePostAndApiUpdatePostDelegate(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId('testuser');
		$entry->setIsManualEntry(true);
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setJustification('j');
		$entry->setStartTime((new \DateTime())->modify('-1 day')->setTime(9, 0, 0));
		$entry->setEndTime((new \DateTime())->modify('-1 day')->setTime(17, 0, 0));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->method('update')->willReturn($entry);
		$this->request->method('getParams')->willReturn([
			'date' => (new \DateTime('yesterday'))->format('Y-m-d'),
			'hours' => '8',
		]);

		$this->assertTrue($this->controller->updatePost(1)->getData()['success']);
		$this->assertTrue($this->controller->apiUpdatePost(1)->getData()['success']);
	}

	public function testCheckOverlapInvalidTimestamps(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$response = $this->controller->checkOverlap('not-a-date', 'also-bad');
		$this->assertFalse($response->getData()['success']);
	}

	public function testCreateAndEditTemplateSurfaces(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->timeCaptureMethodService->method('isManualTimeEntryEnabled')->willReturn(false);
		$this->timeEntryMapper->method('findByUser')->willReturn([]);
		$this->timeEntryMapper->method('countByUser')->willReturn(0);

		$create = $this->controller->create();
		$this->assertInstanceOf(\OCP\AppFramework\Http\TemplateResponse::class, $create);

		$entry = new TimeEntry();
		$entry->setId(1);
		$entry->setUserId('other');
		$entry->setUpdatedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$edit = $this->controller->edit(1);
		$this->assertInstanceOf(\OCP\AppFramework\Http\TemplateResponse::class, $edit);
	}

	public function testCompleteInvalidId(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$response = $this->controller->complete(0);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	/**
	 * Object-level AuthZ: API update aliases must reject non-owned entries (BOLA).
	 */
	public function testApiUpdateReturnsForbiddenWhenNotOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-16',
			'hours' => '8',
		]);

		$entry = new TimeEntry();
		$entry->setId(42);
		$entry->setUserId('otheruser');
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(true);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->expects($this->never())->method('update');

		$response = $this->controller->apiUpdate(42);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testApiUpdatePostReturnsForbiddenWhenNotOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-16',
			'hours' => '8',
		]);

		$entry = new TimeEntry();
		$entry->setId(42);
		$entry->setUserId('otheruser');
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setIsManualEntry(true);
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->expects($this->never())->method('update');

		$response = $this->controller->apiUpdatePost(42);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testRequestCorrectionReturnsForbiddenWhenNotOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'justification' => 'Wrong time recorded for testing',
		]);

		$entry = new TimeEntry();
		$entry->setId(7);
		$entry->setUserId('otheruser');
		$entry->setStatus(TimeEntry::STATUS_COMPLETED);
		$entry->setStartTime(new \DateTime('2024-01-15 09:00:00'));
		$entry->setEndTime(new \DateTime('2024-01-15 17:00:00'));
		$entry->setCreatedAt(new \DateTime());
		$entry->setUpdatedAt(new \DateTime());
		$this->timeEntryMapper->method('find')->willReturn($entry);
		$this->timeEntryMapper->expects($this->never())->method('update');

		$response = $this->controller->requestCorrection(7);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('Access denied', $response->getData()['error']);
	}

	public function testCompleteReturnsForbiddenWhenNotOwned(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);
		$this->request->method('getParams')->willReturn([]);

		$this->timeTrackingService->expects($this->once())
			->method('completePausedEntry')
			->with('testuser', 9, null)
			->willThrowException(new \OCA\ArbeitszeitCheck\Exception\BusinessRuleException('Access denied'));

		$response = $this->controller->complete(9);
		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertSame('Access denied', $response->getData()['error']);
	}

	public function testApiStoreComplianceStrictModeInvokesStrictCheckAndBlocks(): void
	{
		$this->appConfigValues['compliance_strict_mode'] = '1';

		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-15',
			'startTime' => '08:00',
			'endTime' => '17:00',
		]);

		$this->complianceService->method('blockingIssuesForCompletedEntry')->willReturn([]);
		$this->complianceService->expects($this->once())
			->method('checkComplianceForCompletedEntry')
			->with($this->isInstanceOf(TimeEntry::class), true, false)
			->willThrowException(new \Exception('Rest period violation under complianceStrictMode'));

		$this->timeEntryMapper->expects($this->never())->method('insert');

		$response = $this->controller->apiStore();
		$data = $response->getData();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('compliance_blocked', $data['error_code']);
		$this->assertStringContainsString('Rest period', $data['error']);
	}

	public function testApiStoreWithoutComplianceStrictModeSkipsStrictGateWhenNoBlockingIssues(): void
	{
		$this->appConfigValues['compliance_strict_mode'] = '0';

		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);

		$this->request->method('getParams')->willReturn([
			'date' => '2024-01-15',
			'startTime' => '09:00',
			'endTime' => '17:00',
		]);

		$this->complianceService->method('blockingIssuesForCompletedEntry')->willReturn([]);
		$strictCalls = 0;
		$this->complianceService->method('checkComplianceForCompletedEntry')
			->willReturnCallback(function ($entry, $strict = false, ...$rest) use (&$strictCalls) {
				unset($entry, $rest);
				if ($strict === true) {
					$strictCalls++;
				}
				return [];
			});

		$savedEntry = new TimeEntry();
		$savedEntry->setId(42);
		$savedEntry->setUserId($userId);
		$savedEntry->setStatus(TimeEntry::STATUS_COMPLETED);
		$savedEntry->setIsManualEntry(true);
		$savedEntry->setStartTime(new \DateTime('2024-01-15T09:00:00'));
		$savedEntry->setEndTime(new \DateTime('2024-01-15T17:00:00'));
		$savedEntry->setCreatedAt(new \DateTime());
		$savedEntry->setUpdatedAt(new \DateTime());

		$this->timeEntryMapper->expects($this->once())
			->method('insert')
			->willReturn($savedEntry);

		$response = $this->controller->apiStore();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame(0, $strictCalls, 'compliance_strict_mode=0 must not run strict checkComplianceForCompletedEntry gate');
	}
}
