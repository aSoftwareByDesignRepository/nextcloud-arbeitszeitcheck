<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for admin employee list access filter.
 * Run: php tests/Mutation/run-admin-employee-access-filter-mutations.php
 */

$root = dirname(__DIR__, 2);
require_once dirname(__DIR__) . '/bootstrap.php';
require_once $root . '/vendor/autoload.php';

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\InvalidEmployeeListFilterException;
use OCA\ArbeitszeitCheck\Service\AdminEmployeeDirectoryService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

/**
 * @param list<IUser> $users
 */
/**
 * @param array<string, int> $currentModels
 * @param array<int, bool> $personalFlags
 */
function makeService(
	bool $restricted,
	array $users,
	?callable $allowed = null,
	array $currentModels = [],
	array $personalFlags = [],
	bool $modelExists = true,
): AdminEmployeeDirectoryService {
	$case = new class extends TestCase {
		public function mock(string $class)
		{
			return $this->createMock($class);
		}
	};

	$userManager = $case->mock(IUserManager::class);
	$userManager->method('search')->willReturn($users);
	$userManager->method('searchDisplayName')->willReturn([]);

	$permission = $case->mock(PermissionService::class);
	$permission->method('isAccessRestrictionEnabled')->willReturn($restricted);
	$permission->method('isUserAllowedByAccessGroups')->willReturnCallback(
		$allowed ?? static fn (string $uid): bool => true,
	);

	$timeEntryMapper = $case->mock(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class);
	$timeEntryMapper->method('findDistinctUserIdsByDate')->willReturn([]);

	$l10n = $case->mock(IL10N::class);
	$l10n->method('t')->willReturnArgument(0);

	$assignmentMapper = $case->mock(UserWorkingTimeModelMapper::class);
	$assignmentMapper->method('findCurrentModelIdsByUserIds')->willReturn($currentModels);
	$modelMapper = $case->mock(WorkingTimeModelMapper::class);
	$modelMapper->method('findPersonalFlagsByIds')->willReturn($personalFlags);
	if ($modelExists) {
		$modelMapper->method('find')->willReturn($case->mock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModel::class));
	} else {
		$modelMapper->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('missing'));
	}

	return new AdminEmployeeDirectoryService(
		$userManager,
		$permission,
		$timeEntryMapper,
		$l10n,
		$case->mock(LoggerInterface::class),
		$assignmentMapper,
		$modelMapper,
	);
}

function makeUser(string $uid, string $displayName, bool $enabled = true): IUser
{
	$case = new class extends TestCase {
		public function mockUser(string $uid, string $displayName, bool $enabled): IUser
		{
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('getDisplayName')->willReturn($displayName);
			$user->method('isEnabled')->willReturn($enabled);
			return $user;
		}
	};
	return $case->mockUser($uid, $displayName, $enabled);
}

kill('invalid-filter-throws', static function (): void {
	$svc = makeService(false, []);
	try {
		$svc->listUsers('evil', '', 50, 0);
		throw new RuntimeException('Expected InvalidEmployeeListFilterException');
	} catch (InvalidEmployeeListFilterException) {
		// expected
	}
});

kill('restricted-default-is-app-access', static function (): void {
	$svc = makeService(true, [makeUser('a', 'A')]);
	$result = $svc->listUsers(null, '', 50, 0);
	if ($result['filter'] !== AdminEmployeeDirectoryService::FILTER_APP_ACCESS) {
		throw new RuntimeException('Default filter must be app_access when restricted');
	}
});

kill('open-default-is-all', static function (): void {
	$svc = makeService(false, [makeUser('a', 'A')]);
	$result = $svc->listUsers(null, '', 50, 0);
	if ($result['filter'] !== AdminEmployeeDirectoryService::FILTER_ALL) {
		throw new RuntimeException('Default filter must be all when open');
	}
});

kill('app-access-hides-denied', static function (): void {
	$svc = makeService(true, [makeUser('in', 'In'), makeUser('out', 'Out')], static fn (string $uid): bool => $uid === 'in');
	$result = $svc->listUsers(AdminEmployeeDirectoryService::FILTER_APP_ACCESS, '', 50, 0);
	if ($result['total'] !== 1 || $result['users'][0]->getUID() !== 'in') {
		throw new RuntimeException('Denied users must not appear in app_access filter');
	}
	if (($result['hiddenCount'] ?? -1) !== 1) {
		throw new RuntimeException('hiddenCount must reflect pre-filter minus post-filter');
	}
});

