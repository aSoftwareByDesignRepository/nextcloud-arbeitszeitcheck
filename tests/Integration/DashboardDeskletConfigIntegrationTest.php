<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Service\DashboardDeskletConfigService;
use OCP\App\IAppManager;
use Test\TestCase;

/**
 * Guards the NC home dashboard desklet: buildForUser must resolve API URLs and load app routes.
 */
class DashboardDeskletConfigIntegrationTest extends TestCase {
	public function testBuildForUserResolvesDeskletApiUrls(): void {
		$service = \OCP\Server::get(DashboardDeskletConfigService::class);
		$config = $service->buildForUser('admin');

		$this->assertStringContainsString('/api/dashboard-widget/employee', (string)$config['employeeDataUrl']);
		$this->assertStringContainsString('/api/dashboard-widget/clock/in', (string)$config['clockInUrl']);
		$this->assertStringContainsString('/dashboard', (string)$config['dashboardUrl']);
		$this->assertTrue(\OCP\Server::get(IAppManager::class)->isAppLoaded(Application::APP_ID));
	}
}
