<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * First-login card: administrators get the setup steps with live links.
 * Everyone else keeps the recording welcome. The card is not a second menu.
 */
class DashboardWelcomeOnboardingTest extends TestCase
{
	private static string $dashboard = __DIR__ . '/../../../templates/dashboard.php';
	private static string $partial = __DIR__ . '/../../../templates/partials/dashboard-welcome.php';

	public function testDashboardIncludesWelcomeOnlyOnFirstLogin(): void
	{
		$src = (string)file_get_contents(self::$dashboard);
		$gate = strpos($src, "!empty(\$_['showAdminSetupWelcome'])");
		$include = strpos($src, "partials/dashboard-welcome.php");
		$this->assertNotFalse($gate);
		$this->assertNotFalse($include);
		$this->assertGreaterThan($gate, $include);
		$this->assertLessThan(
			strpos($src, 'azc-dashboard-overview'),
			$include,
			'welcome card stays above the dashboard overview'
		);
	}

	public function testAdminWelcomeLinksTheThreeSetupPages(): void
	{
		$html = $this->render([
			'showAdminSetupWelcome' => true,
			'urls' => [
				'adminHolidays' => '/apps/arbeitszeitcheck/admin/holidays',
				'adminWorkingTimeModels' => '/apps/arbeitszeitcheck/admin/working-time-models',
				'adminUsers' => '/apps/arbeitszeitcheck/admin/users',
			],
		]);
		$xpath = $this->xpath($html);

		$this->assertSame('admin', $this->article($xpath)->getAttribute('data-welcome'));
		$this->assertSame('Set up ArbeitszeitCheck first', $this->text($xpath, '//*[@id="welcome-title"]'));
		$this->assertSame('welcome-title', $this->article($xpath)->getAttribute('aria-labelledby'));
		$this->assertSame(0, $xpath->query('//*[contains(text(), "Welcome to Time Tracking")]')->length);
		$this->assertSame(3, $xpath->query('//ol/li')->length);
		$this->assertSame(
			['Country, region, and holidays', 'A working time model', 'Assign it'],
			$this->texts($xpath, '//h3')
		);
		$this->assertSame(3, $xpath->query('//h3/strong')->length);
		$this->assertSame([
			'holidays' => '/apps/arbeitszeitcheck/admin/holidays',
			'models' => '/apps/arbeitszeitcheck/admin/working-time-models',
			'employees' => '/apps/arbeitszeitcheck/admin/users',
		], $this->stepHrefs($xpath));
		$this->assertDismiss($xpath);
	}

	public function testAdminWelcomeFallsBackToNamedRoutes(): void
	{
		$html = $this->render(['showAdminSetupWelcome' => true]);
		$xpath = $this->xpath($html);

		$this->assertSame([
			'holidays' => '/route/arbeitszeitcheck.admin.holidays',
			'models' => '/route/arbeitszeitcheck.admin.workingTimeModels',
			'employees' => '/route/arbeitszeitcheck.admin.users',
		], $this->stepHrefs($xpath));
	}

	public function testAdminWelcomeEscapesHref(): void
	{
		$html = $this->render([
			'showAdminSetupWelcome' => true,
			'urls' => [
				'adminHolidays' => '/holidays?x=1&y="2"',
				'adminWorkingTimeModels' => '/models',
				'adminUsers' => '/users',
			],
		]);

		$this->assertStringContainsString('href="/holidays?x=1&amp;y=&quot;2&quot;"', $html);
		$this->assertStringNotContainsString('href="/holidays?x=1&y="2""', $html);
	}

	/**
	 * @dataProvider employeeFlags
	 */
	public function testEmployeesKeepTheRecordingWelcome(mixed $showAdminSetupWelcome): void
	{
		$params = [];
		if ($showAdminSetupWelcome !== null) {
			$params['showAdminSetupWelcome'] = $showAdminSetupWelcome;
		}
		$html = $this->render($params, true, true);
		$xpath = $this->xpath($html);

		$this->assertSame('employee', $this->article($xpath)->getAttribute('data-welcome'));
		$this->assertSame('Welcome to Time Tracking!', $this->text($xpath, '//*[@id="welcome-title"]'));
		$this->assertSame(0, $xpath->query('//*[@data-welcome-step]')->length);
		$this->assertSame(0, $xpath->query('//*[contains(text(), "Set up ArbeitszeitCheck first")]')->length);
		$this->assertGreaterThan(0, $xpath->query('//*[contains(text(), "Clock In")]')->length);
		$this->assertSame(1, $xpath->query('//a[contains(@href, "page.timeEntries")]')->length);
		$this->assertDismiss($xpath);
	}

