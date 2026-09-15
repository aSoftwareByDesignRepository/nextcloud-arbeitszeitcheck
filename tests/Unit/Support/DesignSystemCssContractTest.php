<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Pins ArbeitszeitCheck design-system theming / responsive contracts
 * (planning/design-system/DESIGN-SYSTEM.md) against dark-mode, high-contrast,
 * and hardcoded-color regressions.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
final class DesignSystemCssContractTest extends TestCase
{
	private string $tokensCss;
	private string $policyCss;
	private string $appRoot;

	protected function setUp(): void
	{
		parent::setUp();
		$this->appRoot = dirname(__DIR__, 3);
		$this->tokensCss = (string) file_get_contents($this->appRoot . '/css/common/tokens.css');
		$this->policyCss = (string) file_get_contents($this->appRoot . '/css/admin-notifications.css');
		self::assertNotSame('', $this->tokensCss);
		self::assertNotSame('', $this->policyCss);
	}

	public function testThemeTokensLiveOnBodyAndDeriveFromNextcloud(): void
	{
		self::assertMatchesRegularExpression(
			'/body\s*\{[^}]*--azc-bg-card:\s*var\(\s*--color-main-background/s',
			$this->tokensCss,
		);
		self::assertMatchesRegularExpression(
			'/--azc-text:\s*var\(\s*--color-main-text/s',
			$this->tokensCss,
		);
		self::assertMatchesRegularExpression(
			'/--azc-muted:\s*var\(\s*--color-text-maxcontrast/s',
			$this->tokensCss,
		);
		self::assertMatchesRegularExpression(
			'/--azc-tint-info:\s*color-mix\(in srgb,\s*var\(--color-primary-element\)[^;]*var\(--color-main-background\)/s',
			$this->tokensCss,
			'Tints must mix into main-background (not transparent)',
		);
		self::assertDoesNotMatchRegularExpression(
			'/--azc-tint-(?:info|success|warning|danger):\s*color-mix\([^;]*transparent\s*\)/s',
			$this->tokensCss,
			'Transparent tint mixes disappear on dark / high-contrast themes',
		);
	}

	public function testTouchAndScrimTokensExist(): void
	{
		self::assertMatchesRegularExpression('/--azc-touch:\s*44px/', $this->tokensCss);
		self::assertMatchesRegularExpression('/--azc-touch-lg:\s*48px/', $this->tokensCss);
		self::assertMatchesRegularExpression(
			'/--azc-scrim:\s*color-mix\(in srgb,\s*var\(--color-main-text\)/s',
			$this->tokensCss,
		);
	}

