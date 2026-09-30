<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Constants;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PermissionServiceTest extends TestCase
{
	private function createService(IGroupManager $groupManager, TeamResolverService $teamResolver, ?IAppManager $appManager = null, ?IConfig $config = null, ?IUserManager $userManager = null): PermissionService
	{
		if ($config === null) {
			$configMock = $this->createMock(IConfig::class);
			$configMock->method('getAppValue')->willReturn('[]');
			$config = $configMock;
		}

		return new PermissionService(
			$groupManager,
			$appManager ?? $this->createMock(IAppManager::class),
			$config,
			$userManager ?? $this->createMock(IUserManager::class),
			$teamResolver,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testCanManageEmployeeRejectsSelf(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$service = $this->createService($groupManager, $teamResolver);

		$this->assertFalse($service->canManageEmployee('u1', 'u1'));
	}

	public function testCanManageEmployeeAllowsAdmin(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin1')->willReturn(true);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->expects($this->never())->method('canUserManageEmployee');
		$service = $this->createService($groupManager, $teamResolver);

		$this->assertTrue($service->canManageEmployee('admin1', 'employee1'));
	}

	public function testCanManageEmployeeDeniedWhenAppTeamsDisabledForNonAdmin(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('useAppTeams')->willReturn(false);
		$teamResolver->expects($this->never())->method('canUserManageEmployee');
		$service = $this->createService($groupManager, $teamResolver);

		$this->assertFalse($service->canManageEmployee('manager1', 'employee1'));
	}

	public function testCanManageEmployeeDelegatesToTeamResolver(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);

		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('useAppTeams')->willReturn(true);
		$teamResolver->expects($this->once())
			->method('canUserManageEmployee')
			->with('manager1', 'employee1')
			->willReturn(true);

		$service = $this->createService($groupManager, $teamResolver);

		$this->assertTrue($service->canManageEmployee('manager1', 'employee1'));
	}

	public function testCanAccessManagerDashboardAllowsAdmin(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin1')->willReturn(true);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->expects($this->never())->method('getTeamMemberIds');
		$service = $this->createService($groupManager, $teamResolver);

		$this->assertTrue($service->canAccessManagerDashboard('admin1'));
	}

	public function testCanAccessManagerDashboardRequiresAtLeastOneTeamMember(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('useAppTeams')->willReturn(true);
		$teamResolver->method('getTeamMemberIds')->willReturnCallback(static fn (string $uid): array => match ($uid) {
			'manager1' => ['employee1'],
			default => [],
		});
		$service = $this->createService($groupManager, $teamResolver);

		$this->assertTrue($service->canAccessManagerDashboard('manager1'));
		$this->assertFalse($service->canAccessManagerDashboard('userNoTeam'));
	}

	public function testCanAccessManagerDashboardDeniedWhenAppTeamsDisabledForNonAdmin(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('useAppTeams')->willReturn(false);
		$teamResolver->expects($this->never())->method('getTeamMemberIds');
		$service = $this->createService($groupManager, $teamResolver);

		$this->assertFalse($service->canAccessManagerDashboard('manager1'));
	}

	public function testCanViewUserReportSelfAllowedOtherwiseDelegates(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('useAppTeams')->willReturn(true);
		$teamResolver->method('canUserManageEmployee')->with('manager1', 'employee1')->willReturn(true);
		$service = $this->createService($groupManager, $teamResolver);

		$this->assertTrue($service->canViewUserReport('u1', 'u1'));
		$this->assertTrue($service->canViewUserReport('manager1', 'employee1'));
	}

	public function testCanResolveViolationAdminOrManager(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin1');

		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('useAppTeams')->willReturn(true);
		$teamResolver->method('canUserManageEmployee')->with('manager1', 'employee1')->willReturn(true);
		$service = $this->createService($groupManager, $teamResolver);

		$this->assertTrue($service->canResolveViolation('admin1', 'employee1'));
		$this->assertTrue($service->canResolveViolation('manager1', 'employee1'));
		$this->assertFalse($service->canResolveViolation('employee1', 'employee1'));
	}

	public function testOpenModeAllowsAnyLoggedInUserWithoutAppManager(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->never())->method('isEnabledForUser');
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = '') {
			return match ($key) {
				Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => '0',
				Constants::CONFIG_ACCESS_ALLOWED_USER_IDS => '[]',
				Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS => '[]',
				Constants::CONFIG_APP_ADMIN_USER_IDS => '[]',
				default => $default,
			};
		});
		$teamResolver = $this->createMock(TeamResolverService::class);
		$service = $this->createService($groupManager, $teamResolver, $appManager, $config);

		$this->assertTrue($service->isUserAllowedByAccessGroups('user1'));
		$this->assertFalse($service->isAccessRestrictionEnabled());
	}

	public function testRestrictedModeFailClosedAndAllowsListedUserOrGroup(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$groupManager->method('isInGroup')->willReturnCallback(static function (string $uid, string $gid): bool {
			return $uid === 'grouped' && $gid === 'hr';
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = '') {
			return match ($key) {
				Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => '1',
				Constants::CONFIG_ACCESS_ALLOWED_USER_IDS => '["alice"]',
				Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS => '["hr"]',
				Constants::CONFIG_APP_ADMIN_USER_IDS => '[]',
				default => $default,
			};
		});
		$teamResolver = $this->createMock(TeamResolverService::class);
		$service = $this->createService($groupManager, $teamResolver, null, $config);

		$this->assertTrue($service->isAccessRestrictionEnabled());
		$this->assertTrue($service->isUserAllowedByAccessGroups('alice'));
		$this->assertTrue($service->isUserAllowedByAccessGroups('grouped'));
		$this->assertFalse($service->isUserAllowedByAccessGroups('stranger'));
	}

	public function testRestrictedEmptyAllowlistsFailClosed(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = '') {
			return match ($key) {
				Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => '1',
				Constants::CONFIG_ACCESS_ALLOWED_USER_IDS => '[]',
				Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS => '[]',
				Constants::CONFIG_APP_ADMIN_USER_IDS => '[]',
				default => $default,
			};
		});
		$teamResolver = $this->createMock(TeamResolverService::class);
		$service = $this->createService($groupManager, $teamResolver, null, $config);

		$this->assertFalse($service->isUserAllowedByAccessGroups('nobody'));
	}

	public function testGetAllowedAccessGroupsReadsAppRestriction(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppRestriction')->with(Application::APP_ID)->willReturn(['group_a', 'group_a', 'group_b']);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default = '') {
			return match ($key) {
				Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS => '',
				default => $default === '' ? '[]' : $default,
			};
		});
		$teamResolver = $this->createMock(TeamResolverService::class);
		$service = $this->createService($groupManager, $teamResolver, $appManager, $config, $this->createMock(IUserManager::class));

		$this->assertSame(['group_a', 'group_b'], $service->getAllowedAccessGroups());
	}

	public function testIsUserAllowedByAccessGroupsAlwaysAllowsAdmin(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'admin1');
		$appManager = $this->createMock(IAppManager::class);
		$appManager->expects($this->never())->method('isEnabledForUser');
		$teamResolver = $this->createMock(TeamResolverService::class);
		$service = $this->createService($groupManager, $teamResolver, $appManager, null, $this->createMock(IUserManager::class));

		$this->assertTrue($service->isUserAllowedByAccessGroups('admin1'));
	}

	public function testIsAdminUsesSystemAdminOrDedicatedList(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturnCallback(static function (string $uid): bool {
			return $uid === 'nc_admin';
		});
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with(Application::APP_ID, Constants::CONFIG_APP_ADMIN_USER_IDS, '[]')
			->willReturn('["colleague","disabled_admin"]');
		$enabled = $this->createMock(IUser::class);
		$enabled->method('isEnabled')->willReturn(true);
		$disabled = $this->createMock(IUser::class);
		$disabled->method('isEnabled')->willReturn(false);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(static function (string $uid) use ($enabled, $disabled) {
			return match ($uid) {
				'colleague' => $enabled,
				'disabled_admin' => $disabled,
				default => null,
			};
		});
		$teamResolver = $this->createMock(TeamResolverService::class);
		$service = $this->createService($groupManager, $teamResolver, null, $config, $userManager);

		$this->assertTrue($service->isAdmin('nc_admin'));
		$this->assertTrue($service->isAdmin('colleague'));
		$this->assertFalse($service->isAdmin('disabled_admin'));
		$this->assertFalse($service->isAdmin('random'));
	}

	public function testNonListedSystemAdminRemainsAppAdminWhenListNonEmpty(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(true);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with(Application::APP_ID, Constants::CONFIG_APP_ADMIN_USER_IDS, '[]')
			->willReturn('["hr_admin"]');
		$teamResolver = $this->createMock(TeamResolverService::class);
		$service = $this->createService($groupManager, $teamResolver, null, $config);

		$this->assertTrue($service->isAdmin('other_admin'));
		$this->assertTrue($service->canManageEmployee('other_admin', 'employee1'));
		$this->assertTrue($service->canAccessManagerDashboard('other_admin'));
		$this->assertTrue($service->canResolveViolation('other_admin', 'employee1'));
	}

	public function testCanViewUserComplianceSelfOrManager(): void
	{
		$teamResolver = $this->createMock(TeamResolverService::class);
		$service = $this->createService($this->createMock(IGroupManager::class), $teamResolver);
		// self always allowed without team lookup
		$this->assertTrue($service->canViewUserCompliance('alice', 'alice'));
	}

	public function testLogPermissionDeniedWritesAuditWarning(): void
	{
		$teamResolver = $this->createMock(TeamResolverService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Permission denied',
			$this->callback(static fn (array $ctx): bool =>
				$ctx['actor'] === 'bob'
				&& $ctx['action'] === 'absence.approve'
				&& $ctx['resource_type'] === 'absence'
				&& $ctx['resource_id'] === '42'
			)
		);
		$service = new PermissionService(
			$this->createMock(IGroupManager::class),
			$this->createMock(IAppManager::class),
			$this->createMock(IConfig::class),
			$this->createMock(IUserManager::class),
			$teamResolver,
			$logger
		);
		$service->logPermissionDenied('bob', 'absence.approve', 'absence', '42');
	}
}