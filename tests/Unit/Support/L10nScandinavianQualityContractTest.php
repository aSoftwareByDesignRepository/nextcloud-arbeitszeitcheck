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
		// Danish-only lexemes: none of these are valid Swedish words, so a hit is
		// always cross-language bleed (2026-10-20 r6 residual sweep).
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
			'Næste',
			'næste',
			'succes',
			'Gyldigt',
			'gyldigt',
			'Forringet',
			'forringet',
			'Pensioneret',
			'pensioneret',
			'genereret',
			'Genereret',
			'nytår',
			'Trykke',
			'sidste',
			'Sidste',
			'gendan',
			'Gendan',
			'kampstart',
			'med succes',
			'overenskomst',
			'kvarfald',
			'opbevaring',
			'Opbevaring',
			'Det er ',
			'ansatte',
			'fravær',
			'medarbeider',
			'korrektion',
			'teamoversigt',
			'holdoversigt',
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
		// Danish-only lexemes for nb. Note: 'tildelt', 'Det er', 'kan ikke være',
		// 'restferie' are valid Norwegian and must NOT be listed here; 'angivet'
		// is Danish in nb (nb: 'angitt') although valid Swedish.
		$forbidden = [
			'angivet',
			'Ændringer',
			'træder i kraft',
			'innstillingerne',
			'arbeidstidsmodelllen',
			'Næste',
			'næste',
			'succes',
			'Gyldigt',
			'gyldigt',
			'Forringet',
			'forringet',
			'Pensioneret',
			'pensioneret',
			'genereret',
			'Genereret',
			'nytår',
			'Trykke',
			'sidste',
			'Sidste',
			'gendan',
			'Gendan',
			'kampstart',
			'med succes',
			'overenskomst',
			'tilbakefald',
			'kvarfald',
			'medtag',
			'korrektion',
			'oppsetten',
			'brugere',
			'afdelinger',
			'når ønsker',
			'Hvis ønsker',
			'om at tildele',
			'tildelt .',
			'holdoversikt',
			'holdferiepolicy',
			'holder pauser',
			'holdt i løbet',
			'løbet',
			'anlæg',
			'misligholdelse',
		];
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

	public function testPolishHasNoDutchOrGermanBleed(): void
	{
		$pl = $this->translations('pl');
		// Dutch/German bleed found in the same 2026-10 contamination cluster
		// ('Zespółs en afdelingen', 'Werktijdrapporten', 'Technische … (auwtt)').
		$forbidden = [
			' en ',
			'vakantie',
			'locaties',
			'afdelingen',
			'Werktijd',
			'eksporteren',
			'genereren',
			'rapportage',
			'auwtt',
			'Zespółs',
			'verlof',
			'medewerker',
			'gebruikers',
			'Technische',
			' niet ',
			'selecteren',
			'moeten',
			'tussen',
			'Afwezighe',
			'logboek',
			'mislukt',
			'liggen',
			' als ',
			'gebruik',
			'aanmaken',
			'gedetecteerd',
			'bruikbaar',
			'downloaden',
			'nachtwerk',
			'geannuleerd',
			'terminalplaatsen',
			'overurenoverzicht',
			'gevonden',
			'overzicht',
			'waarschuwingen',
			'activiteiten',
			'statistieken',
			'revisieveilig',
			'itbetaling',
			'aanvra',
			'aanp',
			' requests',
			' found',
		];
		foreach ($pl as $msgid => $text) {
			if (!is_string($text)) {
				continue;
			}
			foreach ($forbidden as $needle) {
				self::assertStringNotContainsString(
					$needle,
					$text,
					"pl must not contain Dutch/German bleed '{$needle}' in msgid {$msgid}",
				);
			}
		}
	}

	public function testNoCatalogContainsZeroWidthCharacters(): void
	{
		foreach (['da', 'de', 'en', 'es', 'fr', 'it', 'nb', 'nl', 'pl', 'pt', 'pt_BR', 'sv'] as $lang) {
			foreach ($this->translations($lang) as $msgid => $text) {
				if (!is_string($text)) {
					continue;
				}
				self::assertStringNotContainsString(
					"\u{200b}",
					$text,
					"{$lang} must not contain zero-width spaces in msgid {$msgid}",
				);
				self::assertStringNotContainsString(
					"\u{feff}",
					$text,
					"{$lang} must not contain BOM characters in msgid {$msgid}",
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

	// The one-off formalizer script (l10n/build_quality_fixes.py) no longer ships
	// in the app repo — dev tooling lives in the workspace artifacts tree, which
	// shipped tests must not depend on. Its pronoun-preservation contract is
	// enforced where the script lives.
}
