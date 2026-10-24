<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamMember;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class TeamResolverServiceTest extends TestCase
{
	/** @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject */
	private $groupManager;

	/** @var IUserManager&\PHPUnit\Framework\MockObject\MockObject */
	private $userManager;

	/** @var IConfig&\PHPUnit\Framework\MockObject\MockObject */
	private $config;

	/** @var TeamMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $teamMapper;

	/** @var TeamMemberMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $teamMemberMapper;

	/** @var TeamManagerMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $teamManagerMapper;

	private TeamResolverService $service;

	protected function setUp(): void
	{
		parent::setUp();

		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->teamMapper = $this->createMock(TeamMapper::class);
		$this->teamMemberMapper = $this->createMock(TeamMemberMapper::class);
		$this->teamManagerMapper = $this->createMock(TeamManagerMapper::class);

		$this->service = new TeamResolverService(
			$this->groupManager,
			$this->userManager,
			$this->config,
			$this->teamMapper,
			$this->teamMemberMapper,
			$this->teamManagerMapper
		);
	}

	public function testUseAppTeamsTrueWhenConfigEnabled(): void
	{
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('arbeitszeitcheck', 'use_app_teams', '0')
			->willReturn('1');

		$this->assertTrue($this->service->useAppTeams());
	}

	public function testGetTeamMemberIdsFromGroupsExcludesSelfAndDeduplicates(): void
	{
		$managerId = 'manager1';
		$this->config->method('getAppValue')->willReturn('0');

		$manager = $this->createMock(IUser::class);
		$manager->method('getUID')->willReturn($managerId);
		$this->userManager->method('get')->with($managerId)->willReturn($manager);

		$groupA = $this->createMock(IGroup::class);
		$groupB = $this->createMock(IGroup::class);
		$this->groupManager->method('getUserGroups')->with($manager)->willReturn([$groupA, $groupB]);

		$userSelf = $this->createMock(IUser::class);
		$userSelf->method('getUID')->willReturn($managerId);
		$user1 = $this->createMock(IUser::class);
		$user1->method('getUID')->willReturn('employee1');
		$user2 = $this->createMock(IUser::class);
		$user2->method('getUID')->willReturn('employee2');

		$groupA->method('getUsers')->willReturn([$userSelf, $user1]);
		$groupB->method('getUsers')->willReturn([$user1, $user2]); // employee1 in both groups

		$ids = $this->service->getTeamMemberIds($managerId);
		$this->assertSame(['employee1', 'employee2'], $ids);
	}

	public function testGetTeamMemberIdsFromAppTeamsIncludesDescendantsAndDeduplicates(): void
	{
		$managerId = 'manager1';
		$this->config->method('getAppValue')->willReturn('1');

		$this->teamManagerMapper->expects($this->once())
			->method('getTeamIdsForManager')
			->with($managerId)
			->willReturn([10, 11]);

		$this->teamMapper->method('getIdsWithDescendants')->willReturnCallback(static function (int $tid): array {
			return match ($tid) {
				10 => [10, 12],
				11 => [11, 12, 13],
				default => [$tid],
			};
		});

		$this->teamMemberMapper->expects($this->once())
			->method('getMemberUserIdsByTeamIds')
			->with($this->callback(static function (array $ids): bool {
				sort($ids);
				return $ids === [10, 11, 12, 13];
			}))
			->willReturn([$managerId, 'employee1', 'employee2', 'employee1']);

		$ids = $this->service->getTeamMemberIds($managerId);
		$this->assertSame(['employee1', 'employee2'], $ids);
	}

	public function testGetTeamMemberIdsFromAppTeamsReturnsEmptyOnMissingTables(): void
	{
		$managerId = 'manager1';
		$this->config->method('getAppValue')->willReturn('1');

		$this->teamManagerMapper->method('getTeamIdsForManager')
			->willThrowException(new \Exception("Table 'oc_at_team_managers' doesn't exist"));

		$this->assertSame([], $this->service->getTeamMemberIds($managerId));
	}

	public function testGetColleagueIdsFromAppTeamsIncludesMemberTeamsAndManagedDescendants(): void
	{
		$userId = 'employee1';
		$this->config->method('getAppValue')->willReturn('1');

		$membershipA = $this->getMockBuilder(TeamMember::class)
			->addMethods(['getTeamId'])
			->getMock();
		$membershipA->method('getTeamId')->willReturn(50);

		$this->teamMemberMapper->method('findByUserId')->with($userId)->willReturn([$membershipA]);
		$this->teamManagerMapper->method('getTeamIdsForManager')->with($userId)->willReturn([60]);
		$this->teamMapper->method('getIdsWithDescendants')->with(60)->willReturn([60, 61]);

		$this->teamMemberMapper->expects($this->once())
			->method('getMemberUserIdsByTeamIds')
			->with($this->callback(static function (array $ids): bool {
				sort($ids);
				return $ids === [50, 60, 61];
			}))
			->willReturn([$userId, 'colleague1', 'colleague2', 'colleague1']);

		$this->assertSame(['colleague1', 'colleague2'], $this->service->getColleagueIds($userId));
	}

	public function testGetManagerIdsForEmployeeReturnsEmptyWhenNotUsingAppTeams(): void
	{
		$this->config->method('getAppValue')->willReturn('0');
		$this->assertSame([], $this->service->getManagerIdsForEmployee('employee1'));
	}

	public function testHasAssignableManagerForEmployeeAppTeamsFalseWhenColleaguesButNoManagers(): void
	{
		$userId = 'employee1';
		$this->config->method('getAppValue')->willReturn('1');

		$membership = $this->getMockBuilder(TeamMember::class)
			->addMethods(['getTeamId'])
			->getMock();
		$membership->method('getTeamId')->willReturn(50);

		$this->teamMemberMapper->method('findByUserId')->with($userId)->willReturn([$membership]);
		$this->teamManagerMapper->method('findByTeamId')->with(50)->willReturn([]);

		$this->assertFalse($this->service->hasAssignableManagerForEmployee($userId));
	}

	public function testHasAssignableManagerForEmployeeAppTeamsTrueWhenOtherManagerAssigned(): void
	{
		$userId = 'employee1';
		$this->config->method('getAppValue')->willReturn('1');

		$membership = $this->getMockBuilder(TeamMember::class)
			->addMethods(['getTeamId'])
			->getMock();
		$membership->method('getTeamId')->willReturn(50);

		$mgr = new \OCA\ArbeitszeitCheck\Db\TeamManager();
		$mgr->setTeamId(50);
		$mgr->setUserId('boss1');

		$this->teamMemberMapper->method('findByUserId')->with($userId)->willReturn([$membership]);
		$this->teamManagerMapper->method('findByTeamId')->with(50)->willReturn([$mgr]);

		$this->assertTrue($this->service->hasAssignableManagerForEmployee($userId));
	}

	/**
	 * Inject a mocked PermissionService into the lazy-resolution seam
	 * (constructor injection would be circular — see permissionService()).
	 */
	private function injectPermissionService($permissionService): void
	{
		$ref = new \ReflectionProperty($this->service, 'permissionServiceInstance');
		$ref->setAccessible(true);
		$ref->setValue($this->service, $permissionService);
	}

	public function testHasAssignableManagerForEmployeeLegacyIgnoresColleagues(): void
	{
		$this->config->method('getAppValue')->willReturn('0');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('employee1');
		$this->userManager->method('get')->with('employee1')->willReturn($user);

		$group = $this->createMock(IGroup::class);
		$this->groupManager->method('getUserGroups')->with($user)->willReturn([$group]);

		$colleague = $this->createMock(IUser::class);
		$colleague->method('getUID')->willReturn('other');
		$group->method('getUsers')->willReturn([$user, $colleague]);
		// No 'admin' group exists at all.
		$this->groupManager->method('get')->with('admin')->willReturn(null);

		$permission = $this->createMock(\OCA\ArbeitszeitCheck\Service\PermissionService::class);
		$permission->method('getConfiguredAppAdminUserIds')->willReturn([]);
		$this->injectPermissionService($permission);

		// Group-mode colleagues can never approve — canManageEmployee() denies
		// them; counting them was the pending-item deadlock (B1-MH-02).
		$this->assertFalse($this->service->hasAssignableManagerForEmployee('employee1'));
	}

	public function testHasAssignableManagerForEmployeeLegacyTrueWhenAdminExists(): void
	{
		$this->config->method('getAppValue')->willReturn('0');

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('boss');
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturn([$admin]);
		$this->groupManager->method('get')->with('admin')->willReturn($adminGroup);

		$permission = $this->createMock(\OCA\ArbeitszeitCheck\Service\PermissionService::class);
		$permission->method('getConfiguredAppAdminUserIds')->willReturn([]);
		$permission->method('isAdmin')->willReturnCallback(static fn (string $uid): bool => $uid === 'boss');
		$this->injectPermissionService($permission);

		$this->assertTrue($this->service->hasAssignableManagerForEmployee('employee1'));
	}

	public function testHasAssignableManagerForEmployeeLegacyFalseWhenOnlyGhostAdmin(): void
	{
		$this->config->method('getAppValue')->willReturn('0');

		// Stale 'admin' group row / configured app-admin uid — isAdmin() must
		// reject ghosts (existence + enabled leg), not just membership.
		$ghost = $this->createMock(IUser::class);
		$ghost->method('getUID')->willReturn('deleted-admin');
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturn([$ghost]);
		$this->groupManager->method('get')->with('admin')->willReturn($adminGroup);

		$permission = $this->createMock(\OCA\ArbeitszeitCheck\Service\PermissionService::class);
		$permission->method('getConfiguredAppAdminUserIds')->willReturn(['also-gone']);
		$permission->method('isAdmin')->willReturn(false);
		$this->injectPermissionService($permission);

		$this->assertFalse($this->service->hasAssignableManagerForEmployee('employee1'));
	}

	public function testHasAssignableManagerForEmployeeLegacyExcludesSelfAsApprover(): void
	{
		$this->config->method('getAppValue')->willReturn('0');

		$self = $this->createMock(IUser::class);
		$self->method('getUID')->willReturn('lone-admin');
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturn([$self]);
		$this->groupManager->method('get')->with('admin')->willReturn($adminGroup);

		$permission = $this->createMock(\OCA\ArbeitszeitCheck\Service\PermissionService::class);
		$permission->method('getConfiguredAppAdminUserIds')->willReturn([]);
		$permission->method('isAdmin')->willReturn(true);
		$this->injectPermissionService($permission);

		// The only admin IS the employee — nobody else can approve.
		$this->assertFalse($this->service->hasAssignableManagerForEmployee('lone-admin'));
	}

	public function testListGroupModeAdminApproversUnionsAndSorts(): void
	{
		$this->config->method('getAppValue')->willReturn('0');

		$groupAdmin = $this->createMock(IUser::class);
		$groupAdmin->method('getUID')->willReturn('z-admin');
		$employee = $this->createMock(IUser::class);
		$employee->method('getUID')->willReturn('employee1');
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturn([$groupAdmin, $employee]);
		$this->groupManager->method('get')->with('admin')->willReturn($adminGroup);

		$permission = $this->createMock(\OCA\ArbeitszeitCheck\Service\PermissionService::class);
		$permission->method('getConfiguredAppAdminUserIds')->willReturn(['a-admin', 'z-admin']);
		$permission->method('isAdmin')->willReturnCallback(
			static fn (string $uid): bool => in_array($uid, ['a-admin', 'z-admin', 'employee1'], true)
		);
		$this->injectPermissionService($permission);

		$this->assertSame(
			['a-admin', 'z-admin'],
			$this->service->listGroupModeAdminApprovers('employee1')
		);
	}

	public function testApproverPredicateSnapshotLabelsModes(): void
	{
		$this->config->method('getAppValue')->willReturn('0');
		$this->groupManager->method('get')->with('admin')->willReturn(null);

		$permission = $this->createMock(\OCA\ArbeitszeitCheck\Service\PermissionService::class);
		$permission->method('getConfiguredAppAdminUserIds')->willReturn([]);
		$this->injectPermissionService($permission);

		$snap = $this->service->approverPredicateSnapshot('employee1');
		$this->assertSame('groups', $snap['mode']);
		$this->assertSame([], $snap['approvers']);
	}

	public function testHasAssignableManagerForEmployeeLegacyFalseWhenSoloInGroups(): void
	{
		$this->config->method('getAppValue')->willReturn('0');

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('solo');
		$this->userManager->method('get')->with('solo')->willReturn($user);

		$group = $this->createMock(IGroup::class);
		$this->groupManager->method('getUserGroups')->with($user)->willReturn([$group]);
		$group->method('getUsers')->willReturn([$user]);
		$this->groupManager->method('get')->with('admin')->willReturn(null);

		$permission = $this->createMock(\OCA\ArbeitszeitCheck\Service\PermissionService::class);
		$permission->method('getConfiguredAppAdminUserIds')->willReturn([]);
		$this->injectPermissionService($permission);

		$this->assertFalse($this->service->hasAssignableManagerForEmployee('solo'));
	}

	public function testCanUserManageEmployee(): void
	{
		// self-management is never allowed
		$this->assertFalse($this->service->canUserManageEmployee('manager1', 'manager1'));

		$this->config->method('getAppValue')->willReturn('1');
		$this->teamManagerMapper->method('getTeamIdsForManager')->with('manager1')->willReturn([10]);
		$this->teamMapper->method('getIdsWithDescendants')->willReturnCallback(
			static fn (int $tid): array => [$tid]
		);
		$this->teamMemberMapper->method('getMemberUserIdsByTeamIds')->willReturn(['employee1']);

		$this->assertTrue($this->service->canUserManageEmployee('manager1', 'employee1'));
		$this->assertFalse($this->service->canUserManageEmployee('manager1', 'outsider'));
	}
}