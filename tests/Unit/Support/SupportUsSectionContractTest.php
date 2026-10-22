<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Static contract: Support & Us template keeps CTA hierarchy and security attributes.
 */
final class SupportUsSectionContractTest extends TestCase {
	private function template(): string {
		$path = dirname(__DIR__, 3) . '/templates/parts/support-us-section.php';
		$src = file_get_contents($path);
		self::assertNotFalse($src);
		return $src;
	}

	public function testPrimaryCtaIsPartnerMailtoNotSponsors(): void {
		$src = $this->template();
		$partnerPos = strpos($src, 'partnerMailto');
		$sponsorsPos = strpos($src, 'sponsorsUrl');
		self::assertNotFalse($partnerPos);
		self::assertNotFalse($sponsorsPos);
		self::assertLessThan($sponsorsPos, $partnerPos, 'Partner CTA must appear before Sponsors');
		self::assertStringContainsString('Ask for a partner offer', $src);
		self::assertStringContainsString('Check Partner', $src);
		self::assertStringContainsString('invoiceable service', $src);
		self::assertStringContainsString('individual partner offer', $src);
		self::assertStringContainsString('data-support-us="1"', $src);
	}

	public function testExternalLinksUseNoopenerNoreferrer(): void {
		$src = $this->template();
		self::assertSame(
			substr_count($src, 'target="_blank"'),
			substr_count($src, 'rel="noopener noreferrer"')
		);
		self::assertGreaterThanOrEqual(2, substr_count($src, 'rel="noopener noreferrer"'));
	}

	public function testNoHardCodedPrices(): void {
		$src = $this->template();
		self::assertStringNotContainsString('490', $src);
		self::assertStringNotContainsString('990', $src);
		self::assertStringNotContainsString('€', $src);
		self::assertStringNotContainsString('EUR', $src);
	}

	public function testAccessibilityHooksPresent(): void {
		$src = $this->template();
		self::assertStringContainsString('aria-labelledby', $src);
		self::assertStringContainsString('aria-describedby', $src);
		self::assertStringContainsString('role="group"', $src);
		self::assertStringContainsString('Support & us', $src);
		self::assertStringContainsString('aria-hidden="true"', $src);
		self::assertStringContainsString('support-us__option-title', $src);
		self::assertStringContainsString('support-us__options', $src);
		self::assertStringContainsString('cta--secondary', $src);
		self::assertStringContainsString('supportUsPresentation', $src);
		self::assertStringContainsString('data-support-us-presentation', $src);
		self::assertStringContainsString('-support-us-secondary-label', $src);
		self::assertStringContainsString('(opens in a new tab)', $src);
		self::assertStringContainsString("'Setup & training'", $src);
		self::assertStringContainsString("'Ask about setup or training'", $src);
		self::assertStringContainsString("'Commissioned feature'", $src);
		self::assertStringContainsString("'Request a commissioned feature'", $src);
		self::assertStringContainsString("'Mobile & terminal'", $src);
	}

	public function testSupportUsLivesOnDedicatedAdminPageNotSettingsEmbed(): void {
		$root = dirname(__DIR__, 3);
		$page = $root . '/templates/admin-support-us.php';
		if (!is_file($page)) {
			$page = $root . '/templates/support-us.php';
		}
		if (!is_file($page)) {
			self::markTestSkipped('No dedicated Support & us page in this app');
		}
		$src = (string)file_get_contents($page);
		self::assertStringContainsString('support-us-section.php', $src);
		self::assertStringContainsString("supportUsPresentation = 'page'", $src);
		if (is_file($root . '/img/vendor-logo-mark.png')) {
			self::assertStringContainsString('vendor-logo-mark.png', $src);
		}
		$settings = $root . '/templates/admin-settings.php';
		if (is_file($settings)) {
			$adminSettings = (string)file_get_contents($settings);
			self::assertStringNotContainsString('#azc-support-us-title', $adminSettings);
		}
	}

	public function testMobileLicenseBlockIsConditional(): void {
		$src = $this->template();
		self::assertStringContainsString('hasOfficialMobileLicenses', $src);
		self::assertStringContainsString('Official mobile & terminal licenses', $src);
		self::assertStringContainsString('software licence on invoice', $src);
		self::assertStringContainsString('billed as a service', $src);
		self::assertStringContainsString('billed as project work', $src);
		self::assertStringContainsString(
			'bookable help on an invoice — or official mobile licenses — choose an option below:',
			$src
		);
		self::assertStringContainsString(
			'bookable help on an invoice, choose an option below:',
			$src
		);
	}

	public function testCssContractHasFocusAndReducedMotion(): void {
		$root = dirname(__DIR__, 3);
		$candidates = [
			$root . '/css/app.css',
			$root . '/css/admin-settings.css',
			$root . '/css/admin-support-us.css',
			$root . '/css/azc.css',
		];
		$css = '';
		foreach ($candidates as $path) {
			if (is_file($path)) {
				$css .= (string)file_get_contents($path);
			}
		}
		self::assertStringContainsString('azc-support-us', $css);
		self::assertStringContainsString(':focus-visible', $css);
		self::assertStringContainsString('prefers-reduced-motion', $css);
		self::assertStringContainsString('prefers-contrast', $css);
		self::assertStringContainsString('forced-colors', $css);
		self::assertStringContainsString('min-height: 44px', $css);
		self::assertStringContainsString('minmax(min(100%, 16rem)', $css);
		self::assertStringContainsString('@media (max-width: 768.98px)', $css);
		self::assertStringContainsString('support-us__option', $css);
		self::assertStringContainsString('support-us__options', $css);
		self::assertStringContainsString('support-us__benefit', $css);
		self::assertStringContainsString('support-us__coverage', $css);
		self::assertStringContainsString('var(--color-primary-element)', $css);
		self::assertStringContainsString('var(--color-main-background)', $css);
		self::assertStringContainsString('var(--color-main-text)', $css);
		$dedicated = $root . '/css/admin-support-us.css';
		if (is_file($dedicated)) {
			$pageCss = (string)file_get_contents($dedicated);
			self::assertStringContainsString('max-width: none', $pageCss);
			self::assertStringContainsString('#app-content.azc-app--admin-support-us ul.azc-support-us-page__trust', $pageCss);
			self::assertStringContainsString('minmax(min(100%, 16rem)', $pageCss);
			self::assertStringContainsString('prefers-contrast', $pageCss);
			self::assertStringContainsString('forced-colors', $pageCss);
			self::assertMatchesRegularExpression(
				'/\.azc-support-us--page \.azc-support-us__primary\s*\{[^}]*max-width:\s*none/s',
				$pageCss,
				'Partner spotlight on the dedicated page must stay full-width'
			);
		}
	}
}
