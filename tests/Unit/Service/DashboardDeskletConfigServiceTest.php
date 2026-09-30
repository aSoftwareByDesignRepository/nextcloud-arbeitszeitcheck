<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\DashboardDeskletConfigService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class DashboardDeskletConfigServiceTest extends TestCase {
	public function testBuildL10nPreservesPlaceholderTemplates(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $id, array $params = []): string {
			return vsprintf($id, $params);
		});

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/route');

		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canAccessManagerDashboard')->willReturn(false);
		$permissions->method('isAdmin')->willReturn(false);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppWebPath')->willReturn('/custom_apps/arbeitszeitcheck');

		$projectCheck = $this->createMock(ProjectCheckIntegrationService::class);
		$projectCheck->method('isLinkingEnabledForUser')->with('alice')->willReturn(false);
		$projectCheck->method('isProjectCheckAvailable')->willReturn(false);
		$projectCheck->expects($this->never())->method('getAvailableProjects');

		$service = new DashboardDeskletConfigService(
			$urlGenerator,
			$permissions,
			$l10n,
			$appManager,
			$projectCheck,
		);
		$config = $service->buildForUser('alice');
		$l10nMap = $config['l10n'];

		$this->assertSame('Status: %1$s', $l10nMap['statusLine']);
		$this->assertSame('Last updated: %1$s', $l10nMap['lastUpdated']);
		$this->assertSame('%1$s successful', $l10nMap['actionDone']);
		$this->assertSame('%1$s: %2$s (%3$s h)', $l10nMap['peopleRow']);
		$this->assertSame('Working', $l10nMap['working']);
		$this->assertSame('Project', $l10nMap['projectLabel']);
		$this->assertSame('Daily maximum reached', $l10nMap['dailyMaximumTitle']);
		$this->assertSame('ProjectCheck linking is turned off', $l10nMap['projectLinkingOffTitle']);
		$this->assertIsArray($config['projectCheck']);
		$this->assertFalse($config['projectCheck']['linkingEnabled']);
		$this->assertSame([], $config['projectCheck']['projects']);
	}

	public function testBuildForUserIncludesAssignableProjectsWhenLinkingEnabled(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static function (string $id, array $params = []): string {
			return vsprintf($id, $params);
		});

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/route');

		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canAccessManagerDashboard')->willReturn(false);
		$permissions->method('isAdmin')->willReturn(false);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppWebPath')->willReturn('/custom_apps/arbeitszeitcheck');

		$projectCheck = $this->createMock(ProjectCheckIntegrationService::class);
		$projectCheck->method('isLinkingEnabledForUser')->with('bob')->willReturn(true);
		$projectCheck->method('isProjectCheckAvailable')->willReturn(true);
		$projectCheck->method('getAvailableProjects')->with('bob')->willReturn([
			['id' => '42', 'displayName' => 'Alpha'],
		]);

		$service = new DashboardDeskletConfigService(
			$urlGenerator,
			$permissions,
			$l10n,
			$appManager,
			$projectCheck,
		);
		$config = $service->buildForUser('bob');

		$this->assertTrue($config['projectCheck']['available']);
		$this->assertTrue($config['projectCheck']['linkingEnabled']);
		$this->assertCount(1, $config['projectCheck']['projects']);
		$this->assertSame('42', $config['projectCheck']['projects'][0]['id']);
	}

	public function testBuildForUserFallsBackToWebPathWhenRoutesNotRegistered(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);

		// router has no routes -> linkToRoute returns "" -> fallback must use appWebPathPrefix
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('');

		$permissions = $this->createMock(PermissionService::class);
		$permissions->method('canAccessManagerDashboard')->willReturn(false);
		$permissions->method('isAdmin')->willReturn(false);

		$projectCheck = $this->createMock(ProjectCheckIntegrationService::class);
		$projectCheck->method('isLinkingEnabledForUser')->willReturn(false);
		$projectCheck->method('isProjectCheckAvailable')->willReturn(false);

		// case 1: appManager returns a path WITHOUT leading slash -> normalized
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppWebPath')->willReturn('custom_apps/arbeitszeitcheck');
		$service = new DashboardDeskletConfigService($urlGenerator, $permissions, $l10n, $appManager, $projectCheck);
		$config = $service->buildForUser('alice');
		$this->assertSame(
			'/index.php/custom_apps/arbeitszeitcheck/api/dashboard-widget/employee',
			$config['employeeDataUrl']
		);
		$this->assertSame(
			'/index.php/custom_apps/arbeitszeitcheck/dashboard',
			$config['dashboardUrl']
		);

		// case 2: appManager returns empty -> default '/apps/<appid>' prefix
		$appManagerEmpty = $this->createMock(IAppManager::class);
		$appManagerEmpty->method('getAppWebPath')->willReturn('');
		$service2 = new DashboardDeskletConfigService($urlGenerator, $permissions, $l10n, $appManagerEmpty, $projectCheck);
		$config2 = $service2->buildForUser('alice');
		$this->assertSame(
			'/index.php/apps/arbeitszeitcheck/time-entries',
			$config2['timeEntriesUrl']
		);
	}
}