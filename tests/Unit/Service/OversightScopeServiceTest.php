<?php

declare(strict_types=1);

/**
 * Unit tests for OversightScopeService — the single authority for
 * manager/HR read scopes. Covers admin org scope, manager team scope,
 * selector validation, ghost-member rejection and uniform denials.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Team;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Service\OversightScopeService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class OversightScopeServiceTest extends TestCase
{
	/** @var array<string,bool> */
	private array $admins = [];
	/** @var array<string,bool> */
	private array $managers = [];
	/** @var list<string> */
	private array $doorUserIds = [];
	/** @var array<string,list<string>> */
	private array $teamMembers = [];
	/** @var array<string,bool> uid → enabled; absent = deleted */
	private array $accounts = [];
	/** @var array<int,Team> */
	private array $teams = [];
	/** @var array<string,list<int>> manager uid → managed team ids */
	private array $managedTeams = [];
	/** @var array<int,list<int>> team id → subtree ids (incl. self) */
	private array $teamSubtrees = [];
	/** @var array<int,list<string>> team id → member uids */
	private array $teamMemberRows = [];

	private PermissionService $permissionService;
	private TeamResolverService $teamResolver;
	private TeamMapper $teamMapper;
	private TeamMemberMapper $teamMemberMapper;
	private TeamManagerMapper $teamManagerMapper;
	private IUserManager $userManager;
	private OversightScopeService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->accounts = [];
		$this->teams = [];
		$this->managedTeams = [];
		$this->teamSubtrees = [];
		$this->teamMemberRows = [];
		$this->admins = [];
		$this->managers = [];
		$this->doorUserIds = [];
		$this->teamMembers = [];

		$this->permissionService = $this->createMock(PermissionService::class);
		$this->permissionService->method('isAdmin')
			->willReturnCallback(fn (string $uid) => $this->admins[$uid] ?? false);
		$this->permissionService->method('canAccessManagerDashboard')
			->willReturnCallback(fn (string $uid) => $this->managers[$uid] ?? false);
		$this->permissionService->method('listAppAccessUserIds')
			->willReturnCallback(fn () => $this->doorUserIds);

		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->teamResolver->method('getTeamMemberIds')
			->willReturnCallback(fn (string $uid) => $this->teamMembers[$uid] ?? []);

		$this->teamMapper = $this->createMock(TeamMapper::class);
		$this->teamMapper->method('find')
			->willReturnCallback(function (int $id) {
				if (!isset($this->teams[$id])) {
					throw new \OCP\AppFramework\Db\DoesNotExistException('no team');
				}
				return $this->teams[$id];
			});
		$this->teamMapper->method('getIdsWithDescendants')
			->willReturnCallback(fn (int $id) => $this->teamSubtrees[$id] ?? [$id]);

		$this->teamMemberMapper = $this->createMock(TeamMemberMapper::class);
		$this->teamMemberMapper->method('getMemberUserIdsByTeamIds')
			->willReturnCallback(function (array $ids) {
				$out = [];
				foreach ($ids as $id) {
					foreach ($this->teamMemberRows[$id] ?? [] as $uid) {
						$out[$uid] = true;
					}
				}
				return array_keys($out);
			});

		$this->teamManagerMapper = $this->createMock(TeamManagerMapper::class);
		$this->teamManagerMapper->method('getTeamIdsForManager')
			->willReturnCallback(fn (string $uid) => $this->managedTeams[$uid] ?? []);

		$this->userManager = $this->createMock(IUserManager::class);
		$this->userManager->method('get')
			->willReturnCallback(function (string $uid) {
				if (!array_key_exists($uid, $this->accounts)) {
					return null;
				}
				$user = $this->createMock(IUser::class);
				$user->method('isEnabled')->willReturn($this->accounts[$uid]);
				$user->method('getUID')->willReturn($uid);
				return $user;
			});

		$this->service = new OversightScopeService(
			$this->permissionService,
			$this->teamResolver,
			$this->teamMapper,
			$this->teamMemberMapper,
			$this->teamManagerMapper,
			$this->userManager,
		);
	}

	private function team(int $id): Team
	{
		$team = new Team();
		$team->setId($id);
		return $team;
	}

	// ---- managedUserIds ----------------------------------------------------

	public function testManagedUserIdsEmptyForEmptyActor(): void
	{
		$this->assertSame([], $this->service->managedUserIds(''));
	}

	public function testManagedUserIdsAdminGetsDoorFilteredOrg(): void
	{
		$this->admins = ['hr.admin' => true];
		$this->doorUserIds = ['e1', 'e2', 'e3'];
		$this->assertSame(['e1', 'e2', 'e3'], $this->service->managedUserIds('hr.admin'));
	}

	public function testManagedUserIdsRegularUserGetsNothing(): void
	{
		$this->assertSame([], $this->service->managedUserIds('employee1'));
	}

	public function testManagedUserIdsManagerGetsTeamMembers(): void
	{
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['e1', 'e2']];
		$this->assertSame(['e1', 'e2'], $this->service->managedUserIds('mgr'));
	}

	public function testManagedEnabledUserIdsDropsDeletedAndDisabled(): void
	{
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['live', 'off', 'ghost']];
		$this->accounts = ['live' => true, 'off' => false]; // ghost deleted
		$this->assertSame(['live'], $this->service->managedEnabledUserIds('mgr'));
	}

	// ---- canOverseeUser -----------------------------------------------------

	public function testCanOverseeUserRejectsEmptyIds(): void
	{
		$this->assertFalse($this->service->canOverseeUser('', 'u1'));
		$this->assertFalse($this->service->canOverseeUser('u1', ''));
	}

	public function testCanOverseeUserRejectsGhostUser(): void
	{
		// Ghost member rows: uid is "managed" but account is deleted.
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['ghost']];
		$this->assertFalse($this->service->canOverseeUser('mgr', 'ghost'));
	}

	public function testCanOverseeUserSelfAlwaysAllowed(): void
	{
		$this->accounts = ['me' => true];
		$this->assertTrue($this->service->canOverseeUser('me', 'me'));
	}

	public function testCanOverseeUserAdminAllowed(): void
	{
		$this->admins = ['hr' => true];
		$this->accounts = ['emp' => true];
		$this->assertTrue($this->service->canOverseeUser('hr', 'emp'));
	}

	public function testCanOverseeUserManagerNeedsMembership(): void
	{
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['in']];
		$this->accounts = ['in' => true, 'out' => true];
		$this->assertTrue($this->service->canOverseeUser('mgr', 'in'));
		$this->assertFalse($this->service->canOverseeUser('mgr', 'out'));
	}

	public function testCanOverseeUserDisabledStillSelectable(): void
	{
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['offboarded']];
		$this->accounts = ['offboarded' => false];
		$this->assertTrue($this->service->canOverseeUser('mgr', 'offboarded'));
	}

	// ---- resolveRequestedScope: selector plumbing ---------------------------

	public function testResolveNoSelectorReturnsNullList(): void
	{
		$r = $this->service->resolveRequestedScope('actor');
		$this->assertTrue($r['ok']);
		$this->assertNull($r['userIds']);
	}

	public function testResolveWhitespaceSelectorsIgnored(): void
	{
		$r = $this->service->resolveRequestedScope('actor', '  ', '', ' ', ' ');
		$this->assertTrue($r['ok']);
		$this->assertNull($r['userIds']);
	}

	public function testResolveAmbiguousSelectorsRejected(): void
	{
		$r = $this->service->resolveRequestedScope('actor', 'u1', 'u2', null, null);
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_AMBIGUOUS, $r['code']);

		$r = $this->service->resolveRequestedScope('actor', null, null, '3', 'self');
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_AMBIGUOUS, $r['code']);
	}

	public function testResolveEmptyActorDenied(): void
	{
		$r = $this->service->resolveRequestedScope('', 'u1');
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_DENIED, $r['code']);
	}

	// ---- scope= selector ----------------------------------------------------

	public function testResolveScopeSelf(): void
	{
		$r = $this->service->resolveRequestedScope('me', null, null, null, 'self');
		$this->assertTrue($r['ok']);
		$this->assertSame(['me'], $r['userIds']);
		$this->assertSame('self', $r['scope']);
	}

	public function testResolveScopeManagedForManager(): void
	{
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['a', 'b']];
		$r = $this->service->resolveRequestedScope('mgr', null, null, null, 'managed');
		$this->assertTrue($r['ok']);
		$this->assertSame(['a', 'b'], $r['userIds']);
	}

	public function testResolveScopeManagedForAdminIsOrgWide(): void
	{
		$this->admins = ['hr' => true];
		$this->doorUserIds = ['all1', 'all2'];
		$r = $this->service->resolveRequestedScope('hr', null, null, null, 'managed');
		$this->assertTrue($r['ok']);
		$this->assertSame(['all1', 'all2'], $r['userIds']);
	}

	public function testResolveScopeManagedDeniedForPlainUser(): void
	{
		$r = $this->service->resolveRequestedScope('pleb', null, null, null, 'managed');
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_DENIED, $r['code']);
	}

	public function testResolveScopeOrganizationAdminOnly(): void
	{
		$this->admins = ['hr' => true];
		$this->doorUserIds = ['org1'];
		$r = $this->service->resolveRequestedScope('hr', null, null, null, 'organization');
		$this->assertTrue($r['ok']);
		$this->assertSame(['org1'], $r['userIds']);

		// Manager (non-admin) must not reach org scope.
		$this->admins = [];
		$this->managers = ['mgr' => true];
		$r = $this->service->resolveRequestedScope('mgr', null, null, null, 'organization');
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_DENIED, $r['code']);
	}

	public function testResolveScopeUnknownValueRejected(): void
	{
		$this->admins = ['hr' => true];
		$r = $this->service->resolveRequestedScope('hr', null, null, null, 'everything');
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_INVALID_SCOPE, $r['code']);
	}

	// ---- userId selector ----------------------------------------------------

	public function testResolveUserIdWithinScope(): void
	{
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['emp1']];
		$this->accounts = ['emp1' => true];
		$r = $this->service->resolveRequestedScope('mgr', 'emp1');
		$this->assertTrue($r['ok']);
		$this->assertSame(['emp1'], $r['userIds']);
	}

	public function testResolveUserIdUniformDenialForForeignAndGhost(): void
	{
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['emp1']];
		$this->accounts = ['emp1' => true, 'foreign' => true];
		// ghost = managed but deleted
		$this->teamMembers['mgr'][] = 'ghost';

		$foreign = $this->service->resolveRequestedScope('mgr', 'foreign');
		$ghost = $this->service->resolveRequestedScope('mgr', 'ghost');
		$this->assertFalse($foreign['ok']);
		$this->assertFalse($ghost['ok']);
		// Uniform denial: foreign existing user and deleted ghost collapse
		// to the same code — no existence oracle.
		$this->assertSame($foreign['code'], $ghost['code']);
		$this->assertSame(OversightScopeService::ERR_DENIED, $foreign['code']);
	}

	// ---- userIds selector ---------------------------------------------------

	public function testResolveUserIdsDedupesAndValidatesEach(): void
	{
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['e1', 'e2']];
		$this->accounts = ['e1' => true, 'e2' => true];
		$r = $this->service->resolveRequestedScope('mgr', null, 'e1, e2 ,e1,,');
		$this->assertTrue($r['ok']);
		$this->assertSame(['e1', 'e2'], $r['userIds']);
	}

	public function testResolveUserIdsEmptyListRejected(): void
	{
		$r = $this->service->resolveRequestedScope('mgr', null, ' , ,');
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_INVALID_USER_IDS, $r['code']);
	}

	public function testResolveUserIdsOverCapRejected(): void
	{
		$this->admins = ['hr' => true];
		foreach (range(1, 201) as $i) {
			$this->accounts["u$i"] = true;
		}
		$ids = implode(',', array_map(fn ($i) => "u$i", range(1, 201)));
		$r = $this->service->resolveRequestedScope('hr', null, $ids);
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_TOO_MANY_USERS, $r['code']);
	}

	public function testResolveUserIdsOneGhostRejectsWholeList(): void
	{
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['e1', 'e2']];
		$this->accounts = ['e1' => true]; // e2 deleted — atomic rejection
		$r = $this->service->resolveRequestedScope('mgr', null, 'e1,e2');
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_DENIED, $r['code']);
	}

	public function testResolveUserIdsManagerListWithForeignMemberDenied(): void
	{
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['e1']];
		$this->accounts = ['e1' => true, 'foreign' => true];
		$r = $this->service->resolveRequestedScope('mgr', null, 'e1,foreign');
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_DENIED, $r['code']);
	}

	public function testResolveUserIdsAdminBypassesMembership(): void
	{
		$this->admins = ['hr' => true];
		$this->accounts = ['anyone' => true];
		$r = $this->service->resolveRequestedScope('hr', null, 'anyone');
		$this->assertTrue($r['ok']);
		$this->assertSame(['anyone'], $r['userIds']);
	}

	public function testResolveUserIdsDisabledAllowedExplicitly(): void
	{
		$this->managers = ['mgr' => true];
		$this->teamMembers = ['mgr' => ['offboarded']];
		$this->accounts = ['offboarded' => false];
		$r = $this->service->resolveRequestedScope('mgr', null, 'offboarded');
		$this->assertTrue($r['ok']);
		$this->assertSame(['offboarded'], $r['userIds']);
	}

	// ---- teamId selector ----------------------------------------------------

	public function testResolveTeamIdRejectsNonNumeric(): void
	{
		foreach (['2.9', '2e3', 'abc', '-1', '1_0', '0x10'] as $bad) {
			$r = $this->service->resolveRequestedScope('mgr', null, null, $bad);
			$this->assertFalse($r['ok'], "teamId '$bad' must be rejected");
			$this->assertSame(OversightScopeService::ERR_INVALID_TEAM_ID, $r['code']);
		}
	}

	public function testResolveTeamIdAdminAnyExistingTeam(): void
	{
		$this->admins = ['hr' => true];
		$this->teams = [7 => $this->team(7)];
		$this->teamSubtrees = [7 => [7, 8]];
		$this->teamMemberRows = [7 => ['m1'], 8 => ['m2', 'deleted']];
		$this->accounts = ['m1' => true, 'm2' => false]; // deleted dropped; disabled kept

		$r = $this->service->resolveRequestedScope('hr', null, null, '7');
		$this->assertTrue($r['ok']);
		$this->assertSame(['m1', 'm2'], $r['userIds']);
		$this->assertSame('team', $r['scope']);
	}

	public function testResolveTeamIdAdminNonexistentDenied(): void
	{
		$this->admins = ['hr' => true];
		$r = $this->service->resolveRequestedScope('hr', null, null, '999');
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_DENIED, $r['code']);
	}

	public function testResolveTeamIdManagerInManagedSubtree(): void
	{
		$this->managers = ['mgr' => true];
		$this->managedTeams = ['mgr' => [5]];
		$this->teamSubtrees = [5 => [5, 6], 6 => [6]];
		$this->teamMemberRows = [6 => ['k1', 'outside']];
		$this->accounts = ['k1' => true, 'outside' => true];
		// Managed flat set for defense-in-depth intersection.
		$this->teamMembers = ['mgr' => ['k1']];

		$r = $this->service->resolveRequestedScope('mgr', null, null, '6');
		$this->assertTrue($r['ok']);
		$this->assertSame(['k1'], $r['userIds']);
	}

	public function testResolveTeamIdManagerForeignAndNonexistentUniform(): void
	{
		$this->managers = ['mgr' => true];
		$this->managedTeams = ['mgr' => [5]];
		$this->teamSubtrees = [5 => [5]];
		$this->teams = [9 => $this->team(9)]; // exists, foreign

		$foreign = $this->service->resolveRequestedScope('mgr', null, null, '9');
		$missing = $this->service->resolveRequestedScope('mgr', null, null, '999');
		$this->assertFalse($foreign['ok']);
		$this->assertFalse($missing['ok']);
		$this->assertSame($foreign['code'], $missing['code']);
		$this->assertSame(OversightScopeService::ERR_DENIED, $foreign['code']);
	}

	public function testResolveTeamIdDeniedForNonManager(): void
	{
		$r = $this->service->resolveRequestedScope('pleb', null, null, '5');
		$this->assertFalse($r['ok']);
		$this->assertSame(OversightScopeService::ERR_DENIED, $r['code']);
	}
}