	public function testAdministrationMenuAloneDoesNotShowTheSetupCard(): void
	{
		$html = $this->render(['showAdminNav' => true, 'showAdminSetupWelcome' => false], true, false);
		$xpath = $this->xpath($html);

		$this->assertSame('employee', $this->article($xpath)->getAttribute('data-welcome'));
		$this->assertSame(0, $xpath->query('//*[@data-welcome-step]')->length);
	}

	/**
	 * @return list<array{0: mixed}>
	 */
	public static function employeeFlags(): array
	{
		return [
			[null],
			[false],
			[0],
			['0'],
			[''],
		];
	}

	public function testEmployeeWelcomeHidesClockAndManualLinksWhenThoseModesAreOff(): void
	{
		$html = $this->render(['showAdminSetupWelcome' => false], false, false);
		$xpath = $this->xpath($html);

		$this->assertSame(0, $xpath->query('//*[contains(text(), "Clock In")]')->length);
		$this->assertSame(0, $xpath->query('//a[contains(@href, "page.timeEntries")]')->length);
		$this->assertGreaterThan(0, $xpath->query('//*[contains(text(), "Absences")]')->length);
		$this->assertDismiss($xpath);
	}

	/**
	 * @param array<string, mixed> $params
	 */
	private function render(array $params, bool $clock = true, bool $manual = true): string
	{
		$_ = $params;
		$l = new class {
			/**
			 * @param array<int|string, string> $parameters
			 */
			public function t(string $text, array $parameters = []): string
			{
				return $parameters === [] ? $text : vsprintf($text, $parameters);
			}
		};
		$urlGenerator = new class {
			public function linkToRoute(string $name): string
			{
				return '/route/' . $name;
			}
		};
		$clockStampingEnabled = $clock;
		$manualTimeEntryEnabled = $manual;

		require_once __DIR__ . '/../Support/template_stubs.php';
		ob_start();
		include self::$partial;

		return (string)ob_get_clean();
	}

	private function xpath(string $html): DOMXPath
	{
		$dom = new DOMDocument();
		$loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
		$this->assertTrue($loaded);

		return new DOMXPath($dom);
	}

	private function article(DOMXPath $xpath): DOMElement
	{
		$node = $xpath->query('//article[@data-welcome]')->item(0);
		$this->assertInstanceOf(DOMElement::class, $node);

		return $node;
	}

	private function text(DOMXPath $xpath, string $query): string
	{
		$node = $xpath->query($query)->item(0);
		$this->assertNotNull($node);

		return trim((string)$node->textContent);
	}

	/**
	 * @return list<string>
	 */
	private function texts(DOMXPath $xpath, string $query): array
	{
		$out = [];
		foreach ($xpath->query($query) as $node) {
			$out[] = trim((string)$node->textContent);
		}

		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	private function stepHrefs(DOMXPath $xpath): array
	{
		$out = [];
		foreach ($xpath->query('//a[@data-welcome-step]') as $node) {
			$this->assertInstanceOf(DOMElement::class, $node);
			$step = $node->getAttribute('data-welcome-step');
			$this->assertNotSame('', trim($node->textContent), $step . ' link has no name');
			$this->assertArrayNotHasKey($step, $out);
			$out[$step] = $node->getAttribute('href');
		}

		return $out;
	}

	private function assertDismiss(DOMXPath $xpath): void
	{
		$button = $xpath->query('//button[@id="dismiss-welcome"]')->item(0);
		$this->assertInstanceOf(DOMElement::class, $button);
		$this->assertSame('button', $button->getAttribute('type'));
		$this->assertSame('Got it, thanks!', trim((string)$button->textContent));
		$this->assertSame(1, $xpath->query('//*[@id="welcome-title"]')->length);
		$this->assertSame(1, $xpath->query('//*[@id="dismiss-welcome"]')->length);
	}
}
