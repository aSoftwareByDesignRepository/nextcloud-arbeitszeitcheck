<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Repair;

use OCA\ArbeitszeitCheck\Repair\InitializeWorkforceSuiteFreshState;
use OCA\ArbeitszeitCheck\Repair\InitializeWorkforceSuiteUpgradeState;
use OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class InitializeWorkforceSuiteStateTest extends TestCase
{
	public function testFreshAndUpgradeRepairsInitializeTheirExpectedState(): void
	{
		$fresh = $this->configService($freshValues);
		$upgrade = $this->configService($upgradeValues);
		$output = $this->createMock(IOutput::class);
		$output->expects(self::exactly(2))->method('info');

		(new InitializeWorkforceSuiteFreshState($fresh))->run($output);
		(new InitializeWorkforceSuiteUpgradeState($upgrade))->run($output);

		self::assertSame(WorkforceSuiteConfig::STATE_FRESH, $freshValues[WorkforceSuiteConfig::INSTALL_STATE_KEY]);
		self::assertSame(WorkforceSuiteConfig::STATE_UPGRADED_LEGACY, $upgradeValues[WorkforceSuiteConfig::INSTALL_STATE_KEY]);
		self::assertSame('0', $upgradeValues['business_trips_enabled']);
		self::assertSame('1', $upgradeValues['legacy_trip_absence_create_enabled']);
	}

	private function configService(?array &$values): WorkforceSuiteConfig
	{
		$values = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $_app, string $key, string $default = '') use (&$values): string {
				return $values[$key] ?? $default;
			}
		);
		$config->method('setAppValue')->willReturnCallback(
			static function (string $_app, string $key, string $value) use (&$values): void {
				$values[$key] = $value;
			}
		);
		return new WorkforceSuiteConfig($config);
	}
}
