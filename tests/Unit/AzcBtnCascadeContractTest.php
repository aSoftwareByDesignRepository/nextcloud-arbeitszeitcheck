<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Contract: azc-btn anchors keep solid fills (not demoted to underlined links).
 *
 * Historic bug: #app-content-wrapper a rules (base/typography/a11y) were more
 * specific than .azc-btn--primary and wiped background + forced underline.
 * Follow-up: NC personal-settings panel and dashboard desklet sat outside the
 * hardened scopes / lacked button CSS entirely.
 */
final class AzcBtnCascadeContractTest extends TestCase {
	public function testLinkResetsExcludeAzcBtn(): void {
		$root = dirname(__DIR__, 2);
		$files = [
			$root . '/css/common/base.css',
			$root . '/css/common/typography.css',
			$root . '/css/common/accessibility.css',
		];
		foreach ($files as $path) {
			$css = (string)file_get_contents($path);
			self::assertStringContainsString('a:not(.btn):not(.azc-btn)', $css, $path);
			self::assertDoesNotMatchRegularExpression(
				'/#app-content-wrapper a\s*\{/',
				$css,
				'Unscoped wrapper link rule must not remain in ' . $path
			);
		}
	}

	public function testAzcBtnPrimaryHasSolidFillAndHover(): void {
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		self::assertStringContainsString('#app-content.azc-app .azc-btn--primary', $css);
		self::assertStringContainsString('#app-content-wrapper .azc-btn--primary', $css);
		self::assertStringContainsString('color-primary-element-text', $css);
		self::assertStringContainsString('.azc-btn--primary:hover', $css);
		self::assertStringContainsString('.azc-btn--secondary:hover', $css);
		self::assertStringContainsString('text-decoration: none !important', $css);
		self::assertMatchesRegularExpression(
			'/background-color:\s*var\(--color-primary-element[^)]*\)\s*!important/',
			$css,
			'Primary fill must use !important so link/theme cascades cannot flatten CTAs'
		);
		self::assertMatchesRegularExpression(
			'/#app-content\.azc-app \.azc-btn--secondary[\s\S]*?background-color:[^;]*!important/',
			$css,
			'Secondary fill must use !important against link demotion'
		);
		// Base buttons must keep the 44px WCAG touch floor — expressed via the
		// --azc-touch design token (falls back to 44px when tokens.css is not
		// loaded, e.g. inside the dashboard desklet context).
		self::assertMatchesRegularExpression(
			'/\.azc-btn,[\s\S]*?\{[\s\S]*?min-height:\s*var\(--azc-touch,\s*44px\)/',
			$css,
			'Base .azc-btn must consume the 44px --azc-touch floor'
		);
		$tokens = (string)file_get_contents(dirname(__DIR__, 2) . '/css/common/tokens.css');
		self::assertMatchesRegularExpression(
			'/--azc-touch:\s*44px\s*;/',
			$tokens,
			'--azc-touch token must resolve to the 44px WCAG touch floor'
		);
	}

	public function testNcPersonalSettingsPanelIsHardenedLikeAppShell(): void {
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		self::assertStringContainsString('.azc-nc-settings-panel .azc-btn', $css);
		self::assertStringContainsString('.azc-nc-settings-panel .azc-btn--primary', $css);
		self::assertStringContainsString('.azc-nc-settings-panel .azc-btn--secondary', $css);
		self::assertMatchesRegularExpression(
			'/\.azc-nc-settings-panel \.azc-btn--primary[\s\S]*?background-color:[^;]*!important/',
			$css,
			'Personal settings CTAs live outside #app-content and need their own solid fills'
		);

		$template = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/personal-settings.php');
		self::assertStringContainsString('azc-nc-settings-panel', $template);
		self::assertStringContainsString('azc-btn azc-btn--primary', $template);
		self::assertStringContainsString('azc-btn azc-btn--secondary', $template);
	}