kill('pagination-after-filter-not-before', static function (): void {
	$users = [makeUser('a', 'A'), makeUser('b', 'B'), makeUser('c', 'C')];
	$svc = makeService(false, $users);
	$page = $svc->listUsers(AdminEmployeeDirectoryService::FILTER_ALL, '', 1, 1);
	if ($page['users'][0]->getUID() !== 'b') {
		throw new RuntimeException('Offset must apply after sort/filter, expected user b on page 2');
	}
});

kill('invalid-status-does-not-scan', static function (): void {
	$svc = makeService(false, [makeUser('a', 'A')]);
	try {
		$svc->listUsers(AdminEmployeeDirectoryService::FILTER_ALL, '', 50, 0, false, null, 'nope');
		throw new RuntimeException('Expected InvalidEmployeeListFilterException');
	} catch (InvalidEmployeeListFilterException) {
		// expected
	}
});

kill('invalid-model-token-rejected', static function (): void {
	$svc = makeService(false, []);
	foreach (['2.9', '02', '0', '1e2', '-3'] as $token) {
		try {
			$svc->listUsers(AdminEmployeeDirectoryService::FILTER_ALL, '', 50, 0, false, $token, null);
			throw new RuntimeException('Expected reject for ' . $token);
		} catch (InvalidEmployeeListFilterException) {
			// expected
		}
	}
});

kill('missing-model-id-rejected', static function (): void {
	$svc = makeService(false, [], null, [], [], false);
	try {
		$svc->listUsers(AdminEmployeeDirectoryService::FILTER_ALL, '', 50, 0, false, '15', null);
		throw new RuntimeException('Unknown model id must be rejected');
	} catch (InvalidEmployeeListFilterException) {
		// expected
	}
});

kill('model-and-status-intersect', static function (): void {
	$users = [
		makeUser('keep', 'Keep', true),
		makeUser('disabled', 'Disabled', false),
		makeUser('other', 'Other', true),
	];
	$svc = makeService(false, $users, null, ['keep' => 5, 'disabled' => 5, 'other' => 9]);
	$result = $svc->listUsers(AdminEmployeeDirectoryService::FILTER_ALL, '', 50, 0, false, '5', 'enabled');
	if ($result['total'] !== 1 || $result['users'][0]->getUID() !== 'keep') {
		throw new RuntimeException('Model and status must both match');
	}
	if ($result['model'] !== '5' || $result['status'] !== 'enabled') {
		throw new RuntimeException('Normalized model and status must be echoed');
	}
});

kill('none-includes-deleted-not-personal', static function (): void {
	$users = [
		makeUser('open', 'Open'),
		makeUser('gone', 'Gone'),
		makeUser('personal', 'Personal'),
		makeUser('org', 'Org'),
	];
	$svc = makeService(false, $users, null, [
		'gone' => 99,
		'personal' => 8,
		'org' => 5,
	], [8 => true, 5 => false]);
	$result = $svc->listUsers(AdminEmployeeDirectoryService::FILTER_ALL, '', 50, 0, false, 'none', null);
	$uids = array_map(static fn (IUser $u) => $u->getUID(), $result['users']);
	sort($uids);
	if ($uids !== ['gone', 'open']) {
		throw new RuntimeException('none must be unassigned or deleted model, got ' . implode(',', $uids));
	}
});

kill('personal-bucket', static function (): void {
	$users = [makeUser('personal', 'Personal'), makeUser('org', 'Org')];
	$svc = makeService(false, $users, null, ['personal' => 8, 'org' => 5], [8 => true, 5 => false]);
	$result = $svc->listUsers(AdminEmployeeDirectoryService::FILTER_ALL, '', 50, 0, false, 'personal', null);
	if ($result['total'] !== 1 || $result['users'][0]->getUID() !== 'personal') {
		throw new RuntimeException('personal must match only personal models');
	}
});

