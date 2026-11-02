<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\MobileAppLinks;
use PHPUnit\Framework\TestCase;

/**
 * Contract: Get the App nav + route + PHP template + Play Store security attributes.
 */
final class GetTheAppPageContractTest extends TestCase
{
	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testRouteAndControllerExist(): void
	{
		$routes = (string) file_get_contents($this->root . '/appinfo/routes.php');
		self::assertStringContainsString("page#getTheApp", $routes);
		self::assertStringContainsString("'/get-the-app'", $routes);

		$php = (string) file_get_contents($this->root . '/lib/Controller/PageController.php');
		self::assertStringContainsString('function getTheApp(', $php);
		self::assertStringContainsString("'get-the-app'", $php);
		self::assertStringContainsString('Get the App', $php);
		self::assertStringContainsString('MobileAppLinks', $php);
		self::assertStringContainsString("'playStore'", $php);
		self::assertStringContainsString("'kioskPlayStore'", $php);
		self::assertStringContainsString("'appStore'", $php);
		self::assertStringContainsString("'kioskAppStore'", $php);
		self::assertStringContainsString("'vendorApps'", $php);

		$shell = (string) file_get_contents($this->root . '/lib/Controller/PageShellTrait.php');
		self::assertStringContainsString('arbeitszeitcheck.page.getTheApp', $shell);
	}

	public function testNavPlacedAfterMySettings(): void
	{
		$nav = (string) file_get_contents($this->root . '/templates/common/navigation.php');
		$settingsPos = strpos($nav, "arbeitszeitcheck.page.settings");
		$getAppPos = strpos($nav, "arbeitszeitcheck.page.getTheApp");
		self::assertNotFalse($settingsPos);
		self::assertNotFalse($getAppPos);
		self::assertGreaterThan($settingsPos, $getAppPos);
		self::assertStringContainsString("azcNavIcon('smartphone')", $nav);
	}

	public function testTemplateWiresPlayStoreSafely(): void
	{
		$tpl = (string) file_get_contents($this->root . '/templates/get-the-app.php');
		self::assertStringContainsString('azc-get-app__hero', $tpl);
		self::assertStringContainsString('azc-get-app__features', $tpl);
		self::assertStringContainsString('azc-get-app__actions', $tpl);
		self::assertStringContainsString('azc-get-app__play', $tpl);
		self::assertStringContainsString('azc-get-app__vendor', $tpl);
		self::assertStringContainsString('azc-btn azc-btn--primary azc-get-app__store-link azc-get-app__store-link--primary azc-get-app__play', $tpl);
		self::assertStringContainsString('azc-btn azc-btn--secondary azc-get-app__store-link azc-get-app__play', $tpl);
		self::assertStringContainsString('azc-btn azc-btn--secondary azc-get-app__store-link"', $tpl);
		self::assertStringContainsString('rel="noopener noreferrer"', $tpl);
		self::assertStringContainsString('target="_blank"', $tpl);
		self::assertStringContainsString('MobileAppLinks::publicHttpsUrl(', $tpl);
		self::assertStringContainsString('MobileAppLinks::PLAY_STORE_URL', $tpl);
		self::assertStringContainsString('MobileAppLinks::KIOSK_PLAY_STORE_URL', $tpl);
		self::assertStringContainsString('MobileAppLinks::APP_STORE_URL', $tpl);
		self::assertStringContainsString('MobileAppLinks::KIOSK_APP_STORE_URL', $tpl);
		self::assertStringContainsString("'https://play.google.com/'", $tpl);
		self::assertStringContainsString("'https://apps.apple.com/'", $tpl);
		self::assertStringContainsString('SupportUsLinks::SITE_ORIGIN', $tpl);
		self::assertStringContainsString('On Google Play', $tpl);
		self::assertStringContainsString('On the App Store', $tpl);
		self::assertStringContainsString('Built by Software by Design', $tpl);
		self::assertStringContainsString('All Check apps', $tpl);
		self::assertStringContainsString('azc-btn azc-btn--primary azc-get-app__action azc-get-app__action--primary', $tpl);
		self::assertStringContainsString('azc-btn azc-btn--secondary azc-get-app__action', $tpl);
		self::assertStringContainsString('gap: var(--azc-space-7, 48px)', (string) file_get_contents($this->root . '/css/get-the-app.css'));
		self::assertStringContainsString('Benefits of the mobile apps', $tpl);
		self::assertStringContainsString('Official apps for Android and iOS', $tpl);
		self::assertStringNotContainsString('The Nextcloud web app stays free (AGPL)', $tpl);
		$playHeading = strpos($tpl, 'On Google Play');
		$mobilePlay = strpos($tpl, 'ArbeitszeitCheck Mobile on Google Play');
		$terminalPlay = strpos($tpl, 'Terminal app on Google Play');
		$appleHeading = strpos($tpl, 'On the App Store');
		$mobileApple = strpos($tpl, 'ArbeitszeitCheck Mobile on the App Store');
		$terminalApple = strpos($tpl, 'Terminal app on the App Store');
		self::assertNotFalse($playHeading);
		self::assertNotFalse($mobilePlay);
		self::assertNotFalse($terminalPlay);
		self::assertNotFalse($appleHeading);
		self::assertNotFalse($mobileApple);
		self::assertNotFalse($terminalApple);
		self::assertLessThan($mobilePlay, $playHeading);
		self::assertLessThan($terminalPlay, $mobilePlay);
		self::assertLessThan($appleHeading, $terminalPlay);
		self::assertLessThan($mobileApple, $appleHeading);
		self::assertLessThan($terminalApple, $mobileApple);
	}

