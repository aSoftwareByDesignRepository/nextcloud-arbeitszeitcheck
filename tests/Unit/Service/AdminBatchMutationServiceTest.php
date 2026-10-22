<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\Team;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMember;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Service\AdminBatchMutationService;
use OCA\ArbeitszeitCheck\Service\AdminUserProfileUpdateService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class AdminBatchMutationServiceTest extends TestCase
{
	public function testAddTeamMembersBatchPartialAndIdempotent(): void
	{
		$users = $this->createMock(IUserManager::class);
		$teamMapper = $this->createMock(TeamMapper::class);
		$memberMapper = $this->createMock(TeamMemberMapper::class);
		$managerMapper = $this->createMock(TeamManagerMapper::class);
		$audit = $this->createMock(AuditLogMapper::class);
		$profile = $this->createMock(AdminUserProfileUpdateService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$team = new Team();
		$team->setName('RheinMain');
		$teamMapper->method('find')->with(7)->willReturn($team);

		$existing = new TeamMember();
		$existing->setUserId('already');
		$memberMapper->method('findByTeamId')->willReturn([$existing]);

		$enabled = $this->createMock(IUser::class);
		$enabled->method('isEnabled')->willReturn(true);
		$disabled = $this->createMock(IUser::class);
		$disabled->method('isEnabled')->willReturn(false);
		$users->method('get')->willReturnCallback(static function (string $id) use ($enabled, $disabled) {
			return match ($id) {
				'new1', 'new2', 'already' => $enabled,
				'off' => $disabled,
				default => null,
			};
		});

		$memberMapper->expects($this->exactly(2))->method('addMember');
		$audit->expects($this->exactly(2))->method('logAction');

		$svc = new AdminBatchMutationService(
			$users,
			$teamMapper,
			$memberMapper,
			$managerMapper,
			$audit,
			$profile,
			$logger,
		);

		$result = $svc->addTeamMembersBatch(7, ['new1', 'already', 'off', 'ghost', 'new2'], 'admin');
		$this->assertTrue($result['ok']);
		$this->assertSame(2, $result['summary']['added']);
		$this->assertSame(1, $result['summary']['skipped']);
		$this->assertSame(2, $result['summary']['failed']);

		$byId = [];
		foreach ($result['results'] as $row) {
			$byId[$row['userId']] = $row;
		}
		$this->assertSame('added', $byId['new1']['status']);
		$this->assertSame('skipped', $byId['already']['status']);
		$this->assertSame('already_member', $byId['already']['error']);
		$this->assertSame('user_disabled', $byId['off']['error']);
		$this->assertSame('user_not_found', $byId['ghost']['error']);
	}

	public function testBatchTooLargeRejected(): void
	{
		$svc = new AdminBatchMutationService(
			$this->createMock(IUserManager::class),
			$this->createMock(TeamMapper::class),
			$this->createMock(TeamMemberMapper::class),
			$this->createMock(TeamManagerMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(AdminUserProfileUpdateService::class),
			$this->createMock(LoggerInterface::class),
		);
		$ids = [];
		for ($i = 0; $i < 101; $i++) {
			$ids[] = 'u' . $i;
		}
		$result = $svc->addTeamMembersBatch(1, $ids, 'admin');
		$this->assertFalse($result['ok']);
		$this->assertSame('batch_too_large', $result['error']);
	}

	public function testBatchProfileDryRunDoesNotWrite(): void
	{
		$users = $this->createMock(IUserManager::class);
		$profile = $this->createMock(AdminUserProfileUpdateService::class);
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$users->method('get')->willReturn($user);

		$profile->expects($this->once())->method('validateProfileFields');
		$profile->expects($this->never())->method('updateProfile');

		$svc = new AdminBatchMutationService(
			$users,
			$this->createMock(TeamMapper::class),
			$this->createMock(TeamMemberMapper::class),
			$this->createMock(TeamManagerMapper::class),
			$this->createMock(AuditLogMapper::class),
			$profile,
			$this->createMock(LoggerInterface::class),
		);

		$result = $svc->batchProfile(
			['u1', 'u2'],
			['workingTimeModel' => ['modelId' => 3, 'startDate' => '2026-10-01']],
			'admin',
			true
		);
		$this->assertTrue($result['ok']);
		$this->assertTrue($result['dryRun']);
		$this->assertSame('would_apply', $result['results'][0]['status']);
		$this->assertSame(2, $result['summary']['applied']);
	}

	public function testAddTeamManagersBatchPartialAndIdempotent(): void
	{
		$users = $this->createMock(IUserManager::class);
		$teamMapper = $this->createMock(TeamMapper::class);
		$memberMapper = $this->createMock(TeamMemberMapper::class);
		$managerMapper = $this->createMock(TeamManagerMapper::class);
		$audit = $this->createMock(AuditLogMapper::class);
		$profile = $this->createMock(AdminUserProfileUpdateService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$team = new Team();
		$team->setName('Ops');
		$teamMapper->method('find')->with(3)->willReturn($team);

		$existing = new \OCA\ArbeitszeitCheck\Db\TeamManager();
		$existing->setUserId('boss');
		$managerMapper->method('findByTeamId')->willReturn([$existing]);

		$enabled = $this->createMock(IUser::class);
		$enabled->method('isEnabled')->willReturn(true);
		$enabled->method('getDisplayName')->willReturn('Boss Person');
		$users->method('get')->willReturnCallback(static function (string $id) use ($enabled) {
			return match ($id) {
				'm1', 'm2', 'boss' => $enabled,
				default => null,
			};
		});

		$managerMapper->expects($this->exactly(2))->method('addManager');
		$audit->expects($this->exactly(2))->method('logAction');

		$svc = new AdminBatchMutationService($users, $teamMapper, $memberMapper, $managerMapper, $audit, $profile, $logger);

		$result = $svc->addTeamManagersBatch(3, ['m1', 'boss', 'ghost', 'm2'], 'admin');
		$this->assertTrue($result['ok']);
		$this->assertSame(['added' => 2, 'skipped' => 1, 'failed' => 1], $result['summary']);
		$this->assertCount(1, $result['managers']);
		$this->assertSame('boss', $result['managers'][0]['userId']);

		$byId = [];
		foreach ($result['results'] as $row) {
			$byId[$row['userId']] = $row;
		}
		$this->assertSame('added', $byId['m1']['status']);
		$this->assertSame('already_manager', $byId['boss']['error']);
		$this->assertSame('user_not_found', $byId['ghost']['error']);
	}

	public function testAddTeamManagersBatchTeamNotFound(): void
	{
		$teamMapper = $this->createMock(TeamMapper::class);
		$teamMapper->method('find')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException('nope')
		);
		$svc = new AdminBatchMutationService(
			$this->createMock(IUserManager::class), $teamMapper,
			$this->createMock(TeamMemberMapper::class),
			$this->createMock(TeamManagerMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(AdminUserProfileUpdateService::class),
			$this->createMock(LoggerInterface::class),
		);
		$result = $svc->addTeamManagersBatch(9, ['u'], 'admin');
		$this->assertFalse($result['ok']);
		$this->assertSame('team_not_found', $result['error']);
		$this->assertSame(404, $result['httpStatus']);
	}

	public function testBatchVacationPolicyValidatesThenApplies(): void
	{
		$users = $this->createMock(IUserManager::class);
		$profile = $this->createMock(AdminUserProfileUpdateService::class);
		$audit = $this->createMock(AuditLogMapper::class);

		$enabled = $this->createMock(IUser::class);
		$enabled->method('isEnabled')->willReturn(true);
		$users->method('get')->willReturnCallback(static fn (string $id) => $id === 'gone' ? null : $enabled);

		$svc = new AdminBatchMutationService(
			$users,
			$this->createMock(TeamMapper::class),
			$this->createMock(TeamMemberMapper::class),
			$this->createMock(TeamManagerMapper::class),
			$audit, $profile,
			$this->createMock(LoggerInterface::class),
		);

		$policy = ['annualDays' => 30];

		// probe validates once against the first existing user
		$profile->expects($this->once())->method('validateProfileFields')
			->with('u1', ['vacationPolicy' => $policy]);
		// applies for the two existing+enabled users, skips the ghost
		$profile->expects($this->exactly(2))->method('applyVacationPolicy');

		$result = $svc->batchVacationPolicy(['u1', 'gone', 'u2'], $policy, 'admin');
		$this->assertTrue($result['ok']);
		$byId = [];
		foreach ($result['results'] as $row) {
			$byId[$row['userId']] = $row;
		}
		$this->assertSame('applied', $byId['u1']['status']);
		$this->assertSame('applied', $byId['u2']['status']);
		$this->assertSame('user_not_found', $byId['gone']['error']);
	}

	public function testBatchVacationPolicyRejectsEmptyPolicyAndFailedProbe(): void
	{
		$users = $this->createMock(IUserManager::class);
		$profile = $this->createMock(AdminUserProfileUpdateService::class);
		$enabled = $this->createMock(IUser::class);
		$users->method('get')->willReturn($enabled);

		$svc = new AdminBatchMutationService(
			$users,
			$this->createMock(TeamMapper::class),
			$this->createMock(TeamMemberMapper::class),
			$this->createMock(TeamManagerMapper::class),
			$this->createMock(AuditLogMapper::class),
			$profile,
			$this->createMock(LoggerInterface::class),
		);

		// empty policy -> 400 before touching users
		$r = $svc->batchVacationPolicy(['u1'], [], 'admin');
		$this->assertFalse($r['ok']);
		$this->assertSame('vacation_policy_required', $r['error']);

		// probe validation failure -> whole batch rejected with validation_failed
		$profile->method('validateProfileFields')->willThrowException(
			new \OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException('bad policy', 422)
		);
		$profile->expects($this->never())->method('applyVacationPolicy');
		$r = $svc->batchVacationPolicy(['u1'], ['x' => 1], 'admin');
		$this->assertFalse($r['ok']);
		$this->assertSame('validation_failed', $r['error']);
		$this->assertSame(422, $r['httpStatus']);
	}
}