	public function testConnectionSwitchStylesApplyInNextcloudAdminShell(): void
	{
		$switchCss = (string) file_get_contents($this->appRoot . '/css/common/switch-field.css');
		self::assertNotSame('', $switchCss);
		self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $switchCss, 'Switch CSS must use tokens (no raw hex)');
		self::assertStringContainsString('.azc-nc-admin-settings .azc-switch-field', $switchCss);
		self::assertStringContainsString('.azc-nc-admin-settings .azc-switch-field__label', $switchCss);
		self::assertMatchesRegularExpression(
			'/\.azc-nc-admin-settings \.azc-switch-field__label[^{]*\{[^}]*min-height:\s*44px/s',
			$switchCss,
			'NC Administration connection switch must stay a 44px touch target',
		);
		self::assertStringContainsString(
			'.azc-nc-admin-settings .azc-switch-field__input:focus-visible + .azc-switch-field__label .azc-switch-field__track',
			$switchCss,
		);
	}

	public function testPolicyCssHasNoRawHex(): void
	{
		self::assertDoesNotMatchRegularExpression(
			'/#[0-9a-fA-F]{3,8}\b/',
			$this->policyCss,
			'admin-notifications.css must not ship raw hex (use NC / azc tokens)',
		);
	}

	public function testPolicyCssNeverDisablesBarePageShell(): void
	{
		self::assertDoesNotMatchRegularExpression(
			'/#app-content\.azc-app--admin-notifications,\s*#app-content\.azc-app--admin-overtime-settings[^\{]*\{[^}]*pointer-events:\s*none/s',
			$this->policyCss,
		);
		self::assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-notifications\s+\[data-settings-disabled=\'true\'\]/',
			$this->policyCss,
		);
	}

	public function testSettingsChipBarIsThemeAndTouchSafe(): void
	{
		self::assertStringContainsString('.azc-settings-nav__link', $this->policyCss);
		self::assertMatchesRegularExpression(
			'/\.azc-settings-nav__link[^{]*\{[^}]*min-height:\s*var\(--azc-touch/s',
			$this->policyCss,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-settings-nav__link:focus-visible[^{]*\{[^}]*outline/s',
			$this->policyCss,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-settings-nav__link\[aria-current="page"\][^{]*\{[^}]*color:\s*var\(--azc-text/s',
			$this->policyCss,
			'Active chip must keep main-text ink (primary-on-tint fails WCAG 1.4.3)',
		);
		self::assertStringContainsString('var(--azc-tint-info', $this->policyCss);
		self::assertStringContainsString('azc-settings-nav__group', $this->policyCss);
		self::assertStringContainsString('azc-settings-nav__title', $this->policyCss);
		self::assertStringNotContainsString('.azc-jump-nav--bar', $this->policyCss);

		$globalCss = (string) file_get_contents($this->appRoot . '/css/admin-settings.css');
		self::assertStringContainsString('azc-app--admin-settings .azc-settings-nav', $globalCss);
		self::assertStringContainsString('.azc-nc-admin-settings .azc-settings-nav', $globalCss);
		self::assertStringContainsString('azc-settings-nav__group', $globalCss);
		self::assertStringContainsString('azc-settings-nav__title', $globalCss);
		self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $globalCss, 'Global settings CSS must use tokens (no raw hex)');
		self::assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-settings\s+\.azc-settings-nav__link[^{]*\{[^}]*min-height:\s*var\(--azc-touch/s',
			$globalCss,
		);
		self::assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-settings\s+\.azc-admin-settings-layout[^{]*\{[^}]*flex-direction:\s*column/s',
			$globalCss,
			'Global settings layout must be single-column (not jump-nav grid)',
		);
		self::assertMatchesRegularExpression(
			'/#app-content\.azc-app--admin-settings\s+\.azc-btn--touch[^{]*\{[^}]*min-height:\s*var\(--azc-touch-lg/s',
			$globalCss,
		);
		self::assertMatchesRegularExpression(
			'/\.form-help--note[^{]*\{[^}]*color:\s*var\(--azc-text/s',
			$globalCss,
		);
		self::assertMatchesRegularExpression(
			'/\.form-help\s+a:not\(\.btn\):not\(\.azc-btn\)[^{]*\{[^}]*text-decoration:\s*underline/s',
			$globalCss,
			'Inline help links must be underlined (WCAG 1.4.1); CTA anchors excluded',
		);
	}

	public function testStickySaveUsesSafeAreaAndThemeSurface(): void
	{
		self::assertStringContainsString('azc-admin-policy-form__actions--sticky', $this->policyCss);
		self::assertStringContainsString('env(safe-area-inset-bottom', $this->policyCss);
		self::assertStringContainsString('var(--color-main-background)', $this->policyCss);
		self::assertMatchesRegularExpression(
			'/azc-btn--touch[^{]*\{[^}]*min-height:\s*var\(--azc-touch-lg/s',
			$this->policyCss,
		);
	}

	public function testBachusChoiceCardsAlignRadioWithLabelCopy(): void
	{
		self::assertStringContainsString('.azc-choice-cards', $this->policyCss);
		self::assertStringContainsString('.azc-choice-card', $this->policyCss);
		self::assertMatchesRegularExpression(
			'/\.azc-choice-card[^{]*\{[^}]*display:\s*flex/s',
			$this->policyCss,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-choice-card[^{]*\{[^}]*align-items:\s*flex-start/s',
			$this->policyCss,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-choice-card\s*>\s*input\[type=\'radio\'\]/',
			$this->policyCss,
		);
		self::assertStringContainsString('max-height: 1.25rem', $this->policyCss);
		self::assertStringContainsString('.azc-choice-card__copy', $this->policyCss);
		self::assertStringContainsString('.azc-choice-card__title', $this->policyCss);
		self::assertStringContainsString('.azc-choice-card__hint', $this->policyCss);
	}

	public function testNativeCheckboxRadioAlignToFirstLabelLine(): void
	{
		$components = (string) file_get_contents($this->appRoot . '/css/common/components.css');
		$appCss = (string) file_get_contents($this->appRoot . '/css/app.css');
		$adminSettings = (string) file_get_contents($this->appRoot . '/css/admin-settings.css');

		self::assertMatchesRegularExpression(
			'/\.form-checkbox,\s*\.form-radio\s*\{[^}]*align-items:\s*flex-start/s',
			$components,
			'Multi-line labels must not vertically center the control mid-block',
		);
		self::assertMatchesRegularExpression(
			'/\.form-checkbox input\[type="checkbox"\],\s*\.form-radio input\[type="radio"\]\s*\{[^}]*max-height:\s*1\.25rem/s',
			$components,
		);
		self::assertMatchesRegularExpression(
			'/\.form-checkbox input\[type="checkbox"\],\s*\.form-radio input\[type="radio"\]\s*\{[^}]*margin-block-start:\s*0\.2rem/s',
			$components,
		);
		self::assertStringContainsString(
			"#app-content.azc-app input[type='checkbox']:not(.azc-switch-field__input)",
			$appCss,
		);
		self::assertStringContainsString('max-height: 1.25rem', $appCss);
		self::assertStringContainsString(".form-toggle input[type='checkbox']", $appCss);
		self::assertMatchesRegularExpression(
			'/#admin-settings-form \.form-checkbox[\s\S]*?align-items:\s*flex-start/s',
			$adminSettings,
		);
		self::assertDoesNotMatchRegularExpression(
			'/#admin-settings-form \.form-checkbox,\s*#admin-notifications-form \.form-checkbox[\s\S]{0,200}align-items:\s*center/s',
			$adminSettings,
			'Admin forms must not reintroduce center alignment for checkbox rows',
		);
	}

	public function testPolicyMatricesScrollInsideWrapNotPage(): void
	{
		self::assertMatchesRegularExpression(
			'/\.admin-notifications-matrix-wrap[^{]*\{[^}]*overflow-x:\s*auto/s',
			$this->policyCss,
		);
		$alerts = (string) file_get_contents($this->appRoot . '/templates/partials/admin-policy-overtime-alerts.php');
		$hr = (string) file_get_contents($this->appRoot . '/templates/partials/admin-policy-hr-office.php');
		self::assertStringContainsString('tabindex="0"', $alerts);
		self::assertStringContainsString('role="region"', $alerts);
		self::assertStringContainsString('tabindex="0"', $hr);
	}

	public function testScopeStripBadgeChipsKeepSemanticInk(): void
	{
		$badges = (string) file_get_contents($this->appRoot . '/css/common/badges.css');
		self::assertStringContainsString('azc-scope-strip__chip.azc-badge--warning', $badges);
		self::assertStringContainsString('color: var(--azc-bdg-ink) !important', $badges);
		$base = (string) file_get_contents($this->appRoot . '/css/common/base.css');
		self::assertStringContainsString(':not(.azc-badge):not(.azc-scope-strip__chip)', $base);
	}

	public function testFormHelpNotesOnTintsUseFullContrastInk(): void
	{
		self::assertMatchesRegularExpression(
			'/\.form-help--note[^{]*\{[^}]*color:\s*var\(--azc-text/s',
			$this->policyCss,
			'form-help--note sits on tint-warning; muted ink fails WCAG 1.4.3',
		);
	}

	public function testForcedColorsAndReducedMotionHonoured(): void
	{
		self::assertStringContainsString('@media (forced-colors: active)', $this->policyCss);
		self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $this->policyCss);
	}

	public function testDangerButtonsUseElementFillNotPaleErrorTint(): void
	{
		self::assertMatchesRegularExpression(
			'/--azc-danger-fill:\s*var\(\s*--color-element-error/s',
			$this->tokensCss,
		);
		self::assertMatchesRegularExpression(
			'/--azc-danger-on-fill:\s*var\(\s*--color-primary-element-text/s',
			$this->tokensCss,
		);
		self::assertStringContainsString('body[data-theme-dark]', $this->tokensCss);
		self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $this->tokensCss, 'tokens.css must not ship raw hex');

		$appCss = (string) file_get_contents($this->appRoot . '/css/app.css');
		self::assertMatchesRegularExpression(
			'/\.azc-btn--danger[^{]*\{[^}]*background-color:\s*var\(--azc-danger-fill\)/s',
			$appCss,
			'Danger CTA must use element fill (not pale --color-error tint)',
		);
		self::assertDoesNotMatchRegularExpression(
			'/\.azc-btn--danger[^{]*\{[^}]*background-color:\s*var\(--color-error\)/s',
			$appCss,
		);
		self::assertDoesNotMatchRegularExpression(
			'/#[0-9a-fA-F]{3,8}\b/',
			preg_replace('/\/\*.*?\*\//s', '', $appCss) ?? $appCss,
			'app.css must not ship raw hex outside comments',
		);

		$settingsCss = (string) file_get_contents($this->appRoot . '/css/settings.css');
		self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $settingsCss);
		self::assertStringContainsString('azc-settings-nav__link', $settingsCss);
		self::assertMatchesRegularExpression(
			'/\.azc-settings-nav__link[^{]*\{[^}]*min-height:\s*var\(--azc-touch/s',
			$settingsCss,
		);
	}

	public function testAppLayoutUsesNextcloudThemeSurfaces(): void
	{
		$layout = (string) file_get_contents($this->appRoot . '/css/common/app-layout.css');
		self::assertMatchesRegularExpression(
			'/#content\.app-arbeitszeitcheck #app-content\s*\{[^}]*background:\s*var\(--color-main-background\)/s',
			$layout,
		);
		self::assertMatchesRegularExpression(
			'/#content\.app-arbeitszeitcheck #app-content\s*\{[^}]*color:\s*var\(--color-main-text\)/s',
			$layout,
		);
		self::assertMatchesRegularExpression(
			'/#content\.app-arbeitszeitcheck #app-content\s*\{[^}]*overflow-x:\s*hidden/s',
			$layout,
			'App content must clip page-level horizontal overflow',
		);
	}

	public function testTimeEntriesDangerAndLicenseScrimUseThemeTokens(): void
	{
		$timeEntries = (string) file_get_contents($this->appRoot . '/css/time-entries.css');
		$license = (string) file_get_contents($this->appRoot . '/css/admin-license.css');
		$reports = (string) file_get_contents($this->appRoot . '/css/reports.css');

		self::assertMatchesRegularExpression(
			'/\.btn--danger\.btn-delete-entry[^{]*\{[^}]*background-color:\s*var\(--azc-danger-fill\)/s',
			$timeEntries,
		);
		self::assertMatchesRegularExpression(
			'/\.btn--danger\.btn-delete-entry[^{]*\{[^}]*color:\s*var\(--azc-danger-on-fill\)/s',
			$timeEntries,
		);
		self::assertDoesNotMatchRegularExpression(
			'/#[0-9a-fA-F]{3,8}\b/',
			preg_replace('/\/\*.*?\*\//s', '', $timeEntries) ?? $timeEntries,
			'time-entries.css must not ship raw hex outside comments',
		);
		self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $reports);

		self::assertStringContainsString('var(--azc-scrim', $license);
		self::assertStringNotContainsString('rgba(0, 0, 0, 0.45)', $license);

		self::assertMatchesRegularExpression(
			'/\.stats-grid\s*\{[^}]*minmax\(min\(100%/s',
			$timeEntries,
			'Stats grid must use min(100%, …) so auto-fit never forces page overflow on 320px',
		);
		self::assertMatchesRegularExpression(
			'/\.stat-card\s+\.stat-value\s*\{[^}]*color:\s*var\(--azc-text/s',
			$timeEntries,
			'Large KPI ink must use main-text (primary fill fails WCAG AA on dark ~2.9:1)',
		);
		self::assertDoesNotMatchRegularExpression(
			'/\.stat-card\s+\.stat-value\s*\{[^}]*color:\s*var\(--arbeitszeitcheck-color-primary\)/s',
			$timeEntries,
		);

		$absences = (string) file_get_contents($this->appRoot . '/css/absences.css');
		self::assertMatchesRegularExpression(
			'/\.stat-card\s+\.stat-value\s*\{[^}]*color:\s*var\(--azc-text/s',
			$absences,
			'Absence KPI ink must use main-text for dark-theme AA',
		);

		$calendar = (string) file_get_contents($this->appRoot . '/css/calendar.css');
		self::assertMatchesRegularExpression(
			'/\.calendar-days\s*\{[^}]*grid-template-columns:\s*repeat\(\s*7\s*,\s*minmax\(0,\s*1fr\)\)/s',
			$calendar,
			'Calendar day grid must use minmax(0,1fr) to avoid 7-column overflow on 320px',
		);
	}

	public function testBachusSimplifySurfacesUseThemeTokensAndFocusRings(): void
	{
		$dashboard = (string) file_get_contents($this->appRoot . '/css/dashboard.css');
		$calendar = (string) file_get_contents($this->appRoot . '/css/calendar.css');
		$adminUsers = (string) file_get_contents($this->appRoot . '/css/admin-users.css');
		$mobileNav = (string) file_get_contents($this->appRoot . '/css/common/mobile-nav.css');

		foreach (['dashboard' => $dashboard, 'calendar' => $calendar, 'admin-users' => $adminUsers, 'mobile-nav' => $mobileNav] as $label => $css) {
			self::assertDoesNotMatchRegularExpression(
				'/#[0-9a-fA-F]{3,8}\b/',
				preg_replace('/\/\*.*?\*\//s', '', $css) ?? $css,
				"{$label}.css must not ship raw hex outside comments",
			);
		}

		self::assertMatchesRegularExpression(
			'/\.azc-dashboard-metrics-more__summary:focus-visible[^{]*\{[^}]*outline:\s*3px solid var\(--color-primary-element\)/s',
			$dashboard,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-dashboard-manual-cta__btn[^{]*\{[^}]*min-height:\s*var\(--azc-touch-lg/s',
			$dashboard,
		);
		self::assertMatchesRegularExpression(
			'/\.day-details-actions[^{]*\{[^}]*border:\s*1px solid var\(--azc-border/s',
			$calendar,
		);
		self::assertMatchesRegularExpression(
			'/\.calendar-day:focus-visible[^{]*\{[^}]*outline:\s*3px solid var\(--color-primary-element\)/s',
			$calendar,
		);
		self::assertDoesNotMatchRegularExpression(
			'/\.calendar-day:focus-visible[^{]*\{[^}]*outline:[^;]*var\(--color-primary\)(?!-element)/s',
			$calendar,
			'Calendar focus must use --color-primary-element (AA-adjusted), not raw --color-primary',
		);
		self::assertMatchesRegularExpression(
			'/\.view-toggle \.btn\.active[^{]*\{[^}]*background:\s*var\(--color-primary-element\)/s',
			$calendar,
		);
		self::assertMatchesRegularExpression(
			'/\.user-overtime-adjust__more-summary:focus-visible[^{]*\{[^}]*outline:\s*3px solid var\(--color-primary-element\)/s',
			$adminUsers,
		);
		self::assertStringContainsString('@media (forced-colors: active)', $adminUsers);
		self::assertMatchesRegularExpression(
			'/\.azc-nav-toggle:focus-visible[^{]*\{[^}]*outline:\s*3px solid var\(--color-primary-element\)/s',
			$mobileNav,
		);
	}
}
