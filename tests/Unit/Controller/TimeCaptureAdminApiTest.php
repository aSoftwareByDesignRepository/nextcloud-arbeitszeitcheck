<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Controller\AdminController;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\AdminUserProfileUpdateService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\HolidayAdminService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\LayeredVacationDefaultsService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Admin PUT /api/admin/users/{userId}/time-capture-settings with a real TimeCaptureMethodService.
 */
class TimeCaptureAdminApiTest extends TestCase
{
	private UserSettingsMapper&MockObject $userSettingsMapper;

	private AuditLogMapper&MockObject $auditLogMapper;

	private AdminController $controller;

	protected function setUp(): void
	{
		parent::setUp();

		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('1');

		$timeCaptureMethodService = new TimeCaptureMethodService(
			$this->userSettingsMapper,
			$this->auditLogMapper,
			$config,
			$l10n,
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn([
			'clockStampingEnabled' => false,
			'manualTimeEntryEnabled' => true,
		]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('bob');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('bob')->willReturn($user);

		// Wrap the *real* TimeCaptureMethodService in a real
		// AdminUserProfileUpdateService so the controller → service →
		// time-capture persistence chain is exercised end to end.
		$adminUserProfileUpdateService = new AdminUserProfileUpdateService(
			$userManager,
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			$this->auditLogMapper,
			$this->userSettingsMapper,
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(VacationAllocationService::class),
			$this->createMock(TariffRuleSetMapper::class),
			$this->createMock(UserVacationPolicyAssignmentMapper::class),
			$this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService::class),
			$timeCaptureMethodService,
			$l10n,
			$this->createMock(IDBConnection::class),
		);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$permissionService = $this->createMock(PermissionService::class);
		$localeFormat = $this->createMock(LocaleFormatService::class);
		$localeFormat->method('clientHints')->willReturn([]);
		$dateTimeFormatter = $this->createMock(\OCP\IDateTimeFormatter::class);
		$auditLogPresenter = new \OCA\ArbeitszeitCheck\Service\AuditLogPresenter($l10n, $dateTimeFormatter);

		$adminEmployeeDirectoryService = new \OCA\ArbeitszeitCheck\Service\AdminEmployeeDirectoryService(
			$userManager,
			$permissionService,
			$this->createMock(TimeEntryMapper::class),
			$l10n,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
		);

		$this->controller = new AdminController(
			'arbeitszeitcheck',
			$request,
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(ComplianceViolationMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			$this->auditLogMapper,
			$userManager,
			$this->createMock(IAppConfig::class),
			$this->userSettingsMapper,
			$this->createMock(TeamMapper::class),
			$this->createMock(TeamMemberMapper::class),
			$this->createMock(TeamManagerMapper::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppManager::class),
			$userSession,
			$this->createMock(CSPService::class),
			$l10n,
			$this->createMock(IURLGenerator::class),
			$this->createMock(HolidayMapper::class),
			$this->createMock(HolidayService::class),
			$this->createMock(HolidayAdminService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\VacationAllocationService::class),
			$this->createMock(TariffRuleSetMapper::class),
			$this->createMock(TariffRuleModuleMapper::class),
			$this->createMock(UserVacationPolicyAssignmentMapper::class),
			$this->createMock(VacationEntitlementEngine::class),
			$this->createMock(LayeredVacationDefaultsService::class),
			$this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\VacationProrationService::class),
			$timeCaptureMethodService,
			$adminUserProfileUpdateService,
			$adminEmployeeDirectoryService,
			$auditLogPresenter,
			$permissionService,
			$localeFormat,
			$this->createMock(IDBConnection::class),
		);
	}

	public function testUpdateUserTimeCaptureSettingsPersistsDisableViaService(): void
	{
		$readIndex = 0;
		$this->userSettingsMapper->method('getBooleanSetting')->willReturnCallback(
			static function (string $userId, string $key, bool $default) use (&$readIndex): bool {
				$readIndex++;
				if ($key === Constants::SETTING_CLOCK_STAMPING_ENABLED && $readIndex >= 3) {
					return false;
				}

				return true;
			}
		);
		$this->userSettingsMapper->expects($this->once())
			->method('setSetting')
			->with('bob', Constants::SETTING_CLOCK_STAMPING_ENABLED, '0');
		$this->auditLogMapper->expects($this->once())->method('logAction');

		$response = $this->controller->updateUserTimeCaptureSettings('bob');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertFalse($data['timeCapture']['clockStampingEnabled']);
		$this->assertTrue($data['timeCapture']['manualTimeEntryEnabled']);
	}
}
