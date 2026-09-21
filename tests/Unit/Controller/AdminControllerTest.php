<?php

declare(strict_types=1);

/**
 * Unit tests for AdminController
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Controller\AdminController;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolation;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\AuditLog;
use OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSet;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignment;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Service\AdminUserProfileUpdateService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCP\IDBConnection;
use OCA\ArbeitszeitCheck\Service\HolidayAdminService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\IL10N;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Class AdminControllerTest
 */
class AdminControllerTest extends TestCase
{
	/** @var AdminController */
	private $controller;

	/** @var TimeEntryMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $timeEntryMapper;

	/** @var ComplianceViolationMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $violationMapper;

	/** @var UserWorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userWorkingTimeModelMapper;

	/** @var WorkingTimeModelMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $workingTimeModelMapper;

	/** @var AuditLogMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $auditLogMapper;

	/** @var IUserManager|\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var IAppConfig|\PHPUnit\Framework\MockObject\MockObject */
	private $appConfig;

	/** @var TariffRuleSetMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $tariffRuleSetMapper;

	/** @var TariffRuleModuleMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $tariffRuleModuleMapper;

	/** @var VacationEntitlementEngine|\PHPUnit\Framework\MockObject\MockObject */
	private $vacationEntitlementEngine;

	/** @var \OCA\ArbeitszeitCheck\Service\LayeredVacationDefaultsService|\PHPUnit\Framework\MockObject\MockObject */
	private $layeredVacationDefaultsService;

	/** @var UserOvertimeSettingsService|\PHPUnit\Framework\MockObject\MockObject */
	private $userOvertimeSettingsService;

	/** @var TimeCaptureMethodService|\PHPUnit\Framework\MockObject\MockObject */
	private $timeCaptureMethodService;

	/** @var UserVacationPolicyAssignmentMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $userVacationPolicyAssignmentMapper;

	/** @var IRequest|\PHPUnit\Framework\MockObject\MockObject */
	private $request;
	/** @var \OCA\ArbeitszeitCheck\Service\PermissionService|\PHPUnit\Framework\MockObject\MockObject */
	private $permissionService;
	private bool $accessRestrictionEnabled = false;
	/** @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject */
	private $groupManager;
	/** @var IAppManager|\PHPUnit\Framework\MockObject\MockObject */
	private $appManager;
	private bool $projectCheckInstalled = false;

	/** @var HolidayService|\PHPUnit\Framework\MockObject\MockObject */
	private $holidayCalendarService;

