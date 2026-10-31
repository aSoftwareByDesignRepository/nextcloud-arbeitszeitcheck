<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Layout contract: table-heavy pages use full-width shell; admin list pages merge shell params.
 */
class PageShellLayoutTest extends TestCase
{
	public function testWideShellIncludesTableHeavyPageIds(): void
	{
		$controller = new ReflectionClass(\OCA\ArbeitszeitCheck\Controller\PageController::class);
		$constant = $controller->getConstant('WIDE_SHELL_PAGE_IDS');
		$this->assertIsArray($constant);

		foreach ([
			'admin-users',
			'admin-kiosk',
			'admin-support-us',
			'time-entries',
			'absences',
			'manager-time-entries',
			'manager-absences',
			'compliance-violations',
			'substitution-requests',
		] as $pageId) {
			$this->assertContains($pageId, $constant, 'Expected wide shell for ' . $pageId);
		}
	}

	public function testAdminOverviewKeepsASingleLeadWithoutMoreHelp(): void
	{
		$content = (string)file_get_contents(__DIR__ . '/../../lib/Controller/AdminController.php');
		$this->assertStringContainsString('function adminOverviewPageHelp(): string', $content);
		$this->assertSame(2, substr_count($content, "'collapsePageHelp' => false"));
		$this->assertSame(2, substr_count($content, '$this->adminOverviewPageHelp()'));
		$this->assertStringNotContainsString(
			"t('Current key metrics and open working-time compliance issues. Detailed settings are available in the left navigation.'),",
			$content
		);
	}

	public function testAdminUsersControllerMergesShellParams(): void
	{
		$content = (string)file_get_contents(__DIR__ . '/../../lib/Controller/AdminController.php');
		$this->assertStringContainsString("'admin-users', array_merge(", $content);
		$this->assertStringContainsString("buildAdminShellParams(\n\t\t\t\t'admin-users'", $content);
	}

	public function testWorkingTimeModelsControllerMergesShellParams(): void
	{
		$content = (string)file_get_contents(__DIR__ . '/../../lib/Controller/AdminController.php');
		$this->assertStringContainsString("'admin-working-time-models'", $content);
		$this->assertStringContainsString("buildAdminShellParams(\n\t\t\t\t'admin-working-time-models'", $content);
	}

	public function testSettingsUsesWideShell(): void
	{
		$controller = new ReflectionClass(\OCA\ArbeitszeitCheck\Controller\PageController::class);
		$wide = $controller->getConstant('WIDE_SHELL_PAGE_IDS');
		$constrained = $controller->getConstant('CONSTRAINED_SHELL_PAGE_IDS');
		$this->assertIsArray($wide);
		$this->assertIsArray($constrained);
		$this->assertContains('settings', $wide);
		$this->assertNotContains('settings', $constrained);
		$this->assertContains('admin-user-detail', $wide);
		$this->assertNotContains('admin-user-detail', $constrained);
		$this->assertContains('admin-license', $wide);
		$this->assertContains('admin-support-us', $wide);
	}

	public function testAdminUserDetailControllerMergesShellParams(): void
	{
		$content = (string)file_get_contents(__DIR__ . '/../../lib/Controller/AdminController.php');
		$this->assertStringContainsString("'admin-user-detail'", $content);
		$this->assertStringContainsString('function userDetail(string $userId)', $content);
	}

	public function testManagerScopePagesUseFullWidthLayout(): void
	{
		$content = (string)file_get_contents(__DIR__ . '/../../css/manager-time-entries.css');
		$this->assertStringContainsString('.manager-scope-page', $content);
		$this->assertStringNotContainsString('max-width: 56rem', $content);

		$monthClosures = (string)file_get_contents(__DIR__ . '/../../css/manager-month-closures.css');
		$this->assertStringContainsString('max-width: none', $monthClosures);
		$this->assertStringNotContainsString('max-width: 48rem', $monthClosures);
		$this->assertStringNotContainsString('max-width: 56rem', $monthClosures);
	}

	public function testAdminKioskUsesFullWidthLayout(): void
	{
		$content = (string)file_get_contents(__DIR__ . '/../../css/admin-kiosk.css');
		$this->assertStringContainsString('.azc-kiosk-page', $content);
		$this->assertStringContainsString('max-width: none', $content);
		$this->assertStringNotContainsString('max-width: min(72rem', $content);
		$this->assertStringContainsString('.azc-kiosk-panel', $content);

		// Overview strip moved to the shared stat-tile pattern (1.5.18):
		// template must use azc-stat-strip and app.css must ship the shared styles.
		$template = (string)file_get_contents(__DIR__ . '/../../templates/admin-kiosk.php');
		$this->assertStringContainsString('azc-stat-strip azc-kiosk-overview', $template);
		$appCss = (string)file_get_contents(__DIR__ . '/../../css/app.css');
		$this->assertStringContainsString("common/stat-tiles.css", $appCss);
		$statTiles = (string)file_get_contents(__DIR__ . '/../../css/common/stat-tiles.css');
		$this->assertStringContainsString('.azc-stat-strip', $statTiles);

		$this->assertStringContainsString('azc-kiosk-open-create', $template);
		$this->assertStringContainsString('azc-kiosk-create-modal', $template);
	}

	public function testAdminLicenseAndSupportUsPageRootsAreFullWidth(): void
	{
		$license = (string)file_get_contents(__DIR__ . '/../../css/admin-license.css');
		$this->assertMatchesRegularExpression(
			'/\.azc-license-page\s*\{[^}]*max-width:\s*none/s',
			$license,
			'License page root must fill the wide shell (no 56rem page cap)'
		);
		$this->assertStringNotContainsString('max-width: 56rem', $license);

		$support = (string)file_get_contents(__DIR__ . '/../../css/admin-support-us.css');
		$this->assertMatchesRegularExpression(
			'/\.azc-support-us-page\s*\{[^}]*max-width:\s*none/s',
			$support,
			'Support Us page root must fill the wide shell'
		);
		// Prose measure is OK; page-root 56rem is not.
		$this->assertDoesNotMatchRegularExpression(
			'/\.azc-support-us-page\s*\{[^}]*max-width:\s*56rem/s',
			$support
		);

		$detail = (string)file_get_contents(__DIR__ . '/../../css/admin-users.css');
		$this->assertMatchesRegularExpression(
			'/\.admin-user-detail\s*\{[^}]*max-width:\s*none/s',
			$detail
		);

		$patterns = (string)file_get_contents(__DIR__ . '/../../css/common/page-patterns.css');
		$this->assertStringContainsString('#azc-main-content > .azc-page-stack', $patterns);
		$this->assertStringContainsString('max-width: none', $patterns);
	}
}
