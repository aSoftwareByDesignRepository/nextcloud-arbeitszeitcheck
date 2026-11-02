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
		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with(
				$this->anything(),
				'vacation_year_mode_changed',
				'app_config',
				0,
				['vacation_year_mode' => Constants::VACATION_YEAR_MODE_CALENDAR],
				$this->callback(static function (array $new): bool {
					return ($new['vacation_year_mode'] ?? null) === Constants::VACATION_YEAR_MODE_ANNIVERSARY
						&& ($new['missing_hire_count'] ?? null) === 1
						&& ($new['missing_hire_acknowledged'] ?? null) === true
						&& array_key_exists('allocations_refreshed', $new)
						&& array_key_exists('allocations_failed_count', $new);
				}),
				$this->anything()
			);

		$response = $this->controller->updateNotificationSettings();
		$data = $response->getData();
		$this->assertTrue($data['success'], 'Response: ' . json_encode($data));
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(Constants::VACATION_YEAR_MODE_ANNIVERSARY, $data['vacationYearModeFlip']['to'] ?? null);
		$this->assertArrayHasKey('allocationsRefreshed', $data['vacationYearModeFlip']);
	}
}
