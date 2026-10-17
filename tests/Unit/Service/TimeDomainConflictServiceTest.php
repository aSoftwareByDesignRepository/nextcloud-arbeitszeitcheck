<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Policy\TimeDomainConflictProvider;
use OCA\ArbeitszeitCheck\Service\TimeDomainConflictService;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

final class TimeDomainConflictServiceTest extends TestCase
{
	public function testDisabledDomainsDoNotQueryProviders(): void
	{
		$provider = $this->createMock(TimeDomainConflictProvider::class);
		$provider->method('id')->willReturn('trips');
		$provider->expects(self::never())->method('conflicts');
		$service = new TimeDomainConflictService($this->config(false));
		$service->register($provider);
		self::assertSame([], $service->check('u1', '2026-01-01', '2026-01-02'));
	}

	public function testReturnsOnlyPublicConflictFieldsInStableProviderOrder(): void
	{
		$service = new TimeDomainConflictService($this->config(true));
		$service->register($this->provider('z_trips', [['code' => 'TRIP_OVERLAP', 'from' => '2026-01-02', 'to' => '2026-01-03', 'secret' => 'drop-me']]));
		$service->register($this->provider('a_absence', [['code' => 'ABSENCE_BLOCKING', 'from' => '2026-01-01', 'to' => '2026-01-01']]));
		self::assertSame([
			['domain' => 'a_absence', 'code' => 'ABSENCE_BLOCKING', 'from' => '2026-01-01', 'to' => '2026-01-01'],
			['domain' => 'z_trips', 'code' => 'TRIP_OVERLAP', 'from' => '2026-01-02', 'to' => '2026-01-03'],
		], $service->check('u1', '2026-01-01', '2026-01-03'));
	}

	public function testRejectsMalformedProviderPayload(): void
	{
		$service = new TimeDomainConflictService($this->config(true));
		$service->register($this->provider('trips', [['code' => 'private detail', 'from' => '2026-01-01', 'to' => '2026-01-01']]));
		$this->expectException(\UnexpectedValueException::class);
		$service->check('u1', '2026-01-01', '2026-01-01');
	}

	public function testRejectsImpossibleDateRange(): void
	{
		$service = new TimeDomainConflictService($this->config(true));
		$this->expectException(\InvalidArgumentException::class);
		$service->check('u1', '2026-02-30', '2026-03-01');
	}

	private function config(bool $enabled): WorkforceSuiteConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $_app, string $key, string $default = ''): string => in_array($key, ['special_entitlements_enabled', 'business_trips_enabled'], true) && $enabled ? '1' : $default
		);
		return new WorkforceSuiteConfig($config);
	}

	private function provider(string $id, array $conflicts): TimeDomainConflictProvider
	{
		$provider = $this->createMock(TimeDomainConflictProvider::class);
		$provider->method('id')->willReturn($id);
		$provider->method('conflicts')->willReturn($conflicts);
		return $provider;
	}
}