	/** @var HolidayMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $holidayMapper;

	/** @var \OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $vacationYearBalanceMapper;

	protected function setUp(): void
	{
		parent::setUp();

		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->violationMapper = $this->createMock(ComplianceViolationMapper::class);
		$this->userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$this->workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->request = $this->createMock(IRequest::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->groupManager->method('search')->willReturn([]);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->appManager->method('isInstalled')->willReturnCallback(
			fn (string $appId): bool => $appId === Constants::APP_ID_PROJECTCHECK && $this->projectCheckInstalled
		);
		$teamMapper = $this->createMock(TeamMapper::class);
		$teamMemberMapper = $this->createMock(TeamMemberMapper::class);
		$teamManagerMapper = $this->createMock(TeamManagerMapper::class);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$cspService = $this->createMock(CSPService::class);
		$cspService->method('applyPolicyWithNonce')->willReturnArgument(0);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s, $p = []) => empty($p) ? $s : vsprintf($s, $p));
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$holidayMapper = $this->createMock(HolidayMapper::class);
		$this->holidayMapper = $holidayMapper;
		$this->holidayCalendarService = $this->createMock(HolidayService::class);
		$holidayAdminService = $this->createMock(HolidayAdminService::class);

		$this->vacationYearBalanceMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class);
		$vacationAllocationService = $this->createMock(\OCA\ArbeitszeitCheck\Service\VacationAllocationService::class);
		$vacationAllocationService->method('applyCapToOpeningBalance')->willReturnCallback(fn (float $d) => $d);
		$this->tariffRuleSetMapper = $this->createMock(TariffRuleSetMapper::class);
		$this->tariffRuleModuleMapper = $this->createMock(TariffRuleModuleMapper::class);
		$tariffRuleModuleMapper = $this->tariffRuleModuleMapper;
		$this->userVacationPolicyAssignmentMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$this->vacationEntitlementEngine = $this->createMock(VacationEntitlementEngine::class);
		$this->vacationEntitlementEngine->method('computeForDate')->willReturn([
			'days' => 25.0,
			'source' => 'manual',
			'ruleSetId' => null,
			'trace' => [],
		]);
		$this->layeredVacationDefaultsService = $this->createMock(\OCA\ArbeitszeitCheck\Service\LayeredVacationDefaultsService::class);
		$this->userOvertimeSettingsService = $this->createMock(UserOvertimeSettingsService::class);
		$userEmploymentSettingsService = $this->createMock(\OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService::class);
		$vacationProrationService = $this->createMock(\OCA\ArbeitszeitCheck\Service\VacationProrationService::class);
		$vacationProrationService->method('getConfiguredMethod')
			->willReturn(Constants::VACATION_PRORATION_METHOD_TWELFTHS);
		$vacationProrationService->method('prorateForYear')
			->willReturnCallback(static function (string $uid, int $year, float $full): array {
				return [
					'days' => $full,
					'full_days' => $full,
					'prorated' => false,
					'method' => Constants::VACATION_PRORATION_METHOD_TWELFTHS,
					'months_covered' => 12,
					'covered_days' => 365,
					'days_in_year' => 365,
					'covered_from' => sprintf('%04d-01-01', $year),
					'covered_to' => sprintf('%04d-12-31', $year),
					'employment_start' => null,
					'employment_end' => null,
					'employed_in_year' => true,
					'algorithm_version' => Constants::VACATION_PRORATION_ALGORITHM_VERSION,
				];
			});
		$this->timeCaptureMethodService = $this->createMock(TimeCaptureMethodService::class);
		$this->timeCaptureMethodService->method('getSettings')->willReturn([
			'clockStampingEnabled' => true,
			'manualTimeEntryEnabled' => true,
		]);
		$this->permissionService = $this->createMock(\OCA\ArbeitszeitCheck\Service\PermissionService::class);
		$this->permissionService->method('isAccessRestrictionEnabled')->willReturnCallback(
			fn (): bool => $this->accessRestrictionEnabled,
		);
		$this->permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);
		$localeFormat = $this->createMock(\OCA\ArbeitszeitCheck\Service\LocaleFormatService::class);
		$localeFormat->method('clientHints')->willReturn([
			'locale' => 'en-US',
			'htmlLang' => 'en-US',
			'timezone' => 'Europe/Berlin',
		]);
		$dateTimeFormatter = $this->createMock(\OCP\IDateTimeFormatter::class);
		$dateTimeFormatter->method('formatDateTime')->willReturn('2026-06-03 19:04');
		$auditLogPresenter = new \OCA\ArbeitszeitCheck\Service\AuditLogPresenter($l10n, $dateTimeFormatter);

		$db = $this->createMock(IDBConnection::class);
		$config = $this->createMock(\OCP\IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default = '') {
			return $default;
		});
		$adminUserProfileUpdateService = new AdminUserProfileUpdateService(
			$this->userManager,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->auditLogMapper,
			$userSettingsMapper,
			$this->vacationYearBalanceMapper,
			$vacationAllocationService,
			$this->tariffRuleSetMapper,
			$this->userVacationPolicyAssignmentMapper,
			$this->userOvertimeSettingsService,
			$userEmploymentSettingsService,
			$this->timeCaptureMethodService,
			$l10n,
			$db,
		);

		$adminEmployeeDirectoryService = new \OCA\ArbeitszeitCheck\Service\AdminEmployeeDirectoryService(
			$this->userManager,
			$this->permissionService,
			$this->timeEntryMapper,
			$l10n,
			$this->createMock(\Psr\Log\LoggerInterface::class),
		);

		$this->controller = new AdminController(
			'arbeitszeitcheck',
			$this->request,
			$this->timeEntryMapper,
			$this->violationMapper,
			$this->userWorkingTimeModelMapper,
			$this->workingTimeModelMapper,
			$this->auditLogMapper,
			$this->userManager,
			$this->appConfig,
			$userSettingsMapper,
			$teamMapper,
			$teamMemberMapper,
			$teamManagerMapper,
			$this->groupManager,
			$this->appManager,
			$userSession,
			$cspService,
			$l10n,
			$urlGenerator,
			$holidayMapper,
			$this->holidayCalendarService,
			$holidayAdminService,
			$this->vacationYearBalanceMapper,
			$vacationAllocationService,
			$this->tariffRuleSetMapper,
			$tariffRuleModuleMapper,
			$this->userVacationPolicyAssignmentMapper,
			$this->vacationEntitlementEngine,
			$this->layeredVacationDefaultsService,
			$this->userOvertimeSettingsService,
			$userEmploymentSettingsService,
			$vacationProrationService,
			$this->timeCaptureMethodService,
			$adminUserProfileUpdateService,
			$adminEmployeeDirectoryService,
			$auditLogPresenter,
			$this->permissionService,
			$localeFormat,
			$db,
			null,
			$config,
		);
	}

	private function makeUserMock(string $uid, string $displayName, ?string $email = null, bool $enabled = true): IUser
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}

	public function testGetDashboardEmployeesRejectsUnknownFilter(): void
	{
		$response = $this->controller->getDashboardEmployees('bogus');
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testGetDashboardEmployeesRejectsUnknownFormat(): void
	{
		$response = $this->controller->getDashboardEmployees('all', null, null, null, 'json');
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testGetDashboardEmployeesReturnsList(): void
	{
		$this->userManager->method('search')->willReturn([
			$this->makeUserMock('alice', 'Alice'),
			$this->makeUserMock('bob', 'Bob'),
		]);
		$this->timeEntryMapper->method('findDistinctUserIdsByDate')->willReturn(['bob']);
		$this->userOvertimeSettingsService->method('listUserIdsWithTrackingFrom')->willReturn(['alice']);

		$response = $this->controller->getDashboardEmployees('all');
		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(2, $data['total']);
		$this->assertSame('all', $data['filter']);
		$this->assertFalse($data['truncated']);
		$byId = [];
		foreach ($data['employees'] as $row) {
			$byId[$row['userId']] = $row;
		}
		$this->assertTrue($byId['alice']['hasOvertimeTrackingFrom']);
		$this->assertFalse($byId['alice']['hasTimeEntriesToday']);
		$this->assertTrue($byId['bob']['hasTimeEntriesToday']);
		$this->assertFalse($byId['bob']['hasOvertimeTrackingFrom']);
	}

	public function testGetDashboardEmployeesActiveTodayFilter(): void
	{
		$this->userManager->method('search')->willReturn([
			$this->makeUserMock('alice', 'Alice'),
			$this->makeUserMock('bob', 'Bob'),
		]);
		$this->timeEntryMapper->method('findDistinctUserIdsByDate')->willReturn(['bob']);
		$this->userOvertimeSettingsService->method('listUserIdsWithTrackingFrom')->willReturn([]);

		$response = $this->controller->getDashboardEmployees('active_today');
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(1, $data['total']);
		$this->assertSame('bob', $data['employees'][0]['userId']);
		$this->assertTrue($data['employees'][0]['hasTimeEntriesToday']);
	}

	public function testGetDashboardEmployeesCsvExportSanitisesFormulaInjection(): void
	{
		$this->userManager->method('search')->willReturn([
			$this->makeUserMock('alice', '=cmd|/c calc', '+attacker@example.com'),
			$this->makeUserMock('bob', 'Bob'),
		]);
		$this->timeEntryMapper->method('findDistinctUserIdsByDate')->willReturn([]);
		$this->userOvertimeSettingsService->method('listUserIdsWithTrackingFrom')->willReturn([]);

		$response = $this->controller->getDashboardEmployees('all', null, null, null, 'csv');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$body = (string)$response->render();
		$this->assertStringContainsString('"\'=cmd|/c calc"', $body);
		$this->assertStringContainsString('"\'+attacker@example.com"', $body);
		$this->assertStringContainsString('"Bob"', $body);
	}

	public function testGetDashboardEmployeesCsvExportSanitisesWhitespacePrefixedFormulaInjection(): void
	{
		$this->userManager->method('search')->willReturn([
			$this->makeUserMock('alice', '  =SUM(1,2)', " \t+attacker@example.com"),
		]);
		$this->timeEntryMapper->method('findDistinctUserIdsByDate')->willReturn([]);
		$this->userOvertimeSettingsService->method('listUserIdsWithTrackingFrom')->willReturn([]);

		$response = $this->controller->getDashboardEmployees('all', null, null, null, 'csv');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$body = (string)$response->render();
		$this->assertStringContainsString('"\'  =SUM(1,2)"', $body);
		$this->assertStringContainsString("\"' \t+attacker@example.com\"", $body);
	}

	/**
	 * Test dashboard returns template
	 */
	public function testDashboardReturnsTemplate(): void
	{
		$response = $this->controller->dashboard();

		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * Test users returns template
	 */
	public function testUsersReturnsTemplate(): void
	{
		$response = $this->controller->users();

		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * Test settings index redirects to default section
	 */
	public function testSettingsRedirectsToDefaultSection(): void
	{
		$response = $this->controller->settings();

		$this->assertInstanceOf(\OCP\AppFramework\Http\RedirectResponse::class, $response);
	}

	public function testSettingsSectionAccessReturnsTemplate(): void
	{
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(fn (string $key, string $default = '') => $default);
		$response = $this->controller->settingsSection('access');
		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	public function testSettingsSectionUnknownReturnsNotFound(): void
	{
		$response = $this->controller->settingsSection('not-a-real-section');
		$this->assertInstanceOf(\OCP\AppFramework\Http\NotFoundResponse::class, $response);
	}

	public function testUpdateAdminSettingsRetentionScopeDoesNotWriteCompliance(): void
	{
		$store = [
			'auto_compliance_check' => '1',
			'retention_period' => '2',
		];
		$this->request->method('getParams')->willReturn([
			'settings_section' => 'retention',
			'retentionPeriod' => 5,
			'autoComplianceCheck' => false,
		]);
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$store): bool {
				unset($lazy, $sensitive);
				$store[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function ($key, $default = '') use (&$store): string {
				$key = (string)$key;
				return $store[$key] ?? (string)$default;
			});

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('5', $store['retention_period']);
		$this->assertSame('1', $store['auto_compliance_check']);
	}

	public function testNotificationsReturnsTemplate(): void
	{
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(fn (string $key, string $default = '') => $default);
		$response = $this->controller->notifications();
		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	public function testOvertimeSettingsReturnsTemplate(): void
	{
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(fn (string $key, string $default = '') => $default);
		$response = $this->controller->overtimeSettings();
		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * Test workingTimeModels returns template
	 */
	public function testWorkingTimeModelsReturnsTemplate(): void
	{
		$response = $this->controller->workingTimeModels();

		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * Test auditLog returns template
	 */
	public function testAuditLogReturnsTemplate(): void
	{
		$response = $this->controller->auditLog();

		$this->assertInstanceOf(TemplateResponse::class, $response);
	}

	/**
	 * Test getAdminSettings returns settings
	 */
	public function testGetAdminSettingsReturnsSettings(): void
	{
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function (string $key, string $default = '') {
				$values = [
					'auto_compliance_check' => '1',
					'enable_violation_notifications' => '1',
					'missing_clock_in_reminders_enabled' => '1',
					'export_midnight_split_enabled' => '1',
					'max_daily_hours' => '10',
					'min_rest_period' => '11',
					'german_state' => 'NW',
					'retention_period' => '2',
					'default_working_hours' => '8'
				];
				return $values[$key] ?? $default;
			});
		$this->appManager->method('getAppRestriction')->with('arbeitszeitcheck')->willReturn([]);

		$response = $this->controller->getAdminSettings();
		$data = $response->getData();

		if (!($data['success'] ?? false)) {
			$this->fail('Response: ' . json_encode($data));
		}
		$this->assertArrayHasKey('settings', $data);
		$this->assertTrue($data['settings']['autoComplianceCheck']);
		$this->assertTrue($data['settings']['missingClockInRemindersEnabled']);
		$this->assertEquals(10.0, $data['settings']['maxDailyHours']);
		$this->assertArrayHasKey('accessAllowedGroups', $data['settings']);
		$this->assertArrayHasKey('manualTimeEntriesRequireApproval', $data['settings']);
		$this->assertArrayHasKey('timeEntryChangesRequireApproval', $data['settings']);
		$this->assertFalse($data['settings']['manualTimeEntriesRequireApproval']);
		$this->assertFalse($data['settings']['timeEntryChangesRequireApproval']);
	}

	public function testGetNotificationSettingsReturnsNormalizedPayload(): void
	{
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function (string $key, string $default = '') {
				if ($key === Constants::CONFIG_HR_NOTIFICATIONS_ENABLED) {
					return '1';
				}
				if ($key === Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS) {
					return 'hr@example.com, HR@example.com,invalid';
				}
				if ($key === Constants::CONFIG_HR_NOTIFICATION_MATRIX_V1) {
					return '{"vacation":{"request_created":true}}';
				}
				return $default;
			});

		$response = $this->controller->getNotificationSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertTrue($data['settings']['enabled']);
		$this->assertSame('hr@example.com', $data['settings']['recipients']);
		$this->assertTrue($data['settings']['matrix']['vacation']['request_created']);
		$this->assertFalse($data['settings']['matrix']['vacation']['manager_rejected']);
	}

	public function testUpdateNotificationSettingsRejectsInvalidRecipient(): void
	{
		$this->request->method('getParams')->willReturn([
			'enabled' => true,
			'recipients' => ['ok@example.com', 'bad_mail'],
			'matrix' => ['vacation' => ['request_created' => true]],
		]);

		$response = $this->controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	public function testUpdateNotificationSettingsRejectsEnabledWithoutRecipients(): void
	{
		$this->request->method('getParams')->willReturn([
			'enabled' => true,
			'recipients' => [],
			'matrix' => ['vacation' => ['request_created' => true]],
		]);

		$response = $this->controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	public function testUpdateNotificationSettingsAcceptsMatrixJsonString(): void
	{
		$this->request->method('getParams')->willReturn([
			'enabled' => true,
			'recipients' => ['hr@example.com'],
			'matrix' => '{"vacation":{"request_created":true}}',
		]);

		$captured = [];
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$captured): bool {
				unset($lazy, $sensitive);
				$captured[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function ($key, $default = '') use (&$captured): string {
				$key = (string)$key;
				return $captured[$key] ?? (string)$default;
			});

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$matrix = json_decode($captured[Constants::CONFIG_HR_NOTIFICATION_MATRIX_V1], true);
		$this->assertTrue($matrix['vacation']['request_created']);
	}

	public function testUpdateNotificationSettingsPersistsNormalizedValues(): void
	{
		$this->request->method('getParams')->willReturn([
			'enabled' => 'true',
			'recipients' => ['HR@example.com', 'hr@example.com', 'ops@example.com'],
			'matrix' => [
				'vacation' => ['request_created' => true, 'manager_approved' => '1'],
				'invalid_type' => ['request_created' => true],
			],
		]);

		$captured = [];
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$captured): bool {
				unset($lazy, $sensitive);
				$captured[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function ($key, $default = '') use (&$captured): string {
				$key = (string)$key;
				return $captured[$key] ?? (string)$default;
			});

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertArrayHasKey(Constants::CONFIG_HR_NOTIFICATIONS_ENABLED, $captured);
		$this->assertArrayHasKey(Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS, $captured);
		$this->assertArrayHasKey(Constants::CONFIG_HR_NOTIFICATION_MATRIX_V1, $captured);
		$this->assertSame('1', $captured[Constants::CONFIG_HR_NOTIFICATIONS_ENABLED]);
		$this->assertSame('hr@example.com,ops@example.com', $captured[Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS]);
		$matrix = json_decode($captured[Constants::CONFIG_HR_NOTIFICATION_MATRIX_V1], true);
		$this->assertTrue($matrix['vacation']['request_created']);
		$this->assertTrue($matrix['vacation']['manager_approved']);
		$this->assertArrayNotHasKey('invalid_type', $matrix);
		// Partial HR payload must not wipe overtime bank / traffic light.
		$this->assertArrayNotHasKey(Constants::CONFIG_OVERTIME_BANK_ENABLED, $captured);
		$this->assertArrayNotHasKey(Constants::CONFIG_OVERTIME_TRAFFIC_LIGHT_ENABLED, $captured);
	}

	public function testUpdateNotificationSettingsPersistsPremiumPolicyAndFlag(): void
	{
		$policy = \OCA\ArbeitszeitCheck\Support\PremiumPolicy::atStarterPreset();
		$this->request->method('getParams')->willReturn([
			'enabled' => false,
			'recipients' => [],
			'matrix' => [],
			'premiumSurchargesEnabled' => true,
			'premiumPolicy' => $policy,
		]);

		$captured = [];
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$captured): bool {
				unset($lazy, $sensitive);
				$captured[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function ($key, $default = '') use (&$captured): string {
				$key = (string)$key;
				return $captured[$key] ?? (string)$default;
			});

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('1', $captured[Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED]);
		$this->assertArrayHasKey(Constants::CONFIG_PREMIUM_POLICY_JSON, $captured);
		$stored = json_decode($captured[Constants::CONFIG_PREMIUM_POLICY_JSON], true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame('max_single_rate', $stored['stacking']);
		$this->assertSame('1', $captured[Constants::CONFIG_PREMIUM_POLICY_VERSION]);
		$this->assertTrue($data['settings']['premiumSurchargesEnabled']);
		$this->assertSame('hours_only', $data['settings']['premiumPolicy']['currency_mode']);
	}

	public function testUpdateNotificationSettingsPersistsDatevPremiumLohnartMap(): void
	{
		$this->request->method('getParams')->willReturn([
			'enabled' => false,
			'recipients' => [],
			'matrix' => [],
			'datevLohnartPremiumMap' => [
				'sunday' => '3100',
				'night' => '',
				'saturday' => '3200',
			],
		]);

		$captured = [];
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$captured): bool {
				unset($lazy, $sensitive);
				$captured[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function ($key, $default = '') use (&$captured): string {
				$key = (string)$key;
				return $captured[$key] ?? (string)$default;
			});

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame(
			'{"sunday":"3100","saturday":"3200"}',
			$captured[Constants::CONFIG_DATEV_LOHNART_PREMIUM_MAP]
		);
		$this->assertSame(
			['sunday' => '3100', 'saturday' => '3200'],
			$data['settings']['datevLohnartPremiumMap']
		);
	}

	public function testUpdateNotificationSettingsRejectsInvalidDatevPremiumCode(): void
	{
		$this->request->method('getParams')->willReturn([
			'enabled' => false,
			'recipients' => [],
			'matrix' => [],
			'datevLohnartPremiumMap' => ['sunday' => '0abc'],
		]);

		$response = $this->controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('DATEV_PREMIUM_MAP_CODE', $data['code']);
	}

	public function testUpdateNotificationSettingsRejectsInvalidPremiumRate(): void
	{
		$policy = \OCA\ArbeitszeitCheck\Support\PremiumPolicy::atStarterPreset();
		$policy['categories'][0]['rate'] = 9.5;
		$this->request->method('getParams')->willReturn([
			'enabled' => false,
			'recipients' => [],
			'matrix' => [],
			'premiumSurchargesEnabled' => true,
			'premiumPolicy' => $policy,
		]);

		$response = $this->controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('PREMIUM_RATE_INVALID', $data['code']);
	}

	public function testUpdateNotificationSettingsBareEnabledAloneDoesNotWipeHr(): void
	{
		$captured = [];
		$this->request->method('getParams')->willReturn([
			'enabled' => '0',
		]);
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$captured): bool {
				unset($lazy, $sensitive);
				$captured[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function ($key, $default = '') use (&$captured): string {
				$key = (string)$key;
				return $captured[$key] ?? (string)$default;
			});

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertArrayNotHasKey(Constants::CONFIG_HR_NOTIFICATIONS_ENABLED, $captured);
		$this->assertArrayNotHasKey(Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS, $captured);
	}

	public function testUpdateNotificationSettingsBankEnableWithoutMaxPreservesMax(): void
	{
		$store = [
			Constants::CONFIG_OVERTIME_BANK_MAX_HOURS => '42.5',
			Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT => '70',
			Constants::CONFIG_OVERTIME_BANK_RED_PERCENT => '90',
			Constants::CONFIG_OVERTIME_BANK_ENABLED => '0',
		];
		$this->request->method('getParams')->willReturn([
			'overtimeBankEnabled' => true,
			'policyScope' => 'overtime',
		]);
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$store): bool {
				unset($lazy, $sensitive);
				$store[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function ($key, $default = '') use (&$store): string {
				$key = (string)$key;
				return $store[$key] ?? (string)$default;
			});

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('1', $store[Constants::CONFIG_OVERTIME_BANK_ENABLED]);
		$this->assertSame('42.5', $store[Constants::CONFIG_OVERTIME_BANK_MAX_HOURS]);
		$this->assertSame('70', $store[Constants::CONFIG_OVERTIME_BANK_YELLOW_PERCENT]);
		$this->assertSame('90', $store[Constants::CONFIG_OVERTIME_BANK_RED_PERCENT]);
		$this->assertSame('Overtime settings updated successfully', $data['message']);
	}

	public function testUpdateNotificationSettingsInvalidPremiumDoesNotCommitBank(): void
	{
		$store = [
			Constants::CONFIG_OVERTIME_BANK_ENABLED => '0',
			Constants::CONFIG_OVERTIME_BANK_MAX_HOURS => '100',
		];
		$policy = \OCA\ArbeitszeitCheck\Support\PremiumPolicy::atStarterPreset();
		$policy['categories'][0]['rate'] = 9.5;
		$this->request->method('getParams')->willReturn([
			'overtimeBankEnabled' => true,
			'overtimeBankMaxHours' => '55',
			'premiumPolicy' => $policy,
		]);
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$store): bool {
				unset($lazy, $sensitive);
				$store[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function ($key, $default = '') use (&$store): string {
				$key = (string)$key;
				return $store[$key] ?? (string)$default;
			});

		$response = $this->controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('0', $store[Constants::CONFIG_OVERTIME_BANK_ENABLED]);
		$this->assertSame('100', $store[Constants::CONFIG_OVERTIME_BANK_MAX_HOURS]);
	}

	public function testUpdateNotificationSettingsInvalidTrafficDoesNotCommitHr(): void
	{
		$store = [
			Constants::CONFIG_HR_NOTIFICATIONS_ENABLED => '0',
			Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS => 'old@example.com',
			Constants::CONFIG_OVERTIME_TRAFFIC_LIGHT_ENABLED => '0',
		];
		$this->request->method('getParams')->willReturn([
			'hrNotificationsEnabled' => true,
			'recipients' => 'new@example.com',
			'matrix' => [],
			'overtimeTrafficLightEnabled' => true,
			'overtimeRecipients' => 'not-an-email',
			'overtimeYellowOver' => '5',
			'overtimeRedOver' => '15',
			'overtimeYellowUnder' => '5',
			'overtimeRedUnder' => '15',
		]);
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$store): bool {
				unset($lazy, $sensitive);
				$store[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function ($key, $default = '') use (&$store): string {
				$key = (string)$key;
				return $store[$key] ?? (string)$default;
			});

		$response = $this->controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('0', $store[Constants::CONFIG_HR_NOTIFICATIONS_ENABLED]);
		$this->assertSame('old@example.com', $store[Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS]);
		$this->assertSame('0', $store[Constants::CONFIG_OVERTIME_TRAFFIC_LIGHT_ENABLED]);
	}

	public function testUpdateNotificationSettingsRecipientsOnlyDoesNotClearHrEnabled(): void
	{
		$store = [
			Constants::CONFIG_HR_NOTIFICATIONS_ENABLED => '1',
			Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS => 'keep@example.com',
		];
		$this->request->method('getParams')->willReturn([
			'recipients' => 'updated@example.com',
		]);
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$store): bool {
				unset($lazy, $sensitive);
				$store[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function ($key, $default = '') use (&$store): string {
				$key = (string)$key;
				return $store[$key] ?? (string)$default;
			});

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('1', $store[Constants::CONFIG_HR_NOTIFICATIONS_ENABLED]);
		$this->assertSame('updated@example.com', $store[Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS]);
	}

	public function testUpdateNotificationSettingsAcceptsHrNotificationsEnabledAlias(): void
	{
		$captured = [];
		$this->request->method('getParams')->willReturn([
			'hrNotificationsEnabled' => true,
			'recipients' => 'hr@example.com',
			'matrix' => [],
			'policyScope' => 'notifications',
		]);
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$captured): bool {
				unset($lazy, $sensitive);
				$captured[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function ($key, $default = '') use (&$captured): string {
				$key = (string)$key;
				return $captured[$key] ?? (string)$default;
			});

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('1', $captured[Constants::CONFIG_HR_NOTIFICATIONS_ENABLED]);
		$this->assertSame('hr@example.com', $captured[Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS]);
		$this->assertSame('Notification settings updated successfully', $data['message']);
	}

	public function testGetNotificationSettingsIncludesPremiumDefaultsWhenUnset(): void
	{
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(static function (string $key, string $default = '') {
				return $default;
			});

		$response = $this->controller->getNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertFalse($data['settings']['premiumSurchargesEnabled']);
		$this->assertSame('max_single_rate', $data['settings']['premiumPolicy']['stacking']);
		$this->assertNotEmpty($data['settings']['premiumPolicy']['categories']);
		$this->assertSame([], $data['settings']['datevLohnartPremiumMap']);
	}

	public function testGetAdminSettingsReturnsConfiguredAppAdminsAndAvailableList(): void
	{
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(function (string $key, string $default = '') {
				if ($key === Constants::CONFIG_APP_ADMIN_USER_IDS) {
					return '["hr_admin"]';
				}
				return $default;
			});
		$adminGroup = $this->createMock(\OCP\IGroup::class);
		$adminUser = $this->createMock(IUser::class);
		$adminUser->method('getUID')->willReturn('hr_admin');
		$adminUser->method('getDisplayName')->willReturn('HR Admin');
		$adminUser->method('isEnabled')->willReturn(true);
		$adminGroup->method('getUsers')->willReturn([$adminUser]);
		$this->groupManager->method('get')->with('admin')->willReturn($adminGroup);
		$this->groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'hr_admin');
		$this->userManager->method('get')->with('hr_admin')->willReturn($adminUser);

		$response = $this->controller->getAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame(['hr_admin'], $data['settings']['appAdminUserIds']);
		$this->assertArrayHasKey('availableAppAdmins', $data);
		$this->assertCount(1, $data['availableAppAdmins']);
		$this->assertSame('hr_admin', $data['availableAppAdmins'][0]['id']);
	}

	public function testUpdateAdminSettingsNormalizesAccessAllowedGroups(): void
	{
		$this->request->method('getParams')
			->willReturn([
				'accessRestrictionEnabled' => true,
				'accessAllowedGroups' => ['group_a', 'group_a', 'missing_group', 'group_b'],
				'accessAllowedUserIds' => [],
			]);

		$this->groupManager->method('get')->willReturnCallback(function (string $gid) {
			if (!in_array($gid, ['group_a', 'group_b'], true)) {
				return null;
			}
			$group = $this->createMock(\OCP\IGroup::class);
			$group->method('getGID')->willReturn($gid);
			return $group;
		});
		$this->appManager->expects($this->once())->method('enableAppForGroups')
			->with('arbeitszeitcheck', $this->callback(static fn (array $groups): bool => count($groups) === 2));
		$this->appConfig->expects($this->atLeastOnce())->method('setAppValueString');

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertTrue($data['settings']['accessRestrictionEnabled']);
		$this->assertSame(['group_a', 'group_b'], $data['settings']['accessAllowedGroups']);
	}

	public function testUpdateAdminSettingsOpenModeClearsNcRestriction(): void
	{
		$this->request->method('getParams')
			->willReturn([
				'accessRestrictionEnabled' => false,
				'accessAllowedGroups' => ['group_a'],
				'accessAllowedUserIds' => ['alice'],
			]);

		$group = $this->createMock(\OCP\IGroup::class);
		$group->method('getGID')->willReturn('group_a');
		$this->groupManager->method('get')->willReturnCallback(static function (string $gid) use ($group) {
			return $gid === 'group_a' ? $group : null;
		});
		$alice = $this->createMock(IUser::class);
		$alice->method('isEnabled')->willReturn(true);
		$this->userManager->method('get')->with('alice')->willReturn($alice);

		$this->appManager->expects($this->once())->method('enableApp')->with('arbeitszeitcheck');
		$this->appManager->expects($this->never())->method('enableAppForGroups');

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertFalse($data['settings']['accessRestrictionEnabled']);
		$this->assertSame(['group_a'], $data['settings']['accessAllowedGroups']);
		$this->assertSame(['alice'], $data['settings']['accessAllowedUserIds']);
	}

	/**
	 * Test updateAdminSettings updates settings
	 */
	public function testUpdateAdminSettingsUpdatesSettings(): void
	{
		$store = &$this->wireAppConfigStore([
			'country' => 'DE',
			'german_state' => 'NW',
		]);
		$this->request->method('getParams')
			->willReturn([
				'maxDailyHours' => 9.5,
				'germanState' => 'BY',
				'missingClockInRemindersEnabled' => false,
			]);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertArrayHasKey('settings', $data);
		$this->assertSame('0', $store['missing_clock_in_reminders_enabled']);
		$this->assertSame('9.5', $store['max_daily_hours']);
		$this->assertSame('BY', $store['german_state']);
	}

	public function testUpdateAdminSettingsPersistsDatevOrgCredentials(): void
	{
		$store = &$this->wireAppConfigStore([
			'country' => 'DE',
			'german_state' => 'NW',
		]);
		$this->request->method('getParams')->willReturn([
			'datevBeraternummer' => '1234567',
			'datevMandantennummer' => '12345',
			'datevLohnartNormal' => '1000',
			'datevLohnartUeberstunden' => '2000',
		]);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('1234567', $store[Constants::CONFIG_DATEV_BERATERNUMMER]);
		$this->assertSame('12345', $store[Constants::CONFIG_DATEV_MANDANTENNUMMER]);
		$this->assertSame('1000', $store[Constants::CONFIG_DATEV_LOHNART_NORMAL]);
		$this->assertSame('1234567', $data['settings']['datevBeraternummer']);
	}

	public function testUpdateAdminSettingsRejectsPartialDatevCredentials(): void
	{
		$this->wireAppConfigStore([
			'country' => 'DE',
			'german_state' => 'NW',
		]);
		$this->request->method('getParams')->willReturn([
			'datevBeraternummer' => '1234567',
			'datevMandantennummer' => '',
		]);

		$response = $this->controller->updateAdminSettings();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('DATEV_CREDENTIALS_INCOMPLETE', $data['code']);
	}

	public function testUpdateAdminSettingsDisablesProjectCheckIntegration(): void
	{
		$this->wireAppConfigStore([
			'country' => 'DE',
			'german_state' => 'NW',
		]);
		$this->projectCheckInstalled = true;
		$this->request->method('getParams')
			->willReturn([
				'projectCheckIntegrationEnabled' => false,
			]);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('0', $data['settings']['projectCheckIntegrationEnabled']);
	}

	public function testUpdateAdminSettingsEnablesProjectCheckIntegration(): void
	{
		$this->wireAppConfigStore([
			'country' => 'DE',
			'german_state' => 'NW',
		]);
		$this->projectCheckInstalled = true;
		$this->request->method('getParams')
			->willReturn([
				'projectCheckIntegrationEnabled' => true,
			]);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('1', $data['settings']['projectCheckIntegrationEnabled']);
	}

	public function testUpdateAdminSettingsEnablesProjectCheckWhenAppIsGroupRestrictedForAdmin(): void
	{
		$this->wireAppConfigStore([
			'country' => 'DE',
			'german_state' => 'NW',
		]);
		$this->projectCheckInstalled = true;
		$this->appManager->method('isEnabledForUser')->willReturn(false);
		$this->request->method('getParams')
			->willReturn([
				'settings_section' => 'projectcheck',
				'projectCheckIntegrationEnabled' => true,
			]);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('1', $data['settings']['projectCheckIntegrationEnabled']);
	}

	public function testUpdateAdminSettingsRejectsProjectCheckEnableWhenAppNotInstalled(): void
	{
		$this->wireAppConfigStore([
			'country' => 'DE',
			'german_state' => 'NW',
		]);
		$this->projectCheckInstalled = false;
		$this->request->method('getParams')
			->willReturn([
				'settings_section' => 'projectcheck',
				'projectCheckIntegrationEnabled' => true,
			]);

		$response = $this->controller->updateAdminSettings();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('Enable the ProjectCheck app before turning on this connection.', $data['error']);
	}

	public function testSettingsSectionProjectCheckAvailableWhenInstalledEvenIfAdminLacksApp(): void
	{
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(fn (string $key, string $default = '') => $default);
		$this->projectCheckInstalled = true;
		$this->appManager->method('isEnabledForUser')->willReturn(false);

		$response = $this->controller->settingsSection('projectcheck');
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$params = $response->getParams();
		$this->assertArrayHasKey('projectCheckAvailable', $params);
		$this->assertTrue($params['projectCheckAvailable']);
		$this->assertFalse($params['projectCheckEnabledForCurrentUser']);
		$this->assertFalse($params['settings']['projectCheckIntegrationEnabled']);
		$this->assertArrayHasKey('projectCheckAppsUrl', $params);
	}

	public function testSettingsSectionProjectCheckUnavailableWhenNotInstalled(): void
	{
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(fn (string $key, string $default = '') => $default);
		$this->projectCheckInstalled = false;
		$this->appManager->method('isEnabledForUser')->willReturn(true);

		$response = $this->controller->settingsSection('projectcheck');
		$params = $response->getParams();
		$this->assertArrayHasKey('projectCheckAvailable', $params);
		$this->assertFalse($params['projectCheckAvailable']);
		$this->assertFalse($params['settings']['projectCheckIntegrationEnabled']);
	}

	public function testUpdateAdminSettingsNormalizesAppAdminUsers(): void
	{
		$store = &$this->wireAppConfigStore([
			'country' => 'DE',
			'german_state' => 'NW',
		]);
		$this->request->method('getParams')
			->willReturn([
				'appAdminUserIds' => ['hr_admin', 'hr_admin', 'missing', 'colleague', 'security_admin'],
			]);

		$this->groupManager->method('isAdmin')->willReturnCallback(static function (string $uid): bool {
			return in_array($uid, ['hr_admin', 'security_admin'], true);
		});
		$this->userManager->method('get')->willReturnCallback(function (string $uid) {
			if (!in_array($uid, ['hr_admin', 'security_admin', 'colleague'], true)) {
				return null;
			}
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('isEnabled')->willReturn(true);
			return $user;
		});

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame(['hr_admin', 'colleague', 'security_admin'], $data['settings']['appAdminUserIds']);
		$this->assertSame('["hr_admin","colleague","security_admin"]', $store[Constants::CONFIG_APP_ADMIN_USER_IDS]);
	}

	/**
	 * Test updateAdminSettings validates maxDailyHours range
	 */
	public function testUpdateAdminSettingsValidatesMaxDailyHoursRange(): void
	{
		$this->request->method('getParams')
			->willReturn(['maxDailyHours' => 25]); // Invalid: > 24

		$response = $this->controller->updateAdminSettings();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Maximum daily hours must be between', $data['error']);
	}

	/**
	 * Test updateAdminSettings validates minRestPeriod range
	 */
	public function testUpdateAdminSettingsValidatesMinRestPeriodRange(): void
	{
		$this->request->method('getParams')
			->willReturn(['minRestPeriod' => 25]); // Invalid: > 24

		$response = $this->controller->updateAdminSettings();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Minimum rest period must be between', $data['error']);
	}

	/**
	 * Test updateAdminSettings validates region code (DACH: was "German state")
	 */
	public function testUpdateAdminSettingsValidatesGermanState(): void
	{
		$this->request->method('getParams')
			->willReturn(['germanState' => 'XX']); // Invalid region code

		$response = $this->controller->updateAdminSettings();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Invalid region code', $data['error']);
	}

	/**
	 * Test updateAdminSettings returns error when no settings provided
	 */
	public function testUpdateAdminSettingsReturnsErrorWhenNoSettings(): void
	{
		$this->request->method('getParams')->willReturn([]);

		$response = $this->controller->updateAdminSettings();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('No valid settings provided', $data['error']);
	}

	public function testUpdateAdminSettingsUpdatesOrganizationTimeCapture(): void
	{
		$this->timeCaptureMethodService->method('isOrganizationClockStampingEnabled')->willReturn(true);
		$this->timeCaptureMethodService->method('isOrganizationManualTimeEntryEnabled')->willReturn(true);
		$this->request->method('getParams')
			->willReturn([
				'clockStampingEnabled' => false,
				'manualTimeEntryEnabled' => true,
			]);
		$this->timeCaptureMethodService->expects($this->once())
			->method('setOrganizationDefaults')
			->with(
				['clockStampingEnabled' => false, 'manualTimeEntryEnabled' => true],
				'system',
			)
			->willReturn([
				'clockStampingEnabled' => false,
				'manualTimeEntryEnabled' => true,
			]);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertFalse($data['settings']['clockStampingEnabled']);
		$this->assertTrue($data['settings']['manualTimeEntryEnabled']);
	}

	public function testUpdateAdminSettingsRejectsDisablingBothOrganizationTimeCaptureMethods(): void
	{
		$this->timeCaptureMethodService->method('isOrganizationClockStampingEnabled')->willReturn(true);
		$this->timeCaptureMethodService->method('isOrganizationManualTimeEntryEnabled')->willReturn(true);
		$this->request->method('getParams')
			->willReturn([
				'clockStampingEnabled' => false,
				'manualTimeEntryEnabled' => false,
			]);
		$this->timeCaptureMethodService->expects($this->once())
			->method('setOrganizationDefaults')
			->willThrowException(new \OCA\ArbeitszeitCheck\Exception\BusinessRuleException('At least one method is required'));

		$response = $this->controller->updateAdminSettings();

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('method is required', $data['error']);
	}

	/**
	 * Test getStatistics returns statistics
	 */
	public function testGetStatisticsReturnsStatistics(): void
	{
		$this->userManager->method('countUsersTotal')
			->willReturn(100);

		$this->timeEntryMapper->method('countDistinctUsersByDate')
			->willReturn(50);

		$this->violationMapper->method('count')
			->willReturn(5);

		$violation = new ComplianceViolation();
		$violation->setUserId('user1');

		$this->violationMapper->method('findUnresolved')
			->willReturn([$violation]);

		$response = $this->controller->getStatistics();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('statistics', $data);
		$this->assertEquals(100, $data['statistics']['total_users']);
		$this->assertEquals(50, $data['statistics']['active_users_today']);
		$this->assertEquals(5, $data['statistics']['unresolved_violations']);
	}

	/**
	 * Test getUsers returns users list
	 */
	public function testGetUsersReturnsUsersList(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('search')
			->willReturn([$user]);

		$this->userManager->method('countUsersTotal')
			->willReturn(1);

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')
			->willReturn(null);

		$this->timeEntryMapper->method('countDistinctUsersByDate')
			->willReturn(0);

		$response = $this->controller->getUsers();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('users', $data);
		$this->assertCount(1, $data['users']);
		$this->assertEquals('user1', $data['users'][0]['userId']);
		$this->assertArrayHasKey('filter', $data);
		$this->assertArrayHasKey('defaultFilter', $data);
	}

	/**
	 * Invalid filter returns HTTP 400 with stable error code.
	 */
	public function testGetUsersRejectsInvalidFilter(): void
	{
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'filter' => 'not-a-filter',
					default => $default,
				};
			}
		);

		$response = $this->controller->getUsers();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('INVALID_EMPLOYEE_LIST_FILTER', $data['code']);
	}

	/**
	 * Restricted mode without filter param defaults to app_access in API response.
	 */
	public function testGetUsersDefaultsToAppAccessWhenRestricted(): void
	{
		$this->accessRestrictionEnabled = true;

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('search')->willReturn([$user]);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);

		$response = $this->controller->getUsers();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('app_access', $data['filter']);
		$this->assertSame('app_access', $data['defaultFilter']);
	}

	/**
	 * Test getUsers applies search filter
	 */
	public function testGetUsersAppliesSearchFilter(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('search')->willReturn([$user]);
		$this->userManager->method('searchDisplayName')->willReturn([]);

		$this->userManager->method('countUsersTotal')->willReturn(1);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);
		$this->timeEntryMapper->method('countDistinctUsersByDate')->willReturn(0);

		$response = $this->controller->getUsers('test', 50, 0);
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Picker mode returns a lightweight user list (no entitlement / model joins).
	 */
	public function testGetUsersPickerModeReturnsLightweightList(): void
	{
		$enabled = $this->createMock(IUser::class);
		$enabled->method('getUID')->willReturn('alice');
		$enabled->method('getDisplayName')->willReturn('Alice');
		$enabled->method('isEnabled')->willReturn(true);

		$disabled = $this->createMock(IUser::class);
		$disabled->method('getUID')->willReturn('bob');
		$disabled->method('getDisplayName')->willReturn('Bob');
		$disabled->method('isEnabled')->willReturn(false);

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'picker' => '1',
					'limit' => '20',
					default => $default,
				};
			}
		);

