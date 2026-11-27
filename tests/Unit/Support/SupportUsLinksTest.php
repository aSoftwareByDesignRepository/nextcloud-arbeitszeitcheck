<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\SupportUsLinks;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for Support & Us link builders (security + CTA hierarchy).
 */
final class SupportUsLinksTest extends TestCase {
	public function testGermanLocaleDetection(): void {
		$links = new SupportUsLinks('DutyCheck');
		self::assertTrue($links->isGermanLocale('de'));
		self::assertTrue($links->isGermanLocale('de_DE'));
		self::assertTrue($links->isGermanLocale('de-DE'));
		self::assertTrue($links->isGermanLocale('de_CH'));
		self::assertFalse($links->isGermanLocale('en'));
		self::assertFalse($links->isGermanLocale('fr'));
		self::assertFalse($links->isGermanLocale(''));
		// Must not treat unrelated codes that merely start with "de" as German.
		self::assertFalse($links->isGermanLocale('den'));
		self::assertFalse($links->isGermanLocale('del'));
	}

	public function testPartnerMailtoIsPrimaryAndEncoded(): void {
		$links = new SupportUsLinks('ArbeitszeitCheck');
		$de = $links->partnerMailto('de');
		$en = $links->partnerMailto('en');
		self::assertStringStartsWith('mailto:info@software-by-design.de?subject=', $de);
		self::assertStringContainsString(rawurlencode('ArbeitszeitCheck: Partner / Care Retainer'), $de);
		self::assertStringContainsString(rawurlencode('ArbeitszeitCheck: partner / care retainer'), $en);
		self::assertStringNotContainsString("\n", $de);
		self::assertStringNotContainsString("\r", $en);
	}

	public function testSecondaryMailtosUseCanonicalSubjects(): void {
		$links = new SupportUsLinks('ProjectCheck');
		self::assertStringContainsString(
			rawurlencode('ProjectCheck: Einrichtung / Schulung'),
			$links->onboardingMailto('en')
		);
		self::assertStringContainsString(
			rawurlencode('ProjectCheck: Feature-Auftrag'),
			$links->featureMailto('de')
		);
	}

	public function testWebsiteUrlsAreHttpsAndLocaleAware(): void {
		$links = new SupportUsLinks('BudgetCheck');
		self::assertSame(
			'https://nextcloud.software-by-design.de/de/support.html#packages',
			$links->supportPageUrl('de_DE')
		);
		self::assertSame(
			'https://nextcloud.software-by-design.de/en/support.html#packages',
			$links->supportPageUrl('en')
		);
		self::assertSame(
			'https://nextcloud.software-by-design.de/de/apps.html',
			$links->appsPageUrl('de')
		);
		self::assertSame(
			'https://nextcloud.software-by-design.de/en/apps.html',
			$links->appsPageUrl('en_GB')
		);
		self::assertStringStartsWith('https://', $links->sponsorsUrl());
		self::assertSame(
			'https://github.com/sponsors/aSoftwareByDesignRepository',
			$links->sponsorsUrl()
		);
	}

	public function testMobileLicenseBlockRequiresSafeUrl(): void {
		$links = new SupportUsLinks(
			'ArbeitszeitCheck',
			true,
			'/apps/arbeitszeitcheck/admin/license'
		);
		self::assertTrue($links->hasOfficialMobileLicenses());
		self::assertSame('/apps/arbeitszeitcheck/admin/license', $links->licensePageUrl());

		$this->expectException(\InvalidArgumentException::class);
		new SupportUsLinks('ArbeitszeitCheck', true, null);
	}

	public function testRejectsUnsafeDisplayNames(): void {
		$this->expectException(\InvalidArgumentException::class);
		new SupportUsLinks("Evil\r\nBcc: attacker@example.com");
	}

	public function testRejectsJavascriptLicenseUrl(): void {
		$this->expectException(\InvalidArgumentException::class);
		new SupportUsLinks('ArbeitszeitCheck', true, 'javascript:alert(1)');
	}

	public function testRejectsNonHttpSchemes(): void {
		$this->expectException(\InvalidArgumentException::class);
		new SupportUsLinks('ArbeitszeitCheck', true, 'ftp://files.example/license');
	}

	public function testRejectsLicenseUrlWithUserinfo(): void {
		$this->expectException(\InvalidArgumentException::class);
		new SupportUsLinks('ArbeitszeitCheck', true, 'https://user:pass@evil.example/phish');
	}

	public function testRejectsRelativeLicenseUrlWithAtSign(): void {
		$this->expectException(\InvalidArgumentException::class);
		new SupportUsLinks('ArbeitszeitCheck', true, '/apps/@evil');
	}

	public function testRejectsProtocolRelativeLicenseUrl(): void {
		// "//evil.example/..." resolves to an external origin in browsers.
		$this->expectException(\InvalidArgumentException::class);
		new SupportUsLinks('ArbeitszeitCheck', true, '//evil.example/license');
	}

	public function testForLocalePayloadOmitsPricesAndKeepsHierarchyFields(): void {
		$links = new SupportUsLinks('TicketCheck');
		$payload = $links->forLocale('en');
		self::assertSame('TicketCheck', $payload['appDisplayName']);
		self::assertFalse($payload['hasOfficialMobileLicenses']);
		self::assertNull($payload['licensePageUrl']);
		self::assertArrayHasKey('partnerMailto', $payload);
		self::assertArrayHasKey('onboardingMailto', $payload);
		self::assertArrayHasKey('featureMailto', $payload);
		self::assertArrayHasKey('sponsorsUrl', $payload);
		$json = json_encode($payload, JSON_THROW_ON_ERROR);
		self::assertStringNotContainsString('490', $json);
		self::assertStringNotContainsString('990', $json);
		self::assertStringNotContainsString('€', $json);
	}

	public function testContactConstantsAreStable(): void {
		$links = new SupportUsLinks('MobilityCheck');
		self::assertSame('info@software-by-design.de', $links->contactEmail());
		self::assertSame('mailto:info@software-by-design.de', $links->contactMailto());
		self::assertSame('Software by Design GbR', $links->vendorName());
	}

	public function testAbsoluteHttpsLicenseUrlAccepted(): void {
		$links = new SupportUsLinks(
			'ArbeitszeitCheck',
			true,
			'https://example.local/apps/arbeitszeitcheck/admin/license'
		);
		self::assertSame(
			'https://example.local/apps/arbeitszeitcheck/admin/license',
			$links->licensePageUrl()
		);
	}

	public function testRejectsEmptyDisplayName(): void {
		$this->expectException(\InvalidArgumentException::class);
		new SupportUsLinks('   ');
	}

	public function testMobileFalseIgnoresLicenseUrl(): void {
		$links = new SupportUsLinks('BudgetCheck', false, '/ignored');
		self::assertFalse($links->hasOfficialMobileLicenses());
		self::assertNull($links->licensePageUrl());
	}
}
