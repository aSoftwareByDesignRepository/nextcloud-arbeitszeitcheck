<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\AdminPolicyPagesCatalog;
use PHPUnit\Framework\TestCase;

/**
 * SETTINGS-PAGES-STANDARD drift protection for the admin policy cluster.
 *
 * Catalog is SSoT: routes, templates (chip bar), PAGE_ID map, legacy anchors,
 * and JS payload consumption must stay in lockstep.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
final class AdminPolicyPagesCatalogContractTest extends TestCase
{
	private static function appRoot(): string
	{
		return dirname(__DIR__, 3);
	}

	private static function read(string $relative): string
	{
		$path = self::appRoot() . '/' . $relative;
		self::assertFileExists($path);
		return (string) file_get_contents($path);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private static function routes(): array
	{
		$config = require self::appRoot() . '/appinfo/routes.php';
		self::assertIsArray($config['routes'] ?? null);
		return $config['routes'];
	}

	private static function routeByName(string $name): array
	{
		foreach (self::routes() as $route) {
			if (($route['name'] ?? '') === $name) {
				return $route;
			}
		}
		self::fail("Route '{$name}' is not registered");
	}

	public function testSectionsAreUniqueAndStable(): void
	{
		$sections = AdminPolicyPagesCatalog::SECTIONS;
		self::assertSame($sections, array_values(array_unique($sections)));
		self::assertContains(AdminPolicyPagesCatalog::SECTION_VACATION, $sections);
		self::assertContains(AdminPolicyPagesCatalog::SECTION_VACATION_ENTITLEMENT, $sections);
		self::assertContains(AdminPolicyPagesCatalog::SECTION_OVERTIME, $sections);
		self::assertContains(AdminPolicyPagesCatalog::SECTION_NOTIFICATIONS, $sections);
	}

	public function testPageIdMapCoversExactlyTheCatalogSections(): void
	{
		$mapped = array_values(AdminPolicyPagesCatalog::PAGE_ID_TO_SECTION);
		sort($mapped);
		$expected = AdminPolicyPagesCatalog::SECTIONS;
		$sortedExpected = $expected;
		sort($sortedExpected);
		self::assertSame($sortedExpected, $mapped, 'PAGE_ID_TO_SECTION must cover every catalog section exactly once');
	}

	public function testLegacyAnchorsOnlyPointAtCatalogSections(): void
	{
		foreach (AdminPolicyPagesCatalog::LEGACY_ANCHORS as $anchor => $section) {
			self::assertContains(
				$section,
				AdminPolicyPagesCatalog::SECTIONS,
				"Legacy anchor #{$anchor} targets unknown section '{$section}'",
			);
			self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $anchor);
		}
	}

	public function testRoutesExistForEveryCatalogSection(): void
	{
		$routeNames = [
			AdminPolicyPagesCatalog::SECTION_VACATION => 'admin#vacationRules',
			AdminPolicyPagesCatalog::SECTION_VACATION_ENTITLEMENT => 'admin#vacationLayers',
			AdminPolicyPagesCatalog::SECTION_OVERTIME => 'admin#overtimeSettings',
			AdminPolicyPagesCatalog::SECTION_PAYOUTS => 'overtime_payout#index',
			AdminPolicyPagesCatalog::SECTION_PAYOUT_AUDIT => 'overtime_payout#auditIndex',
			AdminPolicyPagesCatalog::SECTION_NOTIFICATIONS => 'admin#notifications',
		];
		$expectedUrls = [
			AdminPolicyPagesCatalog::SECTION_VACATION => '/admin/vacation-rules',
			AdminPolicyPagesCatalog::SECTION_VACATION_ENTITLEMENT => '/admin/vacation-layers',
			AdminPolicyPagesCatalog::SECTION_OVERTIME => '/admin/overtime-settings',
			AdminPolicyPagesCatalog::SECTION_PAYOUTS => '/admin/overtime-payouts',
			AdminPolicyPagesCatalog::SECTION_PAYOUT_AUDIT => '/admin/overtime-payout-audit',
			AdminPolicyPagesCatalog::SECTION_NOTIFICATIONS => '/admin/notifications',
		];
		foreach (AdminPolicyPagesCatalog::SECTIONS as $section) {
			$route = self::routeByName($routeNames[$section]);
			self::assertSame('GET', $route['verb']);
			self::assertSame($expectedUrls[$section], $route['url']);
		}
	}

	public function testShellInjectsPolicyPagesViaTrait(): void
	{
		$trait = self::read('lib/Controller/PageShellTrait.php');
		self::assertStringContainsString('withPolicyPagesNav', $trait);
		self::assertStringContainsString('AdminPolicyPagesCatalog', $trait);
		self::assertStringContainsString("\$params['policyPages']", $trait);
		self::assertStringContainsString('return $this->withPolicyPagesNav($params, $pageId);', $trait);
	}

	public function testEveryPolicyTemplateIncludesChipBar(): void
	{
		$templates = [
			'templates/admin-notifications.php',
			'templates/admin-overtime-settings.php',
			'templates/admin-vacation-rules.php',
			'templates/admin-vacation-layers.php',
			'templates/admin-overtime-payouts.php',
			'templates/admin-overtime-payout-audit.php',
		];
		foreach ($templates as $tpl) {
			$src = self::read($tpl);
			self::assertStringContainsString(
				"azc-policy-pages-nav.php",
				$src,
				"{$tpl} must include the SETTINGS-PAGES-STANDARD chip bar",
			);
		}
		$nav = self::read('templates/common/azc-policy-pages-nav.php');
		self::assertStringContainsString('azc-settings-nav', $nav);
		self::assertStringContainsString('azc-settings-nav__link', $nav);
		self::assertStringContainsString('azc-settings-nav__group', $nav);
		self::assertStringContainsString('azc-settings-nav__title', $nav);
		self::assertStringContainsString('Choose a topic', $nav);
		self::assertStringContainsString('id="azc-policy-pages"', $nav);
		self::assertStringContainsString('aria-current="page"', $nav);
		self::assertStringContainsString("if (\$href === '' || \$href === '#' || \$sectionLabel === '')", $nav);
		self::assertStringNotContainsString('azc-jump-nav', $nav);
	}

	public function testPolicyTemplatesDoNotIncludeJumpNav(): void
	{
		$templates = [
			'templates/admin-notifications.php',
			'templates/admin-overtime-settings.php',
			'templates/admin-vacation-rules.php',
			'templates/admin-vacation-layers.php',
		];
		foreach ($templates as $tpl) {
			$src = self::read($tpl);
			self::assertStringNotContainsString(
				'azc-jump-nav.php',
				$src,
				"{$tpl} must not stack jump-nav under topic chips (menu-in-menu)",
			);
		}
	}

	public function testNotificationsControllerRegistersLegacyRedirectScript(): void
	{
		$controller = self::read('lib/Controller/AdminController.php');
		self::assertMatchesRegularExpression(
			"/function notifications\(\).*?admin-policy-legacy-redirect/s",
			$controller,
			'Notifications page must load admin-policy-legacy-redirect.js',
		);
	}

	public function testLegacyRedirectJsIsFailClosedAndUsesPayloadAnchors(): void
	{
		$js = self::read('js/admin-policy-legacy-redirect.js');
		self::assertStringContainsString('Object.prototype.hasOwnProperty.call', $js);
		self::assertStringContainsString('legacyAnchors', $js);
		self::assertStringContainsString('location.replace', $js);
		self::assertStringContainsString('AdminPolicyLegacyRedirect', $js);
		self::assertStringNotContainsString('encodeURIComponent(hash)', $js, 'Hash must stay as allowlisted id text');
	}

	/**
	 * Owning surfaces for legacy kitchen-sink anchors after the IA split.
	 *
	 * @return array<string, list<string>>
	 */
	private static function sectionHaystacks(): array
	{
		return [
			AdminPolicyPagesCatalog::SECTION_VACATION => [
				'templates/admin-vacation-rules.php',
				'templates/partials/admin-policy-vacation.php',
			],
			AdminPolicyPagesCatalog::SECTION_VACATION_ENTITLEMENT => [
				'templates/admin-vacation-layers.php',
			],
			AdminPolicyPagesCatalog::SECTION_OVERTIME => [
				'templates/admin-overtime-settings.php',
				'templates/partials/admin-policy-overtime-bank.php',
				'templates/partials/admin-policy-hour-premiums.php',
			],
			AdminPolicyPagesCatalog::SECTION_NOTIFICATIONS => [
				'templates/admin-notifications.php',
				'templates/partials/admin-policy-clock-reminders.php',
				'templates/partials/admin-policy-calendar-email.php',
				'templates/partials/admin-policy-overtime-alerts.php',
				'templates/partials/admin-policy-hr-office.php',
			],
		];
	}

	public function testEveryLegacyAnchorStillExistsOnOwningPage(): void
	{
		$haystacks = self::sectionHaystacks();
		foreach (AdminPolicyPagesCatalog::LEGACY_ANCHORS as $anchor => $section) {
			self::assertArrayHasKey($section, $haystacks, "No haystack mapping for section {$section}");
			$blob = '';
			foreach ($haystacks[$section] as $file) {
				$blob .= self::read($file);
			}
			self::assertMatchesRegularExpression(
				'/\sid="' . preg_quote($anchor, '/') . '"/',
				$blob,
				"Anchor #{$anchor} must exist on the '{$section}' page so forwarded fragments still scroll",
			);
		}
	}

	public function testChipBarCssMeetsTouchAndContrastContracts(): void
	{
		$css = self::read('css/admin-notifications.css');
		self::assertStringContainsString('.azc-settings-nav', $css);
		self::assertStringContainsString('.azc-settings-nav__link', $css);
		self::assertMatchesRegularExpression(
			'/\.azc-settings-nav__link[^{]*\{[^}]*min-height:\s*var\(--azc-touch/s',
			$css,
		);
		self::assertStringContainsString(':focus-visible', $css);
		self::assertStringContainsString('aria-current="page"', $css);
		self::assertStringContainsString('var(--azc-text, var(--color-main-text))', $css);
		self::assertStringContainsString('azc-admin-policy-form__actions--sticky', $css);
		self::assertStringContainsString('azc-btn--touch', $css);
		// Multi-page rewrite footgun: never disable the whole page shell.
		self::assertDoesNotMatchRegularExpression(
			'/#app-content\.azc-app--admin-notifications,\s*#app-content\.azc-app--admin-overtime-settings,\s*#app-content\.azc-app--admin-vacation-layers\s*\{[^}]*pointer-events:\s*none/s',
			$css,
			'Bare page roots must never get pointer-events:none',
		);
		self::assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-notifications\s+\[data-settings-disabled=\'true\'\]/',
			$css,
			'Disabled-state pointer lock must be scoped to [data-settings-disabled]',
		);
	}

	public function testSidebarUsesSinglePolicyEntryNotNestedSections(): void
	{
		$nav = self::read('templates/common/navigation.php');
		self::assertStringContainsString('admin-nav-group-policy', $nav);
		self::assertStringContainsString('Policy settings', $nav);
		self::assertStringContainsString('admin.vacationRules', $nav);
		self::assertStringContainsString('$isAdminPolicyPage', $nav);
		// Topics live in the chip bar — not as nested sidebar children.
		self::assertStringNotContainsString('admin-nav-group-alerts', $nav);
		self::assertStringNotContainsString('admin-nav-group-leave', $nav);
		self::assertStringNotContainsString('admin-nav-group-overtime', $nav);
		self::assertStringNotContainsString('admin.overtimeSettings', $nav);
		self::assertStringNotContainsString('overtime_payout.index', $nav);
		self::assertStringNotContainsString('admin.notifications', $nav);
	}

	public function testCatalogExposesGroupedChipPayload(): void
	{
		self::assertArrayHasKey('leave', AdminPolicyPagesCatalog::SECTION_GROUPS);
		self::assertArrayHasKey('overtime', AdminPolicyPagesCatalog::SECTION_GROUPS);
		self::assertArrayHasKey('alerts', AdminPolicyPagesCatalog::SECTION_GROUPS);
		$flat = [];
		foreach (AdminPolicyPagesCatalog::SECTION_GROUPS as $sections) {
			foreach ($sections as $section) {
				$flat[] = $section;
			}
		}
		sort($flat);
		$expected = AdminPolicyPagesCatalog::SECTIONS;
		$sorted = $expected;
		sort($sorted);
		self::assertSame($sorted, $flat, 'SECTION_GROUPS must cover every catalog section exactly once');
		$trait = self::read('lib/Controller/PageShellTrait.php');
		self::assertStringContainsString('breadcrumbParent', $trait);
		self::assertStringContainsString('Policy settings', $trait);
	}

	public function testNotificationsPageDoesNotHostBankOrPremiums(): void
	{
		$src = self::read('templates/admin-notifications.php');
		self::assertStringNotContainsString('admin-policy-overtime-bank.php', $src);
		self::assertStringNotContainsString('admin-policy-hour-premiums.php', $src);
		self::assertStringNotContainsString('admin-policy-vacation.php', $src);
	}

	public function testLegacyRedirectTargetResolvesAnchorOrNull(): void
	{
		$catalog = new AdminPolicyPagesCatalog();
		$urls = $this->createMock(\OCP\IURLGenerator::class);
		$urls->method('linkToRoute')->willReturnCallback(
			static fn (string $route): string => '/apps/x/' . $route
		);

		// unknown anchor -> null (no redirect)
		$this->assertNull($catalog->legacyRedirectTarget($urls, 'bogus-anchor'));

		// anchor on a fragment-keeping section -> base + #anchor
		$withFrag = $catalog->legacyRedirectTarget(
			$urls,
			array_key_first(array_filter(
				AdminPolicyPagesCatalog::LEGACY_ANCHORS,
				static fn (string $s): bool => $s === AdminPolicyPagesCatalog::SECTION_NOTIFICATIONS
			))
		);
		$this->assertSame('/apps/x/arbeitszeitcheck.admin.notifications#' . 'block-clock-reminders-heading', $withFrag);

		// anchor on a non-fragment section -> bare base
		$bareAnchor = array_key_first(array_filter(
			AdminPolicyPagesCatalog::LEGACY_ANCHORS,
			static fn (string $s): bool => !in_array($s, [
				AdminPolicyPagesCatalog::SECTION_NOTIFICATIONS,
				AdminPolicyPagesCatalog::SECTION_OVERTIME,
				AdminPolicyPagesCatalog::SECTION_VACATION,
				AdminPolicyPagesCatalog::SECTION_VACATION_ENTITLEMENT,
			], true)
		));
		if ($bareAnchor !== null) {
			$this->assertStringNotContainsString('#', $catalog->legacyRedirectTarget($urls, $bareAnchor));
		}
	}
}