<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the HR/manager oversight scope.
 *
 * Each kill() asserts a security-critical contract of
 * OversightScopeService. Any plausible regression — dropping the
 * ghost-uid existence leg, letting non-admins widen to organization
 * scope, accepting ambiguous selectors, leaking team existence — must
 * make at least one check fail.
 *
 * Run inside the container:
 *   php tests/Mutation/run-oversight-scope-mutations.php
 */

$root = dirname(__DIR__, 2);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once $root . '/vendor/autoload.php';

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

$failures = 0;

function kill(string $label, callable $assert): void
{
	global $failures;
	try {
		$assert();
		fwrite(STDOUT, "KILL  {$label}\n");
	} catch (Throwable $e) {
		$failures++;
		fwrite(STDOUT, "SURVIVE {$label}: {$e->getMessage()}\n");
	}
}

$case = new class extends TestCase {
	public function mock(string $class)
	{
		return $this->createMock($class);
	}
};

/**
 * @param array<string,bool> $deleted uids whose accounts no longer exist
 * @param array<string,bool> $disabled uids whose accounts are disabled
 */
function makeScope(
	array $admins = [],
	array $managers = [],
	array $members = [],
	array $orgUsers = [],
	array $deleted = [],
	array $disabled = [],
	array $managedTeams = [],
	array $teamDescendants = [],
	array $teamMembers = [],
): OversightScopeService {
	global $case;

	$permission = $case->mock(PermissionService::class);
	$permission->method('isAdmin')->willReturnCallback(
		static fn (string $uid): bool => isset($admins[$uid])
	);
	$permission->method('canAccessManagerDashboard')->willReturnCallback(
		static fn (string $uid): bool => isset($managers[$uid])
	);
	$permission->method('listAppAccessUserIds')->willReturn($orgUsers);

	$teamResolver = $case->mock(TeamResolverService::class);
	$teamResolver->method('getTeamMemberIds')->willReturnCallback(
		static fn (string $uid): array => $members[$uid] ?? []
	);

	$userManager = $case->mock(IUserManager::class);
	$userManager->method('get')->willReturnCallback(
		function (string $uid) use ($deleted, $disabled, $case): ?IUser {
			if ($uid === '' || isset($deleted[$uid])) {
				return null;
			}
			$user = $case->mock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('isEnabled')->willReturn(!isset($disabled[$uid]));
			return $user;
		}
	);

	$teamMapper = $case->mock(TeamMapper::class);
	$teamMapper->method('find')->willReturnCallback(static function (int $id) use ($teamDescendants): Team {
		if (!isset($teamDescendants[$id])) {
			throw new \OCP\AppFramework\Db\DoesNotExistException('no such team');
		}
		$team = new Team();
		$team->setId($id);
		return $team;
	});
	$teamMapper->method('getIdsWithDescendants')->willReturnCallback(
		static fn (int $id): array => $teamDescendants[$id] ?? [$id]
	);

	$teamMemberMapper = $case->mock(TeamMemberMapper::class);
	$teamMemberMapper->method('getMemberUserIdsByTeamIds')->willReturnCallback(
		static fn (array $ids): array => array_values(array_unique(array_merge(
			...array_map(static fn (int $id): array => $teamMembers[$id] ?? [], $ids)
		)) ?: [[]])
	);

	$teamManagerMapper = $case->mock(TeamManagerMapper::class);
	$teamManagerMapper->method('getTeamIdsForManager')->willReturnCallback(
		static fn (string $uid): array => $managedTeams[$uid] ?? []
	);

	return new OversightScopeService(
		$permission,
		$teamResolver,
		$teamMapper,
		$teamMemberMapper,
		$teamManagerMapper,
		$userManager,
	);
}

// ── Ghost-member hole: stale member row of a deleted account ─────────

kill('ghost-uid-cannot-be-overseen', static function (): void {
	$scope = makeScope(
		managers: ['mgr' => true],
		members: ['mgr' => ['employee1', 'ghost']],
		deleted: ['ghost' => true],
	);
	if ($scope->canOverseeUser('mgr', 'ghost')) {
		throw new RuntimeException('Deleted user with stale member row must not be overseeable');
	}
});

kill('ghost-uid-rejected-from-userids-selector', static function (): void {
	$scope = makeScope(
		managers: ['mgr' => true],
		members: ['mgr' => ['employee1', 'ghost']],
		deleted: ['ghost' => true],
	);
	$result = $scope->resolveRequestedScope('mgr', null, 'employee1,ghost');
	if ($result['ok'] !== false) {
		throw new RuntimeException('userIds containing a ghost uid must fail closed');
	}
});

// ── Scope widening ───────────────────────────────────────────────────

kill('non-manager-gets-empty-scope', static function (): void {
	$scope = makeScope();
	if ($scope->managedUserIds('nobody') !== []) {
		throw new RuntimeException('Plain user must receive an empty managed scope');
	}
});

