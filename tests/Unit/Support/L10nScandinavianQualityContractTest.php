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

	/**
	 * Case-insensitive containment check: a forbidden lexeme is a cross-language
	 * bleed in ANY casing (residual 2026-10-21: 'Tildelt'/'Korrektion' evaded the
	 * exact-case needles). Needles are lowercased once and compared against the
	 * lowercased value.
	 *
	 * @param array<string, string> $catalog
	 * @param list<string> $forbidden
	 */
	private static function assertNoForbiddenLexemes(array $catalog, array $forbidden, string $lang): void
	{
		foreach ($catalog as $msgid => $text) {
			if (!is_string($text)) {
				continue;
			}
			$haystack = mb_strtolower($text);
			foreach ($forbidden as $needle) {
				self::assertStringNotContainsString(
					mb_strtolower($needle),
					$haystack,
					"{$lang} must not contain foreign-language bleed '{$needle}' in msgid {$msgid}",
				);
			}
		}
	}

	/**
	 * Case-insensitive regex check for bleed shapes a plain substring cannot
	 * express (e.g. sv 'periode' is only Danish when NOT extended to legit
	 * 'perioden/perioder/periods').
	 *
	 * @param array<string, string> $catalog
	 * @param list<string> $patterns PCRE patterns (no delimiters)
	 */
	private static function assertNoForbiddenPatterns(array $catalog, array $patterns, string $lang): void
	{
		foreach ($catalog as $msgid => $text) {
			if (!is_string($text)) {
				continue;
			}
			foreach ($patterns as $pattern) {
				self::assertSame(
					0,
					preg_match('/' . $pattern . '/iu', $text),
					"{$lang} must not match foreign-language bleed /{$pattern}/ in msgid {$msgid}",
				);
			}
		}
	}

	/**
	 * Exact-case containment for needles whose lowercase form collides with
	 * legit target-language words ('Trykke'/'trykke', formal 'Państwa').
	 *
	 * @param array<string, string> $catalog
	 * @param list<string> $forbidden
	 */
	private static function assertNoForbiddenExactCase(array $catalog, array $forbidden, string $lang): void
	{
		foreach ($catalog as $msgid => $text) {
			if (!is_string($text)) {
				continue;
			}
			foreach ($forbidden as $needle) {
				self::assertStringNotContainsString(
					$needle,
					$text,
					"{$lang} must not contain foreign-language bleed '{$needle}' in msgid {$msgid}",
				);
			}
		}
	}

	public function testSwedishHasNoDanishBleedOrFormalizerDamage(): void
	{
		$sv = $this->translations('sv');
		// Danish-only lexemes: none of these are valid Swedish words, so a hit is
		// always cross-language bleed (2026-10-20 r6 + 2026-10-21 residual sweeps).
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
			// 2026-10-21 residuals: da 'oversigt' (sv 'översikt'), untranslated
			// 'download'/'Generer och' truncation; 'trykke' is da/nb (sv 'trycka').
			'oversigt',
			'download',
			'generer och',
			'trykke',
		];
		self::assertNoForbiddenLexemes($sv, $forbidden, 'sv');
		// Danish 'periode' is only a bleed when not extended into the legit
		// Swedish 'perioden/perioder/periods' declensions.
		self::assertNoForbiddenPatterns($sv, ['periode(?![nrs])'], 'sv');
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
			// 2026-10-21 residuals: 'indeholde'/'overskuelig' (da; nb
			// 'inneholde'/'oversiktlig'), 'nedn' MT-truncation, 'kun at'
			// Danish infinitive shape (nb 'å').
			'indeholde',
			'overskuelig',
			'nedn',
		];
		self::assertNoForbiddenLexemes($nb, $forbidden, 'nb');
		// Danish 'der' as existential/relative pronoun (nb 'det'/'som') and
		// Danish 'at'-infinitive (nb 'å') need regex shapes — plain substring
		// would false-positive on legit nb 'der borte' adverbials.
		self::assertNoForbiddenPatterns($nb, [
			'\bder (ble|var|vil|matcher|ligger|kommer|finnes|skal|kan|er)\b',
			'\bat (vise|gjøre|få|være|kunne|bruke|velge|se|slette|sende|ta|sette|aktivere|deaktivere|fortsætte|benytte|tildele|bruge|åbne|lukke|tilføje|fjerne|bekræfte|downloade|udfylde|gemme|registrere)\b',
		], 'nb');
		// 'trykke' is a legit Norwegian verb; only the capitalized 'Print'
		// mistranslation is a bleed.
		self::assertNoForbiddenExactCase($nb, ['Trykke'], 'nb');
	}

	public function testPolishHasNoDutchOrGermanBleed(): void
	{
		$pl = $this->translations('pl');
		// Dutch/German bleed found in the same 2026-10 contamination cluster
		// ('Zespółs en afdelingen', 'Werktijdrapporten', 'Technische … (auwtt)').
		// 2026-10-21 second residual pass added the capitalized/inflected
		// variants that evaded the exact-case needles plus the mangled MT
		// tokens (Reponining, Vollewtg, czwwnload, pongazijn, ptij…).
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
			'found',
			// 2026-10-21 residuals
			'requests',
			'uitklokken',
			'tijdregistratie',
			'toewijzingshistoria',
			'bijwerken',
			'zespółlijst',
			'bewerken',
			'vorige',
			'volgende',
			'periode',
			'bijv',
			'urlop days',
			'ptij',
			'pongazijn',
			'reponining',
			'vollewt',
			'czwwnload',
			'resolutiespoor',
			'nadgodzinyrekening',
			'nadgodzinybetaling',
			'klantbendek',
			'handpontig',
			'rokdni',
			'vergadervoorbereiwtng',
			'projectnaam',
			'projectwerk',
			'succesvol',
			'sobrejestrowano',
			'plaatsen',
			'einde ',
			' tonen',
			'lijst',
			'cios',
		];
		self::assertNoForbiddenLexemes($pl, $forbidden, 'pl');
		// Formal-register 'Państwa/Państwo/Państwu/Państwem/Państwom' is MT
		// register damage (app uses informal 'Twój'); lowercase 'państwow-'
		// (state, e.g. 'święta państwowe') stays legit.
		self::assertNoForbiddenPatterns($pl, ['[\p{L}\s"„]Państw[auom]\b'], 'pl');
		// 'your' must never be rendered by the 'assigned' calque
		// 'przypisan*' (66-string MT formalizer damage fixed 2026-10-21).
		foreach ($pl as $msgid => $text) {
			if (!is_string($text)) {
				continue;
			}
			if (preg_match('/\byour\b/i', $msgid) === 1
				&& preg_match('/assign/i', $msgid) !== 1
			) {
				self::assertSame(
					0,
					preg_match('/przypisan/iu', $text),
					"pl must not render 'your' as 'przypisan*' calque in msgid {$msgid}",
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
