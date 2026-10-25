<?php

declare(strict_types=1);

/**
 * Application class for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024-2025 Alexander Mäule <info@software-by-design.de>
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\AppInfo;

use OCP\Lock\ILockingProvider;
use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCA\ArbeitszeitCheck\Repair\BackupBeforeUpdate;
use OCA\ArbeitszeitCheck\Capabilities;
use OCA\ArbeitszeitCheck\Repair\BackfillAbsenceDays;
use OCA\ArbeitszeitCheck\Repair\EnsureArbeitszeitCheckSchema;
use OCA\ArbeitszeitCheck\Repair\InitializeWorkforceSuiteFreshState;
use OCA\ArbeitszeitCheck\Repair\InitializeWorkforceSuiteUpgradeState;
use OCA\ArbeitszeitCheck\Repair\ReleaseStuckPendingAbsences;
use OCA\ArbeitszeitCheck\Repair\CleanDanglingTariffRuleSetRefs;
use OCA\ArbeitszeitCheck\Repair\ClearStuckKioskEnrollmentLocks;
use OCA\ArbeitszeitCheck\Repair\RepairOrphanedPausedEntries;
use OCA\ArbeitszeitCheck\Repair\SeedWorkLocationCatalog;
use OCA\ArbeitszeitCheck\Repair\UninstallDropTables;
use OCA\ArbeitszeitCheck\Listener\LoadSidebarScripts;
use OCA\ArbeitszeitCheck\Listener\CSPListener;
use OCA\ArbeitszeitCheck\Listener\TimeClientBootstrapListener;
use OCA\ArbeitszeitCheck\Listener\LoadUsersSettingsArbeitszeitListener;
use OCA\ArbeitszeitCheck\Listener\UserDeletedListener;
use OCA\ArbeitszeitCheck\Middleware\ApiExceptionMiddleware;
use OCA\ArbeitszeitCheck\Middleware\ApiValidationErrorMiddleware;
use OCA\ArbeitszeitCheck\Middleware\AppAccessMiddleware;
use OCA\ArbeitszeitCheck\Middleware\AppAdminMiddleware;
use OCA\ArbeitszeitCheck\Middleware\ClientLicenseMiddleware;
use OCA\ArbeitszeitCheck\Middleware\KioskLicenseMiddleware;
use OCA\ArbeitszeitCheck\Middleware\SessionCsrfGuardMiddleware;
use OCA\ArbeitszeitCheck\Notification\Notifier;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCA\ArbeitszeitCheck\Service\VacationRolloverService;
use OCA\ArbeitszeitCheck\Service\AbsenceIcalMailService;
use OCA\ArbeitszeitCheck\Service\AbsenceNotificationMailService;
use OCA\ArbeitszeitCheck\Service\ManagerPendingApprovalMailService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\PaidAbsencePlannedHoursCreditService;
use OCA\ArbeitszeitCheck\Service\DatevExportService;
use OCA\ArbeitszeitCheck\Service\ReportingService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Support\NavGate;
use OCA\ArbeitszeitCheck\Service\AdminEmployeeDirectoryService;
use OCA\ArbeitszeitCheck\Service\OvertimeTrafficLightService;
use OCA\ArbeitszeitCheck\Service\OvertimeNotificationMailService;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutMailService;
use OCA\ArbeitszeitCheck\Dashboard\EmployeeStatusWidget;
use OCA\ArbeitszeitCheck\Dashboard\ManagerTeamStatusWidget;
use OCA\ArbeitszeitCheck\Dashboard\AdminGlobalStatusWidget;
use OCA\Files\Event\LoadSidebar;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IDBConnection;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
use OCA\Settings\Events\BeforeTemplateRenderedEvent as SettingsBeforeTemplateRenderedEvent;
use OCP\User\Events\UserDeletedEvent;

/**
 * Class Application
 */
class Application extends App implements IBootstrap {
	public const APP_ID = 'arbeitszeitcheck';

