<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Renders templates/common/breadcrumb.php — the shared page-chrome trail.
 *
 * Guards the admin-settings hierarchy contract:
 * App (link) → Administration (plain text) → Global settings (link) → section,
 * plus the legacy single-ancestor params used by every other page.
 */
final class BreadcrumbTrailRenderTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/Unit/Support/template_stubs.php';
	}

	public function testAdminSettingsTrailRendersFourOrderedCrumbs(): void
	{
		$html = $this->render([
			'pageTitle' => 'Access control',
			'urls' => ['dashboard' => '/apps/arbeitszeitcheck/'],
			'breadcrumbParents' => [
				['label' => 'Administration'],
				['label' => 'Global settings', 'url' => '/apps/arbeitszeitcheck/admin/settings/access'],
			],
		]);

		// Exactly four items: app, Administration, Global settings, current.
		$this->assertSame(4, substr_count($html, 'class="azc-breadcrumb__item'));
		$this->assertSame(2, substr_count($html, 'azc-breadcrumb__link'));

		// Order: ArbeitszeitCheck → Administration → Global settings → Access control.
		$posApp = strpos($html, 'ArbeitszeitCheck');
		$posAdmin = strpos($html, 'Administration');
		$posSettings = strpos($html, 'Global settings');
		$posCurrent = strpos($html, 'Access control');
		$this->assertNotFalse($posApp);
		$this->assertNotFalse($posAdmin);
		$this->assertNotFalse($posSettings);
		$this->assertNotFalse($posCurrent);
		$this->assertTrue($posApp < $posAdmin && $posAdmin < $posSettings && $posSettings < $posCurrent);

		// Administration is plain text (like every admin page) — never a link.
		$this->assertStringContainsString('<span class="azc-breadcrumb__text">Administration</span>', $html);
		$this->assertStringNotContainsString('>Administration</a>', $html);

		// Global settings links to the section index; current page never links.
		$this->assertStringContainsString('href="/apps/arbeitszeitcheck/admin/settings/access"', $html);
		$this->assertStringContainsString('aria-current="page"', $html);
		$this->assertStringContainsString('<span class="azc-breadcrumb__current">Access control</span>', $html);
		$this->assertStringNotContainsString('href="#"', $html);
	}

	public function testLegacyBreadcrumbParentRendersLinkedAncestor(): void
	{
		$html = $this->render([
			'pageTitle' => 'Vacation rules',
			'urls' => ['dashboard' => '/apps/arbeitszeitcheck/'],
			'breadcrumbParent' => ['label' => 'Policy settings', 'url' => '/apps/arbeitszeitcheck/admin/vacation-rules'],
		]);

		$this->assertSame(3, substr_count($html, 'class="azc-breadcrumb__item'));
		$this->assertStringContainsString('href="/apps/arbeitszeitcheck/admin/vacation-rules"', $html);
		$this->assertMatchesRegularExpression('/>\s*Policy settings\s*<\/a>/', $html);
	}

	public function testLegacyBreadcrumbSectionRendersPlainTextAncestor(): void
	{
		$html = $this->render([
			'pageTitle' => 'Employees',
			'urls' => ['dashboard' => '/apps/arbeitszeitcheck/'],
			'breadcrumbSection' => 'Administration',
		]);

		$this->assertSame(3, substr_count($html, 'class="azc-breadcrumb__item'));
		$this->assertStringContainsString('<span class="azc-breadcrumb__text">Administration</span>', $html);
		$this->assertSame(1, substr_count($html, 'azc-breadcrumb__link'));
	}

	public function testParentsListWinsOverLegacyParams(): void
	{
		$html = $this->render([
			'pageTitle' => 'Access control',
			'urls' => ['dashboard' => '/apps/arbeitszeitcheck/'],
			'breadcrumbSection' => 'Stale section label',
			'breadcrumbParent' => ['label' => 'Stale parent', 'url' => '/stale'],
			'breadcrumbParents' => [
				['label' => 'Administration'],
			],
		]);

		$this->assertStringContainsString('Administration', $html);
		$this->assertStringNotContainsString('Stale section label', $html);
		$this->assertStringNotContainsString('Stale parent', $html);
		$this->assertStringNotContainsString('/stale', $html);
	}

	public function testLegacyParentStillWinsOverSection(): void
	{
		$html = $this->render([
			'pageTitle' => 'Access control',
			'urls' => ['dashboard' => '/apps/arbeitszeitcheck/'],
			'breadcrumbSection' => 'Stale section label',
			'breadcrumbParent' => ['label' => 'Global settings', 'url' => '/settings/access'],
		]);

		$this->assertStringContainsString('Global settings', $html);
		$this->assertStringNotContainsString('Stale section label', $html);
	}

	public function testInvalidAncestorEntriesAreSkipped(): void
	{
		$html = $this->render([
			'pageTitle' => 'Access control',
			'urls' => ['dashboard' => '/apps/arbeitszeitcheck/'],
			'breadcrumbParents' => [
				'not-an-array',
				['label' => ''],
				['url' => '/no-label'],
				['label' => 'Administration'],
			],
		]);

		$this->assertSame(3, substr_count($html, 'class="azc-breadcrumb__item'));
		$this->assertStringContainsString('>Administration</span>', $html);
		$this->assertStringNotContainsString('/no-label', $html);
	}

	public function testNoAncestorsRendersTwoCrumbs(): void
	{
		$html = $this->render([
			'pageTitle' => 'Dashboard',
			'urls' => ['dashboard' => '/apps/arbeitszeitcheck/'],
		]);

		$this->assertSame(2, substr_count($html, 'class="azc-breadcrumb__item'));
		$this->assertSame(1, substr_count($html, 'azc-breadcrumb__link'));
	}

	public function testMissingDashboardUrlFallsBackToHash(): void
	{
		$html = $this->render([
			'pageTitle' => 'Access control',
			'breadcrumbParents' => [['label' => 'Administration']],
		]);

		$this->assertStringContainsString('href="#"', $html);
	}

	public function testLabelsAndUrlsAreEscaped(): void
	{
		$html = $this->render([
			'pageTitle' => '<script>alert(1)</script>',
			'urls' => ['dashboard' => '/apps/arbeitszeitcheck/'],
			'breadcrumbParents' => [
				['label' => '<img src=x onerror=alert(2)>', 'url' => '/ok?a="b"'],
			],
		]);

		$this->assertStringNotContainsString('<script>alert(1)</script>', $html);
		$this->assertStringNotContainsString('<img src=x', $html);
		$this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
		$this->assertStringContainsString('&lt;img src=x onerror=alert(2)&gt;', $html);
		$this->assertStringContainsString('href="/ok?a=&quot;b&quot;"', $html);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function render(array $params): string
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s) => $s);
		$_ = $params;

		ob_start();
		include dirname(__DIR__, 3) . '/templates/common/breadcrumb.php';
		$html = (string)ob_get_clean();
		$this->assertNotSame('', trim($html));
		return $html;
	}
}