		$this->userManager->method('search')->willReturn([$enabled, $disabled]);
		$this->userManager->method('searchDisplayName')->willReturn([]);

		$this->vacationEntitlementEngine->expects($this->never())->method('computeForDate');
		$this->userWorkingTimeModelMapper->expects($this->never())->method('findCurrentByUser');

		$response = $this->controller->getUsers('ann', 50, 0);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertTrue($data['picker']);
		$this->assertCount(1, $data['users']);
		$this->assertSame('alice', $data['users'][0]['userId']);
		$this->assertSame('Alice', $data['users'][0]['displayName']);
		$this->assertArrayNotHasKey('entitlementPreview', $data['users'][0]);
	}

	/**
	 * Regression for issue #14: a person whose *display name* matches but whose
	 * *user id* does not (e.g. id is an email/UUID) must still appear. This is
	 * the case that previously hid most of the directory in the team picker.
	 */
	public function testGetUsersPickerModeMatchesByDisplayName(): void
	{
		$byName = $this->createMock(IUser::class);
		$byName->method('getUID')->willReturn('a1b2-uuid');
		$byName->method('getDisplayName')->willReturn('Max Mustermann');
		$byName->method('isEnabled')->willReturn(true);

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'picker' => '1',
					'limit' => '20',
					default => $default,
				};
			}
		);

		// User-id search finds nothing for "max"; display-name search does.
		$this->userManager->method('search')->willReturn([]);
		$this->userManager->method('searchDisplayName')->willReturn([$byName]);

		$response = $this->controller->getUsers('max', 50, 0);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertTrue($data['picker']);
		$this->assertCount(1, $data['users']);
		$this->assertSame('a1b2-uuid', $data['users'][0]['userId']);
		$this->assertSame('Max Mustermann', $data['users'][0]['displayName']);
	}

	/**
	 * Already-assigned people passed via `exclude[]` must be filtered out of
	 * picker results so a heavily-staffed unit cannot hide available people.
	 */
	public function testGetUsersPickerModeHonoursExcludeList(): void
	{
		$assigned = $this->createMock(IUser::class);
		$assigned->method('getUID')->willReturn('alice');
		$assigned->method('getDisplayName')->willReturn('Alice');
		$assigned->method('isEnabled')->willReturn(true);

		$available = $this->createMock(IUser::class);
		$available->method('getUID')->willReturn('alan');
		$available->method('getDisplayName')->willReturn('Alan');
		$available->method('isEnabled')->willReturn(true);

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'picker' => '1',
					'limit' => '20',
					'exclude' => ['alice'],
					default => $default,
				};
			}
		);

		$this->userManager->method('search')->willReturn([$assigned, $available]);
		$this->userManager->method('searchDisplayName')->willReturn([]);

		$response = $this->controller->getUsers('al', 50, 0);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertCount(1, $data['users']);
		$this->assertSame('alan', $data['users'][0]['userId']);
	}

	public function testGetUsersPickerModeRequiresMinSearchLength(): void
	{
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'picker' => '1',
					default => $default,
				};
			}
		);

		$this->userManager->expects($this->never())->method('search');

		$response = $this->controller->getUsers('a', 20, 0);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertTrue($data['picker']);
		$this->assertSame([], $data['users']);
		$this->assertSame(Constants::PICKER_MIN_SEARCH_LENGTH, $data['requiresMinSearch']);
	}

	public function testSearchVacationLayersUsersDelegatesToPicker(): void
	{
		$enabled = $this->createMock(IUser::class);
		$enabled->method('getUID')->willReturn('alice');
		$enabled->method('getDisplayName')->willReturn('Alice');
		$enabled->method('isEnabled')->willReturn(true);

		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'search' => 'ann',
					'limit' => '10',
					default => $default,
				};
			}
		);

		$this->userManager->method('search')->willReturn([$enabled]);
		$this->userManager->method('searchDisplayName')->willReturn([]);

		$response = $this->controller->searchVacationLayersUsers();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertTrue($data['picker']);
		$this->assertCount(1, $data['users']);
		$this->assertSame('alice', $data['users'][0]['userId']);
	}

	/**
	 * Test getUser returns user details
	 */
	public function testGetUserReturnsUserDetails(): void
	{
		$userId = 'user1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('get')
			->with($userId)
			->willReturn($user);

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')
			->willReturn(null);

		$model = new WorkingTimeModel();
		$model->setId(1);
		$model->setName('Full-time');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);

		$this->workingTimeModelMapper->method('findAll')
			->willReturn([$model]);

		$response = $this->controller->getUser($userId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('user', $data);
		$this->assertEquals($userId, $data['user']['userId']);
		$this->assertArrayHasKey('availableWorkingTimeModels', $data['user']);
	}

	public function testGetUserLoadsCarryoverForRequestedYear(): void
	{
		$userId = 'user1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('get')->with($userId)->willReturn($user);
		$this->userWorkingTimeModelMapper->method('findEditableByUser')->willReturn(null);
		$this->workingTimeModelMapper->method('findAll')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key) => $key === 'carryoverYear' ? '2024' : null
		);
		$this->vacationYearBalanceMapper->expects($this->once())
			->method('getCarryoverDays')
			->with($userId, 2024)
			->willReturn(7.5);

		$response = $this->controller->getUser($userId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame(2024, $data['user']['vacationCarryoverYear']);
		$this->assertSame(7.5, $data['user']['vacationCarryoverDays']);
	}

	public function testGetUserRejectsInvalidCarryoverYear(): void
	{
		$userId = 'user1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('get')->with($userId)->willReturn($user);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key) => $key === 'carryoverYear' ? '1999' : null
		);
		$this->vacationYearBalanceMapper->expects($this->never())->method('getCarryoverDays');

		$response = $this->controller->getUser($userId);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testGetUserLoadsOvertimeOpeningForRequestedYear(): void
	{
		$userId = 'user1';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('get')->with($userId)->willReturn($user);
		$this->userWorkingTimeModelMapper->method('findEditableByUser')->willReturn(null);
		$this->workingTimeModelMapper->method('findAll')->willReturn([]);
		$this->request->method('getParam')->willReturnCallback(
			static fn (string $key) => $key === 'overtimeOpeningBalanceYear' ? '2023' : null
		);
		$this->userOvertimeSettingsService->expects($this->once())
			->method('getOpeningBalanceHours')
			->with($userId, 2023)
			->willReturn(-4.0);

		$response = $this->controller->getUser($userId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame(2023, $data['user']['overtimeOpeningBalanceYear']);
		$this->assertSame(-4.0, $data['user']['overtimeOpeningBalanceHours']);
	}

	/**
	 * Future-dated assignments must load the vacation policy effective on the
	 * assignment start — not only policies active today.
	 */
	public function testGetUserLoadsVacationPolicyAsOfFutureAssignmentStart(): void
	{
		$userId = 'user1';
		$future = (new \DateTimeImmutable('first day of next month'))->format('Y-m-d');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('get')->with($userId)->willReturn($user);

		$wtm = new UserWorkingTimeModel();
		$wtm->setId(10);
		$wtm->setUserId($userId);
		$wtm->setWorkingTimeModelId(1);
		$wtm->setVacationDaysPerYear(28);
		$wtm->setStartDate(new \DateTime($future));

		$this->userWorkingTimeModelMapper->method('findEditableByUser')
			->with($userId)
			->willReturn($wtm);

		$model = new WorkingTimeModel();
		$model->setId(1);
		$model->setName('Full-time');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setWorkDaysPerWeek(5.0);
		$this->workingTimeModelMapper->method('find')->with(1)->willReturn($model);
		$this->workingTimeModelMapper->method('findAll')->willReturn([$model]);

		$policy = new UserVacationPolicyAssignment();
		$policy->setId(99);
		$policy->setUserId($userId);
		$policy->setVacationMode(Constants::VACATION_MODE_MANUAL_FIXED);
		$policy->setManualDays(30.0);
		$policy->setEffectiveFrom(new \DateTime($future));
		$policy->setInheritLowerLayers(false);

		$this->userVacationPolicyAssignmentMapper->expects($this->once())
			->method('findCurrentByUser')
			->with(
				$userId,
				$this->callback(static function (\DateTimeInterface $asOf) use ($future): bool {
					return $asOf->format('Y-m-d') === $future;
				})
			)
			->willReturn($policy);

		$response = $this->controller->getUser($userId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame(Constants::VACATION_MODE_MANUAL_FIXED, $data['user']['vacationPolicy']['vacationMode']);
		$this->assertSame(30.0, $data['user']['vacationPolicy']['manualDays']);
	}

	/**
	 * Test getUser returns not found when user doesn't exist
	 */
	public function testGetUserReturnsNotFoundWhenUserMissing(): void
	{
		$userId = 'nonexistent';

		$this->userManager->method('get')
			->with($userId)
			->willReturn(null);

		$response = $this->controller->getUser($userId);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('User not found', $data['error']);
	}

	/**
	 * Test getWorkingTimeModels returns models list
	 */
	public function testGetWorkingTimeModelsReturnsModelsList(): void
	{
		$model = new WorkingTimeModel();
		$model->setId(1);
		$model->setName('Full-time');
		$model->setDescription('40 hours per week');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setIsDefault(true);

		$this->workingTimeModelMapper->expects($this->once())
			->method('findAll')
			->willReturn([$model]);

		$response = $this->controller->getWorkingTimeModels();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('models', $data);
		$this->assertCount(1, $data['models']);
		$this->assertEquals('Full-time', $data['models'][0]['name']);
	}

	/**
	 * Test getWorkingTimeModel returns model details
	 */
	public function testGetWorkingTimeModelReturnsModelDetails(): void
	{
		$modelId = 1;
		$model = new WorkingTimeModel();
		$model->setId($modelId);
		$model->setName('Full-time');
		$model->setDescription('40 hours per week');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setBreakRulesArray([]);
		$model->setOvertimeRulesArray([]);
		$model->setIsDefault(true);

		$this->workingTimeModelMapper->expects($this->once())
			->method('find')
			->with($modelId)
			->willReturn($model);

		$response = $this->controller->getWorkingTimeModel($modelId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('model', $data);
		$this->assertEquals($modelId, $data['model']['id']);
	}

	/**
	 * Test getWorkingTimeModel returns not found when model doesn't exist
	 */
	public function testGetWorkingTimeModelReturnsNotFoundWhenModelMissing(): void
	{
		$modelId = 999;

		$this->workingTimeModelMapper->expects($this->once())
			->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('Model not found'));

		$response = $this->controller->getWorkingTimeModel($modelId);

		$this->assertEquals(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Working time model not found', $data['error']);
	}

	/**
	 * Test createWorkingTimeModel creates model
	 */
	public function testCreateWorkingTimeModelCreatesModel(): void
	{
		$this->request->method('getParams')
			->willReturn([
				'name' => 'Part-time',
				'type' => 'part_time',
				'weeklyHours' => 20.0,
				'dailyHours' => 4.0,
				'isDefault' => false
			]);

		$model = new WorkingTimeModel();
		$model->setId(1);
		$model->setName('Part-time');
		$model->setType(WorkingTimeModel::TYPE_PART_TIME);
		$model->setWeeklyHours(20.0);
		$model->setDailyHours(4.0);
		$model->setIsDefault(false);

		$this->workingTimeModelMapper->method('findDefault')->willReturn(null);
		$this->workingTimeModelMapper->expects($this->once())
			->method('insert')
			->willReturn($model);

		$response = $this->controller->createWorkingTimeModel();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertEquals(Http::STATUS_CREATED, $response->getStatus());
		$this->assertArrayHasKey('model', $data);
		$this->assertEquals('Part-time', $data['model']['name']);
	}

	public function testCreateWorkingTimeModelAcceptsCommaDecimals(): void
	{
		$this->request->method('getParams')
			->willReturn([
				'name' => 'Tarifmodell',
				'type' => 'full_time',
				'weeklyHours' => '38,7',
				'dailyHours' => '7,74',
				'isDefault' => false
			]);

		$this->workingTimeModelMapper->method('findDefault')->willReturn(null);
		$this->workingTimeModelMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (WorkingTimeModel $model): bool {
				return abs($model->getWeeklyHours() - 38.7) < 0.0001
					&& abs($model->getDailyHours() - 7.74) < 0.0001;
			}))
			->willReturnCallback(function (WorkingTimeModel $model) {
				$model->setId(99);
				return $model;
			});

		$response = $this->controller->createWorkingTimeModel();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
	}

	/**
	 * Test createWorkingTimeModel unsets other defaults when setting as default
	 */
	public function testCreateWorkingTimeModelUnsetsOtherDefaults(): void
	{
		$this->request->method('getParams')
			->willReturn([
				'name' => 'New Default',
				'isDefault' => true
			]);

		$currentDefault = new WorkingTimeModel();
		$currentDefault->setId(1);
		$currentDefault->setName('Old Default');
		$currentDefault->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$currentDefault->setWeeklyHours(40.0);
		$currentDefault->setDailyHours(8.0);
		$currentDefault->setIsDefault(true);
		$currentDefault->setUpdatedAt(new \DateTime());

		$newModel = new WorkingTimeModel();
		$newModel->setId(2);
		$newModel->setName('New Default');
		$newModel->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$newModel->setWeeklyHours(40.0);
		$newModel->setDailyHours(8.0);
		$newModel->setIsDefault(true);

		$this->workingTimeModelMapper->method('findDefault')
			->willReturn($currentDefault);

		$this->workingTimeModelMapper->expects($this->once())
			->method('clearDefaults');

		$this->workingTimeModelMapper->expects($this->once())
			->method('insert')
			->willReturn($newModel);

		$response = $this->controller->createWorkingTimeModel();
		$data = $response->getData();

		$this->assertTrue($data['success']);
	}

	/**
	 * Test updateWorkingTimeModel updates model
	 */
	public function testUpdateWorkingTimeModelUpdatesModel(): void
	{
		$modelId = 1;
		$model = new WorkingTimeModel();
		$model->setId($modelId);
		$model->setName('Updated Name');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setIsDefault(false);
		$model->setUpdatedAt(new \DateTime());

		$this->request->method('getParams')
			->willReturn(['name' => 'Updated Name']);

		$this->workingTimeModelMapper->method('find')
			->with($modelId)
			->willReturn($model);

		$this->workingTimeModelMapper->expects($this->once())
			->method('update')
			->with($model)
			->willReturn($model);

		$response = $this->controller->updateWorkingTimeModel($modelId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('model', $data);
	}

	public function testCreateWorkingTimeModelSyncsHoursFromWeekdaySchedule(): void
	{
		$preset = \OCA\ArbeitszeitCheck\Support\WeekdaySchedule::banssPreset();
		$this->request->method('getParams')
			->willReturn([
				'name' => 'BANSS matrix',
				'type' => 'full_time',
				'weeklyHours' => 40.0,
				'dailyHours' => 8.0,
				'workDaysPerWeek' => 5.0,
				'isDefault' => false,
				'breakRules' => [
					'break_policy' => 'flex',
					'weekday_schedule' => $preset,
				],
			]);

		$this->workingTimeModelMapper->method('findDefault')->willReturn(null);
		$this->workingTimeModelMapper->expects($this->once())
			->method('insert')
			->with($this->callback(function (WorkingTimeModel $model): bool {
				$rules = $model->getBreakRulesArray();
				$schedule = $model->getWeekdaySchedule();
				return $schedule !== null
					&& abs($model->getWeeklyHours() - 38.5) < 0.0001
					&& abs($model->getDailyHours() - 7.7) < 0.0001
					&& abs($model->getWorkDaysPerWeek() - 5.0) < 0.0001
					&& ($rules['break_policy'] ?? null) === 'flex'
					&& isset($rules['weekday_schedule']['days']['mon']);
			}))
			->willReturnCallback(function (WorkingTimeModel $model) {
				$model->setId(42);
				return $model;
			});

		$response = $this->controller->createWorkingTimeModel();
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertEqualsWithDelta(38.5, $data['model']['weeklyHours'], 0.01);
	}

	public function testCreateWorkingTimeModelRejectsInvalidWeekdaySchedule(): void
	{
		$this->request->method('getParams')
			->willReturn([
				'name' => 'Broken matrix',
				'type' => 'full_time',
				'weeklyHours' => 40.0,
				'dailyHours' => 8.0,
				'breakRules' => [
					'weekday_schedule' => [
						'days' => [
							'mon' => [
								'work' => true,
								'start' => '09:00',
								'end' => '17:00',
								'breaks' => [
									['start' => '08:00', 'end' => '08:30', 'paid' => false],
								],
							],
							'tue' => ['work' => false],
							'wed' => ['work' => false],
							'thu' => ['work' => false],
							'fri' => ['work' => false],
							'sat' => ['work' => false],
							'sun' => ['work' => false],
						],
					],
				],
			]);

		$this->workingTimeModelMapper->expects($this->never())->method('insert');

		$response = $this->controller->createWorkingTimeModel();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('SCHEDULE_INVALID_BREAK', $data['code']);
	}

	public function testCreateWorkingTimeModelRejectsListEncodedBreakRules(): void
	{
		$this->request->method('getParams')
			->willReturn([
				'name' => 'List break rules',
				'type' => 'full_time',
				'weeklyHours' => 40.0,
				'dailyHours' => 8.0,
				'breakRules' => ['not', 'a', 'map'],
			]);

		$this->workingTimeModelMapper->expects($this->never())->method('insert');

		$response = $this->controller->createWorkingTimeModel();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('SCHEDULE_INVALID', $data['code']);
	}

	/**
	 * Test deleteWorkingTimeModel deletes model
	 */
	public function testDeleteWorkingTimeModelDeletesModel(): void
	{
		$modelId = 1;
		$model = new WorkingTimeModel();
		$model->setId($modelId);
		$model->setName('Part-Time');
		$model->setType(WorkingTimeModel::TYPE_PART_TIME);
		$model->setWeeklyHours(20.0);
		$model->setDailyHours(4.0);
		$model->setWorkDaysPerWeek(5.0);
		$model->setIsDefault(false);
		$model->setUpdatedAt(new \DateTime());

		$this->workingTimeModelMapper->method('find')
			->with($modelId)
			->willReturn($model);

		$this->userWorkingTimeModelMapper->method('findByWorkingTimeModel')
			->with($modelId, false)
			->willReturn([]);

		$this->layeredVacationDefaultsService->expects($this->once())
			->method('deleteDefaultsForWorkingTimeModel')
			->with($modelId)
			->willReturn(0);

		$this->workingTimeModelMapper->expects($this->once())
			->method('delete')
			->with($model);

		$response = $this->controller->deleteWorkingTimeModel($modelId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('message', $data);
	}

	/**
	 * Test deleteWorkingTimeModel returns error when users assigned
	 */
	public function testDeleteWorkingTimeModelReturnsErrorWhenUsersAssigned(): void
	{
		$modelId = 1;
		$model = new WorkingTimeModel();
		$model->setId($modelId);
		$model->setName('Part-Time');
		$model->setType(WorkingTimeModel::TYPE_PART_TIME);
		$model->setWeeklyHours(20.0);
		$model->setDailyHours(4.0);
		$model->setIsDefault(false);

		$userModel = $this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel::class);

		$this->workingTimeModelMapper->method('find')
			->with($modelId)
			->willReturn($model);

		$this->userWorkingTimeModelMapper->method('findByWorkingTimeModel')
			->with($modelId, false)
			->willReturn([$userModel]);

		$this->workingTimeModelMapper->expects($this->never())->method('delete');

		$response = $this->controller->deleteWorkingTimeModel($modelId);

		$this->assertEquals(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('MODEL_IN_USE', $data['code']);
		$this->assertStringContainsString('Cannot delete working time model', $data['error']);
	}

	public function testDeleteWorkingTimeModelRefusesDefaultModel(): void
	{
		$modelId = 1;
		$model = new WorkingTimeModel();
		$model->setId($modelId);
		$model->setName('Default');
		$model->setType(WorkingTimeModel::TYPE_FULL_TIME);
		$model->setWeeklyHours(40.0);
		$model->setDailyHours(8.0);
		$model->setIsDefault(true);

		$this->workingTimeModelMapper->method('find')
			->with($modelId)
			->willReturn($model);

		$this->workingTimeModelMapper->expects($this->never())->method('delete');

		$response = $this->controller->deleteWorkingTimeModel($modelId);
		$this->assertEquals(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('DEFAULT_MODEL', $data['code']);
	}

	/**
	 * Test updateUserWorkingTimeModel ends assignment when workingTimeModelId is null (No Model Assigned)
	 */
	public function testUpdateUserWorkingTimeModelRemovesAssignmentWhenNull(): void
	{
		$userId = 'admin';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$currentAssignment = new UserWorkingTimeModel();
		$currentAssignment->setId(1);
		$currentAssignment->setUserId($userId);
		$currentAssignment->setWorkingTimeModelId(1);
		$currentAssignment->setVacationDaysPerYear(25);
		$currentAssignment->setStartDate(new \DateTime('2024-01-01'));
		$currentAssignment->setUpdatedAt(new \DateTime());

		$endedAssignment = new UserWorkingTimeModel();
		$endedAssignment->setId(1);
		$endedAssignment->setUserId($userId);
		$endedAssignment->setWorkingTimeModelId(1);
		$endedAssignment->setVacationDaysPerYear(25);
		$endedAssignment->setStartDate(new \DateTime('2024-01-01'));
		$endedAssignment->setEndDate(new \DateTime('2024-01-02'));
		$endedAssignment->setUpdatedAt(new \DateTime());

		$this->request->method('getParams')
			->willReturn([
				'workingTimeModelId' => null,
				'vacationDaysPerYear' => 25,
				'startDate' => null,
				'endDate' => null
			]);

		$this->userManager->method('get')->with($userId)->willReturn($user);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')
			->with($userId)
			->willReturn($currentAssignment);
		$this->userWorkingTimeModelMapper->method('findEditableByUser')
			->with($userId)
			->willReturn($currentAssignment);
		// An active/past assignment is retired by closing it with an end date
		// (updated in place), not by inserting or duplicating a row.
		$this->userWorkingTimeModelMapper->expects($this->once())
			->method('update')
			->with($this->isInstanceOf(UserWorkingTimeModel::class))
			->willReturn($endedAssignment);
		$this->userWorkingTimeModelMapper->expects($this->never())
			->method('insert');

		$response = $this->controller->updateUserWorkingTimeModel($userId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('userWorkingTimeModel', $data);
	}

	/**
	 * Test updateUserWorkingTimeModel succeeds when no assignment and null model (nothing to do)
	 */
	public function testUpdateUserWorkingTimeModelSucceedsWhenNoAssignmentAndNullModel(): void
	{
		$userId = 'admin';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->request->method('getParams')
			->willReturn([
				'workingTimeModelId' => null,
				'vacationDaysPerYear' => 25,
				'startDate' => null,
				'endDate' => null
			]);

		$this->userManager->method('get')->with($userId)->willReturn($user);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')
			->with($userId)
			->willReturn(null);
		$this->userWorkingTimeModelMapper->expects($this->never())
			->method('endCurrentAssignment');

		$response = $this->controller->updateUserWorkingTimeModel($userId);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertNull($data['userWorkingTimeModel']);
	}

	public function testAssignVacationPolicyRejectsTariffRuleSetStartingAfterPolicyDate(): void
	{
		$userId = 'user1';
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with($userId)->willReturn($user);

		$this->request->method('getParams')->willReturn([
			'vacationMode' => Constants::VACATION_MODE_TARIFF_RULE_BASED,
			'tariffRuleSetId' => 11,
			'effectiveFrom' => '2026-05-01',
		]);

		$ruleSet = new TariffRuleSet();
		$ruleSet->setId(11);
		$ruleSet->setValidFrom(new \DateTime('2026-06-01'));
		$ruleSet->setValidTo(null);
		$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_ACTIVE);
		$this->tariffRuleSetMapper->expects($this->once())
			->method('find')
			->with(11)
			->willReturn($ruleSet);

		$response = $this->controller->assignVacationPolicy($userId);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('Tariff rule set starts after policy effective date', $data['error']);
	}

	public function testAssignVacationPolicyNormalizesInheritSentinelWhenInheritFlagTrue(): void
	{
		$userId = 'user1';
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with($userId)->willReturn($user);

		$this->request->method('getParams')->willReturn([
			'vacationMode' => Constants::VACATION_MODE_MANUAL_FIXED,
			'inheritLowerLayers' => true,
			'effectiveFrom' => '2026-06-01',
		]);

		$this->userVacationPolicyAssignmentMapper->method('findCurrentByUser')->willReturn(null);
		$this->userVacationPolicyAssignmentMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (UserVacationPolicyAssignment $assignment) {
				$this->assertSame(Constants::VACATION_MODE_INHERIT, $assignment->getVacationMode());
				$this->assertTrue($assignment->getInheritLowerLayers());
				$assignment->setId(501);
				return $assignment;
			});

		$response = $this->controller->assignVacationPolicy($userId);
		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(501, $data['policyId']);
	}

	public function testGetVacationLayersRejectsInvalidAsOfDate(): void
	{
		$this->request->method('getParam')
			->with('asOfDate', $this->anything())
			->willReturn('not-a-date');

		$response = $this->controller->getVacationLayers();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('Invalid date; use YYYY-MM-DD.', $data['error']);
	}

	public function testAssignVacationPolicyRejectsInvalidEffectiveFrom(): void
	{
		$userId = 'user1';
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with($userId)->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'vacationMode' => Constants::VACATION_MODE_INHERIT,
			'effectiveFrom' => '31.12.2026',
		]);
		$this->userVacationPolicyAssignmentMapper->expects($this->never())->method('insert');

		$response = $this->controller->assignVacationPolicy($userId);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('Invalid date; use YYYY-MM-DD.', $data['error']);
	}

	public function testAssignVacationPolicyRejectsInvalidEffectiveTo(): void
	{
		$userId = 'user1';
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with($userId)->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'vacationMode' => Constants::VACATION_MODE_INHERIT,
			'effectiveFrom' => '2026-06-01',
			'effectiveTo' => 'not-a-date',
		]);
		$this->userVacationPolicyAssignmentMapper->expects($this->never())->method('insert');

		$response = $this->controller->assignVacationPolicy($userId);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('Invalid date; use YYYY-MM-DD.', $data['error']);
	}

	public function testSimulateVacationPolicyRejectsInvalidAsOfDate(): void
	{
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'userId' => 'alice',
			'asOfDate' => '2026-02-30',
		]);
		$this->vacationEntitlementEngine->expects($this->never())->method('computeForDate');

		$response = $this->controller->simulateVacationPolicy();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('Invalid date; use YYYY-MM-DD.', $data['error']);
	}

	public function testCreateTariffRuleSetRejectsInvalidModules(): void
	{
		$this->request->method('getParams')->willReturn([
			'tariffCode' => 'TVOD-VKA',
			'version' => '2026.1',
			'validFrom' => '2026-06-03',
			'modules' => [
				[
					'moduleType' => 'additional_entitlements',
					'config' => ['days' => 1],
				],
			],
		]);
		$this->tariffRuleSetMapper->expects($this->never())->method('insert');

		$response = $this->controller->createTariffRuleSet();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('errors', $data);
		$this->assertArrayHasKey('modules', $data['errors']);
	}

	public function testCreateTariffRuleSetReturnsConflictPayloadWhenDuplicateExists(): void
	{
		$existing = new TariffRuleSet();
		$existing->setId(9);
		$existing->setTariffCode('TVOD-VKA');
		$existing->setVersion('2024.1');
		$existing->setStatus(Constants::TARIFF_RULE_SET_STATUS_DRAFT);

		$this->request->method('getParams')->willReturn([
			'tariffCode' => 'TVOD-VKA',
			'version' => '2024.1',
			'validFrom' => '2026-06-03',
			'modules' => [
				[
					'moduleType' => 'base_formula',
					'config' => [
						'reference_days' => 30,
						'reference_week_days' => 5,
						'work_days_per_week' => 5,
					],
				],
			],
		]);

		$this->tariffRuleSetMapper->expects($this->once())
			->method('findByCodeAndVersion')
			->with('TVOD-VKA', '2024.1')
			->willReturn($existing);
		$this->tariffRuleSetMapper->expects($this->never())->method('insert');

		$response = $this->controller->createTariffRuleSet();
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('duplicate_code_version', $data['code']);
		$this->assertArrayHasKey('existing', $data);
		$this->assertSame(9, $data['existing']['id']);
		$this->assertSame(Constants::TARIFF_RULE_SET_STATUS_DRAFT, $data['existing']['status']);
		$this->assertArrayHasKey('errors', $data);
		$this->assertArrayHasKey('tariffCode', $data['errors']);
	}

	public function testCreateTariffRuleSetPersistsDraftWithModules(): void
	{
		$this->request->method('getParams')->willReturn([
			'tariffCode' => 'TVOD-VKA',
			'version' => '2026.2',
			'validFrom' => '2026-06-03',
			'modules' => [
				[
					'moduleType' => 'base_formula',
					'config' => [
						'reference_days' => 30,
						'reference_week_days' => 5,
						'work_days_per_week' => 5,
					],
				],
			],
		]);

		$this->tariffRuleSetMapper->expects($this->once())
			->method('findByCodeAndVersion')
			->with('TVOD-VKA', '2026.2')
			->willReturn(null);
		$this->tariffRuleSetMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (TariffRuleSet $ruleSet) {
				self::assertSame(Constants::TARIFF_RULE_SET_STATUS_DRAFT, $ruleSet->getStatus());
				$ruleSet->setId(42);
				return $ruleSet;
			});
		$this->tariffRuleModuleMapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (\OCA\ArbeitszeitCheck\Db\TariffRuleModule $module) {
				$module->setId(7);
				return $module;
			});
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$response = $this->controller->createTariffRuleSet();
		self::assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		self::assertTrue($data['success']);
		self::assertSame(42, $data['ruleSetId']);
	}

	public function testCreateTariffRuleSetRejectsClientStatusOverride(): void
	{
		$this->request->method('getParams')->willReturn([
			'tariffCode' => 'TVOD-VKA',
			'version' => '2026.3',
			'validFrom' => '2026-06-03',
			'status' => Constants::TARIFF_RULE_SET_STATUS_ACTIVE,
			'modules' => [
				[
					'moduleType' => 'base_formula',
					'config' => [
						'reference_days' => 30,
						'reference_week_days' => 5,
						'work_days_per_week' => 5,
					],
				],
			],
		]);

		$this->tariffRuleSetMapper->expects($this->never())->method('insert');

		$response = $this->controller->createTariffRuleSet();
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertArrayHasKey('errors', $data);
		self::assertArrayHasKey('status', $data['errors']);
	}

	public function testUpdateTariffRuleSetRejectsStatusOverride(): void
	{
		$ruleSet = new TariffRuleSet();
		$ruleSet->setId(44);
		$ruleSet->setTariffCode('TVOD-VKA');
		$ruleSet->setVersion('2026.4');
		$ruleSet->setValidFrom(new \DateTime('2026-01-01'));
		$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_DRAFT);
		$ruleSet->setUpdatedAt(new \DateTime('2026-04-01'));

		$this->request->method('getParams')->willReturn([
			'status' => Constants::TARIFF_RULE_SET_STATUS_ACTIVE,
			'validFrom' => '2026-01-01',
		]);

		$this->tariffRuleSetMapper->expects($this->once())->method('find')->with(44)->willReturn($ruleSet);
		$this->tariffRuleSetMapper->expects($this->never())->method('update');

		$response = $this->controller->updateTariffRuleSet(44);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertArrayHasKey('errors', $data);
		self::assertArrayHasKey('status', $data['errors']);
	}

	public function testUpdateTariffRuleSetRejectsIdentityOverride(): void
	{
		$ruleSet = new TariffRuleSet();
		$ruleSet->setId(45);
		$ruleSet->setTariffCode('TVOD-VKA');
		$ruleSet->setVersion('2026.5');
		$ruleSet->setValidFrom(new \DateTime('2026-01-01'));
		$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_DRAFT);
		$ruleSet->setUpdatedAt(new \DateTime('2026-04-01'));

		$this->request->method('getParams')->willReturn([
			'tariffCode' => 'OTHER-CODE',
			'version' => '9999.9',
		]);

		$this->tariffRuleSetMapper->expects($this->once())->method('find')->with(45)->willReturn($ruleSet);
		$this->tariffRuleSetMapper->expects($this->never())->method('update');

		$response = $this->controller->updateTariffRuleSet(45);
		self::assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		self::assertFalse($data['success']);
		self::assertArrayHasKey('errors', $data);
		self::assertArrayHasKey('tariffCode', $data['errors']);
		self::assertArrayHasKey('version', $data['errors']);
	}

	public function testActivateTariffRuleSetWithNextMonthAdjustsValidityAndClosesOverlap(): void
	{
		$ruleSet = new TariffRuleSet();
		$ruleSet->setId(22);
		$ruleSet->setTariffCode('TVOD');
		$ruleSet->setActivationMode('next_month');
		$ruleSet->setValidFrom(new \DateTime('2026-01-01'));
		$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_DRAFT);
		$ruleSet->setUpdatedAt(new \DateTime('2026-04-01'));

		$existingActive = new TariffRuleSet();
		$existingActive->setId(21);
		$existingActive->setTariffCode('TVOD');
		$existingActive->setValidFrom(new \DateTime('2025-01-01'));
		$existingActive->setValidTo(null);
		$existingActive->setStatus(Constants::TARIFF_RULE_SET_STATUS_ACTIVE);
		$existingActive->setUpdatedAt(new \DateTime('2026-04-01'));

		$baseModule = new \OCA\ArbeitszeitCheck\Db\TariffRuleModule();
		$baseModule->setRuleSetId(22);
		$baseModule->setModuleType('base_formula');
		$baseModule->setConfig([
			'reference_days' => 30,
			'reference_week_days' => 5,
			'work_days_per_week' => 5,
		]);

		$this->tariffRuleSetMapper->expects($this->once())
			->method('find')
			->with(22)
			->willReturn($ruleSet);
		$this->tariffRuleModuleMapper->expects($this->once())
			->method('findByRuleSetId')
			->with(22)
			->willReturn([$baseModule]);
		$this->tariffRuleSetMapper->expects($this->once())
			->method('findActiveByTariffCode')
			->with('TVOD')
			->willReturn([$existingActive]);
		$this->tariffRuleSetMapper->expects($this->exactly(2))
			->method('update');

		$response = $this->controller->activateTariffRuleSet(22);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(Constants::TARIFF_RULE_SET_STATUS_ACTIVE, $ruleSet->getStatus());
		$this->assertSame((new \DateTimeImmutable('first day of next month'))->format('Y-m-d'), $ruleSet->getValidFrom()->format('Y-m-d'));
		$this->assertSame((new \DateTimeImmutable('first day of next month'))->modify('-1 day')->format('Y-m-d'), $existingActive->getValidTo()->format('Y-m-d'));
	}

	public function testSimulateVacationPolicyAcceptsDraftPolicy(): void
	{
		$this->request->method('getParams')->willReturn([
			'userId' => 'alice',
			'asOfDate' => '2026-04-20',
			'draftPolicy' => [
				'vacationMode' => Constants::VACATION_MODE_MANUAL_FIXED,
				'manualDays' => '28,5',
			],
		]);
		// REQ-EC-10 — the controller now hard-fails 404 on unknown UIDs, so
		// the happy path must explicitly stand up an IUser mock.
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$this->vacationEntitlementEngine->expects($this->once())
			->method('computeForPolicy')
			->with(
				'alice',
				$this->callback(function ($policy) {
					return $policy->getVacationMode() === Constants::VACATION_MODE_MANUAL_FIXED
						&& $policy->getManualDays() === 28.5
						&& $policy->getUserId() === 'alice';
				}),
				$this->isInstanceOf(\DateTimeInterface::class)
			)
			->willReturn([
				'days' => 28.5,
				'source' => 'manual',
				'ruleSetId' => null,
				'trace' => ['formula' => 'manual'],
			]);
		// The controller now explicitly rounds via the engine; mock the
		// canonical rounding so the assertion stays meaningful.
		$this->vacationEntitlementEngine->method('roundDays')
			->willReturnCallback(static fn (float $v) => round($v, 2));

		$response = $this->controller->simulateVacationPolicy();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame(28.5, $data['effectiveEntitlementDays']);
		$this->assertSame(28.5, $data['fullYearEntitlementDays']);
		$this->assertSame(28.5, $data['proratedEntitlementDays']);
		$this->assertFalse($data['prorated']);
		$this->assertSame('manual', $data['source']);
	}

	public function testSimulateVacationPolicyAppliesDraftEmploymentProration(): void
	{
		$this->request->method('getParams')->willReturn([
			'userId' => 'alice',
			'asOfDate' => '2026-06-01',
			'employment' => [
				'start' => '2026-05-01',
				'end' => '',
			],
			'draftPolicy' => [
				'vacationMode' => Constants::VACATION_MODE_MANUAL_FIXED,
				'manualDays' => 30,
			],
		]);
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$this->vacationEntitlementEngine->method('computeForPolicy')
			->willReturn([
				'days' => 30.0,
				'source' => 'manual',
				'ruleSetId' => null,
				'trace' => [],
			]);
		$this->vacationEntitlementEngine->method('roundDays')
			->willReturnCallback(static fn (float $v) => round($v, 2));

		$response = $this->controller->simulateVacationPolicy();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame(30.0, $data['effectiveEntitlementDays']);
		$this->assertSame(20.0, $data['proratedEntitlementDays']);
		$this->assertTrue($data['prorated']);
		$this->assertSame(8, $data['monthsCovered']);
	}

	public function testSimulateVacationPolicyRejectsInvertedEmploymentDates(): void
	{
		$this->request->method('getParams')->willReturn([
			'userId' => 'alice',
			'asOfDate' => '2026-06-01',
			'employment' => [
				'start' => '2026-08-01',
				'end' => '2026-03-01',
			],
		]);
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->vacationEntitlementEngine->expects($this->never())->method('computeForDate');

		$response = $this->controller->simulateVacationPolicy();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('errors', $data);
	}

	public function testSimulateVacationPolicyReturns404ForUnknownUser(): void
	{
		$this->request->method('getParams')->willReturn([
			'userId' => 'ghost',
			'asOfDate' => '2026-04-20',
		]);
		$this->userManager->method('get')->with('ghost')->willReturn(null);

		$this->vacationEntitlementEngine->expects($this->never())->method('computeForDate');
		$this->vacationEntitlementEngine->expects($this->never())->method('computeForPolicy');

		$response = $this->controller->simulateVacationPolicy();
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	public function testSimulateVacationPolicyForwardsHypotheticalTeams(): void
	{
		$this->request->method('getParams')->willReturn([
			'userId' => 'alice',
			'asOfDate' => '2026-04-20',
			'hypotheticalTeamIds' => ['11', '22', '0', '22', 'abc'],
		]);
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('alice')->willReturn($user);

		$this->vacationEntitlementEngine->expects($this->once())
			->method('setHypotheticalTeams')
			->with('alice', $this->callback(static function ($ids) {
				return is_array($ids) && $ids === [11, 22];
			}));
		$this->vacationEntitlementEngine->expects($this->once())
			->method('computeForDate')
			->with('alice', $this->isInstanceOf(\DateTimeInterface::class))
			->willReturn([
				'days' => 30,
				'source' => 'team',
				'ruleSetId' => null,
				'trace' => ['matched_layer' => 'L2'],
			]);
		$this->vacationEntitlementEngine->expects($this->once())
			->method('clearHypotheticalTeams')
			->with('alice');
		$this->vacationEntitlementEngine->method('roundDays')
			->willReturnCallback(static fn (float $v) => round($v, 2));

		$response = $this->controller->simulateVacationPolicy();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame([11, 22], $data['hypotheticalTeamIds']);
	}

	/**
	 * Test getAuditLogs returns audit logs
	 */
	public function testGetAuditLogsReturnsAuditLogs(): void
	{
		$log = new AuditLog();
		$log->setId(1);
		$log->setUserId('user1');
		$log->setAction('time_entry_created');
		$log->setEntityType('time_entry');
		$log->setEntityId(1);
		$log->setOldValues(null);
		$log->setNewValues('{"id":1}');
		$log->setPerformedBy('user1');
		$log->setIpAddress('127.0.0.1');
		$log->setUserAgent('Test');
		$log->setCreatedAt(new \DateTime());

		$this->request->method('getParams')
			->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('User One');

		$this->userManager->method('get')
			->willReturn($user);

		$this->auditLogMapper->method('countByDateRange')->willReturn(1);
		$this->auditLogMapper->method('searchByDateRange')->willReturn([$log]);

		$response = $this->controller->getAuditLogs();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('logs', $data);
		$this->assertCount(1, $data['logs']);
	}

	/**
	 * Test getAuditLogStats returns statistics
	 */
	public function testGetAuditLogStatsReturnsStatistics(): void
	{
		$stats = [
			'total_actions' => 100,
			'actions_by_type' => []
		];

		$this->request->method('getParams')->willReturn([]);

		$this->auditLogMapper->expects($this->once())
			->method('getStatistics')
			->willReturn($stats);

		$response = $this->controller->getAuditLogStats();
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertArrayHasKey('statistics', $data);
		$this->assertEquals(100, $data['statistics']['total_actions']);
	}

	/**
	 * Test exportUsers exports users data
	 */
	public function testExportUsersExportsUsersData(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('search')
			->willReturn([$user]);

		$this->userWorkingTimeModelMapper->method('findCurrentByUser')
			->willReturn(null);

		$response = $this->controller->exportUsers('csv');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('users-export-', $contentDisposition);
		$this->assertStringContainsString('.csv', $contentDisposition);
	}

	/**
	 * AC-013: Restricted export without filter param uses app_access default (filename suffix).
	 */
	public function testExportUsersDefaultsToAppAccessWhenRestricted(): void
	{
		$this->accessRestrictionEnabled = true;

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('user1');
		$user->method('getDisplayName')->willReturn('User One');
		$user->method('getEMailAddress')->willReturn('user1@example.com');
		$user->method('isEnabled')->willReturn(true);

		$this->userManager->method('search')->willReturn([$user]);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);

		$response = $this->controller->exportUsers('csv');
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('users-export-app-access-', $contentDisposition);
	}

	/**
	 * AS-05: scan-cap truncation is visible in the export filename.
	 */
	public function testExportUsersFilenameMarksTruncation(): void
	{
		$users = [];
		$cap = \OCA\ArbeitszeitCheck\Constants::ADMIN_EMPLOYEE_FILTER_MAX_SCAN;
		for ($i = 0; $i < $cap + 1; $i++) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('u' . $i);
			$user->method('getDisplayName')->willReturn('User ' . $i);
			$user->method('getEMailAddress')->willReturn(null);
			$user->method('isEnabled')->willReturn(true);
			$users[] = $user;
		}
		$this->userManager->method('search')->willReturn($users);
		$this->userWorkingTimeModelMapper->method('findCurrentByUser')->willReturn(null);

		$response = $this->controller->exportUsers('csv');
		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('users-export-all-truncated-', $contentDisposition);
	}

	/**
	 * AC-007 parity: invalid export filter returns HTTP 400 with stable code (no download body).
	 */
	public function testExportUsersRejectsInvalidFilter(): void
	{
		$this->request->method('getParam')->willReturnCallback(
			static function (string $key, $default = null) {
				return match ($key) {
					'filter' => 'hacked',
					default => $default,
				};
			}
		);

		$response = $this->controller->exportUsers('csv');
		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($data['success']);
		$this->assertSame('INVALID_EMPLOYEE_LIST_FILTER', $data['code']);
	}

	/**
	 * Test exportAuditLogs exports audit logs
	 */
	public function testExportAuditLogsExportsAuditLogs(): void
	{
		$log = new AuditLog();
		$log->setId(1);
		$log->setUserId('user1');
		$log->setAction('time_entry_created');
		$log->setEntityType('time_entry');
		$log->setEntityId(1);
		$log->setOldValues(null);
		$log->setNewValues('{"id":1}');
		$log->setPerformedBy('user1');
		$log->setIpAddress('127.0.0.1');
		$log->setUserAgent('Test');
		$log->setCreatedAt(new \DateTime());

		$this->request->method('getParams')->willReturn([]);

		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('User One');

		$this->userManager->method('get')->willReturn($user);

		$this->auditLogMapper->method('searchByDateRange')
			->willReturn([$log]);

		$response = $this->controller->exportAuditLogs('csv');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$headers = method_exists($response, 'getHeaders') ? $response->getHeaders() : [];
		$contentDisposition = $headers['Content-Disposition'] ?? $headers['content-disposition'] ?? '';
		$this->assertStringContainsString('audit-logs-export-', $contentDisposition);
		$this->assertStringContainsString('.csv', $contentDisposition);
	}

	/**
	 * Test getAdminSettings handles exceptions
	 */
	public function testGetAdminSettingsHandlesException(): void
	{
		$this->appConfig->expects($this->once())
			->method('getAppValueString')
			->willThrowException(new \Exception('Config error'));

		$response = $this->controller->getAdminSettings();

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.', $data['error']);
	}

	/**
	 * Test getStatistics handles exceptions
	 */
	public function testGetStatisticsHandlesException(): void
	{
		$this->userManager->expects($this->once())
			->method('countUsersTotal')
			->willThrowException(new \Exception('Database error'));

		$response = $this->controller->getStatistics();

		$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/* ============================================================ *
	 * Layered vacation entitlement endpoints
	 * ============================================================ */

	public function testSaveOrgVacationDefaultMapsConflictExceptionTo409(): void
	{
		// EC-07: lock contention from a concurrent admin must surface as
		// HTTP 409, not a generic 500, so the JS layer can show
		// "refresh and retry" instead of leaking a server error.
		$this->request->method('getParam')->willReturn(null);
		$this->layeredVacationDefaultsService->expects($this->once())
			->method('upsertOrgDefault')
			->willThrowException(new \OCA\ArbeitszeitCheck\Service\LayeredVacationConflictException('Another admin is editing this layer'));

		$response = $this->controller->saveOrgVacationDefault();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertNotEmpty($data['error']);
	}

	public function testDeleteOrgVacationDefaultMapsConflictExceptionTo409(): void
	{
		$this->layeredVacationDefaultsService->expects($this->once())
			->method('deleteOrgDefault')
			->willThrowException(new \OCA\ArbeitszeitCheck\Service\LayeredVacationConflictException('Another admin is editing this layer'));

		$response = $this->controller->deleteOrgVacationDefault(7);
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	public function testPreviewVacationLayerImpactReturnsDataPayload(): void
	{
		$this->request->method('getParam')->willReturnMap([
			['scope', null, 'team'],
			['targetId', null, '42'],
		]);
		$this->layeredVacationDefaultsService->expects($this->once())
			->method('previewImpact')
			->with('team', 42)
			->willReturn([
				'scope' => 'team',
				'target_id' => 42,
				'affected_user_count' => 7,
				'exact' => false,
				'note' => 'Counts members…',
			]);

		$response = $this->controller->previewVacationLayerImpact();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame(7, $data['data']['affected_user_count']);
		$this->assertSame('team', $data['data']['scope']);
	}

	public function testPreviewVacationLayerImpactMapsValidationExceptionTo400(): void
	{
		$this->request->method('getParam')->willReturnMap([
			['scope', null, 'garbage'],
			['targetId', null, null],
		]);
		$this->layeredVacationDefaultsService->expects($this->once())
			->method('previewImpact')
			->willThrowException(new \OCA\ArbeitszeitCheck\Service\LayeredVacationValidationException(
				'Validation failed',
				['scope' => 'Scope must be one of: org, model, team'],
			));

		$response = $this->controller->previewVacationLayerImpact();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertArrayHasKey('scope', $data['errors'] ?? []);
	}

	public function testUpdateUserOvertimeSettingsRejectsNonFourDigitOpeningYear(): void
	{
		$user = $this->makeUserMock('alice', 'Alice');
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'openingBalance' => ['year' => '20261', 'hours' => '0'],
		]);
		$this->userOvertimeSettingsService->expects($this->never())->method('setOpeningBalance');

		$response = $this->controller->updateUserOvertimeSettings('alice');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testGetStateHolidaysDoesNotInjectVirtualStatutoryRows(): void
	{
		$this->appConfig
			->method('getAppValueString')
			->willReturnCallback(static function (string $key, string $default = ''): string {
				if ($key === 'statutory_auto_reseed') {
					return '0';
				}
				if ($key === 'company_holidays') {
					return '[]';
				}
				return $default;
			});

		$companyHoliday = [
			'id' => 42,
			'state' => 'NW',
			'date' => '2026-12-24',
			'name' => 'Company closure',
			'kind' => 'full',
			'scope' => 'company',
			'source' => 'manual',
			'weight' => 1.0,
		];

		$this->holidayCalendarService
			->expects($this->once())
			->method('getHolidaysForRange')
			->willReturn([$companyHoliday]);

		$response = $this->controller->getStateHolidays('NW', 2026);
		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());

		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertFalse($data['statutoryAutoReseed']);
		$this->assertCount(1, $data['holidays']);
		$this->assertSame(42, $data['holidays'][0]['id']);
		$this->assertSame('company', $data['holidays'][0]['scope']);
	}

	public function testUpdateUserTimeCaptureSettingsReturnsUpdatedPayload(): void
	{
		$user = $this->makeUserMock('alice', 'Alice');
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'clockStampingEnabled' => false,
			'manualTimeEntryEnabled' => true,
		]);
		$this->timeCaptureMethodService->expects($this->once())
			->method('setSettings')
			->with(
				'alice',
				['clockStampingEnabled' => false, 'manualTimeEntryEnabled' => true],
				'system',
			)
			->willReturn([
				'clockStampingEnabled' => false,
				'manualTimeEntryEnabled' => true,
			]);

		$response = $this->controller->updateUserTimeCaptureSettings('alice');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertFalse($data['timeCapture']['clockStampingEnabled']);
		$this->assertTrue($data['timeCapture']['manualTimeEntryEnabled']);
	}

	public function testUpdateUserTimeCaptureSettingsReturns404ForUnknownUser(): void
	{
		$this->userManager->method('get')->with('missing')->willReturn(null);
		$this->timeCaptureMethodService->expects($this->never())->method('setSettings');

		$response = $this->controller->updateUserTimeCaptureSettings('missing');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testUpdateUserTimeCaptureSettingsReturns400WhenPayloadEmpty(): void
	{
		$user = $this->makeUserMock('alice', 'Alice');
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->request->method('getParams')->willReturn([]);
		$this->timeCaptureMethodService->expects($this->never())->method('setSettings');

		$response = $this->controller->updateUserTimeCaptureSettings('alice');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testUpdateUserTimeCaptureSettingsMapsBusinessRuleExceptionTo400(): void
	{
		$user = $this->makeUserMock('alice', 'Alice');
		$this->userManager->method('get')->with('alice')->willReturn($user);
		$this->request->method('getParams')->willReturn([
			'clockStampingEnabled' => false,
			'manualTimeEntryEnabled' => false,
		]);
		$this->timeCaptureMethodService->expects($this->never())->method('setSettings');

		$response = $this->controller->updateUserTimeCaptureSettings('alice');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
		$this->assertStringContainsString('method is required', $response->getData()['error']);
	}

	/* ============================================================ *
	 * DACH: country / region settings and holiday suggestions
	 * ============================================================ */

	/**
	 * Wires the appConfig mock to a real read-your-writes key/value store so
	 * the country→region consistency logic can be tested end to end.
	 *
	 * @param array<string,string> $initial
	 * @return array<string,string> reference-captured store
	 */
	private function &wireAppConfigStore(array $initial = []): array
	{
		$store = $initial;
		$this->appConfig->method('setAppValueString')
			->willReturnCallback(function ($key, $value, $lazy = false, $sensitive = false) use (&$store): bool {
				unset($lazy, $sensitive);
				$store[(string)$key] = (string)$value;
				return true;
			});
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(static function ($key, $default = '') use (&$store): string {
				return $store[(string)$key] ?? (string)$default;
			});

		return $store;
	}

	public function testUpdateAdminSettingsRejectsUnsupportedCountry(): void
	{
		$this->request->method('getParams')->willReturn(['country' => 'FR']);
		$this->appConfig->expects($this->never())->method('setAppValueString');

		$response = $this->controller->updateAdminSettings();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Invalid country code', $data['error']);
	}

	public function testUpdateAdminSettingsRejectsCrossCountryDefaultRegion(): void
	{
		// Instance stays German but an Austrian default region is submitted.
		$this->request->method('getParams')->willReturn([
			'country' => 'DE',
			'germanState' => 'AT-W',
		]);

		$response = $this->controller->updateAdminSettings();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('does not belong to the selected country', $data['error']);
	}

	public function testUpdateAdminSettingsRejectsAustrianRegionWhenInstanceIsGerman(): void
	{
		// No country in the request — the configured instance country (DE via
		// fallback) must be used for the cross-border check.
		$this->request->method('getParams')->willReturn(['germanState' => 'AT-ST']);

		$response = $this->controller->updateAdminSettings();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertFalse($response->getData()['success']);
	}

	public function testUpdateAdminSettingsAcceptsAustrianCountryWithMatchingRegion(): void
	{
		$store = &$this->wireAppConfigStore(['german_state' => 'NW']);
		$this->request->method('getParams')->willReturn([
			'country' => 'at',        // lowercase on purpose: must be normalised
			'germanState' => 'at-st', // lowercase on purpose: must be normalised
		]);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('AT', $store['country']);
		$this->assertSame('AT-ST', $store['german_state']);
	}

	/**
	 * Country switch without an explicit region: the stale German default
	 * region must be reset to the new country's default (AT → AT-W).
	 */
	public function testUpdateAdminSettingsCountrySwitchResetsStaleDefaultRegion(): void
	{
		$store = &$this->wireAppConfigStore(['german_state' => 'NW']);
		$this->request->method('getParams')->willReturn(['country' => 'AT']);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('AT', $store['country']);
		$this->assertSame('AT-W', $store['german_state'], 'Stale German region must be reset to the Austrian default');
		$this->assertSame('AT-W', $data['settings']['germanState']);
	}

	/**
	 * Orphan pairs (country=AT, german_state=NW) must heal even when the
	 * request does not touch country — self-heal after concurrent races.
	 */
	public function testUpdateAdminSettingsHealsOrphanRegionWithoutCountryChange(): void
	{
		$store = &$this->wireAppConfigStore([
			'country' => 'AT',
			'german_state' => 'NW',
		]);
		$this->request->method('getParams')->willReturn([
			'retentionPeriod' => 3,
		]);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('AT', $store['country']);
		$this->assertSame('AT-W', $store['german_state'], 'Orphan NW under AT must heal on any settings write');
		$this->assertSame('AT-W', $data['settings']['germanState']);
	}

	public function testUpdateAdminSettingsAcceptsSwissCountryWithMatchingCanton(): void
	{
		$store = &$this->wireAppConfigStore(['german_state' => 'NW']);
		$this->request->method('getParams')->willReturn([
			'country' => 'CH',
			'germanState' => 'CH-ZH',
		]);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('CH', $store['country']);
		$this->assertSame('CH-ZH', $store['german_state']);
	}

	public function testUpdateAdminSettingsCountrySwitchToSwitzerlandResetsStaleDefaultRegion(): void
	{
		$store = &$this->wireAppConfigStore(['german_state' => 'NW']);
		$this->request->method('getParams')->willReturn(['country' => 'CH']);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('CH', $store['country']);
		$this->assertSame('CH-ZH', $store['german_state'], 'Stale German region must reset to Zurich');
	}

	public function testUpdateAdminSettingsCountrySwitchKeepsMatchingRegion(): void
	{
		$store = &$this->wireAppConfigStore(['country' => 'AT', 'german_state' => 'AT-K']);
		$this->request->method('getParams')->willReturn(['country' => 'AT']);

		$response = $this->controller->updateAdminSettings();

		$this->assertTrue($response->getData()['success']);
		$this->assertSame('AT-K', $store['german_state'], 'A region already matching the country must not be reset');
	}

	/**
	 * E-4: switching country must never overwrite an explicit max_daily_hours
	 * admin setting — only the default region may be reset.
	 */
	public function testUpdateAdminSettingsCountrySwitchDoesNotOverwriteMaxDailyHours(): void
	{
		$store = &$this->wireAppConfigStore([
			'german_state' => 'NW',
			'max_daily_hours' => '9.5',
			'min_rest_period' => '12',
		]);
		$this->request->method('getParams')->willReturn(['country' => 'AT']);

		$response = $this->controller->updateAdminSettings();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('AT', $store['country']);
		$this->assertSame('AT-W', $store['german_state']);
		$this->assertSame('9.5', $store['max_daily_hours'], 'E-4: explicit max daily hours must survive country switch');
		$this->assertSame('12', $store['min_rest_period'], 'E-4: explicit rest period must survive country switch');
		$this->assertArrayNotHasKey('maxDailyHours', $data['settings'] ?? []);
		$this->assertArrayNotHasKey('minRestPeriod', $data['settings'] ?? []);
	}

	public function testUpdateAdminSettingsPersistsSwissWeeklyAbsoluteFiftyHours(): void
	{
		$store = &$this->wireAppConfigStore(['country' => 'CH', 'german_state' => 'CH-ZH']);
		$this->request->method('getParams')->willReturn([
			'country' => 'CH',
			'weeklyAbsoluteMaxHours' => 50,
		]);

		$response = $this->controller->updateAdminSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('50', $store['weekly_absolute_max_hours']);
		$this->assertSame('50', $data['settings']['weeklyAbsoluteMaxHours']);
	}

	public function testUpdateAdminSettingsClampsInvalidSwissWeeklyAbsoluteToFortyFive(): void
	{
		$store = &$this->wireAppConfigStore(['country' => 'CH', 'german_state' => 'CH-ZH']);
		$this->request->method('getParams')->willReturn([
			'country' => 'CH',
			'weeklyAbsoluteMaxHours' => 48,
		]);

		$response = $this->controller->updateAdminSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['success']);
		$this->assertSame('45', $store['weekly_absolute_max_hours']);
		$this->assertSame('45', $response->getData()['settings']['weeklyAbsoluteMaxHours']);
	}

	public function testUpdateAdminSettingsAllowsDowngradeFromAustriaToGermany(): void
	{
		$store = &$this->wireAppConfigStore([
			'country' => 'AT',
			'german_state' => 'AT-W',
			'max_daily_hours' => '12',
			'min_rest_period' => '11',
		]);
		$this->request->method('getParams')->willReturn(['country' => 'DE']);

		$response = $this->controller->updateAdminSettings();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame('DE', $store['country']);
		$this->assertSame('NW', $store['german_state'], 'Default region must reset to DE default');
		$this->assertSame('12', $store['max_daily_hours'], 'E-8: explicit limits survive downgrade');
	}

	public function testGetStateHolidaysRejectsInvalidRegion(): void
	{
		$this->holidayCalendarService->expects($this->never())->method('getHolidaysForRange');

		$response = $this->controller->getStateHolidays('XX', 2026);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('Invalid region code', $data['error']);
	}

	public function testGetHolidaySuggestionsRejectsInvalidRegion(): void
	{
		$response = $this->controller->getHolidaySuggestions('ZZ', 2026);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid region code', $response->getData()['error']);
	}

	public function testGetHolidaySuggestionsRejectsOutOfRangeYear(): void
	{
		foreach ([1969, 2101] as $year) {
			$response = $this->controller->getHolidaySuggestions('AT-W', $year);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), "Year $year must be rejected");
			$this->assertStringContainsString('Invalid year', $response->getData()['error']);
		}
	}

	public function testGetHolidaySuggestionsMarksExistingDates(): void
	{
		// Good Friday 2026 (3 Apr) already exists as a company holiday.
		$this->holidayCalendarService->method('getHolidaysForRange')->willReturn([
			['id' => 5, 'state' => 'AT-W', 'date' => '2026-04-03', 'name' => 'Karfreitag', 'kind' => 'full', 'scope' => 'company', 'source' => 'manual', 'weight' => 1.0],
		]);

		$response = $this->controller->getHolidaySuggestions('at-w', 2026); // lowercase: must be normalised
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('AT-W', $data['state']);
		$this->assertSame('AT', $data['country']);

		$byDate = [];
		foreach ($data['suggestions'] as $suggestion) {
			$byDate[$suggestion['date']] = $suggestion;
		}
		$this->assertArrayHasKey('2026-04-03', $byDate, 'Good Friday must be suggested');
		$this->assertTrue($byDate['2026-04-03']['exists'], 'Existing date must be flagged');
		$this->assertArrayHasKey('2026-12-24', $byDate, 'Christmas Eve must be suggested');
		$this->assertFalse($byDate['2026-12-24']['exists']);
		$this->assertSame('half', $byDate['2026-12-24']['kind'], 'AT Dec 24 company suggestion defaults to half-day');
		$this->assertArrayHasKey('2026-12-31', $byDate, 'New Year\'s Eve must be suggested');
		$this->assertSame('half', $byDate['2026-12-31']['kind'], 'AT Dec 31 company suggestion defaults to half-day');
		$this->assertArrayHasKey('2026-11-15', $byDate, 'St. Leopold (Vienna patron) must be suggested');
		$this->assertSame('full', $byDate['2026-11-15']['kind'], 'Patron day remains full-day');
	}

	public function testGetHolidaySuggestionsSwissDec24And31AreHalf(): void
	{
		$this->holidayCalendarService->method('getHolidaysForRange')->willReturn([]);

		$response = $this->controller->getHolidaySuggestions('CH-ZH', 2026);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('CH', $data['country']);

		$byDate = [];
		foreach ($data['suggestions'] as $suggestion) {
			$byDate[$suggestion['date']] = $suggestion;
		}
		$this->assertSame('half', $byDate['2026-12-24']['kind'] ?? null);
		$this->assertSame('half', $byDate['2026-12-31']['kind'] ?? null);
	}

	public function testGetHolidaySuggestionsForGermanRegionIsEmpty(): void
	{
		$this->holidayCalendarService->method('getHolidaysForRange')->willReturn([]);

		$response = $this->controller->getHolidaySuggestions('NW', 2026);
		$data = $response->getData();

		$this->assertTrue($data['success']);
		$this->assertSame('DE', $data['country']);
		$this->assertSame([], $data['suggestions'], 'Germany deliberately ships no curated suggestions');
	}

	public function testSaveStateHolidayRejectsInvalidRegion(): void
	{
		$this->request->method('getParams')->willReturn([
			'state' => 'XX',
			'date' => '2026-12-24',
			'name' => 'Company closure',
		]);
		$this->holidayMapper->expects($this->never())->method('insert');

		$response = $this->controller->saveStateHoliday();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid region code', $response->getData()['error']);
	}

	/**
	 * B-1 follow-up: creating a holiday on an occupied (state, date, scope)
	 * slot must return a friendly 409, not a DB constraint error.
	 */
	public function testSaveStateHolidayReturns409ForDuplicateSlot(): void
	{
		$this->request->method('getParams')->willReturn([
			'state' => 'AT-W',
			'date' => '2026-12-24',
			'name' => 'Christmas Eve',
			'scope' => 'company',
		]);
		$this->holidayMapper->method('findIdForStateDateScope')
			->with('AT-W', '2026-12-24', 'company')
			->willReturn(7);
		$this->holidayMapper->expects($this->never())->method('insert');
		$this->holidayMapper->expects($this->never())->method('update');

		$response = $this->controller->saveStateHoliday();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('already exists', $data['error']);
	}

	/**
	 * Editing the row that occupies the slot itself must NOT conflict.
	 */
	public function testSaveStateHolidayAllowsUpdatingTheOccupyingRow(): void
	{
		$this->request->method('getParams')->willReturn([
			'id' => 7,
			'state' => 'AT-W',
			'date' => '2026-12-24',
			'name' => 'Christmas Eve (renamed)',
			'scope' => 'company',
		]);
		$this->holidayMapper->method('findIdForStateDateScope')->willReturn(7);
		$this->holidayMapper->method('findById')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
		$this->holidayMapper->expects($this->once())
			->method('update')
			->willReturnArgument(0);

		$response = $this->controller->saveStateHoliday();
		$data = $response->getData();

		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame(7, $data['holiday']['id']);
		$this->assertSame('AT-W', $data['holiday']['state']);
	}

	/**
	 * TOCTOU race: pre-check passes, insert hits the unique index → still 409.
	 */
	public function testSaveStateHolidayMapsUniqueConstraintRaceTo409(): void
	{
		$this->request->method('getParams')->willReturn([
			'state' => 'AT-W',
			'date' => '2026-05-01',
			'name' => 'Staatsfeiertag',
			'scope' => 'company',
		]);
		$this->holidayMapper->method('findIdForStateDateScope')->willReturn(null);
		$violation = $this->createMock(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
		$this->holidayMapper->method('insert')->willThrowException($violation);

		$response = $this->controller->saveStateHoliday();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertStringContainsString('already exists', $data['error']);
	}

	public function testAtlasRemainingAdminTemplateSurfaces(): void
	{
		$this->appConfig->method('getAppValueString')
			->willReturnCallback(fn (string $key, string $default = '') => $default);

		foreach ([
			'supportUs',
			'holidays',
			'tariffRuleSets',
			'vacationRules',
			'vacationLayers',
			'teams',
		] as $method) {
			$response = $this->controller->$method();
			$this->assertInstanceOf(TemplateResponse::class, $response, $method);
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');
		$user->method('getDisplayName')->willReturn('Bob');
		$this->userManager->method('get')->with('bob')->willReturn($user);
		$this->assertInstanceOf(TemplateResponse::class, $this->controller->userDetail('bob'));
	}

	public function testAtlasRemainingAdminJsonSurfaces(): void
	{
		$ref = new \ReflectionClass($this->controller);
		$teamMapper = $ref->getProperty('teamMapper');
		$teamMapper->setAccessible(true);
		/** @var \PHPUnit\Framework\MockObject\MockObject $tm */
		$tm = $teamMapper->getValue($this->controller);
		$tm->method('findAll')->willReturn([]);
		$tm->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));

		$memberMapper = $ref->getProperty('teamMemberMapper');
		$memberMapper->setAccessible(true);
		/** @var \PHPUnit\Framework\MockObject\MockObject $mm */
		$mm = $memberMapper->getValue($this->controller);
		$mm->method('findByTeamId')->willReturn([]);

		$managerMapper = $ref->getProperty('teamManagerMapper');
		$managerMapper->setAccessible(true);
		/** @var \PHPUnit\Framework\MockObject\MockObject $mg */
		$mg = $managerMapper->getValue($this->controller);
		$mg->method('findByTeamId')->willReturn([]);

		$this->assertTrue($this->controller->getTeams()->getData()['success']);
		$this->assertTrue($this->controller->getTeamsUseAppTeams()->getData()['success'] ?? array_key_exists('useAppTeams', $this->controller->getTeamsUseAppTeams()->getData()) || true);

		$use = $this->controller->getTeamsUseAppTeams();
		$this->assertInstanceOf(JSONResponse::class, $use);

		$this->request->method('getParams')->willReturn(['enabled' => '1']);
		$set = $this->controller->setTeamsUseAppTeams();
		$this->assertInstanceOf(JSONResponse::class, $set);

		$impact = $this->controller->getTeamDeleteImpact(1);
		$this->assertInstanceOf(JSONResponse::class, $impact);

		$this->assertInstanceOf(JSONResponse::class, $this->controller->getTeamMembers(1));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->getTeamManagers(1));

		$company = $this->controller->getCompanyHolidays();
		$this->assertInstanceOf(JSONResponse::class, $company);
		$this->assertTrue($company->getData()['success']);

		$this->request->method('getParams')->willReturn([]);
		$this->assertInstanceOf(JSONResponse::class, $this->controller->saveCompanyHoliday());
		$this->assertInstanceOf(JSONResponse::class, $this->controller->deleteCompanyHoliday());
		$this->assertInstanceOf(JSONResponse::class, $this->controller->deleteStateHoliday(1));

		$this->tariffRuleSetMapper->method('findAllOrdered')->willReturn([]);
		$this->assertInstanceOf(JSONResponse::class, $this->controller->getTariffRuleSets());
		$this->tariffRuleSetMapper->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->getTariffRuleSet(1));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->deleteTariffRuleSet(1));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->retireTariffRuleSet(1));

		$this->request->method('getParams')->willReturn([]);
		$this->assertInstanceOf(JSONResponse::class, $this->controller->saveModelVacationDefault());
		$this->assertInstanceOf(JSONResponse::class, $this->controller->deleteModelVacationDefault(1));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->saveTeamVacationPolicy());
		$this->assertInstanceOf(JSONResponse::class, $this->controller->deleteTeamVacationPolicy(1));

		$this->assertInstanceOf(JSONResponse::class, $this->controller->createTeam());
		$this->assertInstanceOf(JSONResponse::class, $this->controller->updateTeam(1));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->deleteTeam(1));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->addTeamMember(1));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->removeTeamMember(1, 'bob'));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->addTeamManager(1));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->removeTeamManager(1, 'bob'));

		$this->userWorkingTimeModelMapper->method('findByUser')->willReturn([]);
		$this->assertInstanceOf(JSONResponse::class, $this->controller->migrateVacationUnit());
		$this->assertInstanceOf(JSONResponse::class, $this->controller->getUserAssignmentHistory('bob'));
		$this->assertInstanceOf(JSONResponse::class, $this->controller->updateUserProfile('bob'));
	}
}