kill('non-manager-cannot-oversee-anyone', static function (): void {
	$scope = makeScope(members: ['mgr' => ['employee1']]);
	if ($scope->canOverseeUser('nobody', 'employee1')) {
		throw new RuntimeException('Non-manager must not oversee foreign users');
	}
});

kill('manager-cannot-oversee-foreign-user', static function (): void {
	$scope = makeScope(
		managers: ['mgr' => true],
		members: ['mgr' => ['employee1']],
	);
	if ($scope->canOverseeUser('mgr', 'foreign')) {
		throw new RuntimeException('Manager must not oversee users outside the managed scope');
	}
});

kill('self-is-always-overseeable', static function (): void {
	$scope = makeScope();
	if (!$scope->canOverseeUser('alice', 'alice')) {
		throw new RuntimeException('Actor must always oversee themself');
	}
});

kill('organization-scope-requires-admin', static function (): void {
	$scope = makeScope(
		managers: ['mgr' => true],
		members: ['mgr' => ['employee1']],
		orgUsers: ['u1', 'u2'],
	);
	$result = $scope->resolveRequestedScope('mgr', null, null, null, 'organization');
	if ($result['ok'] !== false) {
		throw new RuntimeException('scope=organization must be admin-only');
	}
});

kill('admin-organization-scope-returns-door-filtered-users', static function (): void {
	$scope = makeScope(admins: ['root' => true], orgUsers: ['u1', 'u2']);
	$result = $scope->resolveRequestedScope('root', null, null, null, 'organization');
	if ($result['ok'] !== true || $result['userIds'] !== ['u1', 'u2']) {
		throw new RuntimeException('Admin org scope must equal listAppAccessUserIds');
	}
});

kill('admin-managed-userids-uses-org-list-not-teams', static function (): void {
	$scope = makeScope(admins: ['root' => true], orgUsers: ['u1']);
	if ($scope->managedUserIds('root') !== ['u1']) {
		throw new RuntimeException('Admin scope must be the org list, never the (empty) managed team');
	}
});

// ── Selector validation ──────────────────────────────────────────────

kill('ambiguous-selectors-rejected', static function (): void {
	$scope = makeScope(managers: ['mgr' => true], members: ['mgr' => ['employee1']]);
	foreach ([
		['employee1', 'employee1', null, null],
		[null, 'employee1', '1', null],
		[null, null, '1', 'managed'],
		['employee1', null, null, 'managed'],
	] as [$userId, $userIds, $teamId, $scopeName]) {
		$result = $scope->resolveRequestedScope('mgr', $userId, $userIds, $teamId, $scopeName);
		if ($result['ok'] !== false || $result['code'] !== OversightScopeService::ERR_AMBIGUOUS) {
			throw new RuntimeException('Ambiguous selector combination must be rejected');
		}
	}
});

kill('unknown-scope-value-rejected', static function (): void {
	$scope = makeScope(managers: ['mgr' => true]);
	$result = $scope->resolveRequestedScope('mgr', null, null, null, 'bogus');
	if ($result['ok'] !== false || $result['code'] !== OversightScopeService::ERR_INVALID_SCOPE) {
		throw new RuntimeException('Unknown scope value must be rejected');
	}
});

kill('no-selector-resolves-to-null-userids', static function (): void {
	$scope = makeScope();
	$result = $scope->resolveRequestedScope('mgr');
	if ($result['ok'] !== true || $result['userIds'] !== null) {
		throw new RuntimeException('Absent selectors must signal caller default via userIds=null');
	}
});

kill('non-numeric-team-id-rejected', static function (): void {
	$scope = makeScope(managers: ['mgr' => true]);
	// Non-digit content must reject even when it parses as numeric-ish.
	foreach (['abc', '1.5', '1e3', '0x10', '5a', 'a5', '+5', '-5', '5.0'] as $bad) {
		$result = $scope->resolveRequestedScope('mgr', null, null, $bad);
		if ($result['ok'] !== false || $result['code'] !== OversightScopeService::ERR_INVALID_TEAM_ID) {
			throw new RuntimeException("teamId '{$bad}' must be rejected as non-numeric");
		}
	}
	// Surrounding whitespace is benign form noise — ' 7 ' must pass the
	// digit check and reach team resolution (here: denied as unmanaged,
	// NOT rejected as invalid).
	$trimmed = $scope->resolveRequestedScope('mgr', null, null, ' 7 ');
	if (($trimmed['code'] ?? '') === OversightScopeService::ERR_INVALID_TEAM_ID) {
		throw new RuntimeException("Whitespace-wrapped teamId must trim before validation");
	}
});

kill('userids-over-cap-rejected', static function (): void {
	$scope = makeScope(admins: ['root' => true]);
	$raw = implode(',', array_map(static fn (int $i): string => "u{$i}", range(1, OversightScopeService::MAX_EXPLICIT_USER_IDS + 1)));
	$result = $scope->resolveRequestedScope('root', null, $raw);
	if ($result['ok'] !== false || $result['code'] !== OversightScopeService::ERR_TOO_MANY_USERS) {
		throw new RuntimeException('userIds over the cap must be rejected');
	}
});

