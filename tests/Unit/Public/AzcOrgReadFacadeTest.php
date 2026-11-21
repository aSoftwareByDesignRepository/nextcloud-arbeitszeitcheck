<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Public;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Public\AzcOrgReadFacade;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\IDBConnection;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F20 — org-structure read facade: contract shape, doorConfig group
 * authority (platform restriction only — the stored group snapshot is
 * write-only, azc #46), fail-closed on every read.
 */
class AzcOrgReadFacadeTest extends TestCase
{
	private TeamResolverService $teams;
	private PermissionService $permissions;
	private IConfig $config;
	private IDBConnection $db;

	protected function setUp(): void
	{
		$this->teams = $this->createMock(TeamResolverService::class);
		$this->permissions = $this->createMock(PermissionService::class);
		$this->config = $this->createMock(IConfig::class);
		$this->db = $this->createMock(IDBConnection::class);
	}

	private function facade(): AzcOrgReadFacade
	{
		return new AzcOrgReadFacade(
			$this->teams,
			$this->permissions,
			$this->config,
			$this->db,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testContractShape(): void
	{
		$this->assertSame(1, AzcOrgReadFacade::FACADE_VERSION);
		foreach (['managersFor', 'canManage', 'hasManager', 'predicateSnapshot', 'substitutesFor', 'doorConfig', 'teamIdsFor'] as $m) {
			$this->assertTrue(method_exists(AzcOrgReadFacade::class, $m), $m);
		}
	}

	/**
	 * azc #46 regression pin: the group allowlist is mirrored into the NC
	 * platform restriction, so getAppRestriction is the ONLY read authority.
	 * doorConfig must never read the stored snapshot key — an out-of-band
	 * Apps-page/`occ app:enable -g` change would otherwise stay invisible.
	 */
	public function testDoorConfigGroupsComeFromPlatformRestriction(): void
	{
		$this->config->method('getAppValue')->willReturnCallback(
			function (string $app, string $key, string $default = '') {
				if ($key === Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS) {
					$this->fail('doorConfig read the write-only group snapshot key');
				}
				return match ($key) {
					Constants::CONFIG_ACCESS_RESTRICTION_ENABLED => '1',
					Constants::CONFIG_ACCESS_ALLOWED_USER_IDS => '["alice","bob"]',
					default => $default,
				};
			}
		);
		$this->permissions->method('getAllowedAccessGroups')->willReturn(['live-group']);

		$out = $this->facade()->doorConfig();

		$this->assertSame('restricted', $out['mode']);
		$this->assertSame(['alice', 'bob'], $out['allowed_users']);
		$this->assertSame(['live-group'], $out['allowed_groups']);
	}

	public function testDoorConfigFailsClosedOnError(): void
	{
		$this->config->method('getAppValue')->willThrowException(new \RuntimeException('boom'));
		$this->assertSame(
			['mode' => 'open', 'allowed_users' => [], 'allowed_groups' => []],
			$this->facade()->doorConfig(),
		);
	}

	public function testManagersForSkipsSelfAndFailsClosed(): void
	{
		$this->teams->method('approverPredicateSnapshot')->willReturn([
			'mode' => 'app_teams',
			'approvers' => ['mgr1', 'emp1', 'mgr1', ''],
		]);
		$this->assertSame(['mgr1'], $this->facade()->managersFor('emp1'));

		$this->teams = $this->createMock(TeamResolverService::class);
		$this->teams->method('approverPredicateSnapshot')
			->willThrowException(new \RuntimeException('boom'));
		$this->assertSame([], $this->facade()->managersFor('emp1'));
		$this->assertSame([], $this->facade()->managersFor(''));
	}

	public function testBooleanReadsFailClosed(): void
	{
		$this->assertFalse($this->facade()->canManage('', 'emp'));
		$this->assertFalse($this->facade()->hasManager(''));

		$this->teams = $this->createMock(TeamResolverService::class);
		$this->teams->method('canUserManageEmployee')->willThrowException(new \RuntimeException('boom'));
		$this->teams->method('hasAssignableManagerForEmployee')->willThrowException(new \RuntimeException('boom'));
		$this->assertFalse($this->facade()->canManage('mgr', 'emp'));
		$this->assertFalse($this->facade()->hasManager('emp'));
	}

	public function testTeamIdsForEmptyOutsideAppTeams(): void
	{
		$this->teams->method('useAppTeams')->willReturn(false);
		$this->db->expects($this->never())->method('getQueryBuilder');
		$this->assertSame([], $this->facade()->teamIdsFor('emp'));
	}
}