	public function testDeskletUsesAzcBtnTaxonomyWithScopedFills(): void {
		$root = dirname(__DIR__, 2);
		$partial = (string)file_get_contents($root . '/templates/partials/dashboard-desklet-workspace.php');
		self::assertStringContainsString('azc-btn azc-btn--primary', $partial);
		self::assertStringContainsString('azc-btn azc-btn--secondary', $partial);
		self::assertStringContainsString('azc-btn azc-btn--danger', $partial);
		self::assertStringNotContainsString('btn-primary', $partial);
		self::assertStringNotContainsString('btn-secondary', $partial);
		self::assertStringNotContainsString('btn-danger', $partial);

		$css = (string)file_get_contents($root . '/css/dashboard-widgets.css');
		self::assertStringContainsString('.dz-workspace .azc-btn--primary', $css);
		$matched = preg_match(
			'/\.dz-workspace \.azc-btn--primary,\s*\n\.dz-workspace \.btn-primary,\s*\n\.dz-workspace \.btn--primary \{(?P<body>[\s\S]*?)\n\}/',
			$css,
			$primaryBlock
		);
		self::assertSame(1, $matched, 'Desklet primary CTA block must exist in dashboard-widgets.css');
		self::assertStringContainsString(
			'background-color: var(--color-primary-element) !important;',
			$primaryBlock['body'] ?? '',
			'Desklet does not load app.css — base primary fill must live in dashboard-widgets.css'
		);
		self::assertStringContainsString('border-color: var(--color-primary-element) !important;', $primaryBlock['body'] ?? '');
		self::assertStringContainsString('color: var(--color-primary-element-text) !important;', $primaryBlock['body'] ?? '');
		self::assertMatchesRegularExpression(
			'/\.dz-workspace \.azc-btn--secondary,\s*\n\.dz-workspace \.btn-secondary,\s*\n\.dz-workspace \.btn--secondary \{\s*\n\tbackground-color:[^;]*!important;/',
			$css
		);
		self::assertMatchesRegularExpression(
			'/\.dz-project-picker__select\s*\{[^}]*min-height:\s*44px/s',
			$css,
			'Project picker must keep WCAG-friendly 44px touch targets'
		);
		self::assertStringContainsString('.dz-capture-notice--warning', $css);
		self::assertStringContainsString('.dz-capture-notice--neutral', $css);
		self::assertStringContainsString('common/desklet-actions', (string)file_get_contents(
			$root . '/lib/Support/DashboardWidgetAssetBootstrap.php'
		));
	}

	public function testSmallAliasMirrorsSmSizing(): void {
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');
		self::assertStringContainsString('.azc-btn--small', $css);
		// --small is a deliberate alias of --sm (kiosk/license): both keep the
		// 44px --azc-touch floor and identical compact chrome.
		foreach (['--sm', '--small'] as $variant) {
			self::assertMatchesRegularExpression(
				'/\.azc-btn' . $variant . '\s*\{[\s\S]*?min-height:\s*var\(--azc-touch,\s*44px\)[\s\S]*?min-width:\s*var\(--azc-touch,\s*44px\)/',
				$css,
				'.azc-btn' . $variant . ' must keep the 44px touch floor like --sm'
			);
		}
	}

	public function testPrintStylesDoNotUnderlineButtonAnchors(): void {
		$root = dirname(__DIR__, 2);
		$base = (string)file_get_contents($root . '/css/common/base.css');
		$typo = (string)file_get_contents($root . '/css/common/typography.css');
		self::assertMatchesRegularExpression(
			'/@media print[\s\S]*a:not\(\.btn\):not\(\.azc-btn\),\s*\n\s*a:not\(\.btn\):not\(\.azc-btn\):visited \{/',
			$base,
			'Print must not underline button anchors (base.css)'
		);
		self::assertStringContainsString("a.azc-btn {\n    text-decoration: none !important;", $base);
		self::assertMatchesRegularExpression(
			'/@media print[\s\S]*a:not\(\.btn\):not\(\.azc-btn\) \{\s*\n\s*color:[^;]+!important;\s*\n\s*text-decoration:\s*underline !important;/',
			$typo,
			'Print must not underline button anchors (typography.css)'
		);
		self::assertStringContainsString("a.azc-btn {\n    text-decoration: none !important;", $typo);
	}

	public function testKioskPinActionsUseButtonVariants(): void {
		$kiosk = (string)file_get_contents(dirname(__DIR__, 2) . '/templates/admin-kiosk.php');
		self::assertStringContainsString('id="azc-kiosk-pin-email" class="azc-btn azc-btn--secondary"', $kiosk);
		self::assertStringContainsString('id="azc-kiosk-pin-share" class="azc-btn azc-btn--secondary"', $kiosk);
		self::assertStringContainsString('id="azc-kiosk-pin-close" class="azc-btn azc-btn--secondary"', $kiosk);
	}
}
