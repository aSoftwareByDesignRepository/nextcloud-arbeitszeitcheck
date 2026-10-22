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
			'\b(skabe\w*|vælg\w*|ændre\b|opret\w*|slette|redigere|gemme|downloade|uploade|sende|modtage|tilføje|fjerne|flytte|kopiere|skjule|åbne|lukke|fortsætte|afbryde|annullere|bekræfte|afvise|godkende|ansøge|anmode|spørge|svare|fortælle|forklare|beskrive|angive|indtaste|udfylde|kontrollere|tjekke|beregne|tælle|sammenligne|bruge|anvende|benytte|gøre|blive|være|have\b|kunne|måtte|burde|behøve|hjælpe|forsøge|håbe|ønske|finde|søge|lede|miste|tabe|køre|rejse|sove|spise|drikke|arbejde|sidde|ligge|lægge|sætte|holde|bære|bringe|føre|følge|møde|kende|vide|mene|synes|forstå|huske|glemme|lære|læse|skrive|kalde|hede|sige|tale|snakke|høre|lytte|kigge|tage|komme|lave|stoppe|starte|vise|reserveres|anvendes|aktiveres|deaktiveres|tillades|opdateres|erstattes|vises|slettes|oprettes|redigeres|tilføjes|skiftes|gemmes|benyttes|foretages|udføres|udfyldes|bekræftes|afvises|godkendes|beregnes|ændres|sendes|godkände|faste)\b',
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
		], 'sv');
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
		'Daglig rapport', 'Deldag', 'Delstat: %1$s', 'Deltid', 'Destinationen',
		'Detaljer', 'Din ArbeitszeitCheck-kiosk-PIN', 'Din status',
		'Dokumentation', 'Dokumentversion', 'Drift', 'Effektiv', 'Enkel',
		'Excel-fil (XLSX)', 'FA', 'Fast?', 'Filformat', 'Fleksibel',
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
		'Kioskterminaler', 'Klar', 'Konto', 'Kontor', 'Korrekt',
		'L0 · Organisationsstandard', 'Land', 'Leopolds dag', 'Licens', 'Luzern',
		'Maj', 'Mandantnummer', 'Min status', 'Moderator', 'Modul %1$s: %2$s',
		'Moduler', 'Mål', 'Nej', 'Netto', 'Nextcloud-instans-ID',
		'Normal pendling', 'Ny PIN', 'Ny version', 'Om', 'Omfang', 'Ons',
		'Organisation', 'Organisationen', 'Organisationslicens',
		'Organisationsstruktur', 'Overlapningsregler', 'Placering',
		'Post-ID: %1$s', 'Prioritet', 'Pro-rata-regel', 'Procent', 'Projekt',
		'Projekt #%s', 'Rapporter', 'Region (område)', 'Registreringsdetaljer',
		'Regler', 'Resultat', 'Ruperts dag', 'Sats', 'Schweiz', 'Session (aktiv)',
		'Session: %1$s (aktiv)', 'Slut', 'Slutminut', 'Sluttid', 'Startede kl',
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
		'prioritet', 'produktion', 'projekt', 'punkt', 'september', 'stabil',
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
		foreach ($target as $msgid => $text) {
			$srcText = $source[$msgid] ?? null;
			if (is_array($text) && is_array($srcText)) {
				foreach ($text as $i => $t) {
					if (($srcText[$i] ?? null) === $t && ($en[$msgid][$i] ?? null) !== $t && $t !== $msgid) {
						self::assertContains(
							$t,
							self::SHARED_DA_SV_IDENTICALS,
							"{$lang} plural form identical to da and not a registered shared form — likely Danish bleed: msgid '{$msgid}' => '{$t}'",
						);
					}
				}
				continue;
			}
			if (!is_string($text) || !is_string($srcText) || $text !== $srcText) {
				continue;
			}
			// Identical to the English text → untranslated/proper noun, not a
			// da→sv copy (completeness is a separate class).
			if (($en[$msgid] ?? null) === $text || $text === $msgid) {
				continue;
			}
			self::assertContains(
				$text,
				self::SHARED_DA_SV_IDENTICALS,
				"{$lang} value identical to da and not a registered shared Scandinavian form — likely Danish bleed: msgid '{$msgid}' => '{$text}'",
			);
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