	public function testIconsAndChromeIncludeSmartphone(): void
	{
		$catalog = (string) file_get_contents($this->root . '/lib/Service/IconCatalog.php');
		$start = (string) file_get_contents($this->root . '/templates/common/page-start.php');
		self::assertStringContainsString("'smartphone'", $catalog);
		self::assertStringContainsString("'get-the-app' => 'smartphone'", $start);
	}

	public function testCssSeparatesStaticFeaturesFromActionButtons(): void
	{
		$css = (string) file_get_contents($this->root . '/css/get-the-app.css');
		self::assertMatchesRegularExpression(
			'/\.azc-get-app__hero[^{]*\{[^}]*linear-gradient/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-get-app__feature-copy[^{]*\{[^}]*flex-direction:\s*column/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-get-app__feature[^{]*\{[^}]*cursor:\s*default/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-get-app__features[^{]*\{[^}]*grid-template-columns:\s*1fr/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-get-app__feature[^{]*\{[^}]*background:\s*var\(--azc-bg-card,\s*var\(--color-main-background\)\)/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/#app-content\.azc-app--get-the-app a\.azc-get-app__store-link--primary[^{]*\{[^}]*background-color:\s*var\(--color-primary-element\)\s*!important/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-get-app__action[^{]*\{[^}]*cursor:\s*pointer/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-get-app__action[^{]*\{[^}]*border:\s*1px\s+solid\s+var\(--color-primary-element\)/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-get-app__action[^{]*\{[^}]*text-decoration:\s*none/s',
			$css,
		);
		self::assertStringContainsString('prefers-contrast', $css);
		self::assertStringContainsString('forced-colors', $css);
		self::assertStringContainsString('min-width: var(--azc-touch, 44px)', $css);
		self::assertStringContainsString(':focus:not(:focus-visible)', $css);
		self::assertStringContainsString('var(--color-primary-element)', $css);
		self::assertStringContainsString('var(--color-primary-element-text)', $css);
		self::assertMatchesRegularExpression(
			'/\.azc-get-app__section-title[^{]*\{[^}]*font-size:\s*clamp\(/s',
			$css,
		);
		self::assertMatchesRegularExpression(
			'/\.azc-get-app__section-title::after[^{]*\{[^}]*background:\s*var\(--color-primary-element\)/s',
			$css,
		);
		self::assertStringContainsString('.azc-get-app__vendor', $css);
		self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\\b/', $css);
		self::assertSame(MobileAppLinks::PLAY_STORE_PACKAGE_ID, 'de.softwarebydesign.arbeitszeitcheck');
		self::assertTrue(MobileAppLinks::PLAY_LISTED);
	}

	public function testGetTheAppCopyIsTranslatedInFooterLocales(): void
	{
		$allowIdentity = [];
		$locales = ['de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'pt_BR'];
		$keys = $this->getTheAppSourceKeys();
		self::assertNotEmpty($keys);
		foreach ($locales as $locale) {
			$jsonPath = $this->root . '/l10n/' . $locale . '.json';
			$jsPath = $this->root . '/l10n/' . $locale . '.js';
			self::assertFileExists($jsonPath, $locale . ' catalog missing');
			self::assertFileExists($jsPath, $locale . ' JS catalog missing');
			$decoded = json_decode((string) file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);
			self::assertIsArray($decoded);
			$translations = $decoded['translations'] ?? null;
			self::assertIsArray($translations);
			$js = (string) file_get_contents($jsPath);
			foreach ($keys as $key) {
				self::assertArrayHasKey($key, $translations, $locale . ' missing Get the App key: ' . $key);
				$value = $translations[$key];
				self::assertIsString($value);
				if (!in_array($key, $allowIdentity, true)) {
					self::assertNotSame($key, $value, $locale . ' left Get the App copy in English: ' . $key);
				}
				$encodedKey = json_encode($key, JSON_UNESCAPED_UNICODE);
				self::assertIsString($encodedKey);
				self::assertStringContainsString($encodedKey, $js, $locale . ' JS missing key: ' . $key);
				$encodedVal = json_encode($value, JSON_UNESCAPED_UNICODE);
				self::assertIsString($encodedVal);
				self::assertStringContainsString($encodedVal, $js, $locale . ' JS missing translation for: ' . $key);
			}
		}
	}

	/**
	 * @return list<string>
	 */
	private function getTheAppSourceKeys(): array
	{
		$keys = [];
		$tpl = (string) file_get_contents($this->root . '/templates/get-the-app.php');
		if (preg_match_all("/\\\$l->t\\('((?:\\\\'|[^'])*)'\\)/", $tpl, $matches) === false) {
			self::fail('Failed to parse get-the-app.php l10n keys');
		}
		foreach ($matches[1] as $raw) {
			$keys[] = str_replace("\\'", "'", $raw);
		}
		$controller = (string) file_get_contents($this->root . '/lib/Controller/PageController.php');
		if (preg_match('/function getTheApp\b[\s\S]{0,2500}/', $controller, $block) === 1
			&& preg_match_all("/->(?:l10n|l)->t\\('((?:\\\\'|[^'])*)'\\)/", $block[0], $ctrlMatches) !== false) {
			foreach ($ctrlMatches[1] as $raw) {
				$keys[] = str_replace("\\'", "'", $raw);
			}
		}
		return array_values(array_unique($keys));
	}
}
