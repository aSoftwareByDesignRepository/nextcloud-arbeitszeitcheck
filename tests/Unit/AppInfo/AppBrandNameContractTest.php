<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\AppInfo;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\TestCase;

/**
 * English brand names only — no localized <name lang="..."> in App Store metadata
 * and no translated app-name keys in shipped l10n catalogs (Option A).
 */
final class AppBrandNameContractTest extends TestCase
{
	private const BRAND = 'ArbeitszeitCheck';

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
		$this->assertStringNotContainsString('<name lang=', $xml, 'Localized app names are not allowed (English brand everywhere)');

		$dom = new DOMDocument();
		$this->assertTrue($dom->loadXML($xml), 'info.xml must be valid XML');
		$xp = new DOMXPath($dom);
		/** @var list<DOMElement> $names */
		$names = iterator_to_array($xp->query('/info/name'));
		$this->assertCount(1, $names, 'Exactly one top-level <name> element is allowed');
		$this->assertSame(self::BRAND, $names[0]->textContent);
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
