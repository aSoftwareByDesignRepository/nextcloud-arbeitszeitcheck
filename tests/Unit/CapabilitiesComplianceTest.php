<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use OCA\ArbeitszeitCheck\Capabilities;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IConfig;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Additive DACH compliance block in capabilities (§4.2).
 */
class CapabilitiesComplianceTest extends TestCase
{
	private function buildCapabilities(string $country): Capabilities
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') use ($country) {
				if ($app === 'arbeitszeitcheck' && $key === 'country') {
					return $country;
				}
				return is_string($default) ? $default : (string)$default;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(
			static fn (string $key, string $default = '') => $default
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->with('arbeitszeitcheck')->willReturn('1.6.0');
		$appManager->method('isEnabledForUser')->willReturn(false);
		$appManager->method('isInstalled')->willReturn(false);

		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);

		$overtimeBank = $this->createMock(OvertimeBankService::class);
		$overtimeBank->method('isEnabled')->willReturn(false);

		$timeCapture = $this->createMock(TimeCaptureMethodService::class);

		return new Capabilities(
			$config,
			$appConfig,
			$overtimeBank,
			$appManager,
			$userSession,
			$timeCapture,
			new LaborLawProfileFactory($config),
		);
	}

	public function testGermanComplianceBlock(): void
	{
		$caps = $this->buildCapabilities(RegionRegistry::COUNTRY_DE)->getCapabilities();
		$block = $caps['arbeitszeitcheck']['compliance'];
		$features = $caps['arbeitszeitcheck']['features'];

		$this->assertSame('DE', $block['country']);
		$this->assertTrue($block['german-labor-law']);
		$this->assertSame(10.0, $block['maxDailyHours']);
		$this->assertSame(15, $block['minBreakMinutes']);
		$this->assertNull($block['allowedBreakSplitPatterns']);
		$this->assertSame('ArbZG §4', $block['lawLabels']['breaks']);
		$this->assertContains('arbzg-compliance', $features);
		$this->assertSame('1.6.0', $caps['arbeitszeitcheck']['version']);
		$this->assertCount(2, $block['breakTiers']);
	}

	public function testAustrianComplianceBlock(): void
	{
		$caps = $this->buildCapabilities(RegionRegistry::COUNTRY_AT)->getCapabilities();
		$block = $caps['arbeitszeitcheck']['compliance'];
		$features = $caps['arbeitszeitcheck']['features'];

		$this->assertSame('AT', $block['country']);
		$this->assertFalse($block['german-labor-law']);
		$this->assertSame(12.0, $block['maxDailyHours']);
		$this->assertSame(10, $block['minBreakMinutes']);
		$this->assertSame([[15, 15], [10, 10, 10]], $block['allowedBreakSplitPatterns']);
		$this->assertSame('AZG §11', $block['lawLabels']['breaks']);
		$this->assertContains('azg-compliance', $features);
		$this->assertCount(1, $block['breakTiers']);
	}

	public function testSwissComplianceBlockUsesDistinctFeatureTag(): void
	{
		$caps = $this->buildCapabilities(RegionRegistry::COUNTRY_CH)->getCapabilities();
		$block = $caps['arbeitszeitcheck']['compliance'];
		$features = $caps['arbeitszeitcheck']['features'];

		$this->assertSame('CH', $block['country']);
		$this->assertFalse($block['german-labor-law']);
		$this->assertSame(10.0, $block['maxDailyHours']);
		$this->assertSame('ArG Art. 15', $block['lawLabels']['breaks']);
		$this->assertContains('swiss-arg-compliance', $features);
		$this->assertNotContains('arg-compliance', $features, 'Must not collide with Austrian ARG naming');
		$this->assertCount(3, $block['breakTiers']);
		$this->assertSame(5.5, $block['breakTiers'][0]['afterHours']);
		$this->assertSame(45.0, $block['weeklyAbsoluteMaxHours']);
	}

	public function testSwissComplianceBlockHonoursFiftyHourWeeklyMaximum(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') {
				if ($app === 'arbeitszeitcheck' && $key === 'country') {
					return RegionRegistry::COUNTRY_CH;
				}
				if ($app === 'arbeitszeitcheck' && $key === LaborLawProfileFactory::CONFIG_KEY_WEEKLY_ABSOLUTE_MAX) {
					return '50';
				}
				return is_string($default) ? $default : (string)$default;
			}
		);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(
			static fn (string $key, string $default = '') => $default
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.6.0');
		$appManager->method('isEnabledForUser')->willReturn(false);
		$appManager->method('isInstalled')->willReturn(false);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$overtimeBank = $this->createMock(OvertimeBankService::class);
		$overtimeBank->method('isEnabled')->willReturn(false);
		$timeCapture = $this->createMock(TimeCaptureMethodService::class);

		$caps = (new Capabilities(
			$config,
			$appConfig,
			$overtimeBank,
			$appManager,
			$userSession,
			$timeCapture,
			new LaborLawProfileFactory($config),
		))->getCapabilities();

		$this->assertSame(50.0, $caps['arbeitszeitcheck']['compliance']['weeklyAbsoluteMaxHours']);
	}

	public function testAccessibilityClaimsWcagAaNotAaa(): void
	{
		$caps = $this->buildCapabilities(RegionRegistry::COUNTRY_DE)->getCapabilities();
		$this->assertSame('AA', $caps['arbeitszeitcheck']['accessibility']['wcag-level']);
	}

	public function testOfflineStampQueueExposesConfigurableSkewDefault24(): void
	{
		$caps = $this->buildCapabilities(RegionRegistry::COUNTRY_DE)->getCapabilities();
		$queue = $caps['arbeitszeitcheck']['mobile']['offlineStampQueue'];
		$this->assertTrue($queue['enabled']);
		$this->assertSame(32, $queue['maxQueueSize']);
		$this->assertSame(24, $queue['maxOccurredSkewPastHours']);
	}

	public function testOfflineStampQueueReadsConfiguredSkew(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') {
				if ($app === 'arbeitszeitcheck' && $key === 'country') {
					return RegionRegistry::COUNTRY_DE;
				}
				if ($app === 'arbeitszeitcheck' && $key === \OCA\ArbeitszeitCheck\Constants::CONFIG_OFFLINE_STAMP_MAX_PAST_HOURS) {
					return '72';
				}
				return is_string($default) ? $default : (string)$default;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(
			static fn (string $key, string $default = '') => $default
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.7.7');
		$appManager->method('isEnabledForUser')->willReturn(false);
		$appManager->method('isInstalled')->willReturn(false);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$overtimeBank = $this->createMock(OvertimeBankService::class);
		$overtimeBank->method('isEnabled')->willReturn(false);
		$timeCapture = $this->createMock(TimeCaptureMethodService::class);

		$caps = (new Capabilities(
			$config,
			$appConfig,
			$overtimeBank,
			$appManager,
			$userSession,
			$timeCapture,
			new LaborLawProfileFactory($config),
		))->getCapabilities();

		$this->assertSame(72, $caps['arbeitszeitcheck']['mobile']['offlineStampQueue']['maxOccurredSkewPastHours']);
	}

	public function testProjectCheckAvailableUsesInstanceInstallNotCurrentUser(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') {
				if ($app === 'arbeitszeitcheck' && $key === 'country') {
					return RegionRegistry::COUNTRY_DE;
				}
				return is_string($default) ? $default : (string)$default;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(
			static fn (string $key, string $default = '') => $default
		);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.6.0');
		$appManager->method('isInstalled')->with(\OCA\ArbeitszeitCheck\Constants::APP_ID_PROJECTCHECK)->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturn(false);
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$overtimeBank = $this->createMock(OvertimeBankService::class);
		$overtimeBank->method('isEnabled')->willReturn(false);
		$timeCapture = $this->createMock(TimeCaptureMethodService::class);

		$caps = (new Capabilities(
			$config,
			$appConfig,
			$overtimeBank,
			$appManager,
			$userSession,
			$timeCapture,
			new LaborLawProfileFactory($config),
		))->getCapabilities();

		$pc = $caps['arbeitszeitcheck']['mobile']['projectCheck'];
		$this->assertTrue($pc['available']);
		$this->assertFalse($pc['linkingEnabled']);
	}
}
