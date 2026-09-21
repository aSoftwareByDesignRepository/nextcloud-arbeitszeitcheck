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
}
