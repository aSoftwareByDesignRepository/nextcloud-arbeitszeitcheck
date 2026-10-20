<?php

declare(strict_types=1);

/**
 * NC AdminSettings must stay in DACH parity with in-app AdminController settings.
 *
 * Regression: omitting weeklyAbsoluteMaxHours caused CH 50h → 45h on save from
 * Administration → ArbeitszeitCheck.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Settings;

use OCA\ArbeitszeitCheck\Settings\AdminSettings;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class AdminSettingsDachParityTest extends TestCase
{
	/**
	 * @param array<string, string> $store
	 */
	private function buildSettings(array $store): AdminSettings
	{
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(
			static function (string $key, string $default = '') use (&$store): string {
				return array_key_exists($key, $store) ? (string)$store[$key] : $default;
			}
		);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);

		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('search')->willReturn([]);
		$groupManager->method('get')->willReturn(null);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isEnabledForUser')->willReturn(false);
		$appManager->method('isInstalled')->willReturn(false);
		$appManager->method('getAppRestriction')->willReturn([]);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/apps/arbeitszeitcheck/admin/settings');

		return new AdminSettings($appConfig, $l10n, $groupManager, $appManager, $urlGenerator, $this->createMock(\OCP\IUserManager::class), $this->createMock(\OCA\ArbeitszeitCheck\Service\PermissionService::class));
	}

	public function testSwissFiftyHourCapSurvivesNcAdminSettingsPayload(): void
	{
		$settings = $this->buildSettings([
			'country' => RegionRegistry::COUNTRY_CH,
			'german_state' => 'CH-ZH',
			LaborLawProfileFactory::CONFIG_KEY_WEEKLY_ABSOLUTE_MAX => '50',
		]);

		$response = $settings->getForm();
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$params = $response->getParams();
		$payload = $params['settings'] ?? [];

		$this->assertSame(RegionRegistry::COUNTRY_CH, $payload['country']);
		$this->assertSame('CH-ZH', $payload['germanState']);
		$this->assertSame(50, $payload['weeklyAbsoluteMaxHours'], 'CH 50h must not default to 45 in NC Admin shell');
		$this->assertSame(20, $payload['vacationDaysSuggestion']);
	}

	public function testInvalidSwissWeeklyCapFallsBackToFortyFive(): void
	{
		$settings = $this->buildSettings([
			'country' => RegionRegistry::COUNTRY_CH,
			'german_state' => 'CH-BE',
			LaborLawProfileFactory::CONFIG_KEY_WEEKLY_ABSOLUTE_MAX => '48',
		]);

		$payload = $settings->getForm()->getParams()['settings'];
		$this->assertSame(45, $payload['weeklyAbsoluteMaxHours']);
	}

	public function testAustrianPayloadUsesCountryAwareDefaults(): void
	{
		$settings = $this->buildSettings([
			'country' => RegionRegistry::COUNTRY_AT,
			// Intentionally omit german_state → must resolve to AT-W, not NW.
		]);

		$payload = $settings->getForm()->getParams()['settings'];
		$this->assertSame(RegionRegistry::COUNTRY_AT, $payload['country']);
		$this->assertSame('AT-W', $payload['germanState']);
		$this->assertSame(45, $payload['weeklyAbsoluteMaxHours'], 'Non-CH still exposes the field for template binding');
		$this->assertSame(25, $payload['vacationDaysSuggestion']);
	}

	public function testOrphanGermanRegionUnderAustriaIsHealedOnRead(): void
	{
		$settings = $this->buildSettings([
			'country' => RegionRegistry::COUNTRY_AT,
			'german_state' => 'NW', // orphan pair from a race / legacy
		]);

		$payload = $settings->getForm()->getParams()['settings'];
		$this->assertSame('AT-W', $payload['germanState']);
	}

	public function testBreakAutoFallbackAndApprovalFlagsArePresent(): void
	{
		$settings = $this->buildSettings([
			'break_auto_fallback_enabled' => '0',
			'break_auto_fallback_minutes' => '240',
			'break_auto_fallback_flex_window_start' => '10',
			'break_auto_fallback_flex_window_end' => '15',
			'time_entry_changes_require_approval' => '1',
			'manual_time_entries_require_approval' => '1',
		]);

		$payload = $settings->getForm()->getParams()['settings'];
		$this->assertFalse($payload['breakAutoFallbackEnabled']);
		$this->assertSame(240, $payload['breakAutoFallbackMinutes']);
		$this->assertSame(10, $payload['breakAutoFallbackFlexWindowStart']);
		$this->assertSame(15, $payload['breakAutoFallbackFlexWindowEnd']);
		$this->assertTrue($payload['timeEntryChangesRequireApproval']);
		$this->assertTrue($payload['manualTimeEntriesRequireApproval']);
	}

	public function testSourceRegistersCountryRegionStylesheet(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Settings/AdminSettings.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString("'common/country-region'", $src);
		$this->assertStringContainsString('weeklyAbsoluteMaxHours', $src);
		$this->assertStringContainsString('vacationDaysSuggestion', $src);
		$this->assertStringContainsString('breakAutoFallbackEnabled', $src);
		$this->assertStringContainsString('timeEntryChangesRequireApproval', $src);
		$this->assertStringContainsString('isInstalled', $src);
		$this->assertStringContainsString('projectCheckEnabledForCurrentUser', $src);
	}

	public function testNcAdminFormExposesInstanceLevelProjectCheckAvailability(): void
	{
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getAppValueString')->willReturnCallback(
			static fn (string $key, string $default = '') => $default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->method('search')->willReturn([]);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->with('projectcheck')->willReturn(true);
		$appManager->method('isEnabledForUser')->willReturn(false);
		$appManager->method('getAppRestriction')->willReturn([]);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturn('/settings/apps');

		$settings = new AdminSettings(
			$appConfig,
			$l10n,
			$groupManager,
			$appManager,
			$urlGenerator,
			$this->createMock(\OCP\IUserManager::class),
			$this->createMock(\OCA\ArbeitszeitCheck\Service\PermissionService::class),
		);
		$params = $settings->getForm()->getParams();
		$this->assertTrue($params['projectCheckAvailable']);
		$this->assertFalse($params['projectCheckEnabledForCurrentUser']);
		$this->assertFalse($params['settings']['projectCheckIntegrationEnabled']);
		$this->assertSame('/settings/apps', $params['projectCheckAppsUrl']);
	}
}
