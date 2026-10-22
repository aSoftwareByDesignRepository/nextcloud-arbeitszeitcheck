<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression GH #33: mobile browsers had no usable nav (core hamburger broken /
 * clipped). Shell must ship in-page Menu + mobile-nav assets.
 */
final class MobileNavShellContractTest extends TestCase
{
	public function testPageStartEmitsInPageNavToggle(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/common/page-start.php');
		self::assertStringContainsString('id="azc-nav-toggle"', $src);
		self::assertStringContainsString('data-azc-nav-toggle', $src);
		self::assertStringContainsString("IconCatalog::render('menu'", $src);
	}

	public function testNavigationLoadsMobileNavScript(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/common/navigation.php');
		self::assertMatchesRegularExpression(
			'/^\s*Util::addScript\(\s*\'arbeitszeitcheck\'\s*,\s*\'common\/mobile-nav\'\s*\)\s*;/m',
			$src,
			'mobile-nav script must be registered via active Util::addScript (not commented out)'
		);
	}

	public function testMobileNavAssetsExist(): void
	{
		$root = dirname(__DIR__, 2);
		self::assertFileExists($root . '/js/common/mobile-nav.js');
		self::assertFileExists($root . '/css/common/mobile-nav.css');
		$cssImport = (string) file_get_contents($root . '/css/app.css');
		self::assertStringContainsString("mobile-nav.css", $cssImport);
	}

	public function testIconCatalogHasMenuGlyph(): void
	{
		$src = (string) file_get_contents(dirname(__DIR__, 2) . '/lib/Service/IconCatalog.php');
		self::assertMatchesRegularExpression("/'menu'\\s*=>/", $src);
	}
}
