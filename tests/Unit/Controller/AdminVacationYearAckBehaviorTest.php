<?php

declare(strict_types=1);

/**
 * Behavioral gate: calendar→anniversary with missing hire dates requires ack.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Controller\AdminController;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\AdminUserProfileUpdateService;
use OCA\ArbeitszeitCheck\Service\AuditLogPresenter;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\HolidayAdminService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\LayeredVacationDefaultsService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine;
use OCA\ArbeitszeitCheck\Service\VacationProrationService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IDBConnection;
use OCP\IDateTimeFormatter;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class AdminVacationYearAckBehaviorTest extends TestCase
{
	/** @var IRequest&MockObject */
	private IRequest $request;
	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;
	/** @var IUserManager&MockObject */
	private IUserManager $userManager;
	/** @var PermissionService&MockObject */
	private PermissionService $permissionService;
	/** @var UserEmploymentSettingsService&MockObject */
	private UserEmploymentSettingsService $employmentService;
	/** @var AuditLogMapper&MockObject */
	private AuditLogMapper $auditLogMapper;
	private AdminController $controller;

	protected function setUp(): void
	{
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->permissionService = $this->createMock(PermissionService::class);
		$this->employmentService = $this->createMock(UserEmploymentSettingsService::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s, $p = []) => empty($p) ? $s : vsprintf($s, $p));

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('search')->willReturn([]);

		$proration = $this->createMock(VacationProrationService::class);
		$proration->method('getConfiguredMethod')->willReturn(Constants::VACATION_PRORATION_METHOD_TWELFTHS);

		$timeCapture = $this->createMock(TimeCaptureMethodService::class);
		$timeCapture->method('getSettings')->willReturn([
			'clockStampingEnabled' => true,
			'manualTimeEntryEnabled' => true,
		]);

		$db = $this->createMock(IDBConnection::class);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$vacationYearBalanceMapper = $this->createMock(VacationYearBalanceMapper::class);
		$vacationAllocation = $this->createMock(VacationAllocationService::class);
		$vacationAllocation->method('applyCapToOpeningBalance')->willReturnCallback(static fn (float $d) => $d);
		$vacationAllocation->method('refreshOpenAllocationsForUsers')->willReturn([
			'refreshed' => 1,
			'failed' => [],
		]);
		$tariffRuleSetMapper = $this->createMock(TariffRuleSetMapper::class);
		$userVacationPolicyAssignmentMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$userOvertime = $this->createMock(UserOvertimeSettingsService::class);

		$adminProfile = new AdminUserProfileUpdateService(
			$this->userManager,
			$userWorkingTimeModelMapper,
			$workingTimeModelMapper,
			$this->auditLogMapper,
			$userSettingsMapper,
			$vacationYearBalanceMapper,
			$vacationAllocation,
			$tariffRuleSetMapper,
			$userVacationPolicyAssignmentMapper,
			$userOvertime,
			$this->employmentService,
			$timeCapture,
			$l10n,
			$db,
		);

		$dateTimeFormatter = $this->createMock(IDateTimeFormatter::class);
		$dateTimeFormatter->method('formatDateTime')->willReturn('2026-08-04 12:00');
		$auditPresenter = new AuditLogPresenter($l10n, $dateTimeFormatter);

		$localeFormat = $this->createMock(LocaleFormatService::class);
		$localeFormat->method('clientHints')->willReturn([
			'locale' => 'en-US',
			'htmlLang' => 'en-US',
			'timezone' => 'Europe/Berlin',
		]);

		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$unit = new \OCA\ArbeitszeitCheck\Service\VacationUnitService($config);
		$migration = new \OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService(
			$config,
			$db,
			$unit,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
			$vacationYearBalanceMapper,
			$this->auditLogMapper,
		);
		$locking = $this->createMock(\OCP\Lock\ILockingProvider::class);
		$locking->method('acquireLock');
		$locking->method('releaseLock');

		$adminEmployeeDirectoryService = new \OCA\ArbeitszeitCheck\Service\AdminEmployeeDirectoryService(
			$this->userManager,
			$this->permissionService,
			$this->createMock(TimeEntryMapper::class),
			$l10n,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
		);

		$this->controller = new AdminController(
			'arbeitszeitcheck',
			$this->request,
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(ComplianceViolationMapper::class),
			$userWorkingTimeModelMapper,
			$workingTimeModelMapper,
			$this->auditLogMapper,
			$this->userManager,
			$this->appConfig,
			$userSettingsMapper,
			$this->createMock(TeamMapper::class),
			$this->createMock(TeamMemberMapper::class),
			$this->createMock(TeamManagerMapper::class),
			$groupManager,
			$this->createMock(IAppManager::class),
			$userSession,
			$this->createMock(CSPService::class),
			$l10n,
			$this->createMock(IURLGenerator::class),
			$this->createMock(HolidayMapper::class),
			$this->createMock(HolidayService::class),
			$this->createMock(HolidayAdminService::class),
			$vacationYearBalanceMapper,
			$vacationAllocation,
			$tariffRuleSetMapper,
			$this->createMock(TariffRuleModuleMapper::class),
			$userVacationPolicyAssignmentMapper,
			$this->createMock(VacationEntitlementEngine::class),
			$this->createMock(LayeredVacationDefaultsService::class),
			$userOvertime,
			$this->employmentService,
			$proration,
			$timeCapture,
			$adminProfile,
			$adminEmployeeDirectoryService,
			$auditPresenter,
			$this->permissionService,
			$localeFormat,
			$db,
			null,
			$config,
			$locking,
			$migration,
		);
	}

	private function stubCalendarYearMode(): void
	{
		$store = [
			Constants::CONFIG_VACATION_YEAR_MODE => Constants::VACATION_YEAR_MODE_CALENDAR,
		];
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(static function (string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			});
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(static function (string $key, string $value) use (&$store): bool {
				$store[$key] = $value;
				return true;
			});
	}

	private function stubOneUserMissingHire(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$user->method('isEnabled')->willReturn(true);
		$this->userManager->method('callForAllUsers')
			->willReturnCallback(static function (callable $cb) use ($user): void {
				$cb($user);
			});
		$this->permissionService->method('isUserAllowedByAccessGroups')->with('alice')->willReturn(true);
		$this->employmentService->method('getEmploymentStart')->with('alice')->willReturn(null);
	}

	public function testSwitchToAnniversaryWithoutAckReturns409(): void
	{
		$this->stubCalendarYearMode();
		$this->stubOneUserMissingHire();
		$this->request->method('getParams')->willReturn([
			'enabled' => false,
			'recipients' => [],
			'matrix' => [],
			'vacationYearMode' => Constants::VACATION_YEAR_MODE_ANNIVERSARY,
		]);
		$this->auditLogMapper->expects($this->never())->method('logAction');

		$response = $this->controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame(Constants::VAC_YEAR_MISSING_HIRE_ACK_REQUIRED, $data['code']);
		$this->assertSame(1, $data['missingHireCount']);
	}

	public function testSwitchToAnniversaryWithAckSucceeds(): void
	{
		$this->stubCalendarYearMode();
		$this->stubOneUserMissingHire();
		$this->request->method('getParams')->willReturn([
			'enabled' => false,
			'recipients' => [],
			'matrix' => [],
			'vacationYearMode' => Constants::VACATION_YEAR_MODE_ANNIVERSARY,
			'vacationYearMissingHireAcknowledged' => true,
		]);
		// The shared settings form posts the HR section alongside the
		// vacation-year flip, so two audit rows are written:
		// vacation_year_mode_changed + hr_notification_settings_changed.
		$actions = [];
		$this->auditLogMapper->method('logAction')
			->willReturnCallback(function (...$args) use (&$actions) {
				$actions[] = $args;
				return new \OCA\ArbeitszeitCheck\Db\AuditLog();
			});

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$this->assertCount(2, $actions);
		$this->assertSame('vacation_year_mode_changed', $actions[0][1]);
		$this->assertSame('app_config', $actions[0][2]);
		$this->assertSame(['vacation_year_mode' => Constants::VACATION_YEAR_MODE_CALENDAR], $actions[0][4]);
		$new = $actions[0][5];
		$this->assertSame(Constants::VACATION_YEAR_MODE_ANNIVERSARY, $new['vacation_year_mode']);
		$this->assertSame(1, $new['missing_hire_count']);
		$this->assertTrue($new['missing_hire_acknowledged']);
		$this->assertArrayHasKey('allocations_refreshed', $new);
		$this->assertArrayHasKey('allocations_failed_count', $new);
		$this->assertSame('hr_notification_settings_changed', $actions[1][1]);
		$this->assertSame(Constants::VACATION_YEAR_MODE_ANNIVERSARY, $data['vacationYearModeFlip']['to'] ?? null);
		$this->assertArrayHasKey('allocationsRefreshed', $data['vacationYearModeFlip']);
	}

	public function testStaleHrNotificationRevisionReturns409BeforeWrites(): void
	{
		$this->stubCalendarYearMode();
		$this->request->method('getParams')->willReturn([
			'enabled' => false,
			'recipients' => ['hr@example.com'],
			'matrix' => [],
			'hrNotificationRevision' => 'stale-revision-token',
		]);
		// The CAS check sits before the commit phase — a stale tab must not
		// reach a single write, so no audit row may be logged either.
		$this->auditLogMapper->expects($this->never())->method('logAction');

		$response = $this->controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('NOTIFICATION_SETTINGS_CONFLICT', $data['code']);
	}

	public function testCurrentHrNotificationRevisionPasses(): void
	{
		$this->stubCalendarYearMode();
		// HR keys unset → the revision hashes the stored defaults
		// '0' | '' | '[]' exactly as buildNotificationSettingsPayload does.
		$revision = substr(hash('sha256', '0' . '|' . '' . '|' . '[]'), 0, 16);
		$this->request->method('getParams')->willReturn([
			'enabled' => false,
			'recipients' => ['hr@example.com'],
			'matrix' => [],
			'hrNotificationRevision' => $revision,
		]);

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testStaleOvertimeNotificationRevisionReturns409(): void
	{
		$this->stubCalendarYearMode();
		$this->request->method('getParams')->willReturn([
			'overtimeTrafficLightEnabled' => '0',
			'overtimeNotificationRevision' => 'stale-revision-token',
		]);
		$this->auditLogMapper->expects($this->never())->method('logAction');

		$response = $this->controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('NOTIFICATION_SETTINGS_CONFLICT', $data['code']);
	}

	public function testCurrentOvertimeNotificationRevisionPasses(): void
	{
		$this->stubCalendarYearMode();
		$revision = substr(hash('sha256',
			'0' . '|' . '' . '|' . '[]' . '|' . '5' . '|' . '15' . '|' . '5' . '|' . '15'
		), 0, 16);
		$this->request->method('getParams')->willReturn([
			'overtimeTrafficLightEnabled' => '0',
			'overtimeNotificationRevision' => $revision,
		]);

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * Mirrors AdminController::generalNotificationSettingsRevision(): the
	 * same 30 covered keys sorted, joined as "key=raw\0" pairs. The stub
	 * store only sets vacation_year_mode='calendar'; every other covered
	 * key falls back to the helper's '' default.
	 */
	private function expectedGeneralNotificationRevision(): string
	{
		$keys = [
			Constants::CONFIG_ASSIGNMENTS_GAP_POLICY,
			Constants::CONFIG_DATEV_LOHNART_PREMIUM_MAP,
			Constants::CONFIG_LEAVE_SOLL_EFFECTS,
			Constants::CONFIG_MANAGER_PENDING_EMAIL_ABSENCES,
			Constants::CONFIG_MANAGER_PENDING_EMAIL_CORRECTIONS,
			Constants::CONFIG_MANAGER_PENDING_EMAIL_MANUAL_ENTRIES,
			Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE,
			Constants::CONFIG_OVERTIME_BANK_ENABLED,
			Constants::CONFIG_OVERTIME_BANK_MAX_HOURS,
			Constants::CONFIG_OVERTIME_BANK_RED_PERCENT,
			Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT,
			Constants::CONFIG_OVERTIME_BLOCK_MONTH_CLOSURE_PENDING_PAYOUT,
			Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_EMAIL,
			Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_IN_APP,
			Constants::CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT,
			Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED,
			Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY,
			Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH,
			Constants::CONFIG_VACATION_CARRYOVER_MAX_DAYS,
			Constants::CONFIG_VACATION_PRORATION_METHOD,
			Constants::CONFIG_VACATION_ROLLOVER_ENABLED,
			Constants::CONFIG_VACATION_ROLLOVER_INCLUDE_UNUSED_ANNUAL,
			Constants::CONFIG_VACATION_YEAR_MODE,
			'missing_clock_in_reminders_enabled',
			'send_email_substitute_approved_to_employee',
			'send_email_substitute_approved_to_manager',
			'send_email_substitution_request',
			'send_ical_approved_absences',
			'send_ical_to_managers',
			'send_ical_to_substitute',
		];
		sort($keys);
		$doc = '';
		foreach ($keys as $k) {
			$v = $k === Constants::CONFIG_VACATION_YEAR_MODE
				? Constants::VACATION_YEAR_MODE_CALENDAR
				: '';
			$doc .= $k . '=' . $v . "\x00";
		}
		return substr(hash('sha256', $doc), 0, 16);
	}

	public function testStaleGeneralNotificationRevisionReturns409BeforeWrites(): void
	{
		$this->stubCalendarYearMode();
		$this->request->method('getParams')->willReturn([
			'missingClockInRemindersEnabled' => '1',
			'generalNotificationRevision' => 'stale-revision-token',
		]);
		$this->auditLogMapper->expects($this->never())->method('logAction');

		$response = $this->controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('NOTIFICATION_SETTINGS_CONFLICT', $data['code']);
	}

	public function testStaleGeneralNotificationRevisionBlocksBankWrite(): void
	{
		$this->stubCalendarYearMode();
		$this->request->method('getParams')->willReturn([
			'overtimeBankEnabled' => '1',
			'overtimeBankMaxHours' => '80',
			'generalNotificationRevision' => 'stale-revision-token',
		]);
		$this->auditLogMapper->expects($this->never())->method('logAction');

		$response = $this->controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('NOTIFICATION_SETTINGS_CONFLICT', $response->getData()['code']);
	}

	public function testCurrentGeneralNotificationRevisionPasses(): void
	{
		$this->stubCalendarYearMode();
		$this->request->method('getParams')->willReturn([
			'missingClockInRemindersEnabled' => '1',
			'sendIcalToManagers' => '1',
			'generalNotificationRevision' => $this->expectedGeneralNotificationRevision(),
		]);

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testGeneralWriteWithoutRevisionTokenStillAllowed(): void
	{
		$this->stubCalendarYearMode();
		// Legacy clients that never rendered the token keep working —
		// the CAS is advisory, same contract as breakPolicyRevision.
		$this->request->method('getParams')->willReturn([
			'missingClockInRemindersEnabled' => '0',
		]);

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}
}
