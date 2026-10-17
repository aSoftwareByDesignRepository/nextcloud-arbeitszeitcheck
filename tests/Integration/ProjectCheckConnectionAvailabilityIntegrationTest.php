<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Test\TestCase;

/**
 * Availability must match Nextcloud's instance-level install flag, not the
 * current user's group restriction (CLI / cron have no session user).
 */
final class ProjectCheckConnectionAvailabilityIntegrationTest extends TestCase
{
	private function liveIntegrationService(): ProjectCheckIntegrationService
	{
		$appConfig = new \OC\AppFramework\Services\AppConfig(
			\OCP\Server::get(\OCP\IConfig::class),
			\OCP\Server::get(\OCP\IAppConfig::class),
			Application::APP_ID,
		);

		return new ProjectCheckIntegrationService(
			\OCP\Server::get(IAppManager::class),
			$appConfig,
			\OCP\Server::get(IDBConnection::class),
			\OCP\Server::get(\OCP\L10N\IFactory::class)->get(Application::APP_ID),
			\OCP\Server::get(LoggerInterface::class),
		);
	}

	public function testServiceAvailabilityMatchesAppManagerInstall(): void
	{
		/** @var IAppManager $apps */
		$apps = \OCP\Server::get(IAppManager::class);
		$svc = $this->liveIntegrationService();

		$installed = $apps->isInstalled(Constants::APP_ID_PROJECTCHECK);
		$this->assertSame($installed, $svc->isProjectCheckAvailable());
		$this->assertSame(
			$installed && $apps->isEnabledForUser(Constants::APP_ID_PROJECTCHECK),
			$svc->isProjectCheckEnabledForUser(null),
		);
	}

	public function testGroupRestrictedInstallStillCountsAsAvailable(): void
	{
		/** @var IAppManager $apps */
		$apps = \OCP\Server::get(IAppManager::class);
		$svc = $this->liveIntegrationService();

		if ($apps->isInstalled(Constants::APP_ID_PROJECTCHECK) !== true) {
			$this->assertFalse($svc->isProjectCheckAvailable());
			return;
		}

		$this->assertTrue($svc->isProjectCheckAvailable());
		$user = \OCP\Server::get(\OCP\IUserSession::class)->getUser();
		if ($user === null && $apps->isEnabledForUser(Constants::APP_ID_PROJECTCHECK) !== true) {
			$this->assertFalse($svc->isProjectCheckEnabledForUser(null));
			$this->assertTrue($svc->isProjectCheckAvailable(), 'CLI/no-user must not hide an installed ProjectCheck');
		}
	}
}