kill('status-disabled', static function (): void {
	$users = [makeUser('on', 'On', true), makeUser('off', 'Off', false)];
	$svc = makeService(false, $users);
	$result = $svc->listUsers(AdminEmployeeDirectoryService::FILTER_ALL, '', 50, 0, false, null, ' DISABLED ');
	if ($result['total'] !== 1 || $result['users'][0]->getUID() !== 'off' || $result['status'] !== 'disabled') {
		throw new RuntimeException('status must trim, lowercase, and keep disabled accounts');
	}
});

kill('model-filter-does-not-change-hidden-count', static function (): void {
	$users = [makeUser('in', 'In'), makeUser('out', 'Out')];
	$svc = makeService(true, $users, static fn (string $uid): bool => $uid === 'in', ['in' => 5]);
	$result = $svc->listUsers(AdminEmployeeDirectoryService::FILTER_APP_ACCESS, '', 50, 0, false, '5', null);
	if (($result['hiddenCount'] ?? -1) !== 1 || $result['total'] !== 1) {
		throw new RuntimeException('hiddenCount stays on the access filter');
	}
});

kill('empty-model-skips-assignment-lookup', static function (): void {
	$case = new class extends TestCase {
		public function mock(string $class)
		{
			return $this->createMock($class);
		}
	};
	$user = makeUser('a', 'A');
	$userManager = $case->mock(IUserManager::class);
	$userManager->method('search')->willReturn([$user]);
	$permission = $case->mock(PermissionService::class);
	$permission->method('isAccessRestrictionEnabled')->willReturn(false);
	$timeEntryMapper = $case->mock(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class);
	$l10n = $case->mock(IL10N::class);
	$l10n->method('t')->willReturnArgument(0);
	$assignmentMapper = $case->mock(UserWorkingTimeModelMapper::class);
	$assignmentMapper->expects($case->never())->method('findCurrentModelIdsByUserIds');
	$modelMapper = $case->mock(WorkingTimeModelMapper::class);
	$modelMapper->expects($case->never())->method('findPersonalFlagsByIds');
	$svc = new AdminEmployeeDirectoryService(
		$userManager,
		$permission,
		$timeEntryMapper,
		$l10n,
		$case->mock(LoggerInterface::class),
		$assignmentMapper,
		$modelMapper,
	);
	$result = $svc->listUsers(AdminEmployeeDirectoryService::FILTER_ALL, '', 50, 0);
	if ($result['model'] !== '' || $result['status'] !== '' || $result['total'] !== 1) {
		throw new RuntimeException('Empty model and status must not query assignments');
	}
});

kill('latest-assignment-wins', static function (): void {
	$map = \OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::pickCurrentModelIds([
		['user_id' => 'a', 'working_time_model_id' => 1, 'start_date' => '2026-01-01', 'id' => 10],
		['user_id' => 'a', 'working_time_model_id' => 2, 'start_date' => '2026-06-01', 'id' => 3],
		['user_id' => 'a', 'working_time_model_id' => 9, 'start_date' => '2026-06-01', 'id' => 4],
		['user_id' => 'b', 'working_time_model_id' => 7, 'start_date' => '2026-02-01', 'id' => 1],
		['user_id' => '', 'working_time_model_id' => 5, 'start_date' => '2026-01-01', 'id' => 1],
		['user_id' => 'c', 'working_time_model_id' => 0, 'start_date' => '2026-01-01', 'id' => 1],
	]);
	if (($map['a'] ?? 0) !== 9 || ($map['b'] ?? 0) !== 7 || isset($map['c']) || count($map) !== 2) {
		throw new RuntimeException('Latest start_date then highest id must win');
	}
});

kill('scan-cap-truncates', static function (): void {
	$cap = Constants::ADMIN_EMPLOYEE_FILTER_MAX_SCAN;
	$users = [];
	for ($i = 0; $i < $cap + 3; $i++) {
		$users[] = makeUser('u' . $i, 'U' . $i);
	}
	$svc = makeService(false, $users);
	$result = $svc->listUsers(AdminEmployeeDirectoryService::FILTER_ALL, '', 50, 0);
	if (!$result['truncated'] || $result['total'] !== $cap) {
		throw new RuntimeException('Scan cap must set truncated and cap total');
	}
});

fwrite(STDOUT, $failures === 0
	? "All admin employee access-filter mutants killed.\n"
	: "{$failures} mutant(s) survived — strengthen tests.\n");

exit($failures === 0 ? 0 : 1);
