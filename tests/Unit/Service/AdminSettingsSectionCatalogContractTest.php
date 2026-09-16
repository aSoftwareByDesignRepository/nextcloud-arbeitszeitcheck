<?php

declare(strict_types=1);

/**
 * Catalog ↔ routes ↔ dispatcher ↔ legacy JS for Global settings multipage.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

class AdminSettingsSectionCatalogContractTest extends TestCase
{
	public function testSectionsMatchRouteRequirement(): void
	{
		$req = AdminSettingsSectionCatalog::routeRequirement();
		$this->assertSame(implode('|', AdminSettingsSectionCatalog::SECTIONS), $req);
		$routes = file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$this->assertNotFalse($routes);
		$this->assertStringContainsString("admin#settingsSection", $routes);
		$this->assertStringContainsString('/admin/settings/{section}', $routes);
		$this->assertStringContainsString('AdminSettingsSectionCatalog::routeRequirement()', $routes);
		$this->assertSame(
			'access|compliance|time-recording|time-approvals|exports|outlook-subscription|month-closure|hours|regional|retention|projectcheck',
			$req,
			'routeRequirement must stay the stable allowlist'
		);
	}

	public function testTopicGroupsCoverEverySectionOnce(): void
	{
		$seen = [];
		foreach (AdminSettingsSectionCatalog::SECTION_GROUPS as $groupId => $sections) {
			$this->assertNotSame('', $groupId);
			$this->assertNotSame([], $sections);
			foreach ($sections as $section) {
				$this->assertContains($section, AdminSettingsSectionCatalog::SECTIONS);
				$this->assertArrayNotHasKey($section, $seen, "Section {$section} must appear in exactly one group");
				$seen[$section] = $groupId;
			}
		}
		$this->assertSame(
			AdminSettingsSectionCatalog::SECTIONS,
			array_keys($seen) === AdminSettingsSectionCatalog::SECTIONS
				? AdminSettingsSectionCatalog::SECTIONS
				: array_values(array_intersect(AdminSettingsSectionCatalog::SECTIONS, array_keys($seen)))
		);
		foreach (AdminSettingsSectionCatalog::SECTIONS as $section) {
			$this->assertArrayHasKey($section, $seen);
		}
		$nav = file_get_contents(dirname(__DIR__, 3) . '/templates/common/navigation.php');
		$this->assertNotFalse($nav);
		$this->assertStringNotContainsString('nav-submenu--settings-sections', $nav);
		$this->assertStringContainsString('Open global administration settings', $nav);
		$chipNav = file_get_contents(dirname(__DIR__, 3) . '/templates/common/azc-admin-settings-nav.php');
		$this->assertNotFalse($chipNav);
		$this->assertStringContainsString('azc-settings-nav__group', $chipNav);
		$this->assertStringContainsString('Choose a topic', $chipNav);
	}

	public function testDispatcherLiteralMapCoversEverySection(): void
	{
		$tpl = file_get_contents(dirname(__DIR__, 3) . '/templates/admin-settings.php');
		$this->assertNotFalse($tpl);
		foreach (AdminSettingsSectionCatalog::SECTIONS as $section) {
			if ($section === AdminSettingsSectionCatalog::SECTION_PROJECTCHECK) {
				$this->assertStringContainsString("projectcheck-admin-settings-section.php", $tpl);
				continue;
			}
			$file = AdminSettingsSectionCatalog::SECTION_FILES[$section] ?? '';
			$this->assertNotSame('', $file);
			$this->assertFileExists(dirname(__DIR__, 3) . '/templates/partials/admin-settings/' . $file);
			$this->assertStringContainsString("'$section' => '$file'", $tpl);
		}
		$this->assertStringContainsString('azc-admin-settings-nav.php', $tpl);
		$this->assertStringContainsString('settings_section', $tpl);
		$this->assertStringContainsString('Save this page', $tpl);
		$this->assertStringContainsString('$urlGenerator', $tpl);
		$this->assertStringContainsString("\$includeSection = static function (string \$slug) use (", $tpl);
		$this->assertStringContainsString('$projectCheckAvailable', $tpl);
		$this->assertStringContainsString('$projectCheckEnabledForCurrentUser', $tpl);
		$this->assertStringContainsString('$projectCheckAppsUrl', $tpl);
		$this->assertStringNotContainsString('Jump to settings sections', $tpl);
		$this->assertStringNotContainsString('Save all settings', $tpl);
	}

	public function testLegacyAnchorsCoverFormerJumpTargets(): void
	{
		$expected = [
			'section-access-heading',
			'section-compliance-heading',
			'section-time-capture-heading',
			'section-time-approval-heading',
			'section-export-heading',
			'section-outlook-subscription-heading',
			'section-month-closure-heading',
			'section-hours-heading',
			'section-regional-heading',
			'section-retention-heading',
			'section-projectcheck-heading',
		];
		foreach ($expected as $anchor) {
			$this->assertArrayHasKey($anchor, AdminSettingsSectionCatalog::LEGACY_ANCHORS);
			$this->assertTrue(
				(new AdminSettingsSectionCatalog())->isSection(AdminSettingsSectionCatalog::LEGACY_ANCHORS[$anchor])
			);
		}
	}

	public function testPartialWriteAllowlistsAreScoped(): void
	{
		$catalog = new AdminSettingsSectionCatalog();
		$this->assertNull($catalog->allowedParamKeys(AdminSettingsSectionCatalog::SECTION_ALL));
		$this->assertSame([], $catalog->allowedParamKeys(AdminSettingsSectionCatalog::SECTION_ACCESS));
		$compliance = $catalog->allowedParamKeys(AdminSettingsSectionCatalog::SECTION_COMPLIANCE);
		$this->assertIsArray($compliance);
		$this->assertContains('autoComplianceCheck', $compliance);
		$this->assertContains('timeEntryChangesRequireApproval', $catalog->allowedParamKeys(AdminSettingsSectionCatalog::SECTION_TIME_APPROVALS) ?? []);
		$this->assertNotContains('timeEntryChangesRequireApproval', $catalog->allowedParamKeys(AdminSettingsSectionCatalog::SECTION_TIME_RECORDING) ?? []);
		$this->assertContains('clockStampingEnabled', $catalog->allowedParamKeys(AdminSettingsSectionCatalog::SECTION_TIME_RECORDING) ?? []);
		$this->assertContains('offlineStampMaxPastHours', $catalog->allowedParamKeys(AdminSettingsSectionCatalog::SECTION_TIME_RECORDING) ?? []);

		$this->assertNotContains('retentionPeriod', $compliance);
		$retention = $catalog->allowedParamKeys(AdminSettingsSectionCatalog::SECTION_RETENTION);
		$this->assertSame(['retentionPeriod'], $retention);
		$this->assertSame(
			['projectCheckIntegrationEnabled'],
			$catalog->allowedParamKeys(AdminSettingsSectionCatalog::SECTION_PROJECTCHECK)
		);
	}

	public function testControllerGatesSettingsSectionAndLegacyRedirect(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/AdminController.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('function settings(): RedirectResponse', $src);
		$this->assertStringContainsString('function settingsSection(string $section)', $src);
		$this->assertStringContainsString('admin-settings-legacy-redirect', $src);
		$this->assertStringNotContainsString("'common/settings-jump-nav', 'common/admin-user-picker', 'admin-settings-legacy-redirect'", $src);
		$this->assertStringContainsString('breadcrumbParent', $src);
		$this->assertStringContainsString('allowedParamKeys($scope)', $src);
		$this->assertStringContainsString('$accessScopeOk', $src);
	}

	public function testJsDoesNotForceMissingCheckboxes(): void
	{
		$js = file_get_contents(dirname(__DIR__, 3) . '/js/admin-settings.js');
		$this->assertNotFalse($js);
		$this->assertStringContainsString('function hasField(name)', $js);
		$this->assertStringContainsString('settings_section', $js);
		$this->assertStringContainsString('data.maxDailyHours !== undefined', $js);
		$legacy = file_get_contents(dirname(__DIR__, 3) . '/js/admin-settings-legacy-redirect.js');
		$this->assertNotFalse($legacy);
		$this->assertStringContainsString('adminSettingsPages', $legacy);
		$this->assertStringContainsString('hasOwnProperty.call', $legacy);
	}
}
