<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\MobileAppLinks;
use PHPUnit\Framework\TestCase;

/**
 * Pins Play Store / product URLs for Get the App (no user input in hrefs).
 */
final class MobileAppLinksTest extends TestCase
{
	public function testPlayStoreUrlsAreCanonicalPackages(): void
	{
		$links = new MobileAppLinks();
		self::assertSame(
			'https://play.google.com/store/apps/details?id=de.softwarebydesign.arbeitszeitcheck',
			$links->playStoreUrl(),
		);
		self::assertSame('de.softwarebydesign.arbeitszeitcheck', $links->playStorePackageId());
		self::assertSame(
			'https://play.google.com/store/apps/details?id=de.softwarebydesign.arbeitszeitcheck.kiosk',
			$links->kioskPlayStoreUrl(),
		);
		self::assertSame('de.softwarebydesign.arbeitszeitcheck.kiosk', $links->kioskPlayStorePackageId());
		self::assertSame(
			'https://apps.apple.com/app/arbeitszeitcheck/id6803192234',
			$links->appStoreUrl(),
		);
		self::assertSame(
			'https://apps.apple.com/app/arbeitszeitcheck-terminal/id6806563753',
			$links->kioskAppStoreUrl(),
		);
		self::assertTrue($links->playListed());
	}

	public function testProductAndPrivacyUrlsAreHttpsOnVendorOrigin(): void
	{
		$links = new MobileAppLinks();
		$enProduct = $links->productPageUrl('en');
		$deProduct = $links->productPageUrl('de_DE');
		$enPrivacy = $links->privacyPageUrl('en');
		$dePrivacy = $links->privacyPageUrl('de');
		$enKiosk = $links->kioskPrivacyPageUrl('en');
		$deKiosk = $links->kioskPrivacyPageUrl('de');

		foreach ([$enProduct, $deProduct, $enPrivacy, $dePrivacy, $enKiosk, $deKiosk] as $url) {
			self::assertStringStartsWith('https://nextcloud.software-by-design.de/', $url);
			self::assertDoesNotMatchRegularExpression('/[\\x00-\\x1F\\x7F]/', $url);
		}
		self::assertStringContainsString('/en/apps/arbeitszeitcheck.html#mobile-app', $enProduct);
		self::assertStringContainsString('/de/apps/arbeitszeitcheck.html#mobile-app', $deProduct);
		self::assertStringContainsString('privacy-arbeitszeitcheck-mobile', $enPrivacy);
		self::assertStringContainsString('datenschutz-arbeitszeitcheck-mobile', $dePrivacy);
		self::assertStringContainsString('privacy-arbeitszeitcheck-terminal', $enKiosk);
		self::assertStringContainsString('datenschutz-arbeitszeitcheck-terminal', $deKiosk);
	}

	public function testVendorAppsPageStaysOnTheVendorSite(): void
	{
		$links = new MobileAppLinks();
		self::assertSame(
			'https://nextcloud.software-by-design.de/en/apps.html',
			$links->vendorAppsPageUrl('en'),
		);
		self::assertSame(
			'https://nextcloud.software-by-design.de/de/apps.html',
			$links->vendorAppsPageUrl('de_DE'),
		);
	}

	public function testPublicHttpsUrlKeepsOnlyTheExpectedHost(): void
	{
		$play = 'https://play.google.com/';
		$fallback = MobileAppLinks::PLAY_STORE_URL;

		self::assertSame($fallback, MobileAppLinks::publicHttpsUrl($fallback, $play, $fallback));
		self::assertSame(
			'https://play.google.com/store/apps/details?id=other',
			MobileAppLinks::publicHttpsUrl('  https://play.google.com/store/apps/details?id=other  ', $play, $fallback),
		);
		self::assertSame($fallback, MobileAppLinks::publicHttpsUrl('', $play, $fallback));
		self::assertSame($fallback, MobileAppLinks::publicHttpsUrl('javascript:alert(1)', $play, $fallback));
		self::assertSame($fallback, MobileAppLinks::publicHttpsUrl('data:text/html,hi', $play, $fallback));
		self::assertSame($fallback, MobileAppLinks::publicHttpsUrl('http://play.google.com/store', $play, $fallback));
		self::assertSame($fallback, MobileAppLinks::publicHttpsUrl('https://play.google.com.evil.com/phish', $play, $fallback));
		self::assertSame($fallback, MobileAppLinks::publicHttpsUrl("https://play.google.com/\nhttps://evil.example", $play, $fallback));
		self::assertSame($fallback, MobileAppLinks::publicHttpsUrl('https://evil.example/play.google.com/', $play, $fallback));
		self::assertSame('', MobileAppLinks::publicHttpsUrl('https://play.google.com.evil.com/phish', 'https://play.google.com', $fallback));
		self::assertSame('', MobileAppLinks::publicHttpsUrl('http://play.google.com/store', 'http://play.google.com/', $fallback));
		self::assertSame('', MobileAppLinks::publicHttpsUrl('javascript:alert(1)', $play, 'http://evil.example'));
		self::assertSame('', MobileAppLinks::publicHttpsUrl('', $play, ''));
		self::assertSame(
			'https://nextcloud.software-by-design.de/de/apps.html',
			MobileAppLinks::publicHttpsUrl('https://nextcloud.software-by-design.de/de/apps.html', 'https://nextcloud.software-by-design.de/', ''),
		);
		self::assertSame('', MobileAppLinks::publicHttpsUrl('https://evil.example/de/apps.html', 'https://nextcloud.software-by-design.de/', ''));
	}

	public function testGermanLocaleDetection(): void
	{
		$links = new MobileAppLinks();
		self::assertTrue($links->isGermanLocale('de'));
		self::assertTrue($links->isGermanLocale('de_DE'));
		self::assertFalse($links->isGermanLocale('en'));
		self::assertFalse($links->isGermanLocale('den'));
	}
}
