<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\AppInfo;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * Brand naming contract (supersedes the 2026-08 Option-A "single English
 * name" rule): per the 2026-11-20 internationalization strategy the store
 * display name is `WorkTimeCheck` by default with exactly one localized
 * gloss — `<name lang="de">ArbeitszeitCheck</name>`. No other localized
 * <name> elements are allowed, and the in-app navigation name
 * (`ArbeitszeitCheck`) stays untranslated in shipped l10n catalogs.
 *
 * @see documentation/arbeitszeitcheck/decisions/20261120-internationalization-strategy.md
 */
final class AppBrandNameContractTest extends TestCase
{
	private const BRAND = 'ArbeitszeitCheck';

	private const INTL_BRAND = 'WorkTimeCheck';

	private const NAV_L10N_KEY = 'ArbeitszeitCheck';

	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 3);
	}

	public function testInfoXmlHasSingleEnglishBrandName(): void
	{
		$xml = (string)file_get_contents($this->root . '/appinfo/info.xml');

		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($xml), 'info.xml must be valid XML');
		$xp = new DOMXPath($dom);
		/** @var list<DOMElement> $names */
		$names = iterator_to_array($xp->query('/info/name'));
		$this->assertCount(2, $names, 'Exactly two <name> elements: WorkTimeCheck default + de gloss');
		$this->assertSame(self::INTL_BRAND, $names[0]->textContent, 'Default <name> must be the WorkTimeCheck international alias');
		$this->assertFalse($names[0]->hasAttribute('lang'), 'Default <name> must not carry a lang attribute');
		$this->assertSame('de', $names[1]->getAttribute('lang'), 'Only a German localized name is allowed');
		$this->assertSame(self::BRAND, $names[1]->textContent, 'de localized name must be the canonical ArbeitszeitCheck brand');
	}

	public function testNavigationRegistrationUsesEnglishBrand(): void
	{
		$xml = (string)file_get_contents($this->root . '/appinfo/info.xml');
		if (str_contains($xml, '<navigations>')) {
			$this->assertMatchesRegularExpression(
				'#<navigations>\s*<navigation>\s*<name>' . preg_quote(self::BRAND, '#') . '</name>#s',
				$xml,
				'Static info.xml navigation must use the English brand name',
			);
			return;
		}

		$appPhp = (string)file_get_contents($this->root . '/lib/AppInfo/Application.php');
		$navGate = (string)file_get_contents($this->root . '/lib/Support/NavGate.php');
		$this->assertStringContainsString(
			'NavGate::register(',
			$appPhp,
			'Dynamic navigation must be registered via the NavGate helper',
		);
		$this->assertStringContainsString('INavigationManager', $navGate);
		$quoted = preg_quote(self::NAV_L10N_KEY, '/');
		$this->assertMatchesRegularExpression(
			"/NavGate::register\\([^)]*['\"]{$quoted}['\"]/s",
			$appPhp,
			'Dynamic navigation must register the English brand',
		);
		$this->assertMatchesRegularExpression(
			'/->t\(\$name\)/',
			$navGate,
			'NavGate must translate the registered name via l10n',
		);
	}

	public function testShippedL10nCatalogsKeepEnglishBrandName(): void
	{
		foreach (['de', 'fr', 'es', 'da', 'nl', 'it', 'pl', 'sv', 'nb', 'en'] as $lang) {
			$path = $this->root . '/l10n/' . $lang . '.json';
			if (!is_file($path)) {
				continue;
			}
			$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
			$tr = $data['translations'] ?? [];
			foreach ([self::BRAND, self::NAV_L10N_KEY] as $key) {
				if (!array_key_exists($key, $tr)) {
					continue;
				}
				$this->assertSame(
					self::BRAND,
					$tr[$key],
					$lang . ' catalog must not localize the app brand name',
				);
			}
		}
	}
}
