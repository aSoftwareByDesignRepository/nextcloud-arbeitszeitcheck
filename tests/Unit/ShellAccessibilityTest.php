<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Unified page shell accessibility contract (WCAG 2.1 AA baseline).
 */
class ShellAccessibilityTest extends TestCase
{
	public function testPageStartIncludesShellLandmarksAndLiveRegions(): void
	{
		$path = __DIR__ . '/../../templates/common/page-start.php';
		$this->assertFileExists($path);
		$content = (string)file_get_contents($path);

		// Skip links are delegated to Nextcloud core's #skip-actions strip —
		// the shell must not render its own duplicates.
		$this->assertStringNotContainsString('azc-skip-link', $content);
		$this->assertStringContainsString('id="azc-main-content"', $content);
		$this->assertStringContainsString('id="azc-live-region"', $content);
		$this->assertStringContainsString('aria-live="polite"', $content);
		$this->assertStringContainsString('id="azc-alert-region"', $content);
		$this->assertStringContainsString('aria-live="assertive"', $content);
		$this->assertStringContainsString('id="azc-page-title"', $content);
		$this->assertStringContainsString('azc-scope-strip', $content);
		$this->assertStringContainsString("include __DIR__ . '/breadcrumb.php'", $content);

		$crumb = (string)file_get_contents(__DIR__ . '/../../templates/common/breadcrumb.php');
		$this->assertStringContainsString('azc-breadcrumb__list', $crumb);
		$this->assertStringContainsString('azc-breadcrumb__link', $crumb);
		$this->assertStringContainsString('aria-current="page"', $crumb);
		$this->assertStringContainsString('<main id="azc-main-content"', $content);
		$this->assertStringContainsString('id="azc-page-title"', $content);
		$this->assertMatchesRegularExpression('/<h1[^>]*id="azc-page-title"/', $content);
	}

	public function testTimeBootstrapEmitsInlineTimezoneConfig(): void
	{
		$path = __DIR__ . '/../../templates/common/time-bootstrap.php';
		$this->assertFileExists($path);
		$content = (string)file_get_contents($path);

		$this->assertStringContainsString('ArbeitszeitCheck.tz', $content);
		$this->assertStringContainsString('serverNow', $content);
		$this->assertStringContainsString('registerConfig()', $content);
	}

	public function testAdminSettingsSupportsNextcloudAdministrationShell(): void
	{
		$path = __DIR__ . '/../../templates/admin-settings.php';
		$this->assertFileExists($path);
		$content = (string)file_get_contents($path);

		$this->assertStringContainsString("settingsShell", $content);
		$this->assertStringContainsString('azc-nc-admin-settings', $content);
		$this->assertStringContainsString('Open in app', $content);
		$this->assertStringNotContainsString('Cancel and go back', $content);
	}

	public function testProjectCheckAdminSettingsPartialIsAccessibleSwitch(): void
	{
		$path = __DIR__ . '/../../templates/partials/projectcheck-admin-settings-section.php';
		$this->assertFileExists($path);
		$content = (string)file_get_contents($path);

		$this->assertStringContainsString('aria-labelledby="section-projectcheck-heading"', $content);
		$this->assertStringContainsString('id="projectCheckIntegrationEnabled"', $content);
		$this->assertStringContainsString('role="switch"', $content);
		$this->assertStringContainsString('aria-checked=', $content);
		$this->assertStringContainsString('aria-describedby="projectcheck-admin-integration-help"', $content);
		$this->assertStringContainsString('id="projectcheck-admin-status-text"', $content);
		$this->assertStringContainsString('role="status"', $content);
		$this->assertStringContainsString("\$calloutId = 'azc-projectcheck-app-required'", $content);
		$this->assertStringContainsString("\$calloutId = 'azc-projectcheck-group-limited'", $content);
		$this->assertStringContainsString('Open Apps', $content);
		$this->assertStringContainsString('azc-btn--touch', $content);
	}

	public function testTimeInitSupportsModernInitialStateApi(): void
	{
		$path = __DIR__ . '/../../js/common/time-init.js';
		$this->assertFileExists($path);
		$content = (string)file_get_contents($path);

		$this->assertStringContainsString('OCP.InitialState', $content);
		$this->assertStringContainsString('OC.initialState', $content);
	}

	public function testPageEndClosesMain(): void
	{
		$path = __DIR__ . '/../../templates/common/page-end.php';
		$this->assertFileExists($path);
		$content = (string)file_get_contents($path);

		$this->assertStringContainsString('</main>', $content);
	}

	public function testPageStartSupportsShellWidthModifier(): void
	{
		$content = (string)file_get_contents(__DIR__ . '/../../templates/common/page-start.php');
		$this->assertStringContainsString('shellWidth', $content);
		$this->assertStringContainsString('azc-shell--wide', $content);
		$this->assertStringContainsString('azc-shell--constrained', $content);
		$this->assertStringContainsString('azc-shell--minimal', $content);
	}

	public function testNavigationHasOnlyNavSkipLink(): void
	{
		$content = (string)file_get_contents(__DIR__ . '/../../templates/common/navigation.php');
		$this->assertStringNotContainsString('href="#app-content"', $content);
		$this->assertStringContainsString('href="#app-navigation"', $content);
	}

	/**
	 * @return list<string>
	 */
	public static function routedPageTemplatesProvider(): array
	{
		$dir = __DIR__ . '/../../templates';
		$files = [
			'dashboard.php',
			'settings.php',
			'substitution-requests.php',
			'manager-dashboard.php',
			'compliance-dashboard.php',
			'admin-dashboard.php',
		];
		$out = [];
		foreach ($files as $file) {
			$out[] = [$dir . '/' . $file];
		}
		return $out;
	}

	/**
	 * @dataProvider routedPageTemplatesProvider
	 */
	public function testRoutedTemplatesUsePageStackAndSingleH1(string $path): void
	{
		$this->assertFileExists($path);
		$content = (string)file_get_contents($path);
		$this->assertStringContainsString('page-start.php', $content);
		$this->assertStringContainsString('azc-page-stack', $content);
		$this->assertSame(0, substr_count($content, '<h1'), $path . ' must not declare h1 (shell owns it)');
	}

	public function testSubstitutionRequestsHasSingleDocumentHeading(): void
	{
		$path = __DIR__ . '/../../templates/substitution-requests.php';
		$content = (string)file_get_contents($path);
		$this->assertSame(0, substr_count($content, '<h1'), 'page shell provides the only h1');
		$this->assertStringContainsString('substitution-requests__list', $content);
		$this->assertStringContainsString('azc-card', $content);
	}
}
