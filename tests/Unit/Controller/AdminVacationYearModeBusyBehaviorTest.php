<?php

declare(strict_types=1);

/**
 * Behavioral: concurrent vacation year-mode flip returns VAC_YEAR_MODE_BUSY
 * with zero sibling IConfig writes (atomic preflight).
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
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
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
use OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IDateTimeFormatter;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

class AdminVacationYearModeBusyBehaviorTest extends TestCase
{
	public function testUpdateNotificationSettingsReturns409WhenYearModeLockHeldWithZeroWrites(): void
	{
		$request = $this->createMock(IRequest::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$store = [
			Constants::CONFIG_VACATION_YEAR_MODE => Constants::VACATION_YEAR_MODE_CALENDAR,
		];
		$appConfig->method('getAppValueString')
			->willReturnCallback(static function (string $key, string $default = '') use (&$store): string {
				return $store[$key] ?? $default;
			});
		$appConfig->expects($this->never())->method('setAppValueString');

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
		$userManager = $this->createMock(IUserManager::class);
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->never())->method('logAction');
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$userWorkingTimeModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$vacationYearBalanceMapper = $this->createMock(VacationYearBalanceMapper::class);
		$vacationAllocation = $this->createMock(VacationAllocationService::class);
		$vacationAllocation->expects($this->never())->method('refreshOpenAllocationsForUsers');
		$tariffRuleSetMapper = $this->createMock(TariffRuleSetMapper::class);
		$userVacationPolicyAssignmentMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$userOvertime = $this->createMock(UserOvertimeSettingsService::class);
		$employment = $this->createMock(UserEmploymentSettingsService::class);

		$adminProfile = new AdminUserProfileUpdateService(
			$userManager,
			$userWorkingTimeModelMapper,
			$workingTimeModelMapper,
			$audit,
			$userSettingsMapper,
			$vacationYearBalanceMapper,
			$vacationAllocation,
			$tariffRuleSetMapper,
			$userVacationPolicyAssignmentMapper,
			$userOvertime,
			$employment,
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

		$lockingMock = $this->createMock(ILockingProvider::class);
		$lockingMock->expects($this->once())
			->method('acquireLock')
			->with(DbLockKeys::vacationYearMode(), ILockingProvider::LOCK_EXCLUSIVE, $this->anything())
			->willThrowException(new LockedException(DbLockKeys::vacationYearMode()));
		$lockingMock->expects($this->never())->method('releaseLock');

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$unit = new VacationUnitService($config);
		$migration = new VacationUnitMigrationService(
			$config,
			$db,
			$unit,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
			$vacationYearBalanceMapper,
			$audit,
		);

		$permissionService = $this->createMock(PermissionService::class);
		$adminEmployeeDirectoryService = new \OCA\ArbeitszeitCheck\Service\AdminEmployeeDirectoryService(
			$userManager,
			$permissionService,
			$this->createMock(TimeEntryMapper::class),
			$l10n,
			$this->createMock(\Psr\Log\LoggerInterface::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
		);

		$controller = new AdminController(
			'arbeitszeitcheck',
			$request,
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(ComplianceViolationMapper::class),
			$userWorkingTimeModelMapper,
			$workingTimeModelMapper,
			$audit,
			$userManager,
			$appConfig,
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
			$employment,
			$proration,
			$timeCapture,
			$adminProfile,
			$adminEmployeeDirectoryService,
			$auditPresenter,
			$permissionService,
			$localeFormat,
			$db,
			null,
			$config,
			$lockingMock,
			$migration,
		);

		$request->method('getParams')->willReturn([
			'policyScope' => 'vacation',
			'vacationYearMode' => Constants::VACATION_YEAR_MODE_ANNIVERSARY,
			'vacationCarryoverExpiryMonth' => 3,
			'vacationCarryoverExpiryDay' => 31,
			'vacationCarryoverMaxDays' => '5',
			'vacationRolloverEnabled' => true,
		]);

		$response = $controller->updateNotificationSettings();
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertSame('VAC_YEAR_MODE_BUSY', $data['code']);
		$this->assertStringContainsString('try again', strtolower((string)$data['error']));
	}
}
