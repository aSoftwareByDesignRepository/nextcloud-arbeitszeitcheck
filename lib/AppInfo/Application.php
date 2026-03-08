<?php

declare(strict_types=1);

/**
 * Application class for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\AppInfo;

use OCA\ArbeitszeitCheck\Capabilities;
use OCA\ArbeitszeitCheck\Listener\LoadSidebarScripts;
use OCA\ArbeitszeitCheck\Listener\CSPListener;
use OCA\ArbeitszeitCheck\Listener\UserDeletedListener;
use OCA\ArbeitszeitCheck\Notification\Notifier;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\AbsenceIcalMailService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\DatevExportService;
use OCA\ArbeitszeitCheck\Service\ReportingService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\Files\Event\LoadSidebar;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\Security\CSP\AddContentSecurityPolicyEvent;
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

		// Register event listeners
		$context->registerEventListener(LoadSidebar::class, LoadSidebarScripts::class);
		$context->registerEventListener(AddContentSecurityPolicyEvent::class, CSPListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);

		// Register mappers
		$context->registerService(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class, function($c) {
			return new \OCA\ArbeitszeitCheck\Db\TimeEntryMapper(
				$c->query(IDBConnection::class)
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

		// Register CSPService
		$context->registerService(CSPService::class, function($c) {
			return new CSPService(
				$c->query(\OC\Security\CSP\ContentSecurityPolicyNonceManager::class)
			);
		});

		// Register ProjectCheckIntegrationService
		$context->registerService(ProjectCheckIntegrationService::class, function($c) {
			return new ProjectCheckIntegrationService(
				$c->query(\OCP\App\IAppManager::class),
				$c->query(IDBConnection::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\Psr\Log\LoggerInterface::class)
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
				$c->query(\OCP\IL10N::class)
			);
		});

		$context->registerService(AbsenceIcalMailService::class, function($c) {
			return new AbsenceIcalMailService(
				$c->query(\OCP\Mail\IMailer::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\Psr\Log\LoggerInterface::class)
			);
		});

		$context->registerService(AbsenceService::class, function($c) {
			return new AbsenceService(
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(TeamResolverService::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IL10N::class),
				$c->query(NotificationService::class),
				$c->query(AbsenceIcalMailService::class)
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
				$c->query(NotificationService::class)
			);
		});

		$context->registerService(NotificationService::class, function($c) {
			return new NotificationService(
				$c->query(\OCP\Notification\IManager::class),
				$c->query(\OCP\IL10N::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserSettingsMapper::class),
				$c->query(\OCP\IUserManager::class)
			);
		});

		$context->registerService(OvertimeService::class, function($c) {
			return new OvertimeService(
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$c->query(\OCP\IL10N::class)
			);
		});

		$context->registerService(DatevExportService::class, function($c) {
			return new DatevExportService(
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCP\IConfig::class),
				$c->query(\OCP\IL10N::class)
			);
		});

		$context->registerService(ReportingService::class, function($c) {
			return new ReportingService(
				$c->query(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\AbsenceMapper::class),
				$c->query(\OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper::class),
				$c->query(OvertimeService::class),
				$c->query(\OCP\IUserManager::class),
				$c->query(\OCP\IL10N::class)
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
				$c->query(TeamResolverService::class),
				$c->query(\Psr\Log\LoggerInterface::class)
			);
		});

		// Register dashboard widget (if implemented)
		// $context->registerDashboardWidget(DashboardWidget::class);
	}

	/**
	 * @inheritDoc
	 */
	public function boot(IBootContext $context): void {
		$context->injectFn(function (INotificationManager $notificationManager) {
			$notificationManager->registerNotifierService(Notifier::class);
		});

		// Load CSS and JS files ONLY on arbeitszeitcheck routes to avoid leaking into other apps
		// Use a safer approach that doesn't fail if IRequest is not available (e.g., during migrations)
		try {
			$container = $this->getContainer();
			
			// Try to get IRequest - if it fails, we're likely in CLI or migration context
			try {
				$request = $container->get(\OCP\IRequest::class);
			} catch (\Throwable $e) {
				// Request not available (e.g., during CLI operations or migrations)
				return;
			}
			
			if ($request === null) {
				return;
			}
			
			$path = $request->getPathInfo();
			if ($path === null || $path === '') {
				return;
			}
			
			if (strpos($path, '/apps/arbeitszeitcheck') === 0 || strpos($path, '/index.php/apps/arbeitszeitcheck') === 0) {
				\OCP\Util::addStyle(self::APP_ID, 'common/base');
				\OCP\Util::addStyle(self::APP_ID, 'common/components');
				\OCP\Util::addStyle(self::APP_ID, 'common/layout');
				\OCP\Util::addStyle(self::APP_ID, 'common/utilities');
				\OCP\Util::addStyle(self::APP_ID, 'navigation');
				\OCP\Util::addStyle(self::APP_ID, 'app-vanilla');
				\OCP\Util::addScript(self::APP_ID, 'common/utils');
				\OCP\Util::addScript(self::APP_ID, 'common/components');
				\OCP\Util::addScript(self::APP_ID, 'common/messaging');
				\OCP\Util::addScript(self::APP_ID, 'common/validation');
			}
		} catch (\Throwable $e) {
			// If request is unavailable or any error occurs, do nothing to keep other apps safe
			// This is expected during migrations, CLI operations, etc.
		}
	}
}