	/**
	 * Application constructor
	 */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	/**
	 * @inheritDoc
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerCapability(Capabilities::class);

		// Register notification provider
		$context->registerNotifierService(Notifier::class);
		// Registered FIRST so its afterException runs LAST (reverse order):
		// JSON 500 envelope for any throwable no specialised middleware handled.
		$context->registerService(ApiExceptionMiddleware::class, function ($c): ApiExceptionMiddleware {
			return new ApiExceptionMiddleware(
				$c->query(\OCP\IRequest::class),
				$c->query(\Psr\Log\LoggerInterface::class),
				$c->query(\OCP\L10N\IFactory::class)->get(self::APP_ID),
			);
		});
		$context->registerMiddleware(ApiExceptionMiddleware::class);

		$context->registerService(AppAdminMiddleware::class, function ($c): AppAdminMiddleware {
			$l10nFactory = $c->query(\OCP\L10N\IFactory::class);
			return new AppAdminMiddleware(
				$c->query(\OCP\IUserSession::class),
				$c->query(PermissionService::class),
				$l10nFactory->get(self::APP_ID),
				$c->query(\OCP\IRequest::class),
				$c->query(\OCP\IURLGenerator::class),
				$l10nFactory,
			);
		});
		$context->registerMiddleware(AppAdminMiddleware::class);
		$context->registerService(AppAccessMiddleware::class, function ($c): AppAccessMiddleware {
			return new AppAccessMiddleware(
				$c->query(\OCP\IUserSession::class),
				$c->query(PermissionService::class),
				$c->query(\OCP\IRequest::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(\OCP\L10N\IFactory::class),
				$c->query(\Psr\Log\LoggerInterface::class),
				$c->query(\OCP\AppFramework\Utility\IControllerMethodReflector::class),
			);
		});
		$context->registerMiddleware(AppAccessMiddleware::class);

		$context->registerService(ClientLicenseMiddleware::class, function ($c): ClientLicenseMiddleware {
			return new ClientLicenseMiddleware(
				$c->query(\OCP\IRequest::class),
				$c->query(\OCP\IUserSession::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\LicenseService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\MobileSeatService::class),
				$c->query(\OCP\L10N\IFactory::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});
		$context->registerMiddleware(ClientLicenseMiddleware::class);

		$context->registerService(SessionCsrfGuardMiddleware::class, function ($c): SessionCsrfGuardMiddleware {
			return new SessionCsrfGuardMiddleware(
				$c->query(\OCP\IRequest::class),
				$c->query(\OCP\AppFramework\Utility\IControllerMethodReflector::class),
				$c->query(\OCP\IL10N::class),
			);
		});
		$context->registerMiddleware(SessionCsrfGuardMiddleware::class);

		$context->registerService(ApiValidationErrorMiddleware::class, function ($c): ApiValidationErrorMiddleware {
			return new ApiValidationErrorMiddleware(
				$c->query(\OCP\IRequest::class),
			);
		});
		$context->registerMiddleware(ApiValidationErrorMiddleware::class);

		$context->registerService(KioskLicenseMiddleware::class, function ($c): KioskLicenseMiddleware {
			return new KioskLicenseMiddleware(
				$c->query(\OCP\IRequest::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\Kiosk\KioskSettingsService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\LicenseService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\Kiosk\KioskTerminalService::class),
				$c->query(\OCP\L10N\IFactory::class),
				$c->query(\Psr\Log\LoggerInterface::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\Kiosk\KioskErrorMessages::class),
			);
		});
		$context->registerMiddleware(KioskLicenseMiddleware::class);

		// Register event listeners
		$context->registerEventListener(LoadSidebar::class, LoadSidebarScripts::class);
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CSPListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerEventListener(SettingsBeforeTemplateRenderedEvent::class, LoadUsersSettingsArbeitszeitListener::class);
		$context->registerEventListener(
			\OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent::class,
			TimeClientBootstrapListener::class
		);

		// Register mappers
		$context->registerService(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\TimeEntryMapper(
				$c->query(IDBConnection::class),
				$c->query(\OCP\IConfig::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\AbsenceMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\AuditLogMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\UserSettingsMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\HolidayMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\HolidayMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\UserOvertimeYearBalanceMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\UserOvertimeYearBalanceMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService(
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserOvertimeYearBalanceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService(
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IL10N::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\AdminUserProfileUpdateService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\AdminUserProfileUpdateService(
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationAllocationService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService::class),
				$c->query(\OCP\IL10N::class),
				$c->query(IDBConnection::class),
				$c->query(\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationUnitService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService::class),
				$c->query(\OCP\Lock\ILockingProvider::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\AbsenceService::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\VacationRolloverLogMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\VacationRolloverLogMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\ModelVacationDefaultMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\ModelVacationDefaultMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\TeamVacationPolicyMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\TeamVacationPolicyMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshotMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshotMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\TeamMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\TeamMapper(
				$c->query(IDBConnection::class)
			);
		});
		$context->registerService(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\TeamMemberMapper(
				$c->query(IDBConnection::class)
			);
		});
		$context->registerService(\OCA\ArbeitszeitCheck\Db\TeamManagerMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\TeamManagerMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\LicenseStateMapper::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Db\LicenseStateMapper(
				$c->query(IDBConnection::class),
			);
		});
		$context->registerService(\OCA\ArbeitszeitCheck\Db\MobileSeatMapper::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Db\MobileSeatMapper(
				$c->query(IDBConnection::class),
			);
		});
		$context->registerService(\OCA\ArbeitszeitCheck\Db\TerminalDeviceMapper::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Db\TerminalDeviceMapper(
				$c->query(IDBConnection::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\MonthClosureMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\MonthClosureMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\MonthClosureRevisionMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\MonthClosureRevisionMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\MonthClosureService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\MonthClosureService(
				$c->query(\OCA\ArbeitszeitCheck\Db\MonthClosureMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\MonthClosureRevisionMapper::class),
				$c->query(ReportingService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\Psr\Log\LoggerInterface::class),
				$c->query(PermissionService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeZoneService::class),
				$c->query(\OCP\Lock\ILockingProvider::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\PremiumSurchargeService::class),
				$c->query(\OCA\ArbeitszeitCheck\Policy\MonthClosureContributorRegistry::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\MonthClosureGuard::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\MonthClosureGuard(
				$c->query(\OCA\ArbeitszeitCheck\Service\MonthClosureService::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\TimeEntryDeletionPolicy::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Service\TimeEntryDeletionPolicy(
				$c->query(\OCP\IConfig::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\MonthClosureGuard::class),
				$c->query(\OCP\IL10N::class),
			);
		});

		$context->registerService(EnsureArbeitszeitCheckSchema::class, function ($c): EnsureArbeitszeitCheckSchema {
			return new EnsureArbeitszeitCheckSchema(
				$c->query(IDBConnection::class),
				$c->query(\OCP\IConfig::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class, function ($c): \OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig {
			return new \OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig($c->query(\OCP\IConfig::class));
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Policy\PolicyScopeResolver::class, fn (): \OCA\ArbeitszeitCheck\Policy\PolicyScopeResolver => new \OCA\ArbeitszeitCheck\Policy\PolicyScopeResolver());
		$context->registerService(\OCA\ArbeitszeitCheck\Service\SpecialEntitlementMonthClosureContributor::class, fn ($c): \OCA\ArbeitszeitCheck\Service\SpecialEntitlementMonthClosureContributor => new \OCA\ArbeitszeitCheck\Service\SpecialEntitlementMonthClosureContributor(
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Policy\MonthClosureContributorRegistry::class, function ($c): \OCA\ArbeitszeitCheck\Policy\MonthClosureContributorRegistry {
			$registry = new \OCA\ArbeitszeitCheck\Policy\MonthClosureContributorRegistry();
			$registry->register($c->query(\OCA\ArbeitszeitCheck\Service\SpecialEntitlementMonthClosureContributor::class));
			$registry->register($c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripMonthClosureContributor::class));
			return $registry;
		});
		$context->registerService(\OCA\ArbeitszeitCheck\Db\PolicyCohortMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\PolicyCohortMapper => new \OCA\ArbeitszeitCheck\Db\PolicyCohortMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper => new \OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\PolicyCohortResolver::class, fn ($c): \OCA\ArbeitszeitCheck\Service\PolicyCohortResolver => new \OCA\ArbeitszeitCheck\Service\PolicyCohortResolver(
			$c->query(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\TimeDomainConflictService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\TimeDomainConflictService => new \OCA\ArbeitszeitCheck\Service\TimeDomainConflictService(
			$c->query(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class),
		));

		$context->registerService(\OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper => new \OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper => new \OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\WorkLocationDayOverrideMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\WorkLocationDayOverrideMapper => new \OCA\ArbeitszeitCheck\Db\WorkLocationDayOverrideMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper => new \OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicyMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicyMapper => new \OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicyMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper => new \OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper => new \OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper => new \OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\SpecialEntitlementResolver::class, fn ($c): \OCA\ArbeitszeitCheck\Service\SpecialEntitlementResolver => new \OCA\ArbeitszeitCheck\Service\SpecialEntitlementResolver(
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicyMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\PolicyCohortResolver::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\SpecialEntitlementService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\SpecialEntitlementService => new \OCA\ArbeitszeitCheck\Service\SpecialEntitlementService(
			$c->query(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\SpecialEntitlementResolver::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\VacationHoursDebitService::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\EntitlementSnapshotService::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
			$c->query(\OCP\Lock\ILockingProvider::class),
			$c->query(\OCP\IDBConnection::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\SpecialEntitlementAdminService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\SpecialEntitlementAdminService => new \OCA\ArbeitszeitCheck\Service\SpecialEntitlementAdminService(
			$c->query(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementTypeMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicyMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementPeriodMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementReservationMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\SpecialEntitlementLedgerEntryMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\SpecialEntitlementResolver::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\SpecialEntitlementService::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshotMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\CustomAbsenceTypeMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\PolicyCohortMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper::class),
			$c->query(\OCP\IUserManager::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
			$c->query(\OCP\Lock\ILockingProvider::class),
			$c->query(\OCP\IDBConnection::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\WorkLocationCatalog::class, fn ($c): \OCA\ArbeitszeitCheck\Service\WorkLocationCatalog => new \OCA\ArbeitszeitCheck\Service\WorkLocationCatalog(
			$c->query(\OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper::class),
			$c->query(\Psr\Log\LoggerInterface::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\WorkLocationDefaultResolver::class, fn ($c): \OCA\ArbeitszeitCheck\Service\WorkLocationDefaultResolver => new \OCA\ArbeitszeitCheck\Service\WorkLocationDefaultResolver(
			$c->query(\OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\WorkLocationCatalog::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Policy\PolicyScopeResolver::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\WorkLocationDayOverrideService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\WorkLocationDayOverrideService => new \OCA\ArbeitszeitCheck\Service\WorkLocationDayOverrideService(
			$c->query(\OCA\ArbeitszeitCheck\Db\WorkLocationDayOverrideMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\WorkLocationCatalog::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
			$c->query(\OCP\Lock\ILockingProvider::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\WorkLocationAdminService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\WorkLocationAdminService => new \OCA\ArbeitszeitCheck\Service\WorkLocationAdminService(
			$c->query(\OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\WorkLocationCatalog::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
			$c->query(\OCP\IUserManager::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
			$c->query(\OCP\Lock\ILockingProvider::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\WorkLocationReportAggregator::class, fn ($c): \OCA\ArbeitszeitCheck\Service\WorkLocationReportAggregator => new \OCA\ArbeitszeitCheck\Service\WorkLocationReportAggregator(
			$c->query(\OCA\ArbeitszeitCheck\Service\WorkLocationCatalog::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\WorkLocationMigrationReadinessService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\WorkLocationMigrationReadinessService => new \OCA\ArbeitszeitCheck\Service\WorkLocationMigrationReadinessService(
			$c->query(\OCP\IDBConnection::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper::class),
		));
		$context->registerService(SeedWorkLocationCatalog::class, function ($c): SeedWorkLocationCatalog {
			return new SeedWorkLocationCatalog(
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
			);
		});

		// Business-trip domain (Phase 5).
		$context->registerService(\OCA\ArbeitszeitCheck\Db\BusinessTripMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\BusinessTripMapper => new \OCA\ArbeitszeitCheck\Db\BusinessTripMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\BusinessTripSegmentMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\BusinessTripSegmentMapper => new \OCA\ArbeitszeitCheck\Db\BusinessTripSegmentMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\BusinessTripTimeEntryLinkMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\BusinessTripTimeEntryLinkMapper => new \OCA\ArbeitszeitCheck\Db\BusinessTripTimeEntryLinkMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper => new \OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper => new \OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\UserBusinessTripProfileMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\UserBusinessTripProfileMapper => new \OCA\ArbeitszeitCheck\Db\UserBusinessTripProfileMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper => new \OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper => new \OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Db\BusinessTripSnapshotMapper::class, fn ($c): \OCA\ArbeitszeitCheck\Db\BusinessTripSnapshotMapper => new \OCA\ArbeitszeitCheck\Db\BusinessTripSnapshotMapper($c->query(\OCP\IDBConnection::class)));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator::class, fn (): \OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator => new \OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator());
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripTimelineValidator::class, fn (): \OCA\ArbeitszeitCheck\Service\BusinessTripTimelineValidator => new \OCA\ArbeitszeitCheck\Service\BusinessTripTimelineValidator());
		$context->registerService(\OCA\ArbeitszeitCheck\Service\TravelComplianceClassifier::class, fn (): \OCA\ArbeitszeitCheck\Service\TravelComplianceClassifier => new \OCA\ArbeitszeitCheck\Service\TravelComplianceClassifier());
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripRuleCatalog::class, fn ($c): \OCA\ArbeitszeitCheck\Service\BusinessTripRuleCatalog => new \OCA\ArbeitszeitCheck\Service\BusinessTripRuleCatalog(
			$c->query(\OCP\IDBConnection::class),
			$c->query(\OCP\Lock\ILockingProvider::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripRuleResolver::class, fn ($c): \OCA\ArbeitszeitCheck\Service\BusinessTripRuleResolver => new \OCA\ArbeitszeitCheck\Service\BusinessTripRuleResolver(
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\PolicyCohortResolver::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripCalculationEngine::class, fn ($c): \OCA\ArbeitszeitCheck\Service\BusinessTripCalculationEngine => new \OCA\ArbeitszeitCheck\Service\BusinessTripCalculationEngine(
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\TravelComplianceClassifier::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\DailyWorkingHoursCalculator::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripSnapshotService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\BusinessTripSnapshotService => new \OCA\ArbeitszeitCheck\Service\BusinessTripSnapshotService(
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripSnapshotMapper::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripCreditLedger::class, fn ($c): \OCA\ArbeitszeitCheck\Service\BusinessTripCreditLedger => new \OCA\ArbeitszeitCheck\Service\BusinessTripCreditLedger(
			$c->query(\OCP\Lock\ILockingProvider::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\BusinessTripService => new \OCA\ArbeitszeitCheck\Service\BusinessTripService(
			$c->query(\OCP\IDBConnection::class),
			$c->query(\OCP\Lock\ILockingProvider::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripSegmentMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripTimeEntryLinkMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\UserBusinessTripProfileMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripTimelineValidator::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripRuleResolver::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripCalculationEngine::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripSnapshotService::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripCreditLedger::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\MonthClosureService::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\PermissionService::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripMonthClosureContributor::class, fn ($c): \OCA\ArbeitszeitCheck\Service\BusinessTripMonthClosureContributor => new \OCA\ArbeitszeitCheck\Service\BusinessTripMonthClosureContributor(
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripAdminService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\BusinessTripAdminService => new \OCA\ArbeitszeitCheck\Service\BusinessTripAdminService(
			$c->query(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class),
			$c->query(\OCP\IDBConnection::class),
			$c->query(\OCP\Lock\ILockingProvider::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\UserBusinessTripProfileMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripService::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripRuleCatalog::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripRuleConfigValidator::class),
			$c->query(\OCP\IUserManager::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripRolloutReadinessService::class),
			$c->query(\Psr\Log\LoggerInterface::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripRolloutReadinessService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\BusinessTripRolloutReadinessService => new \OCA\ArbeitszeitCheck\Service\BusinessTripRolloutReadinessService(
			$c->query(\OCP\IDBConnection::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\BusinessTripExportService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\BusinessTripExportService => new \OCA\ArbeitszeitCheck\Service\BusinessTripExportService(
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripSnapshotService::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\PostCommitEffectQueue::class, fn ($c): \OCA\ArbeitszeitCheck\Service\PostCommitEffectQueue => new \OCA\ArbeitszeitCheck\Service\PostCommitEffectQueue(
			$c->query(\OCP\IDBConnection::class),
			$c->query(\Psr\Log\LoggerInterface::class),
		));
		$context->registerService(\OCA\ArbeitszeitCheck\Service\ManagerBulkDecisionService::class, fn ($c): \OCA\ArbeitszeitCheck\Service\ManagerBulkDecisionService => new \OCA\ArbeitszeitCheck\Service\ManagerBulkDecisionService(
			$c->query(\OCP\IDBConnection::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\BusinessTripMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService::class),
			$c->query(AbsenceService::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\BusinessTripService::class),
			$c->query(PermissionService::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class),
			$c->query(\OCP\IL10N::class),
			$c->query(\OCA\ArbeitszeitCheck\Service\PostCommitEffectQueue::class),
		));

		$context->registerService(InitializeWorkforceSuiteFreshState::class, function ($c): InitializeWorkforceSuiteFreshState {
			return new InitializeWorkforceSuiteFreshState($c->query(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class));
		});

		$context->registerService(InitializeWorkforceSuiteUpgradeState::class, function ($c): InitializeWorkforceSuiteUpgradeState {
			return new InitializeWorkforceSuiteUpgradeState($c->query(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class));
		});

		$context->registerService(BackfillAbsenceDays::class, function ($c): BackfillAbsenceDays {
			return new BackfillAbsenceDays(
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(HolidayService::class),
			);
		});

		$context->registerService(ReleaseStuckPendingAbsences::class, function ($c): ReleaseStuckPendingAbsences {
			return new ReleaseStuckPendingAbsences(
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(AbsenceService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService::class),
				$c->query(TeamResolverService::class),
			);
		});

		$context->registerService(RepairOrphanedPausedEntries::class, function ($c): RepairOrphanedPausedEntries {
			return new RepairOrphanedPausedEntries(
				$c->query(IDBConnection::class),
			);
		});

		$context->registerService(ClearStuckKioskEnrollmentLocks::class, function ($c): ClearStuckKioskEnrollmentLocks {
			return new ClearStuckKioskEnrollmentLocks(
				$c->query(IDBConnection::class),
			);
		});

		$context->registerService(CleanDanglingTariffRuleSetRefs::class, function ($c): CleanDanglingTariffRuleSetRefs {
			return new CleanDanglingTariffRuleSetRefs(
				$c->query(IDBConnection::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
			);
		});

		$context->registerService(UninstallDropTables::class, function ($c): UninstallDropTables {
			return new UninstallDropTables(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(IRootFolder::class),
			);
		});
		$context->registerService(UpgradeBackupService::class, function ($c): UpgradeBackupService {
			return new UpgradeBackupService(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\IConfig::class),
				$c->query(IRootFolder::class),
				$c->query(IAppManager::class),
				$c->query(ILockingProvider::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(BackupBeforeUpdate::class, function ($c): BackupBeforeUpdate {
			return new BackupBeforeUpdate(
				$c->query(UpgradeBackupService::class),
			);
		});


		// Register CSPService
		$context->registerService(CSPService::class, function($c) {
			return new CSPService(
				$c->query(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)
			);
		});

		// Register ProjectCheckIntegrationService
		$context->registerService(ProjectCheckIntegrationService::class, function($c) {
			$projectCheckProjectService = null;
			if (\class_exists(\OCA\ProjectCheck\Service\ProjectService::class)) {
				try {
					$projectCheckProjectService = $c->query(\OCA\ProjectCheck\Service\ProjectService::class);
				} catch (\Throwable $e) {
					$projectCheckProjectService = null;
				}
			}
			$projectCheckTimeEntryService = null;
			if (\class_exists(\OCA\ProjectCheck\Service\TimeEntryService::class)) {
				try {
					$projectCheckTimeEntryService = $c->query(\OCA\ProjectCheck\Service\TimeEntryService::class);
				} catch (\Throwable $e) {
					$projectCheckTimeEntryService = null;
				}
			}
			return new ProjectCheckIntegrationService(
				$c->query(\OCP\App\IAppManager::class),
				$c->query(\OCP\AppFramework\Services\IAppConfig::class),
				$c->query(IDBConnection::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\Psr\Log\LoggerInterface::class),
				$projectCheckProjectService,
				$projectCheckTimeEntryService,
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class, function ($c) {
			$projectCheckTimeEntryService = null;
			if (\class_exists(\OCA\ProjectCheck\Service\TimeEntryService::class)) {
				try {
					$projectCheckTimeEntryService = $c->query(\OCA\ProjectCheck\Service\TimeEntryService::class);
				} catch (\Throwable $e) {
					$projectCheckTimeEntryService = null;
				}
			}
			return new \OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService(
				$c->query(\OCP\App\IAppManager::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeZoneService::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\Psr\Log\LoggerInterface::class),
				$projectCheckTimeEntryService,
			);
		});

		$context->registerService(DailyWorkingHoursCalculator::class, function ($c) {
			return new DailyWorkingHoursCalculator(
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeZoneService::class),
			);
		});

		// Register services
		$context->registerService(TimeTrackingService::class, function($c) {
			return new TimeTrackingService(
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(ProjectCheckIntegrationService::class),
				$c->query(ComplianceService::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\MonthClosureGuard::class),
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\Lock\ILockingProvider::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeZoneService::class),
				$c->query(DailyWorkingHoursCalculator::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService::class),
				$c->query(\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::class),
			);
		});

		$context->registerService(AbsenceIcalMailService::class, function($c) {
			return new AbsenceIcalMailService(
				$c->query(\OCP\Mail\IMailer::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(TeamResolverService::class),
				$c->query(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(AbsenceNotificationMailService::class, function($c) {
			return new AbsenceNotificationMailService(
				$c->query(\OCP\Mail\IMailer::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(TeamResolverService::class),
				$c->query(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(ManagerPendingApprovalMailService::class, function ($c) {
			return new ManagerPendingApprovalMailService(
				$c->query(\OCP\Mail\IMailer::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IURLGenerator::class),
				$c->query(TeamResolverService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamManagerMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(AbsenceService::class, function($c) {
			return new AbsenceService(
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(TeamResolverService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\Lock\ILockingProvider::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IL10N::class),
				$c->query(NotificationService::class),
				$c->query(AbsenceIcalMailService::class),
				$c->query(HolidayService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class),
				$c->query(VacationAllocationService::class),
				$c->query(AbsenceNotificationMailService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\MonthClosureService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeZoneService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationYearWindowResolver::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationUnitService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationHoursDebitService::class),
				$c->query(ManagerPendingApprovalMailService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\SpecialEntitlementService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\PostCommitEffectQueue::class),
				$c->query(\OCP\ICacheFactory::class),
			);
		});

		// Pass null for MonthClosureService — eager query here re-enters
		// MonthClosure → Reporting → Overtime → DutyRotationSoll and blows the
		// PHP stack (503 for every Nextcloud request). Lazy-resolved inside
		// DutyRotationSollProvider::resolveMonthClosure() after boot.
		$context->registerService(\OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider(
				$c->query(\OCP\IConfig::class),
				$c,
				null,
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\VacationHoursDebitService::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Service\VacationHoursDebitService(
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$c->query(HolidayService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationUnitService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory(
				$c->query(\OCP\IConfig::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
			);
		});

		$context->registerService(ComplianceService::class, function($c) {
			return new ComplianceService(
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IL10N::class),
				$c->query(NotificationService::class),
				$c->query(HolidayService::class),
				$c->query(\OCP\IConfig::class),
				$c->query(PermissionService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeZoneService::class),
				$c->query(DailyWorkingHoursCalculator::class),
				$c->query(\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::class),
				$c->query(\OCA\ArbeitszeitCheck\Support\BreakPolicyResolver::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
			);
		});

		$context->registerService(HolidayService::class, function($c) {
			return new HolidayService(
				$c->query(\OCA\ArbeitszeitCheck\Db\HolidayMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\HolidaySuppressionMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\ICacheFactory::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\HolidayAdminService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\HolidayAdminService(
				$c->query(\OCA\ArbeitszeitCheck\Db\HolidayMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\HolidaySuppressionMapper::class),
				$c->query(HolidayService::class),
				$c->query(\OCP\IConfig::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService(
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\VacationProrationService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\VacationProrationService(
				$c->query(\OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService::class),
				$c->query(\OCP\IConfig::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\VacationYearWindowResolver::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\VacationYearWindowResolver(
				$c->query(\OCP\IConfig::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Support\PremiumSurchargeClassifier::class, function () {
			return new \OCA\ArbeitszeitCheck\Support\PremiumSurchargeClassifier();
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\PremiumSurchargeService::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Service\PremiumSurchargeService(
				$c->query(\OCP\IConfig::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(HolidayService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Support\PremiumSurchargeClassifier::class),
			);
		});

		$context->registerService(VacationAllocationService::class, function($c) {
			return new VacationAllocationService(
				$c->query(\OCP\IConfig::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class),
				$c->query(HolidayService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\EntitlementSnapshotService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationProrationService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationYearWindowResolver::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationUnitService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationHoursDebitService::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\VacationUnitService::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Service\VacationUnitService(
				$c->query(\OCP\IConfig::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService(
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationUnitService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper::class),
				null,
				$c->query(\OCP\Lock\ILockingProvider::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine(
				$c->query(\OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\ModelVacationDefaultMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamVacationPolicyMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
				$c->query(\OCP\IConfig::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\EntitlementSnapshotService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\EntitlementSnapshotService(
				$c->query(\OCA\ArbeitszeitCheck\Db\EntitlementComputationSnapshotMapper::class),
				$c->query(\OCP\Lock\ILockingProvider::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\LayeredVacationDefaultsService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\LayeredVacationDefaultsService(
				$c->query(\OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\ModelVacationDefaultMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamVacationPolicyMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(IDBConnection::class),
				$c->query(\OCP\Lock\ILockingProvider::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationUnitService::class)
			);
		});

		$context->registerService(VacationRolloverService::class, function($c) {
			return new VacationRolloverService(
				$c->query(\OCP\IConfig::class),
				$c->query(VacationAllocationService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\VacationRolloverLogMapper::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(PermissionService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationUnitService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Command\ImportVacationBalanceCommand::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Command\ImportVacationBalanceCommand(
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(VacationAllocationService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationUnitService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\VacationUnitMigrationService::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Command\RecomputePaidBreaksCommand::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Command\RecomputePaidBreaksCommand(
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeTrackingService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(\OCP\BackgroundJob\IJobList::class),
			);
		});

		$context->registerService(NotificationService::class, function($c) {
			return new NotificationService(
				$c->query(\OCP\Notification\IManager::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCA\ArbeitszeitCheck\Util\AbsenceWorkingDaysResolver::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService::class),
				$c->query(TeamResolverService::class),
				$c->query(ManagerPendingApprovalMailService::class),
			);
		});

		$context->registerService(OvertimeNotificationMailService::class, function($c) {
			return new OvertimeNotificationMailService(
				$c->query(\OCP\Mail\IMailer::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(PaidAbsencePlannedHoursCreditService::class, function ($c) {
			return new PaidAbsencePlannedHoursCreditService(
				$c->query(\OCP\IConfig::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$c->query(HolidayService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider::class),
			);
		});

		$context->registerService(OvertimeService::class, function($c) {
			return new OvertimeService(
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCP\IL10N::class),
				$c->query(HolidayService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\DutyRotationSollProvider::class),
				$c->query(PaidAbsencePlannedHoursCreditService::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService(
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\MonthClosureGuard::class),
				$c->query(ComplianceService::class),
				$c->query(TimeTrackingService::class),
				$c->query(NotificationService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IL10N::class),
				$c->query(ProjectCheckIntegrationService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\ProjectCheckLaborTimeSyncService::class),
			);
		});

		$context->registerService(OvertimeTrafficLightService::class, function($c) {
			return new OvertimeTrafficLightService(
				$c->query(\OCP\IConfig::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\OvertimeBankService(
				$c->query(\OCP\IConfig::class),
				$c->query(OvertimeService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\OvertimeAdjustmentService::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Service\OvertimeAdjustmentService(
				$c->query(\OCA\ArbeitszeitCheck\Db\OvertimeAdjustmentMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\Lock\ILockingProvider::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\OvertimeDisplayService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\OvertimeDisplayService(
				$c->query(OvertimeService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class),
				$c->query(OvertimeTrafficLightService::class),
			);
		});

		$context->registerService(OvertimePayoutMailService::class, function($c) {
			return new OvertimePayoutMailService(
				$c->query(\OCP\Mail\IMailer::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\OvertimePayoutService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\OvertimePayoutService(
				$c->query(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService::class),
				$c->query(PermissionService::class),
				$c->query(NotificationService::class),
				$c->query(OvertimePayoutMailService::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\Lock\ILockingProvider::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\OvertimePayoutAuditService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\OvertimePayoutAuditService(
				$c->query(\OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\OvertimePayoutService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\MonthClosureService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IURLGenerator::class),
			);
		});

		$context->registerService(DatevExportService::class, function($c) {
			return new DatevExportService(
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\PremiumSurchargeService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\MonthClosureMapper::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\TimeEntryExportTransformer::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\TimeEntryExportTransformer(
				$c->query(\OCP\IConfig::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\WorkLocationCatalog::class)
			);
		});

		$context->registerService(ReportingService::class, function($c) {
			return new ReportingService(
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper::class),
				$c->query(OvertimeService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\OvertimeBankService::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IL10N::class),
				$c->query(HolidayService::class),
				$c->query(PermissionService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeZoneService::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\WorkLocationReportAggregator::class)
			);
		});

		$context->registerService(TeamResolverService::class, function($c) {
			return new TeamResolverService(
				$c->query(\OCP\IGroupManager::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamManagerMapper::class)
			);
		});

		$context->registerService(PermissionService::class, function($c) {
			return new PermissionService(
				$c->query(\OCP\IGroupManager::class),
				$c->query(\OCP\App\IAppManager::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(TeamResolverService::class),
				$c->query(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Db\OutlookIcalSubscriptionTokenMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\OutlookIcalSubscriptionTokenMapper(
				$c->query(IDBConnection::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionFeedService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionFeedService(
				$c->query(\OCP\L10N\IFactory::class)
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionService::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionService(
				$c->query(\OCA\ArbeitszeitCheck\Db\OutlookIcalSubscriptionTokenMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\OutlookIcalSubscriptionFeedService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TeamManagerMapper::class),
				$c->query(TeamResolverService::class),
				$c->query(PermissionService::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IConfig::class),
				$c->query(IDBConnection::class),
				$c->query(\OCP\L10N\IFactory::class),
				$c->query(\OCP\Security\ICrypto::class),
			);
		});

		$context->registerService(AdminEmployeeDirectoryService::class, function ($c) {
			return new AdminEmployeeDirectoryService(
				$c->query(\OCP\IUserManager::class),
				$c->query(PermissionService::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Config\InstanceId::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Config\InstanceId(
				$c->query(\OCP\IConfig::class),
			);
		});
		$context->registerService(\OCA\ArbeitszeitCheck\Service\LicenseService::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Service\LicenseService(
				$c->query(\OCA\ArbeitszeitCheck\Db\LicenseStateMapper::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(\Psr\Log\LoggerInterface::class),
				$c->query(\OCA\ArbeitszeitCheck\Config\InstanceId::class),
			);
		});
		$context->registerService(\OCA\ArbeitszeitCheck\Service\MobileSeatService::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Service\MobileSeatService(
				$c->query(\OCA\ArbeitszeitCheck\Db\MobileSeatMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\LicenseService::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\AppFramework\Utility\ITimeFactory::class),
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\Lock\ILockingProvider::class),
			);
		});
		$context->registerService(\OCA\ArbeitszeitCheck\Service\TerminalDeviceService::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Service\TerminalDeviceService(
				$c->query(\OCA\ArbeitszeitCheck\Db\TerminalDeviceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\LicenseService::class),
				$c->query(\OCP\IDBConnection::class),
				$c->query(\OCP\Lock\ILockingProvider::class),
			);
		});

		$context->registerService(\OCA\ArbeitszeitCheck\Service\LocaleFormatService::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Service\LocaleFormatService(
				$c->query(\OCP\L10N\IFactory::class),
				$c->query(\OCP\IDateTimeFormatter::class),
				$c->query(\OCP\IUserSession::class),
				$c->query(\OCP\IDateTimeZone::class),
				$c->query(\OCA\ArbeitszeitCheck\Service\TimeZoneService::class),
			);
		});

		// F19: versioned suite facade — approved-absence status-only reads
		// for sibling apps. Probed via class_exists + resolved through
		// Server::get; additive-only contract.
		$context->registerService(\OCA\ArbeitszeitCheck\Public\ApprovedAbsenceReadFacade::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Public\ApprovedAbsenceReadFacade(
				$c->query(\OCP\IDBConnection::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});

		// F16/F19b: versioned suite facade — sibling apps propose pending
		// stamps the affected worker confirms. Probed via class_exists +
		// resolved through Server::get; additive-only contract.
		$context->registerService(\OCA\ArbeitszeitCheck\Public\ExternalStampDraftFacade::class, function ($c) {
			return new \OCA\ArbeitszeitCheck\Public\ExternalStampDraftFacade(
				$c->query(\OCA\ArbeitszeitCheck\Service\ExternalStampService::class),
				$c->query(\Psr\Log\LoggerInterface::class),
			);
		});

		$context->registerDashboardWidget(EmployeeStatusWidget::class);
		$context->registerDashboardWidget(ManagerTeamStatusWidget::class);
		$context->registerDashboardWidget(AdminGlobalStatusWidget::class);
	}

	/**
	 * @inheritDoc
	 *
	 * Asset registration is owned by the central FrontEndAssetService and is
	 * invoked from every entry point that renders a template (PageShellTrait,
	 * AdminSettings, the access-denied template, and personal-settings).
	 * We deliberately keep boot() empty so other apps cannot accidentally
	 * inherit our CSS/JS, and so the loader cannot drift out of sync with
	 * the FrontEndAssetService bundle (which is the single source of truth).
	 */
	public function boot(IBootContext $context): void {
		$app = $context->getAppContainer();
		// Menu entry only for users with app access — a static <navigation>
		// entry would advertise the app to users the door denies.
		NavGate::register(
			$app,
			'arbeitszeitcheck.page.index',
			'app.svg',
			'ArbeitszeitCheck',
			15,
			static fn(string $uid): bool => $app->get(PermissionService::class)->isUserAllowedByAccessGroups($uid),
		);
	}
}