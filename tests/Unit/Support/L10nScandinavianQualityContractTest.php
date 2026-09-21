<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

/**
 * Pins Scandinavian locale quality: no Danish bleed into nb/sv, no formalizer
 * pronoun damage ("aktuella administratör"), Kraft seat/bulk strings translated.
 */
final class L10nScandinavianQualityContractTest extends TestCase
{
	private string $appRoot;

	protected function setUp(): void
	{
		parent::setUp();
		$this->appRoot = dirname(__DIR__, 3);
	}

	/** @return array<string, string> */
	private function translations(string $lang): array
	{
		$path = $this->appRoot . '/l10n/' . $lang . '.json';
		$data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
		/** @var array<string, string> $tr */
		$tr = $data['translations'] ?? [];
		return $tr;
	}

	public function testSwedishHasNoDanishBleedOrFormalizerDamage(): void
	{
		$sv = $this->translations('sv');
		$forbidden = [
			'aktuella administratör',
			'Kontakta aktuella',
			'Be aktuella',
			'pladser',
			'afdelinger',
			'brugere',
			'endnu',
			'i brug.',
			'Det er inte',
			'Rettelsesbegäran',
			'tildelt',
			'enhesom',
			'teampolitikker',
		];
		foreach ($sv as $msgid => $text) {
			if (!is_string($text)) {
				continue;
			}
			foreach ($forbidden as $needle) {
				self::assertStringNotContainsString(
					$needle,
					$text,
					"sv must not contain Danish/formalizer bleed '{$needle}' in msgid {$msgid}",
				);
			}
		}
	}

	public function testNorwegianHasNoDanishContamination(): void
	{
		$nb = $this->translations('nb');
		$forbidden = ['angivet', 'Ændringer', 'træder i kraft', 'innstillingerne', 'arbeidstidsmodelllen'];
		foreach ($nb as $msgid => $text) {
			if (!is_string($text)) {
				continue;
			}
			foreach ($forbidden as $needle) {
				self::assertStringNotContainsString(
					$needle,
					$text,
					"nb must not contain Danish '{$needle}' in msgid {$msgid}",
				);
			}
		}
	}

	public function testKraftSeatBulkStringsAreTranslatedInDaNbSv(): void
	{
		$en = $this->translations('en');
		$keys = [
			'Assign selected',
			'Clear selection',
			'Select all on this page',
			'Bulk actions for selected employees',
			'Mobile seats',
			'Offline sync',
			'Ask your administrator to assign a mobile seat or add an organisation license.',
			'This absence was already decided by another manager.',
			'Ask your administrator if you need to record hours yourself.',
			'Clock in/out is off for you. Add a finished work block in one step.',
		];
		foreach (['da', 'nb', 'sv'] as $lang) {
			$tr = $this->translations($lang);
			foreach ($keys as $msgid) {
				self::assertArrayHasKey($msgid, $en, "en missing {$msgid}");
				self::assertArrayHasKey($msgid, $tr, "{$lang} missing {$msgid}");
				self::assertNotSame(
					$en[$msgid],
					$tr[$msgid],
					"{$lang} must translate Kraft/critical string: {$msgid}",
				);
				self::assertNotSame(
					$msgid,
					$tr[$msgid],
					"{$lang} must not leave msgid as value: {$msgid}",
				);
			}
		}
	}

	public function testFormalizerNoLongerRewritesScandinavianPronouns(): void
	{
		$src = (string)file_get_contents($this->appRoot . '/l10n/build_quality_fixes.py');
		self::assertStringNotContainsString(
			'(r"\\bdin\\b", "aktuella")',
			$src,
			'formalize_sv must not rewrite din→aktuella',
		);
		self::assertStringNotContainsString(
			'(r"\\bdin\\b", "den aktuelle")',
			$src,
			'formalize_da must not rewrite din→den aktuelle',
		);
		self::assertStringNotContainsString(
			'(r"\\bdin\\b", "gjeldende")',
			$src,
			'formalize_nb must not rewrite din→gjeldende',
		);
		self::assertStringContainsString(
			'Keep din/ditt/dina/du',
			$src,
			'formalize_sv must document pronoun preservation',
		);
	}
}
