<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PermissionServiceTest extends TestCase
{
	public function testCanManageEmployeeRejectsSelf(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new PermissionService($groupManager, $teamResolver, $logger);

		$this->assertFalse($service->canManageEmployee('u1', 'u1'));
	}

	public function testCanManageEmployeeAllowsAdmin(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin1')->willReturn(true);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->expects($this->never())->method('canUserManageEmployee');
		$logger = $this->createMock(LoggerInterface::class);

		$service = new PermissionService($groupManager, $teamResolver, $logger);

		$this->assertTrue($service->canManageEmployee('admin1', 'employee1'));
	}

	public function testCanManageEmployeeDeniedWhenAppTeamsDisabledForNonAdmin(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('useAppTeams')->willReturn(false);
		$teamResolver->expects($this->never())->method('canUserManageEmployee');
		$logger = $this->createMock(LoggerInterface::class);

		$service = new PermissionService($groupManager, $teamResolver, $logger);

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

		$logger = $this->createMock(LoggerInterface::class);

		$service = new PermissionService($groupManager, $teamResolver, $logger);

		$this->assertTrue($service->canManageEmployee('manager1', 'employee1'));
	}

	public function testCanAccessManagerDashboardAllowsAdmin(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->with('admin1')->willReturn(true);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->expects($this->never())->method('getTeamMemberIds');
		$logger = $this->createMock(LoggerInterface::class);

		$service = new PermissionService($groupManager, $teamResolver, $logger);

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
		$logger = $this->createMock(LoggerInterface::class);

		$service = new PermissionService($groupManager, $teamResolver, $logger);

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
		$logger = $this->createMock(LoggerInterface::class);

		$service = new PermissionService($groupManager, $teamResolver, $logger);

		$this->assertFalse($service->canAccessManagerDashboard('manager1'));
	}

	public function testCanViewUserReportSelfAllowedOtherwiseDelegates(): void
	{
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('isAdmin')->willReturn(false);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('useAppTeams')->willReturn(true);
		$teamResolver->method('canUserManageEmployee')->with('manager1', 'employee1')->willReturn(true);
		$logger = $this->createMock(LoggerInterface::class);

		$service = new PermissionService($groupManager, $teamResolver, $logger);

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

		$logger = $this->createMock(LoggerInterface::class);

		$service = new PermissionService($groupManager, $teamResolver, $logger);

		$this->assertTrue($service->canResolveViolation('admin1', 'employee1'));
		$this->assertTrue($service->canResolveViolation('manager1', 'employee1'));
		$this->assertFalse($service->canResolveViolation('employee1', 'employee1'));
	}
}

