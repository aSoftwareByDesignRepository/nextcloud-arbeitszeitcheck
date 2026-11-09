<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * Renders the two-level settings topic switcher (DS §3.12.1):
 * level-1 group tabs + level-2 chips of the active group only.
 *
 * Covers templates/common/azc-admin-settings-nav.php and
 * azc-policy-pages-nav.php (same structure, different payload var).
 */
final class SettingsTopicsNavRenderTest extends TestCase
{
	private const PAYLOAD = [
		'current' => 'compliance',
		'labels' => [
			'access' => 'Access',
			'compliance' => 'Compliance',
			'time-recording' => 'Time recording',
			'exports' => 'Exports',
			'projectcheck' => 'ProjectCheck',
		],
		'urls' => [
			'access' => '/apps/arbeitszeitcheck/admin/settings/access',
			'compliance' => '/apps/arbeitszeitcheck/admin/settings/compliance',
			'time-recording' => '/apps/arbeitszeitcheck/admin/settings/time-recording',
			'exports' => '/apps/arbeitszeitcheck/admin/settings/exports',
			'projectcheck' => '/apps/arbeitszeitcheck/admin/settings/projectcheck',
		],
		'groups' => [
			['id' => 'access', 'label' => 'Who can use the app', 'sections' => ['access']],
			['id' => 'time', 'label' => 'Working time rules', 'sections' => ['compliance', 'time-recording']],
			['id' => 'ops', 'label' => 'Exports & data', 'sections' => ['exports']],
			['id' => 'connections', 'label' => 'Connections', 'sections' => ['projectcheck']],
		],
	];

	protected function setUp(): void
	{
		parent::setUp();
		require_once dirname(__DIR__, 2) . '/Unit/Support/template_stubs.php';
	}

	public function testActiveSectionRendersGroupTabsAndOwnGroupChipsOnly(): void
	{
		$html = $this->renderNav(self::PAYLOAD);

		// Four group tabs; each links to the first section of its group.
		$this->assertSame(4, $this->countTabs($html));
		$this->assertSame(1, substr_count($html, 'class="azc-settings-nav__tabs"'));
		foreach ([
			'Who can use the app' => '/apps/arbeitszeitcheck/admin/settings/access',
			'Working time rules' => '/apps/arbeitszeitcheck/admin/settings/compliance',
			'Exports &amp; data' => '/apps/arbeitszeitcheck/admin/settings/exports',
			'Connections' => '/apps/arbeitszeitcheck/admin/settings/projectcheck',
		] as $label => $href) {
			$this->assertStringContainsString($label, $html);
			$this->assertStringContainsString('href="' . $href . '"', $html);
		}

		// Active group tab: underline state via is-active + aria-current="location"
		// ("page" is reserved for the current topic chip).
		$this->assertMatchesRegularExpression(
			'/class="azc-settings-nav__tab is-active"[^>]*aria-current="location"[^>]*>\s*Working time rules/s',
			$html
		);
		$this->assertSame(1, substr_count($html, 'aria-current="location"'));

		// Only the active group's chips render — access/exports/projectcheck stay out.
		$this->assertSame(2, substr_count($html, 'azc-settings-nav__link'));
		$this->assertMatchesRegularExpression('/>\s*Compliance\s*<\/a>/', $html);
		$this->assertMatchesRegularExpression('/>\s*Time recording\s*<\/a>/', $html);
		$this->assertDoesNotMatchRegularExpression('/>\s*Access\s*<\/a>/', $html);
		$this->assertDoesNotMatchRegularExpression('/>\s*Exports\s*<\/a>/', $html);
		$this->assertDoesNotMatchRegularExpression('/>\s*ProjectCheck\s*<\/a>/', $html);

		// Exactly one current topic, marked aria-current="page".
		$this->assertSame(1, substr_count($html, 'aria-current="page"'));
		$this->assertStringContainsString('azc-settings-nav__link is-active', $html);
	}

