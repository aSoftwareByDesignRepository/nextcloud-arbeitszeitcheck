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
		// 2026-10-22 r7 third-cluster fix: systematic signatures replace the
		// enumerative needle lists that missed the residual contamination.
		//
		// Layer 0 — orthography: Swedish never writes æ/ø outside loaned proper
		// nouns (none occur in this catalog); any hit is Danish/Norwegian bleed.
		self::assertNoForbiddenPatterns($sv, ['[\x{00e6}\x{00f8}\x{00c6}\x{00d8}]'], 'sv');
		// Danish-only lexemes: none of these are valid Swedish words at a word
		// boundary, so a hit is always cross-language bleed (2026-10-20 r6 +
		// 2026-10-21/22 residual sweeps). 'taget' is exempt only inside the
		// Swedish idiom 'i taget' (one at a time); elsewhere it is Danish
		// ('tagna/tagit' is Swedish).
		self::assertNoForbiddenPatterns($sv, [
			// function words + interrogatives (da; sv och/jag/var/vad/hur…)
			'\b(ikke|og|jeg|der|hvad|hvem|hvordan|hvorfor|hvor|også|endnu|allerede|kun|til|fra|for|mellem|gennem|før|hver|hvert|intet|andre|anden|disse|hvilke|samme|begge|noget|nogle|lidt|meget|mere|sidste|næste|første|senest|først|straks)\b',
			// nouns (da; sv orsak/meddelande/förklaring/inställning/undantag…)
			'\b(årsag\w*|mennesker|besked|forklaring|meddelelse\w*|begivenhed|indstilling\w*|eksempel|mulighed\w*|fejl\b|advarsel|tilføjelse|ændring\w*|forbedring|godkendelse|afvisning|afslag|anmodning|beslutning|tilstand|betingelse|aftale|overenskomst|arbejde\b|arbejdstid|orlov\b|sygdom|fravær|natarbejde|overarbejde|overtid|helligdag|fridag|måned\w*|uge\b|uger|dage\b|minutter|aar\b|dato\b|bruger\w*|medarbejder|ansat\b|hold\b|gruppe\b|enhed|afdeling\w*|stilling|rolle\b|tilladelse|adgang\b|adgangskode|abonnement|betaling|udbetaling\w*|beløb|vagt\b|eksport\w*|mappe\b|skærm|knap\b|felt\b|kolonne|række|side\b|valg\b|værdi\w*|beskrivelse|tekst\b|sprog\b|adresse\b|gade\b|overblik|postkasse|undtagelse|ret\b)\b',
			// adjectives/participles (da -ske/-et/-ede forms vs sv -ska/-t/-ade)
			'\b(aktiveret|deaktiveret|registreret|vellykket|gemt\b|ændret|valgt|tilføjet|oprettet|slettet|redigeret|fuldt|sluttime|taget(?<!i taget)|marts\b|nedgang|retning|hurtig\w*|parametre|datofilter|forhåndsvisning\w*|indgange|kladde\w*|tidligere|yderligere|gennemsnit\w*|frembring\w*|forenklet|gyldig|mangler|automatiske|tekniske|praktiske|juridiske|statistiske|årlige|daglige|ugentlige|månedlige|personlige|offentlige|tilgængelige|mulige|nødvendige|godkendte|afviste|registrerede|anvendte|oprettede|slettede|redigerede|ændrede|gemte|forsøgte|ventede|begyndte|sluttede|fandt|havde|lille|gammel|manuel\b|manuelle\w*)\b',
			// verbs + passive -eres forms (da -e/-es vs sv -a/-as)
			'\b(skabe\w*|vælg\w*|ændre\b|opret\w*|slette|redigere|gemme|downloade|uploade|sende|modtage|tilføje|fjerne|flytte|kopiere|skjule|åbne|lukke|fortsætte|afbryde|annullere|bekræfte|afvise|godkende|ansøge|anmode|spørge|svare|fortælle|forklare|beskrive|angive|indtaste|udfylde|kontrollere|tjekke|beregne|tælle|sammenligne|bruge|anvende|benytte|gøre|blive|være|have\b|kunne|måtte|burde|behøve|hjælpe|forsøge|håbe|ønske|finde|søge|lede|miste|tabe|køre|rejse|sove|spise|drikke|arbejde|sidde|ligge|lægge|sætte|holde|bære|bringe|føre|følge|møde|kende|vide|mene|synes|forstå|huske|glemme|lære|læse|skrive|kalde|hede|sige|tale|snakke|høre|lytte|kigge|tage|komme|lave|stoppe|starte|vise|reserveres|anvendes|aktiveres|deaktiveres|tillades|opdateres|erstattes|vises|slettes|oprettes|redigeres|tilføjes|skiftes|gemmes|benyttes|foretages|udføres|udfyldes|bekræftes|afvises|godkendes|beregnes|ændres|sendes|godkendte|godkendt)\b',
			// Danish infinitive marker 'at' (sv 'att')
			'\bat (vise|gjøre|få|være|kunne|bruge|velge|se|slette|sende|ta|sette|aktivere|deaktivere|fortsætte|benytte|tildele|bruge|åbne|lukke|tilføje|fjerne|bekræfte|downloade|udfylde|gemme|registrere|teame|tækka|täcka)\b',
			// 'stem ind' clock-in calque (da 'stem ind'; sv 'stämpla in')
			'\bstem\b ind\b',
			// Norwegian-imperative bleed: nb drops the sv infinitive -a
			// ('Rediger frånvarosbegäran' → sv 'Redigera …'). nb-only forms —
			// 'lagre'/'slett'/'opprett'/'velg'/'åpne'/'lukk'/'skjul'/'endre'/
			// 'oppgi'/'avklar'/'bestem'/'oppdater'/'forsøk'/'tilbakekall' —
			// have no valid Swedish reading at a word boundary.
			'\b(rediger|lagre|slett|opprett|velg|åpne|lukk|skjul|endre|oppgi|avklar|bestem|oppdater|forsøk|tilbakekall|send\b|godkjenn|avvis|viser|lagres|endres|opprettes|tildeles|godkjennes|planlegges|beregnes|behandles|gjennomføres|utføres|velges|åpnes|lukkes|skjules|oppdateres|overføres|importeres|eksporteres|lastes)\b',
			// missing-subject defect class: subordinate adverbs immediately
			// followed by a conjugated verb ('om vill spara' → sv requires
			// 'om du vill spara'). 'när'/question shapes are excluded —
			// 'När slutar din frånvaro?' is a legitimate V2 question.
			'\b(om|att|förrän|innan|eftersom)\s+(vill|är|har|kan|ska|måste|behöver|väljer|anger|fyller|laddar|trycker|klickar|bokar|börjar|slutar|registrerar|arbetar|leder|ansvarar|förväntar|förväntade|konfigurerar|konfigurerat|sparar|redigerar|stoppar|fortsätter)\b',
			// da/nb 'leder' as clause-final verb ('medarbetare leder' →
			// sv 'du ansvarar för'); 'leder till' is legit Swedish
			'\b(team|medarbetare|personer|anställda|användare)\s+leder\b(?!\s+till)',
			// 2026-10-22 third-cluster families. Every stem below is a Danish
			// form with no valid Swedish reading at a word boundary — months
			// and weekdays that diverge (sv januari/februari/mars/augusti/
			// måndag/tisdag/lördag/söndag), da ordinals + the '-tedel' fraction
			// family (sv fjärde/sjunde/…/hundradel/tusendel/miljonte), da-only
			// function words (alle/hele/selv/siden/indtil/enhver), da verbs
			// (indsende/fjerne/erstatte/nedlastning), da participles
			// (-et/-ede: styret/tildelt/genereret/filtrerede/gjort/virket/
			// lukket/optaget/afbrudt), and the enum/domain vocabulary the
			// cluster leaked (kohorte/selskab/etage/dybde/afsnit/opgave/seer/
			// areal/loft/niveau/plads/lufthavn/stikkontakt/knudepunkt/
			// forarbejdning/forretning/bidragyder/genstande/påmindelse/
			// udvikling/websted/enhed/ejer/havn/plante/komplet/kopi/personale/
			// historikken/begäraner-mangle/hovedind*-mangle).
			'\b(alle|hele|selv|siden|indtil|enhver|fremad|tilbage|over|mange|vælg\w*|indsend\w*|fjern\w*|erstatte|erstatning|nedlastning|gendan|styret|tildelt|genereret|filtrerede|resultater|virket|lukket|kommet|laver|håndterer|vises|kopi|ejer|havn|plante|komplet|personale|politikker|teampolitik\w*|kohortepolitik\w*|holdvarsl\w*|tarifregel\w*|holdliste|holdstatistik|holdstatus|kohorte\w*|selskab|etage|dybde|afsnit|opgave|seer|besat|areal|loft|niveau|levende|plads|optaget|afbrudt|handicappet|lufthavn|stikkontakt|knudepunkt|forarbejdning|forretning|bidragyder|genstande|påmindelse|udvikling|websted|enhed\w*|arbejdsgiver|tidszone|indbakke|virksomhed|vindue|begrundelse|opbevaring|tidligere|yderligere|gennemsnit\w*|arbejd\w*|fjernarbejde|kontorarbejde|uberettiget|uafklaret|tillidsbaseret|nedgang|retning|datofilter|kladde|indgange|sluttime|ugen|august|mandag|tirsdag|fjerde|sjette|syvende|ottende|niende|tiende|elvte|tolvte|trettende|fjortende|femtende|sekstende|syttende|attende|nittende|tyvende|tredivte|begäraner|hovedind\w*)\b',
			// da fraction ordinals end in -tedel (hundrededel, tusindedel,
			// milliardtedel, …); sv uses -del/-iljonte (hundradel, miljonte).
			'\w+tedel\b',
			// da high ordinals '-sindstyvende' (halvtredsindstyvende, …);
			// sv uses -tionde forms.
			'\w+indstyvende\b',
			// 2026-11-05 r8 fourth-cluster: every Danish form that survived
			// rounds 1–3 inside otherwise-Swedish values. All of these were
			// verified to have no valid Swedish reading at the stated boundary
			// (sv godkänt/behov/nästa/registrera/justering/omfattning/
			// överlappningsregler/välkommen/…). Unbounded stems catch the
			// compound variants the word-boundary version missed
			// ('Semestertilstand', 'indgangsdetaljer', 'varslingsregler').
			'\b(afventer|afvis|afbalancere|aflyst|aktive|arve|behandle|beregn\w*|berettiget|erstatter|filtre|forbind|forudsat|fundet|genberegner\w*|genoptag|godkännt|hinanden|hviletid|importeret|inkluderer|installeret|juster|koble|kommaseparerede|konfigurer|konfigureret|kopier|kundeprojekter|medarbetaree|mindst|modtagere|naviger|nylige|opfyldt|overensstemmende|rastn|registrer|samlede|seneste|skabt|startede|totale|matchende|utbetalninger|valgte|velkommen|chefopgave|opgave)\b',
			'(semestertilstand|indgangsdetaljer|varslingsregler|tilstand|overlapningsregler|omfang)',
			// 2026-11-05 r8 residual-family supplements: da spellings that
			// are absent from the da catalog itself, so the donor-vocab
			// lint cannot see them. All have no valid Swedish reading:
			// sv valfritt/prioriterad svarstid/uppdelning/ingångs-/övertid.
			// 'overtid'/'indgang' unbounded stems catch every compound
			// ('overtidsregistrering', 'indgangsinformation').
			'\bvalfrit\b|\w*(prioritetsvar|exportsplitt|indgang|overtid)\w*',
			// r8 sibling-mangle sweep: 'administrator' is the da/de spelling;
			// Swedish only has 'administratör'. Caught 2 live residuals
			// ('Lägg till administrator' for 'Add manager').
			'\badministrator\w*',
			// sentence-initial/implicit-subject calques: Danish/MT drops the
			// required Swedish subject pronoun ('Om var sjuk', 'slutade
			// arbeta', 'får hantera'). Catches the verb-second violation a
			// token lint cannot see.
			'\b(om|att|innan|eftersom|förrän)\s+(vill|är|har|kan|ska|måste|behöver|var|fick|kommer|åkte|reste|arbetade|sov)\s',
			// Danish digraphs that never occur in Swedish outside proper
			// nouns: 'aa' ('Aargau' is the one legit canton name), 'ij',
			// word-final 'tn' ('Rastn'), and the 'wt' MT insertion.
			'\b(?!aargau\b)\w*aa\w*',
			'\w*(ij|wt)\w*',
			'\wtn\b',
		], 'sv');
		// Layer 4 — donor-vocabulary token lint (fail-closed, systematic).
		// Any sv token spelled verbatim in the Danish catalog that is not
		// English (msgid/value technical passthrough) and not a registered
		// legit shared token is a bleed suspect. This is the catalog-internal
		// OOV approximation: CI has no hunspell/enchant dictionaries, so the
		// donor vocabularies stand in for a Danish lexicon. Registry
		// LEGIT_SV_DA_TOKENS = the post-fix intersection, reviewed
		// token-by-token (all legit shared Scandinavian vocabulary); a new
		// hit requires human verification before allowlisting.
		self::assertNoDonorVocabTokens(
			$sv,
			['da'],
			self::LEGIT_SV_DA_TOKENS,
			'sv',
			$this->appRoot,
		);
		// Layer 5 — implicit-subject scan: modal + active infinitive whose
		// clause window contains neither a subject (pronoun or content
		// noun) nor a coordinator ('och kan se' shares the earlier
		// subject). Catches 'Välj hur vill spara', 'får hantera',
		// 'slutade arbeta' — defects with all-legit tokens.
		self::assertNoImplicitSubjectCalques($sv, 'sv');
		// Layer 6 — sentence-initial conjugated verb: a value (or a
		// sentence after '. ! ?') starting with a verb form that is never
		// imperative is missing its subject ('är redan instämplad',
		// 'har redan arbetat'). Terse label openers that are idiomatic
		// fragments are registered.
		self::assertNoSubjectlessSentenceOpeners($sv, 'sv');
		// Layer 3 — da↔sv identical-value comparison. Any value spelled
		// identically in da and sv that is not the English msgid itself and
		// not a registered pan-Scandinavian shared form is a Danish bleed
		// suspect ('Alle', 'Uge', 'Timer', 'Kopi', 'Erstatte', 'Hele …' all
		// evaded every needle list this way). Legit identicals — shared
		// vocabulary ('Aktiv', 'Kalender'), proper names ('Kanton Bern',
		// 'Zürich'), abbreviations ('PIN', 'HR') — live in the registry
		// below; a new identical requires a human to verify it is genuinely
		// shared before allowlisting (fail-closed).
		$this->assertNoUnregisteredDanishIdenticals($sv, $this->translations('da'), $this->translations('en'), 'sv');
		// Layer 7 — English-run lint: ≥3 consecutive English tokens is
		// residual untranslated text, not a loanword.
		self::assertNoEnglishRuns($sv, self::LEGIT_EN_RUNS, 'sv', $this->appRoot);
	}

	/**
	 * Pan-Scandinavian shared forms: values legitimately spelled identically
	 * in Danish and Swedish (shared vocabulary, proper names, abbreviations,
	 * format strings). NOT an enumeration of past defects — the baseline of
	 * known-legit identicals for the da↔sv identical-value comparison.
	 *
	 * @var list<string>
	 */
	private const SHARED_DA_SV_IDENTICALS = [
		'%1$s t', '%1$s → %2$s', '%n teammedlem', '(ingen)', '0 poster',
		'1 hel dag', '1 medlem', 'Administration', 'Administration (panel)',
		'Aktiv', 'Aktiv i dag', 'Aldrig', 'ArbeitszeitCheck Mobile på Google Play',
		'ArbeitszeitCheck-version:', 'Automatisk', 'Beta', 'Cykel',
		'DATEV Lohnart-koder', 'DATEV-personalnummer', 'Dag', 'Dag (1-31)',
		'Dagens statistik', 'Daglig rapport', 'Deldag', 'Delstat: %1$s', 'Deltid',
		'Destinationen',
		'Detaljer', 'Din ArbeitszeitCheck-kiosk-PIN', 'Din status',
		'Dokumentation', 'Dokumentversion', 'Drift', 'Effektiv', 'Enkel',
		'Excel-fil (XLSX)', 'FA', 'Fast?', 'Filformat',
		'Florians dag', 'Fre', 'Funktioner', 'Genève', 'HR', 'HR-kontor',
		'Halv dag', 'Hej %s,', 'Hel dag', 'I dag', 'I dag: %1$s t · Session: %2$s',
		'I år', 'Inaktiv', 'Ingen', 'Ja', 'Kalender', 'Kalenderikon', 'Kalenderår',
		'Kamp', 'Kanton Aargau', 'Kanton Appenzell Ausserrhoden',
		'Kanton Appenzell Innerrhoden', 'Kanton Basel-Landschaft',
		'Kanton Basel-Stadt', 'Kanton Bern', 'Kanton Burgenland',
		'Kanton Fribourg', 'Kanton Glarus', 'Kanton Graubünden', 'Kanton Jura',
		'Kanton Kärnten', 'Kanton Neuchâtel', 'Kanton Nidwalden',
		'Kanton Niederösterreich', 'Kanton Oberösterreich', 'Kanton Obwalden',
		'Kanton Salzburg', 'Kanton Schaffhausen', 'Kanton Schwyz',
		'Kanton Solothurn', 'Kanton St. Gallen', 'Kanton Steiermark',
		'Kanton Thurgau', 'Kanton Ticino', 'Kanton Valais', 'Kanton Vaud',
		'Kanton Vorarlberg', 'Kanton Wien', 'Kategori', 'Kioskterminal',
		'Reformationsdagen',
		'Kioskterminaler', 'Klar', 'Konto', 'Kontor', 'Korrekt',
		'L0 · Organisationsstandard', 'Land', 'Leopolds dag', 'Licens', 'Luzern',
		'Maj', 'Mandantnummer', 'Min status', 'Moderator', 'Modul %1$s: %2$s',
		'Moduler', 'Mål', 'Nej', 'Netto', 'Nextcloud-instans-ID',
		'Normal pendling', 'Ny PIN', 'Ny version', 'Om', 'Ons',
		'Organisation', 'Organisationen', 'Organisationslicens',
		'Organisationsstruktur', 'Placering',
		'Post-ID: %1$s', 'Prioritet', 'Pro-rata-regel', 'Procent', 'Projekt',
		'Projekt #%s', 'Rapporter', 'Region (område)', 'Registreringsdetaljer',
		'Regler', 'Resultat', 'Ruperts dag', 'Sats', 'Schweiz', 'Session (aktiv)',
		'Session: %1$s (aktiv)', 'Slut', 'Slutminut', 'Sluttid',
		'Starttid', 'Station', 'Statistik', 'Status: %1$s - I dag: %2$s t',
		'Status: %1$s, i dag: %2$s t', 'Statusfilter', 'Struktur',
		'System (modul)', 'Teammedlem', 'Terminal-app på Google Play',
		'Terminaler', 'Tidslinje', 'Tidslinjevisning', 'Tidsregistrering',
		'Tidsregistrering har ingen starttid', 'Tidszoner', 'Tor', 'Tyskland',
		'Undertid', 'Versionsnummer', 'Versionsnummer:', 'Zürich', 'alfa',
		'april', 'butik', 'centrum', 'dag', 'dd.mm.åååå', 'december', 'distrikt',
		'falsk', 'fast', 'femte', 'filial', 'fredag', 'gratis', 'gård',
		'historik', 'i dag', 'ingen', 'ja', 'jord', 'jul', 'juli', 'juni',
		'klient', 'kontor', 'lager', 'ledig', 'medlem', 'mindre', 'nej',
		'notifikation', 'november', 'obligatorisk', 'oktober', 'område',
		'onsdag', 'organisation', 'organisationsregel', 'parkering', 'personer',
		'prioritet', 'produktion', 'projekt', 'punkt', 'september', 'stabil', 'standard',
		'status', 'tag', 'territorium', 'tom', 'torsdag', 'tredje',
		'{count} poster', 'År', 'år',
	];

	/**
	 * Fails when a value is spelled identically in the source-language
	 * catalog (da) and the target catalog without being a registered shared
	 * form — the recurring shape of cross-language MT contamination that
	 * needle lists keep missing.
	 *
	 * @param array<string, string|list<string>> $target
	 * @param array<string, string|list<string>> $source
	 * @param array<string, string|list<string>> $en
	 */
	private function assertNoUnregisteredDanishIdenticals(array $target, array $source, array $en, string $lang): void
	{
		// Identical to the English text → untranslated/proper noun, not a
		// da→sv copy (completeness is a separate class). Implementation
		// lives in the generalized assertNoUnregisteredIdenticals below.
		self::assertNoUnregisteredIdenticals(
			$target,
			$source,
			$en,
			self::SHARED_DA_SV_IDENTICALS,
			$lang,
			'da',
		);
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
			// 2026-11-05 r8 nb sweep: verbatim-da values that evaded the
			// identicals check because nb↔da share ~945 legit identicals.
			// All have no valid Bokmål reading: nb vinner/fulle/høyere/
			// kjede/utgang/teamdybde.
			'vinder',
			'fulde',
			'højere',
			'udgang',
			'holddybde',
			'lader se',
		];
		self::assertNoForbiddenLexemes($nb, $forbidden, 'nb');
		// Danish 'der' as existential/relative pronoun (nb 'det'/'som') and
		// Danish 'at'-infinitive (nb 'å') need regex shapes — plain substring
		// would false-positive on legit nb 'der borte' adverbials.
		self::assertNoForbiddenPatterns($nb, [
			'\bder (ble|var|vil|matcher|ligger|kommer|finnes|skal|kan|er)\b',
			// r8 sibling-mangle sweep: 'Bloker månedsavlutning' — 'bloker'
			// is neither nb 'blokker' nor da 'bloker' (da 'blokere'); and
			// 'avlutning' is a dropped-s mangle of nb 'avslutning' that no
			// Scandinavian vocabulary contains. Unbounded stems catch the
			// compound variants.
			'\w*(bloker|avlutning)\w*',
			'\bat (vise|gjøre|få|være|kunne|bruke|velge|se|slette|sende|ta|sette|aktivere|deaktivere|fortsætte|benytte|tildele|bruge|åbne|lukke|tilføje|fjerne|bekræfte|downloade|udfylde|gemme|registrere)\b',
			// da '-kæde' compounds (prioritetskæde/forrangskæde; nb '-kjede')
			// and da 'feriedage' (nb 'feriedager') — boundary-guarded so legit
			// nb 'feriedager'/'kjede' pass.
			'\w*kæde\w*',
			'\bferiedage\b',
			// da 'lader' = let (nb 'lar'); the nb noun 'lader' (charger) is
			// protected by requiring a following pronoun/verb.
			'\blader (se|deg|oss|dem|bli|ligge|være)\b',
			// 2026-11-05 r8 dictionary-verified nb residual family: ~115
			// values carried da spellings with no valid Bokmål reading
			// (nb avløser/avlyst/utvidet/utbetalt/ukjent/ivareta/kjøre/
			// inngang/forutsatt/plassert/pågeldende/inkludert/installert/
			// kopiert/importert/registrerte/konfigurerte/rekkefølge/
			// begrunnelse/samsvar/oppdeling/korrigering/sertifikat/
			// kontroll/generell/blikk/manuell/komplett/månedlig/
			// ekvivalente/eldre/ekte/atferd/reise/fremmøte/jubileum/
			// knutepunkt/ufullstendig/gjenstander/nettsted/avsnitt/
			// lønnsfiler/vindu/lokasjoner/butikk/gjest/kjeller/gjelde/
			// åttende/førtiende/bidragsyter/alvorlig/lovlig/redusere/
			// akseptere/individuell/angis/ferierettighets*/ikrafttredelse/
			// dashbord/stemplingsur/e-postvarsler/varslingsoppsett).
			'\b(afløser\w*|aflyst|afbrudt|afprøvning|afsnit|alvorligt|angives|begrundelse|berigtigelse|bidragyder|blik\b|butik\b|certifikat|forudsat|genløst|genoptag\w*|genstande|gælde|gæst\w*|ikrafttrædelse\w*|importeret|indgang\w*|individuel\b|inkluderet|installeret|iscenesættelse|instrumentbræt|knudepunkt|komplet\b|konfigurerede|kontrol\b|kopieret|kælder|køre\b|lokationer|lovligt|lønningsfiler|manuel\b|månedligt|opdeling|opfyldt|optage|ottende|fyrretyvende|sindstyvende|placeret|pågældende|reducer\b|accepter\b|registrerede|rækkefølge|udbetalt|udløst|ufuldstændig|ugedag\w*|ukendt|varetage|vindue\b|websted|ældre\b|ægte\b|ækvivalente|adfærd|forældre\w*|forældet|forretningsrejse\w*|fremmøde\w*|ferieretlige|fuld\b|e-mailvarsler|varselsopsætning|ansættelsesjubilæum|overensstemmelse\w*|generel\b|ferieret\b|modelferie\w*)\b',
		], 'nb');
		// 'trykke' is a legit Norwegian verb; only the capitalized 'Print'
		// mistranslation is a bleed.
		self::assertNoForbiddenExactCase($nb, ['Trykke'], 'nb');
		// Layer 4 — donor-vocabulary token lint nb←da (same shape as
		// sv←da). Earlier rounds claimed nb↔da was ungateable because ~945
		// identical *values* are legitimate — but token-level lint still
		// discriminates: a da token with no valid Bokmål reading inside an
		// nb value is a bleed suspect. The legit shared token mass lives
		// in tests/Fixtures/l10n/legit-tokens-nb-da.txt (1,514 reviewed
		// shared Scandinavian tokens — every current nb-catalog token
		// spelled verbatim in the da vocabulary; adding a new token
		// requires human verification).
		self::assertNoDonorVocabTokens(
			$nb,
			['da'],
			self::fixtureTokens('legit-tokens-nb-da'),
			'nb',
			$this->appRoot,
		);
		// English-run lint (same shape as sv/pl).
		self::assertNoEnglishRuns($nb, self::LEGIT_EN_RUNS, 'nb', $this->appRoot);
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
		// (state, e.g. 'święta państwowe') stays legit. 2026-11-05 r8: the
		// 'Pana/Pani' third-person formalizer cluster (16 strings) — exact
		// case only, since lowercase 'pana/pani' is legit Polish.
		self::assertNoForbiddenPatterns($pl, ['[\p{L}\s"„]Państw[auom]\b'], 'pl');
		self::assertNoForbiddenExactCase($pl, ['Pana/Pani', '/Pani', 'Pana/', 'Pana ', 'Pani ', 'Panu '], 'pl');
		// 2026-10-22 r7 cluster: systematic Dutch-lexeme signature — every word
		// has no valid Polish reading at a word boundary ('Naar hoofd*',
		// 'naam/naleving/beleid', 'ploegen*/rondleiding*', 'verlopen/ongeld*',
		// 'tarief*/ingangsdata', everyday nl vocabulary).
		self::assertNoForbiddenPatterns($pl, [
			'\b(naar|hoofd\w*|naam\w*|naleving|beleid|ploegen\w*|rondleiding\w*|ongeld\w*|verlopen|overslaan|tarief\w*|ingangsdata|zijn|het|een|van|voor|werken|pauze|verzoek|afkeuren|goedkeuren|opslaan|verwijderen|wijzigen|toevoegen|annuleren|bevestigen|gegevens|vandaag|morgen|gisteren|gebruiker\w*|werknemer\w*|werkgever\w*|bedrijf|afdeling\w*|instellingen|uren|dagen|weken|maanden|jaar|maand|week\b|medewerkers|overuren|feestdagen|toestemming|melding|eigen|klopt|vinden|zoeken|nieuw|hele|halve|niet|als|met|gesniskid|użytkowniksdane)\b',
			// 2026-10-22 third-cluster hardening: systematic Dutch signature.
			// Every stem is a Dutch word with no valid Polish reading at a word
			// boundary — articles/prepositions/conjunctions (het/de/een/van/
			// voor/met/aan/tot/uit/bij/over/onder/door/zonder/tussen/naast/
			// tijdens/naar/maar/omdat/terwijl/zodra/hoewel/zodat/indien/mits),
			// pronouns/demonstratives (deze/dit/die/dat/elk/elke/ieder/alle/
			// beide/andere/geen/niets/iets/alles/iedereen/niemand/iemand/zich/
			// zelf/zelfde/mijn/jouw/zijn/haar/ons/onze/uw/hun/me/hem/hen/wij/
			// jullie/zij/ik), adverbs (nu/dan/als/ook/niet/wel/nee/nog/altijd/
			// nooit/vaak/soms/alleen/slechts/zelfs/misschien/waarschijnlijk/
			// zeker/bijna/ongeveer/straks/zo/direct/meteen/snel/meer/meest/
			// minder/minst/erg/zeer/heel/vrij/genoeg/even/bovendien/echter/
			// toch/immers/namelijk/daarna/verder/eindelijk), time units (dag/
			// week/maand/jaar/uur/tijd/datum/weekdag/vandaag/morgen/gisteren/
			// minuten/jaren), domain nouns (werkdag/dienst/ploegendienst/
			// nachtdienst/verlof/vakantie/feestdag/ziekte/overwerk/overuren/
			// uitbetaling/loon/salaris/aanwezig/afwezig/afwezigheid/melding/
			// instelling/rapport/overzicht/bestand/toegang/wachtwoord/beheer/
			// taak/aanvraag/goedkeuring/waarschuwing/fout/succes/gelukt/
			// teamlid/teamleden/groep/categorie/veld/kolom/rij/tabel/lijst/
			// versie/voorbeeld/sjabloon/standaard/aangepast/optie/keuze/
			// weergave/zicht/scherm/knop/venster/dialoog/meldingen/notificatie/
			// herinnering/notitie/opmerking/bijlage/beschrijving/waarde/regel/
			// limiet/grens/maximum/minimum/gemiddelde/totaal/som/aantal/
			// percentage/berekening/export/rapportage/statistiek/geschiedenis/
			// logboek/controle/werknemer/medewerker/beheerder/vestiging/
			// verdieping/etage/kantoor/klant/directeur/leidinggevend/diepte/
			// snelkoppeling/enheid/hoofd/rondleiding/klant), verbs
			// (werken/maken/doen/kijken/zien/vinden/geven/nemen/komen/gaan/
			// blijven/staan/hebben/worden/zullen/willen/kunnen/moeten/mogen/
			// laten/zeggen/weten/vragen/beginnen/eindigen/stoppen/wachten/
			// proberen/horen/denken/geloven/begrijpen/vergeten/onthouden/
			// leren/lezen/schrijven/spreken/praten/vertellen/verklaren/
			// aangeven/invullen/controleren/tellen/rekenen/vergelijken/
			// gebruiken/vereisen/helpen/wensen/rijden/reizen/leggen/zetten/
			// houden/brengen/volgen/kennen/menen/verstaan/indienen/verwerken/
			// registreren/bewerken/aanpassen/toevoegen/inschakelen/
			// uitschakelen/activeren/deactiveren/vernieuwen/verlengen/
			// vervallen/slaan), participles (goedgekeurd/afgekeurd/ingediend/
			// verwerkt/geregistreerd/opgeslagen/verwijderd/aangemaakt/
			// bijgewerkt/geactiveerd/gedeactiveerd/vernieuwd/gewijzigd/
			// ontbrekend/ontvangen/openbaar), adjectives (beschikbaar/
			// onbeschikbaar/geldig/ongeldig/vereist/optioneel/actief/inactief/
			// automatisch/handmatig/volledig/gedeeltelijk/permanent/
			// tijdelijk/regelmatig/normaal/maximaal/minimaal/gemiddeld/
			// totaal/goed/groot/klein/lang/kort/hoog/laag/jong/mooi/oud/
			// vorig/vorige/volgend/volgende/eerste/laatste), and the MT-mangle
			// shapes the cluster produced (pong/czwor/geprzerwaerd/
			// informacjermation/ponj). Polish-only lookalikes are deliberately
			// excluded: te/ja/je/ze/u/na/per/was/net/do/po/za/menu/panel/
			// status/model/kopie/label/filter/import/team — and the
			// false-positive-prone tokens dat (gen.pl. of data), de
			// (locale code quoted in strings), minimum (legit Polish).
			'\b(het|een|van|voor|met|aan|tot|uit|bij|om|over|onder|door|zonder|tussen|naast|tijdens|naar|maar|omdat|terwijl|zodra|hoewel|zodat|indien|mits|deze|dit|die|elk|elke|ieder|iedere|alle|beide|andere|geen|niets|iets|alles|iedereen|niemand|iemand|zich|zelf|eigen|zelfde|mijn|jouw|zijn|haar|ons|onze|uw|hun|me|hem|hen|wij|jullie|zij|ik|nu|dan|als|ook|niet|wel|nee|nog|altijd|nooit|vaak|soms|alleen|slechts|zelfs|misschien|waarschijnlijk|zeker|bijna|ongeveer|straks|zo|direct|meteen|snel|meer|meest|minder|minst|erg|zeer|heel|hele|vrij|genoeg|even|bovendien|echter|toch|immers|namelijk|daarna|verder|eindelijk|huidig|vandaag|morgen|gisteren|dag|week|maand|jaar|uur|tijd|datum|weekdag|dagen|uren|minuten|weken|maanden|jaren|gebruiker|werknemer|medewerker|werkgever|beheerder|afdeling|bedrijf|vestiging|verdieping|etage|kantoor|werkdag|dienst|ploegendienst|nachtdienst|pauze|verlof|vakantie|feestdag|ziekte|overwerk|overuren|uitbetaling|loon|salaris|aanwezig|afwezig|afwezigheid|melding|instelling|instellingen|rapport|overzicht|bestand|gegevens|toegang|wachtwoord|beheer|taak|aanvraag|verzoek|goedkeuring|waarschuwing|fout|succes|gelukt|teamlid|teamleden|groep|categorie|veld|kolom|rij|tabel|lijst|versie|voorbeeld|sjabloon|standaard|aangepast|optie|keuze|weergave|zicht|scherm|knop|venster|dialoog|meldingen|notificatie|herinnering|notitie|opmerking|bijlage|beschrijving|waarde|beleid|regel|limiet|grens|maximum|gemiddelde|totaal|som|aantal|percentage|berekening|export|rapportage|statistiek|geschiedenis|logboek|controle|werktijd|arbeidstijd|werken|maken|doen|kijken|zien|zoeken|vinden|geven|nemen|komen|gaan|blijven|staan|zijn|hebben|worden|zullen|willen|kunnen|moeten|mogen|laten|zeggen|weten|vragen|beginnen|eindigen|starten|stoppen|wachten|proberen|horen|denken|geloven|begrijpen|vergeten|onthouden|leren|lezen|schrijven|spreken|praten|vertellen|verklaren|aangeven|invullen|controleren|tellen|rekenen|vergelijken|gebruiken|toepassen|vereisen|helpen|wensen|rijden|reizen|leggen|zetten|houden|brengen|volgen|kennen|menen|verstaan|indienen|verwerken|registreren|bewerken|wijzigen|aanpassen|verwijderen|toevoegen|opslaan|annuleren|bevestigen|activeren|deactiveren|inschakelen|uitschakelen|vernieuwen|verlengen|verlopen|vervallen|overslaan|slaan|goedgekeurd|afgekeurd|ingediend|verwerkt|geregistreerd|opgeslagen|verwijderd|aangemaakt|bijgewerkt|geactiveerd|gedeactiveerd|vernieuwd|gewijzigd|ontbrekend|ontvangen|openbaar|beschikbaar|onbeschikbaar|geldig|ongeldig|vereist|optioneel|actief|inactief|automatisch|handmatig|volledig|gedeeltelijk|permanent|tijdelijk|regelmatig|normaal|maximaal|minimaal|gemiddeld|totaal|goed|groot|klein|lang|kort|hoog|laag|jong|mooi|oud|vorig|vorige|volgend|volgende|eerste|laatste|snelkoppeling\w*|sinds|eenheid|hoofd\w*|rondleiding\w*|directeur|leidinggevend|diepte|bevatten|gewerkt|klant\w*|bijvoorbeeld|inclusief|exclusief|precies|exact|pong|czwor|geprzerwaerd|informacjermation|ponj)\b',
		], 'pl');
		// Generic mangled-token lint: MT corruption leaves 'wt' insertions in
		// Dutch/Polish stems ('Mewtum', 'hoofwtnhoud', 'Rondleiwtng', 'wtstrict',
		// 'verwteping', 'Melwtngspontrix'). Legit Polish wt-tokens are
		// whitelisted: wtedy/wtorek family, wtór- family, powt- family, the
		// weekday abbreviation 'wt' itself, and rare wt-initial stems.
		$wtAllow = '/^(wt|wtedy|wtorek|wtorkow\w*|wt[óo]r\w*|powt\w*|wtajemnicz\w*|wtręt\w*|wtrąt\w*)$/iu';
		foreach ($pl as $msgid => $text) {
			if (!is_string($text)) {
				continue;
			}
			if (preg_match_all('/\b\w*wt\w*\b/iu', $text, $m) > 0) {
				foreach ($m[0] as $token) {
					self::assertSame(
						1,
						preg_match($wtAllow, $token),
						"pl contains wt-mangled token '{$token}' in msgid {$msgid}",
					);
				}
			}
		}
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
		// 2026-11-05 r8 fourth-cluster: same systematic layers as sv.
		// Layer 4 — donor-vocabulary token lint against Dutch AND German
		// (both donors contaminated this catalog). Legit Polish tokens that
		// coincide with donor vocabulary live in the registries.
		self::assertNoDonorVocabTokens(
			$pl,
			['nl'],
			self::LEGIT_PL_NL_TOKENS,
			'pl',
			$this->appRoot,
		);
		self::assertNoDonorVocabTokens(
			$pl,
			['de'],
			self::LEGIT_PL_DE_TOKENS,
			'pl',
			$this->appRoot,
		);
		// Layer 5 — near-identical donor tokens: Polish shares almost no
		// morphology with nl/de, so a pl token within edit distance 1 of a
		// donor word (len≥7) is a copy-adapt suspect ('Raportbereik' vs nl
		// 'rapportbereik' — the single-p variant that token membership
		// missed). sv↔da is excluded: legitimate Scandinavian cognate
		// pairs blanket this signal (~1800 pairs) and are covered by
		// Layers 1–4 instead.
		self::assertNoNearDonorTokens(
			$pl,
			['nl', 'de'],
			self::LEGIT_PL_NEAR_DONOR_TOKENS,
			'pl',
			$this->appRoot,
		);
		// Layer 6 — nl↔pl identical-value comparison (same shape as the
		// da↔sv layer). nb↔da is deliberately NOT generalized: Bokmål
		// legitimately shares ~945 whole values with Danish, so value
		// identity cannot discriminate contamination there — nb stays on
		// needle families + parity.
		self::assertNoUnregisteredIdenticals(
			$pl,
			$this->translations('nl'),
			$this->translations('en'),
			self::SHARED_NL_PL_IDENTICALS,
			'pl',
			'nl',
		);
		// Dutch digraphs with no valid Polish reading: 'aa' outside the
		// 'zaa*' prefix family (zaakceptuj/zaawansowane are legit Polish
		// prefix assimilations), 'uu' outside 'kontynuuj/kontynuuje', and
		// double 'ee' outside the English loanwords weekend/employee…
		self::assertNoForbiddenPatterns($pl, [
			'\b(?!zaa\w)\w*aa\w*',
			'\b(?!kontynuu\w*)\w*uu\w*',
			'\b(?!weekend\w*|employee\w*|feedback\w*|cookie\w*|screen\w*|career\w*)\w*ee\w*',
		], 'pl');
		// English-run lint: caught 'Pracownik work schedule assigned',
		// 'Zapisz ProjectCheck connection setting', 'Tariff rule set nie
		// znaleziono', 'User ustawienia'.
		self::assertNoEnglishRuns($pl, self::LEGIT_EN_RUNS, 'pl', $this->appRoot);
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

	/**
	 * Legit sv tokens that also appear verbatim in the Danish catalog.
	 * Generated 2026-11-05 from the post-fix sv∩da token intersection
	 * (len≥4 donor / len≥5 target), minus English passthrough; every entry
	 * reviewed as genuine shared Scandinavian vocabulary. Fail-closed: a
	 * NEW intersection token is a bleed suspect until a human verifies it.
	 *
	 * @var list<string>
	 */
	private const LEGIT_SV_DA_TOKENS = [
		'absolut', 'administrationsområdet', 'administrationsstatus', 'administrativ', 'adminrollen',
		'adresser', 'aktivering', 'aktivitet', 'aktiviteter', 'aktivitetsloggen', 'aktivt',
		'aldrig', 'angivet', 'antal', 'appadministration', 'appen', 'appguiden', 'appversion',
		'automatisk', 'behov', 'beskriv', 'beskriver', 'betyder', 'butik', 'celler', 'centrum', 'cykel',
		'dagen', 'dagens', 'daglig', 'dagligt', 'dagsandel', 'databasen', 'decimaler', 'definitionen',
		'deldag', 'delstat', 'delstaters', 'deltid', 'delvis', 'destinationen', 'detaljer', 'distrikt',
		'dokumentation', 'dokumentationen', 'dokumentversion', 'drift', 'effekt', 'effektiv', 'efter',
		'eftermiddag', 'eller', 'enkel', 'fakta', 'faktor', 'faktura', 'fakturerbar', 'fakturerbart',
		'fakturering', 'falsk', 'femte', 'filer', 'filformat', 'filial', 'filtrering',
		'florians', 'formatet', 'formel', 'fredag', 'frivilligt', 'funktion', 'funktionen',
		'funktioner', 'garanti', 'generering', 'generisk', 'genève', 'gjorde', 'globalt', 'gratis',
		'grund', 'grupper', 'guiden', 'halvdags', 'heldags', 'helst', 'heltal', 'hierarki', 'historik',
		'historisk', 'hypotetisk', 'idéer', 'importer', 'inaktiv', 'ingen', 'inklusive', 'installationer',
		'instans', 'instansens', 'instansstandard', 'integrationer', 'intern', 'intervallet', 'juridisk',
		'justering', 'justeringen', 'kalenderappen', 'kalenderdag', 'kalenderformat', 'kalenderikon',
		'kalenderinvitationer', 'kalenderkonto', 'kalendersynkronisering', 'kalenderår', 'kalenderåret',
		'kalenderårs', 'kanton', 'kategori', 'kategorier', 'kioskadministration', 'kioskadministrationen',
		'kiosken', 'kiosksession', 'kioskstatus', 'kioskterminal', 'kioskterminaler', 'klart',
		'klassificering', 'klient', 'knappen', 'koden', 'koder', 'kohort', 'kollega', 'kombination',
		'komma', 'kommer', 'kommunikation', 'kompakt', 'kompatibel', 'konfiguration', 'konflikt',
		'konsekvent', 'konsultationstider', 'konto', 'kontor', 'kontoret', 'kontos', 'kontrakt',
		'kontraktsmål', 'kontroller', 'konvertering', 'korrekt', 'kortet', 'kraft', 'kreditering',
		'krediteringen', 'kristi', 'kronologisk', 'kryptografisk', 'kunde', 'kunder', 'kvoter', 'kärntens',
		'lager', 'lagring', 'lagringen', 'landet', 'landsprofil', 'ledig', 'lektioner', 'leopolds', 'licens',
		'licensadministration', 'licensdata', 'licensen', 'licenser', 'licensformat', 'licenssignatur',
		'ligger', 'lokalt', 'luzern', 'låser', 'mandantnummer', 'markering', 'medlem', 'metoder',
		'migrering', 'migreringen', 'mindre', 'minut', 'mobil', 'mobilappen', 'modellen', 'modellens',
		'modeller', 'modul', 'moduler', 'målområdet', 'nationaldag', 'natten',
		'nattens', 'negativt', 'netto', 'normalt', 'notifikation', 'nummer', 'objekt', 'obligatorisk',
		'offentlig', 'oktober', 'område', 'området', 'onlinestatus', 'onsdag', 'organisationen',
		'organisationens', 'organisationer', 'organisationslandet', 'organisationslicens',
		'organisationslicensen', 'organisationsregel', 'organisationsregler', 'organisationsstandard',
		'organisationsstandarden', 'organisationsstrukturen',
		'parkering', 'passiv', 'pausen', 'pausens', 'pauser', 'pendling', 'perfekt', 'perioder',
		'personen', 'personens', 'personer', 'personlig', 'personligt', 'personpris', 'persons',
		'placering', 'positivt', 'posten', 'poster', 'posttyper', 'prioritet', 'privat', 'privatliv',
		'problemet', 'procent', 'produktion', 'profil', 'projekt', 'projektet', 'projektteam',
		'projektteamet', 'punkt', 'rapport', 'rapporten', 'rapportens', 'rapporter', 'rapportering',
		'rapportperioden', 'realtid', 'receptions', 'redigering', 'reduktionen', 'reformationsdagen', 'regel',
		'regelresultatet', 'regionen', 'register', 'registrering', 'registreringen',
		'registreringsdetaljer', 'regler', 'rekordtid', 'resultat', 'resultatet', 'resumé', 'revisionslog',
		'revisionsregister', 'rotation', 'ruperts', 'rådgivning', 'satser', 'schweiz', 'schweizisk',
		'sekunder', 'separat', 'serverproblem', 'sessionen', 'sessioner', 'signatur', 'simulering',
		'skift', 'skriv', 'skriver', 'skulle', 'slutminut', 'sluttid', 'sluttiden', 'sluttider',
		'sluttidsformat', 'snart', 'sortering', 'specificitet', 'specifik', 'specifikt', 'sponsorer',
		'stabil', 'standarden', 'standarder', 'standardkalender', 'standardregion',
		'standardregionen', 'starten', 'startpunkter', 'starttid', 'starttidsformat', 'statistik',
		'statusfilter', 'statussektioner', 'strikt', 'struktur', 'strukturen',
		'synkronisering', 'synlig', 'systemet', 'tabellen', 'tabeller', 'taget', 'teamet', 'teamets',
		'teammedlem', 'teammedlemmer', 'teamrapport', 'teamrapporten', 'teamrapporter', 'teamregel',
		'teamstandarder', 'teknisk', 'telefon', 'telefonen', 'terminalen', 'terminaler', 'terminallicens',
		'terminallicenser', 'terminaltoken', 'territorium', 'testkonto', 'tiden', 'tider', 'tidsformat',
		'tidsformatet', 'tidslinje', 'tidslinjedata', 'tidslinjeformat', 'tidslinjen', 'tidslinjer',
		'tidslinjevisning', 'tidsperioden', 'tidsregistrering', 'tidsregistreringen',
		'tidsregistreringsdata', 'tidsregistreringsmetoder', 'tidsregistreringsrapporter',
		'tidsregistreringssystemet', 'tidssegment', 'tidszonen', 'tidszoner', 'titel', 'toppen', 'torsdag',
		'totalt', 'tredje', 'typen', 'typer', 'tyskland', 'undertid', 'validering', 'versionen',
		'versioner', 'versionsnummer', 'visning', 'yngre', 'zürich', 'årets', 'årlig', 'årligt', 'årsdag',
		'årsdagsår',
		// 2026-11-05 family-union expansion (r8 residue fix): legit sv
		// tokens surfaced only when the donor vocabulary was widened to
		// all sibling da catalogs — all reviewed, genuine Swedish.
		'ansvarsområde', 'arkiv', 'beslut', 'bland', 'cirka', 'dialogen', 'effekten',
		'effektivt', 'etiketter', 'givet', 'grupp', 'gången', 'importfilen', 'inget',
		'installation', 'integritet', 'internt', 'introduktion', 'jurisdiktion',
		'kredit', 'laddas', 'licenssignaturen', 'nextclouds', 'objektet', 'perioden',
		'postdetaljer', 'procenten', 'rensning', 'signaturen', 'standardstatus',
		'testning',
	];

	/**
	 * Legit pl tokens verbatim in the Dutch catalog (reviewed loanwords).
	 * @var list<string>
	 */
	private const LEGIT_PL_NL_TOKENS = [
		'centrum', 'firma', 'kanton', 'legenda', 'marketing', 'netto', 'premie', 'tekst',
		// family-union expansion (r8 residue fix): legit Polish, shared
		// with Dutch — all reviewed.
		'adres', 'klient', 'kwestie', 'linie', 'procent', 'proces', 'element',
		'projekt',
	];

	/**
	 * Legit pl tokens verbatim in the German catalog (reviewed loanwords).
	 * @var list<string>
	 */
	private const LEGIT_PL_DE_TOKENS = [
		'alarm', 'element', 'firma', 'jeden', 'kanton', 'katalog', 'kategorie', 'konflikt', 'konto',
		'marketing', 'netto', 'profil', 'projekt', 'punkt', 'telefon', 'termin',
		// family-union expansion (r8 residue fix): legit Polish, shared
		// with German — all reviewed.
		'klient', 'linie', 'selektor',
	];

	/**
	 * Legit pl tokens within edit distance 1 of nl/de donor vocabulary —
	 * shared Indo-European stems inflected differently (problemu~probleem).
	 * Reviewed 2026-11-05; all genuine Polish.
	 *
	 * @var list<string>
	 */
	private const LEGIT_PL_NEAR_DONOR_TOKENS = [
		'absolutne', 'administratora', 'administratorem', 'centralne', 'duplikaty', 'dyrektor',
		'elementem', 'elementu', 'elementy', 'formatu', 'formularz', 'globalne', 'hierarchii', 'historia',
		'historii', 'historią', 'historię', 'importu', 'importy', 'kantonu', 'katalogu', 'kategoria',
		'kategorii', 'kategorię', 'kohorty', 'komputer', 'kontrole', 'laptopa', 'leopolda', 'minimalne',
		'mobilne', 'momentu', 'normalne', 'outlooku', 'problemu', 'problemy', 'procent', 'profilu',
		'projektem', 'projektu', 'projekty', 'regionu', 'ruperta', 'schemat', 'segmenty', 'statusu',
		'statusy', 'struktura', 'struktury', 'strukturę', 'sugestie', 'symulator', 'systemu', 'tabletu',
		'tablety', 'telefonu', 'terminala', 'terminale', 'terminali', 'terminalu', 'terminem', 'terminie',
		'thunderbirda', 'weekendy',
	];

	/**
	 * Values legitimately spelled identically in nl and pl (product names,
	 * canton names, shared loanwords, format strings) — same contract as
	 * SHARED_DA_SV_IDENTICALS for the nl↔pl comparison.
	 *
	 * @var list<string>
	 */
	private const SHARED_NL_PL_IDENTICALS = [
		'%1$s – %2$s', '%1$s → %2$s', '%1$s: %2$s', 'Appenzell Ausserrhoden (CH)',
		'Appenzell Innerrhoden (CH)', 'ArbeitszeitCheck', 'Basel-Landschaft (CH)', 'Basel-Stadt (CH)',
		'Burgenland (AT)', 'Check Partner', 'E-mail', 'GitHub Issues', 'Jura (kanton)', 'Legenda',
		'Marketing', 'Netto', 'Neuchâtel (kanton)', 'Nextcloud', 'Nidwalden (kanton)', 'Obwalden (kanton)',
		'ProjectCheck', 'Salzburg (AT)', 'Schwyz (kanton)', 'Ticino (kanton)', 'Vaud (kanton)',
		'Vorarlberg (AT)', 'alfa', 'centrum', 'firma', 'HR', 'IT',
	];

	/**
	 * Terse sv openers that legitimately omit the subject: permission
	 * labels ('Kan öppna X' = capability rows) and question fragments
	 * ('Vill ta bort …?' = confirm-dialog ellipses).
	 *
	 * @var list<string>
	 */
	private const LEGIT_SV_IMPLICIT_SUBJECT_OPENERS = [
		'kan öppna',
		'vill ta bort',
	];

	/**
	 * sv sentence-openers that are idiomatic subject-less fragments in UI
	 * label style ('Visas inte i kalendern', 'Krävs', 'Gäller endast …').
	 * Registered per-opener; anything else sentence-initial is a
	 * missing-subject suspect.
	 *
	 * @var list<string>
	 */
	private const LEGIT_SV_SUBJECTLESS_OPENERS = [
		'visas', 'används', 'krävs', 'behövs', 'saknas', 'gäller', 'beror',
		'finns', 'saknar', 'listar', 'räknar', 'täcker', 'visar', 'läser',
		'sparar', 'kräver',
	];

	/**
	 * Vocabulary of lowercased tokens (len≥$minLen) across all values.
	 *
	 * @param array<string, string|list<string>> $catalog
	 * @return array<string, true>
	 */
	private static function catalogVocab(array $catalog, int $minLen): array
	{
		$vocab = [];
		foreach ($catalog as $text) {
			foreach (is_array($text) ? $text : [$text] as $t) {
				if (!is_string($t)) {
					continue;
				}
				if (preg_match_all('/[\p{L}\p{M}]+/u', mb_strtolower($t), $m) !== false) {
					foreach ($m[0] as $w) {
						if (mb_strlen($w) >= $minLen) {
							$vocab[$w] = true;
						}
					}
				}
			}
		}
		return $vocab;
	}

	/**
	 * Donor-vocabulary token lint (r8 Layer 4): flags every target token
	 * (len≥5) that exists verbatim in a donor catalog but is absent from
	 * the English vocabulary (msgid words + en values = technical
	 * passthrough) and absent from the reviewed legit-shared registry.
	 *
	 * @param array<string, string|list<string>> $target
	 * @param list<string> $donorLangs
	 * @param list<string> $legitTokens
	 */
	private static function assertNoDonorVocabTokens(
		array $target,
		array $donorLangs,
		array $legitTokens,
		string $lang,
		string $appRoot,
	): void {
		$donorVocab = [];
		foreach ($donorLangs as $donor) {
			$data = json_decode(
				(string)file_get_contents($appRoot . '/l10n/' . $donor . '.json'),
				true,
				512,
				JSON_THROW_ON_ERROR,
			);
			$donorVocab += self::catalogVocab($data['translations'] ?? [], 4);
			// Family-union expansion (r8 residue fix): a leaked donor word
			// this app never uses ('verzenden', 'fundet') is invisible to
			// the own-catalog vocabulary. The fixture unions every sibling
			// app's donor catalog — regenerate via
			// scripts/generate-l10n-donor-vocab.php.
			$fixture = $appRoot . '/tests/Fixtures/l10n/donor-vocab-' . $donor . '.txt';
			if (is_file($fixture)) {
				foreach (file($fixture, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $w) {
					$donorVocab[$w] = true;
				}
			}
		}
		$enData = json_decode(
			(string)file_get_contents($appRoot . '/l10n/en.json'),
			true,
			512,
			JSON_THROW_ON_ERROR,
		);
		$enCat = $enData['translations'] ?? [];
		$enVocab = self::catalogVocab($enCat, 3);
		foreach (array_keys($enCat) as $msgid) {
			if (preg_match_all('/[\p{L}]+/u', mb_strtolower((string)$msgid), $m) !== false) {
				foreach ($m[0] as $w) {
					if (mb_strlen($w) >= 3) {
						$enVocab[$w] = true;
					}
				}
			}
		}
		$legit = array_fill_keys($legitTokens, true);
		foreach ($target as $msgid => $text) {
			foreach (is_array($text) ? $text : [$text] as $t) {
				if (!is_string($t)) {
					continue;
				}
				if (preg_match_all('/[\p{L}\p{M}]+/u', mb_strtolower($t), $m) === false) {
					continue;
				}
				foreach ($m[0] as $tok) {
					if (mb_strlen($tok) < 5) {
						continue;
					}
					self::assertFalse(
						isset($donorVocab[$tok]) && !isset($enVocab[$tok]) && !isset($legit[$tok]),
						"{$lang} token '{$tok}' appears verbatim in donor catalog(s) ["
						. implode(',', $donorLangs) . "] and is not a registered shared token "
						. "— likely cross-language bleed in msgid '{$msgid}' => '{$t}'",
					);
				}
			}
		}
	}

	/**
	 * Near-identical donor tokens (r8 Layer 5): target tokens len≥7 within
	 * Levenshtein distance 1 of a donor-vocab word — the copy-adapt shape
	 * ('Raportbereik' vs nl 'rapportbereik') that exact membership misses.
	 * Candidates are pruned by a first-two-chars index: a single edit can
	 * never change BOTH of the first two characters, so every lev≤1 pair
	 * shares ≥1 of them.
	 *
	 * @param array<string, string|list<string>> $target
	 * @param list<string> $donorLangs
	 * @param list<string> $legitTokens
	 */
	private static function assertNoNearDonorTokens(
		array $target,
		array $donorLangs,
		array $legitTokens,
		string $lang,
		string $appRoot,
	): void {
		$donorVocab = [];
		foreach ($donorLangs as $donor) {
			$data = json_decode(
				(string)file_get_contents($appRoot . '/l10n/' . $donor . '.json'),
				true,
				512,
				JSON_THROW_ON_ERROR,
			);
			$donorVocab += self::catalogVocab($data['translations'] ?? [], 4);
		}
		// index donor words by length and by each of their first two chars
		$index = [];
		foreach (array_keys($donorVocab) as $w) {
			$len = mb_strlen($w);
			foreach ([mb_substr($w, 0, 1), mb_substr($w, 1, 1)] as $c) {
				$index[$len][$c][] = $w;
			}
		}
		$legit = array_fill_keys($legitTokens, true);
		foreach ($target as $msgid => $text) {
			foreach (is_array($text) ? $text : [$text] as $t) {
				if (!is_string($t)) {
					continue;
				}
				if (preg_match_all('/[\p{L}\p{M}]+/u', mb_strtolower($t), $m) === false) {
					continue;
				}
				foreach (array_unique($m[0]) as $tok) {
					$len = mb_strlen($tok);
					if ($len < 7 || isset($legit[$tok]) || isset($donorVocab[$tok])) {
						continue;
					}
					$cands = [];
					foreach ([$len - 1, $len, $len + 1] as $l) {
						foreach ([mb_substr($tok, 0, 1), mb_substr($tok, 1, 1)] as $c) {
							foreach ($index[$l][$c] ?? [] as $cand) {
								$cands[$cand] = true;
							}
						}
					}
					foreach (array_keys($cands) as $cand) {
						self::assertFalse(
							self::levAtMostOne($tok, $cand),
							"{$lang} token '{$tok}' is within edit distance 1 of donor word "
							. "'{$cand}' — likely copy-adapt bleed in msgid '{$msgid}' => '{$t}'",
						);
					}
				}
			}
		}
	}

	/**
	 * Levenshtein distance ≤1 with early exit (no mb_ char splitting cost:
	 * operates on the token strings after case folding; multibyte-safe
	 * because all catalog tokens here are compared byte-wise only for
	 * equality/substitution detection — accented bytes differ in exactly
	 * the same positions).
	 */
	private static function levAtMostOne(string $a, string $b): bool
	{
		$la = strlen($a);
		$lb = strlen($b);
		if (abs($la - $lb) > 1 || $a === $b) {
			return false;
		}
		if ($la === $lb) {
			$diff = 0;
			for ($i = 0; $i < $la; $i++) {
				if ($a[$i] !== $b[$i] && ++$diff > 1) {
					return false;
				}
			}
			return $diff === 1;
		}
		if ($la > $lb) {
			[$a, $b] = [$b, $a];
			[$la, $lb] = [$lb, $la];
		}
		$diff = 0;
		$i = 0;
		$j = 0;
		while ($i < $la && $j < $lb) {
			if ($a[$i] === $b[$j]) {
				$i++;
				$j++;
				continue;
			}
			if (++$diff > 1) {
				return false;
			}
			$j++;
		}
		return true;
	}

	/**
	 * Implicit-subject scan (r8 Layer 5, sv): a modal followed by an active
	 * infinitive whose clause window contains no subject (pronoun OR any
	 * non-function token) and is not coordinated ('och kan se' shares the
	 * earlier subject) is a missing-subject calque.
	 *
	 * @param array<string, string|list<string>> $catalog
	 */
	private static function assertNoImplicitSubjectCalques(array $catalog, string $lang): void
	{
		$modals = '(?:vill|ska|måste|kan|behöver|bör|får)';
		$activeInfs = '(?:välja|ange|använda|anpassa|konfigurera|skapa|redigera|simulera|granska|hantera'
			. '|spara|exportera|filtrera|söka|klicka|trycka|ändra|radera|aktivera|avaktivera|öppna'
			. '|stänga|ladda|visa|göra|läsa|skriva|fylla|boka|följa|registrera|skicka|starta|stoppa'
			. '|fortsätta|avsluta|bekräfta|avvisa|godkänna|begära|ansöka|logga|signera|se|ta)\b';
		$coordinators = '(?:och|eller|att|som|då|så|men|för|,|:|;|–|—|/|\(|\?|!|\.)';
		// Pure non-subject function words: prepositions, conjunctions,
		// adverbs, auxiliaries. Pronouns and determiners are deliberately
		// absent — 'om du vill ange', 'det kan visa' HAVE a subject and
		// must pass; only clauses whose pre-modal window is *all* function
		// words are flagged.
		$funcWords = [
			'för', 'att', 'som', 'och', 'eller', 'av', 'i', 'på', 'med', 'kan', 'ska', 'vill',
			'måste', 'bör', 'får', 'behöver', 'är', 'har', 'blir', 'var', 'hade', 'inte', 'bara',
			'också', 'fortfarande', 'endast', 'automatiskt', 'när', 'om', 'då', 'så', 'här',
			'där', 'nu', 'redan', 'ännu', 'aldrig', 'alltid', 'ofta', 'ibland', 'möjligt', 'väl',
			'mycket', 'mer', 'mest', 'mindre', 'minst', 'lika', 'hur', 'vad', 'varför', 'även',
			'sedan', 'själv', 'direkt', 'igen', 'ut', 'in', 'upp', 'ner', 'till', 'från', 'över',
			'under', 'mellan', 'genom', 'utan', 'vid', 'efter', 'före', 'mot', 'bland', 'helt',
			'delvis', 'per', 'via', 'än', 'åt', 'hos', 'emot', 'omkring', 'utanför', 'innanför',
		];
		$func = array_fill_keys($funcWords, true);
		foreach ($catalog as $msgid => $text) {
			if (!is_string($text)) {
				continue;
			}
			if (preg_match_all('/' . $modals . '\s+' . $activeInfs . '/iu', $text, $mm, PREG_OFFSET_CAPTURE) === false) {
				continue;
			}
			foreach ($mm[0] as [$match, $pos]) {
				// $pos is a BYTE offset (PREG_OFFSET_CAPTURE); convert to
				// chars so the window never slices a multibyte sequence.
				$charPos = mb_strlen(substr($text, 0, $pos));
				$window = mb_strtolower(mb_substr($text, max(0, $charPos - 80), min(80, $charPos)));
				if (preg_match('/' . $coordinators . '\s*$/u', $window) === 1) {
					continue; // coordinated/subordinated verb shares an earlier subject
				}
				preg_match_all('/[\p{L}\p{M}]+/u', $window, $wm);
				$tail = array_slice($wm[0], -4);
				$hasSubject = false;
				foreach ($tail as $w) {
					if (!isset($func[$w])) {
						$hasSubject = true;
						break;
					}
				}
				if ($hasSubject) {
					continue;
				}
				$opener = mb_strtolower($match);
				$allowlisted = false;
				foreach (self::LEGIT_SV_IMPLICIT_SUBJECT_OPENERS as $ok) {
					if (str_starts_with($opener, $ok)) {
						$allowlisted = true;
						break;
					}
					if (str_starts_with(mb_strtolower(mb_substr($text, 0, 40)), $ok)) {
						$allowlisted = true;
						break;
					}
				}
				self::assertTrue(
					$allowlisted,
					"{$lang} implicit-subject calque: '{$match}' has no subject in its clause window "
					. "in msgid '{$msgid}' => '{$text}'",
				);
			}
		}
	}

	/**
	 * Subject-less sentence openers (r8 Layer 6, sv): a value or a
	 * sentence inside it starting with a conjugated verb that can never be
	 * imperative ('är', 'har', 'kommer', 'finns', …) is missing its
	 * subject. Terse label-style openers are registered.
	 *
	 * @param array<string, string|list<string>> $catalog
	 */
	private static function assertNoSubjectlessSentenceOpeners(array $catalog, string $lang): void
	{
		$conjugated = '(?:är|har|hade|fick|blev|kommer|finns|ligger|heter|går|ser|hör|tar|ger|står|sitter'
			. '|följer|använder|visar|betyder|motsvarar|representerar|innehåller|tillåter|kräver|orsakar'
			. '|skapar|omfattar|reglerar|definierar|beskriver|anger|markerar|summerar|kontrollerar'
			. '|säkerställer|förhindrar|förutsätter|antar|stödjer|hanterar|loggar|spårar|mäter'
			. '|övervakar|synkroniserar|uppdaterar|hämtar|sparar|skickar|läser|skriver|genererar'
			. '|bygger|körs|utgör|återspeglar|registrerar|bokar|laddar|visas|används|krävs|behövs'
			. '|saknas|finnas|hittar|når|påminner|meddelar|varnar|blockerar|saknar|återstår|matchar'
			. '|överensstämmer|överlappar|kolliderar|utlöser|aktiverar|deaktiverar|påbörjar|avslutar'
			. '|fortsätter|pausar|stoppar|hindrar|skyddar|krypterar|signerar|verifierar|godkänner'
			. '|avvisar|ignorerar|kopplar|bifogar|exporterar|importerar|tilldelar|tillhör'
			. '|består|gäller|beror|listar|räknar|täcker)\b';
		foreach ($catalog as $msgid => $text) {
			if (!is_string($text)) {
				continue;
			}
			// value start, or after a sentence terminator ('. ', '! ', '? ')
			if (preg_match_all(
				'/(?:^|(?<=[.!?]\s))(' . $conjugated . ')/u',
				$text,
				$m,
				PREG_OFFSET_CAPTURE,
			) === false) {
				continue;
			}
			foreach ($m[1] as [$word, $pos]) {
				$lower = mb_strtolower($word);
				self::assertContains(
					$lower,
					self::LEGIT_SV_SUBJECTLESS_OPENERS,
					"{$lang} subject-less sentence opener '{$word}' at offset {$pos} — a conjugated "
					. "verb that is never imperative means the subject was dropped: "
					. "msgid '{$msgid}' => '{$text}'",
				);
			}
		}
	}

	/**
	 * Generalized identical-value comparison (r8 Layer 6): any value
	 * spelled identically in source and target catalogs that is not the
	 * English text and not a registered shared form is a bleed suspect.
	 *
	 * @param array<string, string|list<string>> $target
	 * @param array<string, string|list<string>> $source
	 * @param array<string, string|list<string>> $en
	 * @param list<string> $registry
	 */
	private static function assertNoUnregisteredIdenticals(
		array $target,
		array $source,
		array $en,
		array $registry,
		string $lang,
		string $srcLang,
	): void {
		foreach ($target as $msgid => $text) {
			$srcText = $source[$msgid] ?? null;
			if (is_array($text) && is_array($srcText)) {
				foreach ($text as $i => $t) {
					if (is_string($t)
						&& ($srcText[$i] ?? null) === $t
						&& ($en[$msgid][$i] ?? null) !== $t
						&& $t !== $msgid
						&& preg_match('/^[\p{Lu}0-9]{1,4}$/u', $t) !== 1
					) {
						self::assertContains(
							$t,
							$registry,
							"{$lang} plural form identical to {$srcLang} and not a registered shared "
							. "form — likely bleed: msgid '{$msgid}' => '{$t}'",
						);
					}
				}
				continue;
			}
			if (!is_string($text) || !is_string($srcText) || $text !== $srcText) {
				continue;
			}
			if (($en[$msgid] ?? null) === $text || $text === $msgid) {
				continue;
			}
			// Short all-caps values are language-agnostic acronyms
			// (AZ/IT/HR/PIN/CSV) — never contamination evidence.
			if (preg_match('/^[\p{Lu}0-9]{1,4}$/u', $text) === 1) {
				continue;
			}
			self::assertContains(
				$text,
				$registry,
				"{$lang} value identical to {$srcLang} and not a registered shared form — likely "
				. "bleed: msgid '{$msgid}' => '{$text}'",
			);
		}
	}

	/**
	 * English-run lint (r8 residue fix): a run of ≥3 consecutive letter
	 * tokens where every token is in the English vocabulary is residual
	 * untranslated text — 'Pracownik work schedule assigned',
	 * 'Zapisz ProjectCheck connection setting', 'global admin switch'.
	 * Single/duplicate English loanwords are normal; three in a row is a
	 * fragment, not a loan. Registered legit runs live in
	 * LEGIT_EN_RUNS (product names, domain terms, commands).
	 *
	 * Only meaningful for sv/pl/nb: en msgids embed German product
	 * strings ('Arbeitszeitmodell', 'Tarifvertrag'), so de-family
	 * catalogs trivially form runs of 4+.
	 *
	 * @param array<string, string|list<string>> $target
	 * @param list<string> $legitRuns lowercased legit run phrases
	 */
	private static function assertNoEnglishRuns(
		array $target,
		array $legitRuns,
		string $lang,
		string $appRoot,
	): void {
		static $enVocab = null;
		if ($enVocab === null) {
			$enVocab = self::englishVocab($appRoot);
		}
		$legit = array_fill_keys($legitRuns, true);
		foreach ($target as $msgid => $text) {
			foreach (is_array($text) ? $text : [$text] as $t) {
				if (!is_string($t) || $t === $msgid) {
					continue;
				}
				if (preg_match_all(
					'/[\p{L}\p{M}]+|[^\p{L}\p{M}\s]+/u', $t, $m
				) === false) {
					continue;
				}
				$run = [];
				$flush = static function () use (&$run, $legit, $lang, $msgid, $t): void {
					if (count($run) >= 3) {
						$phrase = mb_strtolower(implode(' ', $run));
						self::assertTrue(
							isset($legit[$phrase]),
							"{$lang} English run '" . implode(' ', $run) . "' — residual "
							. "untranslated text in msgid '{$msgid}' => '{$t}'",
						);
					}
					$run = [];
				};
				foreach ($m[0] as $piece) {
					if (preg_match('/[\p{L}\p{M}]/u', $piece)
						&& isset($enVocab[mb_strtolower($piece)])
					) {
						$run[] = $piece;
					} else {
						$flush();
					}
				}
				$flush();
			}
		}
	}

	/**
	 * English passthrough vocabulary: every word in en values AND en
	 * msgids (msgids carry technical terms like 'Mandantennummer' that
	 * legitimately appear untranslated in other catalogs).
	 *
	 * @return array<string, true>
	 */
	private static function englishVocab(string $appRoot): array
	{
		$enCat = json_decode(
			(string)file_get_contents($appRoot . '/l10n/en.json'),
			true,
			512,
			JSON_THROW_ON_ERROR,
		)['translations'] ?? [];
		$vocab = self::catalogVocab($enCat, 3);
		foreach (array_keys($enCat) as $msgid) {
			if (preg_match_all('/[\p{L}]+/u', mb_strtolower((string)$msgid), $m) !== false) {
				foreach ($m[0] as $w) {
					if (mb_strlen($w) >= 3) {
						$vocab[$w] = true;
					}
				}
			}
		}
		return $vocab;
	}

	/**
	 * Load a committed token fixture (one token per line).
	 *
	 * @return list<string>
	 */
	private static function fixtureTokens(string $name): array
	{
		$file = dirname(__DIR__, 2) . '/Fixtures/l10n/' . $name . '.txt';
		if (!is_file($file)) {
			self::fail("l10n fixture missing: {$file} — regenerate via scripts/generate-l10n-donor-vocab.php");
		}
		return array_values(array_filter(
			file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
			static fn(string $w): bool => $w !== '',
		));
	}

	/**
	 * Legit English runs in Scandinavian/Polish values (lowercased):
	 * product names, DATEV domain terms, shell commands, borrowed
	 * idioms. A new entry requires human verification that the run is
	 * genuinely language-agnostic.
	 *
	 * @var list<string>
	 */
	private const LEGIT_EN_RUNS = [
		'php occ upgrade',
		'nextcloud login flow',
		'mandantennummer under administration',
		'mandantennummer under admin',
		'datev personalnummer under',
		'datev lohnart for',
		'per person under',
		'send ical via',
		'pro rata for',
	];

	// The one-off formalizer script (l10n/build_quality_fixes.py) no longer ships
	// in the app repo — dev tooling lives in the workspace artifacts tree, which
	// shipped tests must not depend on. Its pronoun-preservation contract is
	// enforced where the script lives.
}
