<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\PolicyCohortMembership;
use OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper;
use OCA\ArbeitszeitCheck\Service\PolicyCohortResolver;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class PolicyCohortResolverTest extends TestCase
{
	public function testDisabledFeatureReturnsWithoutDomainQuery(): void
	{
		$mapper = $this->createMock(PolicyCohortMembershipMapper::class);
		$mapper->expects(self::never())->method('findEffectiveForUser');
		$resolver = new PolicyCohortResolver($this->config(false), $mapper);
		self::assertSame([], $resolver->effectiveCohortIds('u1', new \DateTimeImmutable('2026-01-01')));
	}

	public function testEnabledFeatureReturnsStableUniqueIds(): void
	{
		$first = new PolicyCohortMembership();
		$first->setCohortId(12);
		$duplicate = new PolicyCohortMembership();
		$duplicate->setCohortId(12);
		$second = new PolicyCohortMembership();
		$second->setCohortId(2);
		$mapper = $this->createMock(PolicyCohortMembershipMapper::class);
		$mapper->expects(self::once())->method('findEffectiveForUser')->with('u1', '2026-01-02')->willReturn([$first, $duplicate, $second]);
		$resolver = new PolicyCohortResolver($this->config(true), $mapper);
		self::assertSame(['12', '2'], $resolver->effectiveCohortIds('u1', new \DateTimeImmutable('2026-01-02')));
	}

	private function config(bool $enabled): WorkforceSuiteConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $_app, string $key, string $default = ''): string => $key === 'policy_cohorts_enabled' ? ($enabled ? '1' : '0') : $default
		);
		return new WorkforceSuiteConfig($config);
	}
}
