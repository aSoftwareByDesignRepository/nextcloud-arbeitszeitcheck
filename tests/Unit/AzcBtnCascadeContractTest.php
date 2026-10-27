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

	public function testSecondaryFillIsPrimaryLightOnEverySurface(): void {
		$root = dirname(__DIR__, 2);
		$app = (string)file_get_contents($root . '/css/app.css');
		$block = self::ruleBody($app, '#app-content.azc-app .azc-btn--secondary,');
		self::assertSame(
			"background-color: var(--color-primary-element-light, var(--color-primary-light)) !important;\n"
			. "\tborder-color: var(--color-primary-element) !important;\n"
			. "\tcolor: var(--azc-text, var(--color-main-text)) !important;",
			trim($block)
		);
		self::assertStringNotContainsString('primary-element-light-text', $block);
		self::assertStringContainsString('.azc-modal-backdrop .btn--secondary,', $app);
		self::assertStringContainsString('.azc-dialog .btn--secondary,', $app);

		$secondaryAt = strpos($app, ".azc-app .btn--secondary,");
		$dangerAt = strpos($app, ".azc-app .btn--danger,");
		self::assertNotFalse($secondaryAt);
		self::assertNotFalse($dangerAt);
		self::assertLessThan($dangerAt, $secondaryAt, 'Danger must follow secondary so a dual-class button stays danger');
		self::assertStringContainsString('background-color: var(--azc-danger-fill) !important;', $app);

		$hover = self::ruleBody($app, '#app-content.azc-app .azc-btn--secondary:hover,');
		self::assertStringContainsString(
			'background-color: var(--color-primary-element-light-hover, var(--color-primary-light-hover)) !important;',
			$hover
		);
		self::assertStringContainsString('color: var(--azc-text, var(--color-main-text)) !important;', $hover);

		$grey = 'color-mix(in srgb, var(--color-main-background) 82%, var(--color-main-text) 18%)';
		$greyHover = 'color-mix(in srgb, var(--color-main-background) 70%, var(--color-main-text) 30%)';
		foreach ([
			'css/app.css',
			'css/dashboard-widgets.css',
			'css/admin-support-us.css',
			'css/common/components.css',
			'css/time-entries.css',
		] as $rel) {
			$css = (string)file_get_contents($root . '/' . $rel);
			self::assertStringNotContainsString($grey, $css, $rel);
			self::assertStringNotContainsString($greyHover, $css, $rel);
		}

		$components = (string)file_get_contents($root . '/css/common/components.css');
		$librarySecondary = self::ruleBody($components, ".btn--secondary {");
		self::assertStringContainsString(
			'background-color: var(--color-primary-element-light, var(--color-primary-light));',
			$librarySecondary
		);
		self::assertStringNotContainsString('background-dark', $librarySecondary);
		self::assertStringContainsString(
			".button.secondary,\n.btn-secondary {\n  background-color: var(--color-primary-element-light, var(--color-primary-light));",
			$components
		);

		$desk = (string)file_get_contents($root . '/css/dashboard-widgets.css');
		self::assertStringContainsString(
			"background-color: var(--color-primary-element-light, var(--color-primary-light)) !important;",
			$desk
		);
		self::assertStringContainsString('border-color: var(--color-primary-element) !important;', self::ruleBody(
			$desk,
			'.dz-workspace .azc-btn--secondary,'
		));

		$support = (string)file_get_contents($root . '/css/admin-support-us.css');
		self::assertStringContainsString(
			"a.azc-support-us__cta.azc-btn--secondary {\n\tbackground-color: var(--color-primary-element-light, var(--color-primary-light)) !important;",
			$support
		);

		$timeEntries = (string)file_get_contents($root . '/css/time-entries.css');
		self::assertStringNotContainsString('azc-app--time-entries .azc-btn--secondary', $timeEntries);

		// Nextcloud default theme values for the tokens above. White on the tint fails AA,
		// so a test that always returns a passing ratio cannot hide a broken formula.
		$themes = [
			'light' => ['text' => '#222222', 'fill' => '#e5eff5', 'hover' => '#d9e3e8', 'border' => '#00679e', 'page' => '#ffffff'],
			'light-default-css' => ['text' => '#222222', 'fill' => '#e5eff5', 'hover' => '#dbe4ea', 'border' => '#00679e', 'page' => '#ffffff'],
			'dark' => ['text' => '#EBEBEB', 'fill' => '#14232c', 'hover' => '#1f2e36', 'border' => '#0091f2', 'page' => '#171717'],
			'light-hc' => ['text' => '#000000', 'fill' => '#e5eef3', 'hover' => '#d9e2e6', 'border' => '#005c88', 'page' => '#ffffff'],
			'dark-hc' => ['text' => '#ffffff', 'fill' => '#031119', 'hover' => '#0f1c24', 'border' => '#27abff', 'page' => '#000000'],
		];
		foreach ($themes as $name => $theme) {
			self::assertGreaterThanOrEqual(4.5, self::contrastRatio($theme['text'], $theme['fill']), $name . ' text on fill');
			self::assertGreaterThanOrEqual(4.5, self::contrastRatio($theme['text'], $theme['hover']), $name . ' text on hover');
			self::assertGreaterThanOrEqual(3.0, self::contrastRatio($theme['border'], $theme['page']), $name . ' border on page');
			self::assertGreaterThanOrEqual(3.0, self::contrastRatio($theme['border'], $theme['fill']), $name . ' border on fill');
		}
		self::assertEqualsWithDelta(21.0, self::contrastRatio('#000', '#fff'), 0.01);
		self::assertEqualsWithDelta(13.63, self::contrastRatio('#222222', '#e5eff5'), 0.05);
		self::assertLessThan(4.5, self::contrastRatio('#ffffff', '#e5eff5'));
		try {
			self::relativeLuminance('#gggggg');
			self::fail('A non-hex colour must not produce a contrast ratio');
		} catch (\InvalidArgumentException $e) {
			self::assertStringContainsString('hex', $e->getMessage());
		}
	}

	/**
	 * button_variant_specificity_collapse tripwire
	 * (azc-vis-web-dialog-primary-variant-collapse):
	 * components.js createModal()/showConfirmDialog() append .azc-dialog /
	 * .azc-modal-backdrop to document.body — outside #app-content(.azc-app) —
	 * where NC core button:not(.button-vue) repaints every <button> pale.
	 * Every variant emitted on <button> inside a dialog must carry
	 * dialog-scoped !important fill+border armor.
	 */
	public function testBodyAppendedDialogsKeepVariantArmor(): void {
		$css = (string)file_get_contents(dirname(__DIR__, 2) . '/css/app.css');

		foreach (['primary', 'secondary', 'danger'] as $variant) {
			foreach (['.azc-modal-backdrop', '.azc-dialog'] as $scope) {
				self::assertMatchesRegularExpression(
					'/' . preg_quote($scope, '/') . ' \.azc-btn--' . $variant . '[\s,]/',
					$css,
					"$scope .azc-btn--$variant selector missing — dialog variant unarmored"
				);
				self::assertMatchesRegularExpression(
					'/' . preg_quote($scope, '/') . ' \.btn--' . $variant . '[\s,]/',
					$css,
					"$scope .btn--$variant selector missing — components.js emits plain btn classes"
				);
			}
			// The scoped rule must carry the armored body, not just the selector.
			self::assertMatchesRegularExpression(
				'/\.azc-dialog \.btn--' . $variant . '[^{]*\{[^}]*background-color:[^;{}]*!important[^}]*border-color:[^;{}]*!important/s',
				$css,
				"Dialog-scoped --$variant rule must pin background + border with !important"
			);
		}

		// Core repaints :hover/:active at higher specificity — the armored
		// states must be restated under the dialog scopes.
		foreach (['primary', 'secondary', 'danger'] as $variant) {
			self::assertStringContainsString(".azc-dialog .btn--$variant:hover", $css);
			self::assertStringContainsString(".azc-modal-backdrop .btn--$variant:hover", $css);
			self::assertStringContainsString(".azc-dialog .btn--$variant:active", $css);
			self::assertStringContainsString(".azc-modal-backdrop .btn--$variant:active", $css);
		}

		// Ghost on <button> exists in dialogs (admin-tariff-rules modal-close);
		// !important keeps the core hover repaint from giving it a pale fill.
		foreach (['.azc-modal-backdrop', '.azc-dialog'] as $scope) {
			self::assertStringContainsString("$scope .azc-btn--ghost", $css);
		}
		self::assertMatchesRegularExpression(
			'/\.azc-dialog \.azc-btn--ghost[^{]*\{[^}]*background-color:\s*transparent\s*!important[^}]*border-color:\s*transparent\s*!important/s',
			$css,
			'Dialog-scoped ghost must pin transparent fill + border with !important'
		);

		// The emitters must keep producing the armored class shapes.
		$components = (string)file_get_contents(dirname(__DIR__, 2) . '/js/common/components.js');
		self::assertStringContainsString("document.body.appendChild(backdrop)", $components);
		self::assertStringContainsString('modal-backdrop azc-modal-backdrop', $components);
		self::assertMatchesRegularExpression(
			"/azc-dialog/",
			$components,
			'createModal must keep the .azc-dialog class on body-appended modals'
		);
	}

	private static function ruleBody(string $css, string $selectorStart): string {
		$at = strpos($css, $selectorStart);
		self::assertNotFalse($at, 'Missing rule ' . $selectorStart);
		$open = strpos($css, '{', $at);
		self::assertNotFalse($open);
		$close = strpos($css, '}', $open);
		self::assertNotFalse($close);
		return substr($css, $open + 1, $close - $open - 1);
	}

	private static function contrastRatio(string $foreground, string $background): float {
		$lighter = max(self::relativeLuminance($foreground), self::relativeLuminance($background));
		$darker = min(self::relativeLuminance($foreground), self::relativeLuminance($background));
		return ($lighter + 0.05) / ($darker + 0.05);
	}

	private static function relativeLuminance(string $hex): float {
		$hex = ltrim($hex, '#');
		if (strlen($hex) === 3) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
			throw new \InvalidArgumentException('Expected a hex colour, got ' . $hex);
		}
		$channels = [];
		foreach ([0, 2, 4] as $offset) {
			$channel = hexdec(substr($hex, $offset, 2)) / 255;
			$channels[] = $channel <= 0.04045
				? $channel / 12.92
				: (($channel + 0.055) / 1.055) ** 2.4;
		}
		return (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
	}
}
