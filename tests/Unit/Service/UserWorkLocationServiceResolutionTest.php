<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper;
use OCA\ArbeitszeitCheck\Service\UserWorkLocationService;
use OCA\ArbeitszeitCheck\Service\WorkLocationCatalog;
use OCA\ArbeitszeitCheck\Service\WorkLocationDayOverrideService;
use OCA\ArbeitszeitCheck\Service\WorkLocationDefaultResolver;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCA\ArbeitszeitCheck\Support\WorkLocation;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class UserWorkLocationServiceResolutionTest extends TestCase
{
	private UserSettingsMapper $settings;
	private AuditLogMapper $audit;
	private WorkLocationCatalog $catalog;

	protected function setUp(): void
	{
		$this->settings = $this->createMock(UserSettingsMapper::class);
		$this->audit = $this->createMock(AuditLogMapper::class);
		$defMapper = $this->createMock(WorkLocationDefinitionMapper::class);
		$defMapper->method('findAllIndexed')->willReturn([]);
		$this->catalog = new WorkLocationCatalog($defMapper, new NullLogger());
	}

	private function config(bool $catalogEnabled): WorkforceSuiteConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $_app, string $key, string $default = ''): string =>
				$key === 'work_location_catalog_enabled' ? ($catalogEnabled ? '1' : '0') : $default
		);
		return new WorkforceSuiteConfig($config);
	}

	private function dayOverride(?string $code): WorkLocationDayOverrideService
	{
		// Real service over an in-memory override store (the class is final).
		$mapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\WorkLocationDayOverrideMapper::class);
		$mapper->method('findForUserDate')->willReturnCallback(function (string $u, string $d) use ($code) {
			if ($code === null) {
				return null;
			}
			$row = new \OCA\ArbeitszeitCheck\Db\WorkLocationDayOverride();
			$row->setUserId($u);
			$row->setLocalDate(new \DateTime($d));
			$row->setLocationCode($code);
			$row->setRevision(1);
			return $row;
		});
		return new WorkLocationDayOverrideService($mapper, $this->catalog, $this->audit, $this->createMock(\OCP\Lock\ILockingProvider::class));
	}

	private function resolver(?array $resolved): WorkLocationDefaultResolver
	{
		// Real resolver (final class) over stubbed mappers; when $resolved is
		// non-null we synthesize a winning user-scope policy for its code.
		$policyMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper::class);
		$policyMapper->method('findEffectiveForScopes')->willReturnCallback(function (array $scopes, string $date) use ($resolved) {
			if ($resolved === null) {
				return [];
			}
			foreach ($scopes as [$type, $id]) {
				if ($type === 'user') {
					$p = new \OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicy();
					$p->setId($resolved['policyId'] ?? 1);
					$p->setScopeType('user');
					$p->setScopeId($id);
					$p->setLocationCode($resolved['code']);
					$p->setPriority(0);
					$p->setEffectiveFrom(new \DateTime('2020-01-01'));
					$p->setRevision(1);
					return [$p];
				}
			}
			return [];
		});
		return new WorkLocationDefaultResolver(
			$policyMapper,
			$this->catalog,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			new \OCA\ArbeitszeitCheck\Policy\PolicyScopeResolver(),
		);
	}

	private function service(bool $catalogEnabled, ?WorkLocationDayOverrideService $day = null, ?WorkLocationDefaultResolver $resolver = null): UserWorkLocationService
	{
		return new UserWorkLocationService(
			$this->settings,
			$this->audit,
			$this->config($catalogEnabled),
			$this->catalog,
			$day,
			$resolver,
		);
	}

	public function testFlagOffKeepsLegacyChain(): void
	{
		$this->settings->method('getStringSetting')->willReturn('home');
		$svc = $this->service(false);
		self::assertSame('external', $svc->resolveForNewEntry('u1', 'external'));
		self::assertSame('home', $svc->resolveForNewEntry('u1', null));
	}

	public function testFlagOffRejectsUnknownCode(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service(false)->resolveForNewEntry('u1', 'custom_x');
	}

	public function testFlagOnExplicitWins(): void
	{
		$day = $this->dayOverride('office');
		$resolver = $this->resolver(['code' => 'external', 'source' => 'policy_user', 'policyId' => 1, 'trace' => []]);
		$svc = $this->service(true, $day, $resolver);
		self::assertSame('home', $svc->resolveForNewEntry('u1', 'home'));
	}

	public function testFlagOnDayOverrideBeatsPolicy(): void
	{
		// The policy mapper asserts it is never queried when a live day
		// override exists — short-circuit ordering, not just precedence.
		$policyMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper::class);
		$policyMapper->expects(self::never())->method('findEffectiveForScopes');
		$resolver = new WorkLocationDefaultResolver(
			$policyMapper,
			$this->catalog,
			$this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMemberMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\TeamMapper::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
			new \OCA\ArbeitszeitCheck\Policy\PolicyScopeResolver(),
		);
		$svc = $this->service(true, $this->dayOverride('home'), $resolver);
		self::assertSame('home', $svc->resolveForNewEntry('u1', null));
	}

	public function testFlagOnPolicyResultUsed(): void
	{
		$day = $this->dayOverride(null);
		$resolver = $this->resolver(['code' => 'external', 'source' => 'policy_team', 'policyId' => 7, 'trace' => []]);
		$svc = $this->service(true, $day, $resolver);
		self::assertSame('external', $svc->resolveForNewEntry('u1', null));
	}

	public function testFlagOnLegacySettingFallbackAfterPolicies(): void
	{
		$this->settings->method('getStringSetting')->willReturn('home');
		$svc = $this->service(true, $this->dayOverride(null), $this->resolver(null));
		self::assertSame('home', $svc->resolveForNewEntry('u1', null));
	}

	public function testFlagOnOfficeFallback(): void
	{
		$this->settings->method('getStringSetting')->willReturn('');
		$svc = $this->service(true, $this->dayOverride(null), $this->resolver(null));
		self::assertSame(WorkLocation::OFFICE, $svc->resolveForNewEntry('u1', null));
	}

	public function testFlagOnInactiveExplicitRejected(): void
	{
		$svc = $this->service(true, $this->dayOverride(null), $this->resolver(null));
		$this->expectException(\InvalidArgumentException::class);
		$svc->resolveForNewEntry('u1', 'custom_ghost');
	}

	public function testEffectiveTodaySources(): void
	{
		// Flag off: legacy default.
		$this->settings->method('getStringSetting')->willReturn('external');
		$r = $this->service(false)->effectiveToday('u1');
		self::assertSame(['code' => 'external', 'source' => 'legacy_default', 'policyId' => null], $r);

		// Flag on with day override.
		$r = $this->service(true, $this->dayOverride('home'), $this->resolver(null))->effectiveToday('u1');
		self::assertSame('day_override', $r['source']);
		self::assertSame('home', $r['code']);

		// Flag on with policy winner (the fixture synthesizes a user-scope row).
		$r = $this->service(true, $this->dayOverride(null), $this->resolver(['code' => 'office', 'policyId' => 4]))->effectiveToday('u1');
		self::assertSame('policy_user', $r['source']);
		self::assertSame(4, $r['policyId']);
	}
}