kill('empty-userids-list-rejected', static function (): void {
	$scope = makeScope(admins: ['root' => true]);
	$result = $scope->resolveRequestedScope('root', null, ',,,');
	if ($result['ok'] !== false || $result['code'] !== OversightScopeService::ERR_INVALID_USER_IDS) {
		throw new RuntimeException('Empty userIds list must be rejected');
	}
});

// ── Team scope: foreign-team and nonexistent collapse identically ────

kill('manager-foreign-team-denied-without-oracle', static function (): void {
	$scope = makeScope(
		managers: ['mgr' => true],
		members: ['mgr' => ['employee1']],
		managedTeams: ['mgr' => [7]],
		teamDescendants: [7 => [7], 99 => [99]],
		teamMembers: [7 => ['employee1'], 99 => ['foreign']],
	);
	$foreign = $scope->resolveRequestedScope('mgr', null, null, '99');
	$nonexistent = $scope->resolveRequestedScope('mgr', null, null, '42');
	if ($foreign['ok'] !== false || $nonexistent['ok'] !== false) {
		throw new RuntimeException('Foreign and nonexistent teams must both be denied');
	}
	// Same code + same message class on the wire — no existence oracle.
	if (($foreign['code'] ?? null) !== ($nonexistent['code'] ?? null)) {
		throw new RuntimeException('Foreign vs nonexistent team must collapse to the same error code');
	}
});

kill('manager-team-scope-intersects-managed-members', static function (): void {
	$scope = makeScope(
		managers: ['mgr' => true],
		members: ['mgr' => ['employee1']],           // teamResolver = managed scope
		managedTeams: ['mgr' => [7]],
		teamDescendants: [7 => [7]],
		teamMembers: [7 => ['employee1', 'intruder']], // intruder in team but not managed scope
	);
	$result = $scope->resolveRequestedScope('mgr', null, null, '7');
	if ($result['ok'] !== true || $result['userIds'] !== ['employee1']) {
		throw new RuntimeException('Manager team scope must be intersected with the managed member set');
	}
});

kill('admin-may-select-any-existing-team', static function (): void {
	$scope = makeScope(
		admins: ['root' => true],
		teamDescendants: [9 => [9]],
		teamMembers: [9 => ['member1']],
	);
	$result = $scope->resolveRequestedScope('root', null, null, '9');
	if ($result['ok'] !== true || $result['userIds'] !== ['member1']) {
		throw new RuntimeException('Admin must resolve any existing team to its members');
	}
});

// ── Enabled/deleted filtering on people surfaces ─────────────────────

kill('managed-enabled-excludes-deleted-and-disabled', static function (): void {
	$scope = makeScope(
		managers: ['mgr' => true],
		members: ['mgr' => ['live', 'gone', 'off']],
		deleted: ['gone' => true],
		disabled: ['off' => true],
	);
	if ($scope->managedEnabledUserIds('mgr') !== ['live']) {
		throw new RuntimeException('Enabled scope must drop deleted and disabled accounts');
	}
});

kill('scope-self-returns-actor-only', static function (): void {
	$scope = makeScope(managers: ['mgr' => true], members: ['mgr' => ['employee1']]);
	$result = $scope->resolveRequestedScope('mgr', null, null, null, 'self');
	if ($result['ok'] !== true || $result['userIds'] !== ['mgr'] || $result['scope'] !== 'self') {
		throw new RuntimeException('scope=self must resolve to the actor alone');
	}
});

kill('scope-managed-for-manager-returns-team', static function (): void {
	$scope = makeScope(managers: ['mgr' => true], members: ['mgr' => ['employee1', 'employee2']]);
	$result = $scope->resolveRequestedScope('mgr', null, null, null, 'managed');
	if ($result['ok'] !== true || $result['userIds'] !== ['employee1', 'employee2']) {
		throw new RuntimeException('scope=managed must resolve to the managed team');
	}
});

kill('userids-dedupe-and-order-preserved', static function (): void {
	$scope = makeScope(admins: ['root' => true]);
	$result = $scope->resolveRequestedScope('root', null, 'b, a, b,  a');
	if ($result['ok'] !== true || $result['userIds'] !== ['b', 'a']) {
		throw new RuntimeException('userIds must dedupe and preserve first-seen order');
	}
});

kill('blank-actor-denied', static function (): void {
	$scope = makeScope(admins: ['root' => true]);
	$result = $scope->resolveRequestedScope('  ', null, 'u1');
	if ($result['ok'] !== false) {
		throw new RuntimeException('Blank actor uid must fail closed');
	}
});

fwrite(STDOUT, $failures === 0
	? "All oversight-scope mutants killed.\n"
	: "{$failures} mutant(s) survived — strengthen tests.\n");

exit($failures === 0 ? 0 : 1);