	public function testEmptyCurrentFallsBackToGroupedIndex(): void
	{
		$payload = self::PAYLOAD;
		$payload['current'] = '';
		$html = $this->renderNav($payload);

		$this->assertStringNotContainsString('class="azc-settings-nav__tab', $html);
		$this->assertStringContainsString('Choose a topic', $html);
		$this->assertSame(4, substr_count($html, 'azc-settings-nav__group-label'));
		// All five topics visible in index mode.
		$this->assertSame(5, substr_count($html, 'azc-settings-nav__link'));
		$this->assertSame(0, substr_count($html, 'aria-current'));
	}

	public function testUnknownCurrentFallsBackToGroupedIndex(): void
	{
		$payload = self::PAYLOAD;
		$payload['current'] = 'does-not-exist';
		$html = $this->renderNav($payload);

		$this->assertStringNotContainsString('class="azc-settings-nav__tab', $html);
		$this->assertSame(5, substr_count($html, 'azc-settings-nav__link'));
	}

	public function testMissingGroupsRenderFlatFallback(): void
	{
		$payload = self::PAYLOAD;
		unset($payload['groups'], $payload['current']);
		$html = $this->renderNav($payload);

		$this->assertStringNotContainsString('class="azc-settings-nav__tab', $html);
		$this->assertSame(5, substr_count($html, 'azc-settings-nav__link'));
	}

	public function testGroupTabWithoutLinkableSectionIsSkipped(): void
	{
		$payload = self::PAYLOAD;
		$payload['urls']['projectcheck'] = '';
		$html = $this->renderNav($payload);

		// Connections group has no linkable section → no tab for it.
		$this->assertSame(3, $this->countTabs($html));
		$this->assertStringNotContainsString('Connections', $html);
	}

	public function testLabelsAndUrlsAreEscaped(): void
	{
		$payload = self::PAYLOAD;
		$payload['groups'][0]['label'] = '<img src=x onerror=alert(1)>';
		$payload['labels']['compliance'] = '<script>alert(2)</script>';
		$html = $this->renderNav($payload);

		$this->assertStringNotContainsString('<img src=x', $html);
		$this->assertStringNotContainsString('<script>alert(2)</script>', $html);
		$this->assertStringContainsString('&lt;img src=x', $html);
		$this->assertStringContainsString('&lt;script&gt;alert(2)&lt;/script&gt;', $html);
	}

	public function testPolicyNavRendersSameTwoLevelStructure(): void
	{
		$html = $this->renderNav(self::PAYLOAD, 'azc-policy-pages-nav.php', 'policyPages');

		$this->assertStringContainsString('id="azc-policy-pages"', $html);
		$this->assertSame(4, $this->countTabs($html));
		$this->assertSame(2, substr_count($html, 'azc-settings-nav__link'));
		$this->assertSame(1, substr_count($html, 'aria-current="location"'));
		$this->assertSame(1, substr_count($html, 'aria-current="page"'));
	}

	public function testAdminNavKeepsStableIdAndAriaLabel(): void
	{
		$html = $this->renderNav(self::PAYLOAD);

		$this->assertStringContainsString('id="azc-admin-settings-pages"', $html);
		$this->assertStringContainsString('aria-label="Settings topics"', $html);
	}

	private function countTabs(string $html): int
	{
		return substr_count($html, 'class="azc-settings-nav__tab"')
			+ substr_count($html, 'class="azc-settings-nav__tab is-active"');
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private function renderNav(array $payload, string $file = 'azc-admin-settings-nav.php', string $var = 'settingsPages'): string
	{
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $s) => $s);
		$$var = $payload;

		ob_start();
		include dirname(__DIR__, 3) . '/templates/common/' . $file;
		$html = (string)ob_get_clean();
		$this->assertNotSame('', trim($html));
		return $html;
	}
}
