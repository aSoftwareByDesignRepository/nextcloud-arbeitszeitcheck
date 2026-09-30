<?php

declare(strict_types=1);

/**
 * Catalog ↔ routes ↔ dispatcher ↔ legacy JS for employee My settings multipage.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\EmployeeSettingsSectionCatalog;
use PHPUnit\Framework\TestCase;

class EmployeeSettingsSectionCatalogContractTest extends TestCase
{
	public function testSectionsMatchRouteRequirement(): void
	{
		$req = EmployeeSettingsSectionCatalog::routeRequirement();
		$this->assertSame(implode('|', EmployeeSettingsSectionCatalog::SECTIONS), $req);
		$routes = file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$this->assertNotFalse($routes);
		$this->assertStringContainsString('page#settingsSection', $routes);
		$this->assertStringContainsString('/settings/{section}', $routes);
		$this->assertStringContainsString('EmployeeSettingsSectionCatalog::routeRequirement()', $routes);
		$this->assertSame('breaks|notifications|data-privacy|about', $req);
	}

	public function testTopicGroupsCoverEverySectionOnce(): void
	{
		$seen = [];
		foreach (EmployeeSettingsSectionCatalog::SECTION_GROUPS as $groupId => $sections) {
			$this->assertNotSame('', $groupId);
			$this->assertNotSame([], $sections);
			foreach ($sections as $section) {
				$this->assertContains($section, EmployeeSettingsSectionCatalog::SECTIONS);
				$this->assertArrayNotHasKey($section, $seen, "Section {$section} must appear in exactly one group");
				$seen[$section] = $groupId;
			}
		}
		foreach (EmployeeSettingsSectionCatalog::SECTIONS as $section) {
			$this->assertArrayHasKey($section, $seen);
		}
		$nav = file_get_contents(dirname(__DIR__, 3) . '/templates/common/navigation.php');
		$this->assertNotFalse($nav);
		$this->assertStringContainsString('My settings', $nav);
		$this->assertStringContainsString('/admin/settings', $nav);
		$this->assertStringContainsString("preg_match('#/apps/arbeitszeitcheck/settings", $nav);
		$chipNav = file_get_contents(dirname(__DIR__, 3) . '/templates/common/azc-employee-settings-nav.php');
		$this->assertNotFalse($chipNav);
		$this->assertStringContainsString('azc-settings-nav__group', $chipNav);
		$this->assertStringContainsString('Choose a topic', $chipNav);
	}

	public function testDispatcherLiteralMapCoversEverySection(): void
	{
		$tpl = file_get_contents(dirname(__DIR__, 3) . '/templates/settings.php');
		$this->assertNotFalse($tpl);
		$this->assertStringContainsString('EmployeeSettingsSectionCatalog::SECTION_FILES', $tpl);
		$this->assertStringContainsString('azc-employee-settings-nav.php', $tpl);
		$this->assertStringContainsString("partials/employee-settings/", $tpl);
		$this->assertStringNotContainsString('Cancel and go back to dashboard', $tpl);
		$this->assertStringNotContainsString('>Cancel<', $tpl);
		foreach (EmployeeSettingsSectionCatalog::SECTION_FILES as $section => $file) {
			$this->assertContains($section, EmployeeSettingsSectionCatalog::SECTIONS);
			$this->assertFileExists(dirname(__DIR__, 3) . '/templates/partials/employee-settings/' . $file);
		}
		$breaks = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/partials/employee-settings/breaks.php');
		$notifications = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/partials/employee-settings/notifications.php');
		$this->assertStringContainsString('Save this page', $breaks);
		$this->assertStringContainsString('Save this page', $notifications);
		$this->assertStringNotContainsString('Cancel', $breaks);
		$this->assertStringNotContainsString('Cancel', $notifications);
	}

	public function testLegacyAnchorsCoverFormerMegaPageTargets(): void
	{
		$expected = [
			'settings-sections-heading' => EmployeeSettingsSectionCatalog::SECTION_BREAKS,
			'auto-break-calculation' => EmployeeSettingsSectionCatalog::SECTION_BREAKS,
			'settings-model-heading' => EmployeeSettingsSectionCatalog::SECTION_BREAKS,
			'settings-notifications-heading' => EmployeeSettingsSectionCatalog::SECTION_NOTIFICATIONS,
			'settings-data-privacy' => EmployeeSettingsSectionCatalog::SECTION_DATA_PRIVACY,
			'settings-privacy-heading' => EmployeeSettingsSectionCatalog::SECTION_DATA_PRIVACY,
			'settings-compliance-heading' => EmployeeSettingsSectionCatalog::SECTION_ABOUT,
			'settings-version-heading' => EmployeeSettingsSectionCatalog::SECTION_ABOUT,
		];
		foreach ($expected as $anchor => $section) {
			$this->assertArrayHasKey($anchor, EmployeeSettingsSectionCatalog::LEGACY_ANCHORS);
			$this->assertSame($section, EmployeeSettingsSectionCatalog::LEGACY_ANCHORS[$anchor]);
		}
	}

	public function testControllerRedirectsAndRegistersLegacyScript(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/PageController.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('function settings(): RedirectResponse', $src);
		$this->assertStringContainsString('function settingsSection(string $section)', $src);
		$this->assertStringContainsString('employee-settings-legacy-redirect', $src);
		$this->assertStringContainsString('breadcrumbParent', $src);
		$this->assertStringContainsString('EmployeeSettingsSectionCatalog', $src);
		$this->assertStringContainsString('NotFoundResponse', $src);
	}

	public function testCssHasNoHardcodedPrimaryHex(): void
	{
		$css = file_get_contents(dirname(__DIR__, 3) . '/css/settings.css');
		$this->assertNotFalse($css);
		$this->assertStringNotContainsString('#0082c9', $css);
		$this->assertStringContainsString('azc-settings-nav__link', $css);
	}

	public function testPersonalPanelDeepLinksToCatalogSections(): void
	{
		$tpl = file_get_contents(dirname(__DIR__, 3) . '/templates/personal-settings.php');
		$this->assertNotFalse($tpl);
		$this->assertStringContainsString('EmployeeSettingsSectionCatalog', $tpl);
		$this->assertStringContainsString('SECTION_DATA_PRIVACY', $tpl);
		$this->assertStringContainsString('DEFAULT_SECTION', $tpl);
	}

	public function testDefaultSectionIsBreaks(): void
	{
		$catalog = new EmployeeSettingsSectionCatalog();
		$this->assertSame(EmployeeSettingsSectionCatalog::DEFAULT_SECTION, $catalog->defaultSection());
		$this->assertTrue($catalog->isSection($catalog->defaultSection()));
	}

	public function testLegacyRedirectTargetMapsAnchorsAndRejectsUnknown(): void
	{
		$urlGenerator = $this->createMock(\OCP\IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static fn (string $route, array $params): string => '/settings/' . $params['section']
		);
		$catalog = new EmployeeSettingsSectionCatalog();
		$this->assertSame(
			'/settings/breaks#auto-break-calculation',
			$catalog->legacyRedirectTarget($urlGenerator, 'auto-break-calculation')
		);
		$this->assertSame(
			'/settings/about#settings-version-heading',
			$catalog->legacyRedirectTarget($urlGenerator, 'settings-version-heading')
		);
		$this->assertNull($catalog->legacyRedirectTarget($urlGenerator, 'never-an-anchor'));
	}
}