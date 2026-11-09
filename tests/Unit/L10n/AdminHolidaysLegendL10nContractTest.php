<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\L10n;

use PHPUnit\Framework\TestCase;

/**
 * The admin holidays info box explains the two unlabeled selects of the
 * inline "add holiday" row by quoting their visible option labels.
 *
 * Contract: every shipped locale must carry the two msgids in .json and .js,
 * translate them (no English fallback outside en), and — critically — the
 * translated text must embed that locale's own 'Full-day holiday',
 * 'Half-day holiday', 'Statutory', 'Company holiday' and 'custom' catalog
 * values verbatim, so the quoted names match what the dropdowns render.
 */
class AdminHolidaysLegendL10nContractTest extends TestCase
{
	private const KIND_KEY = '"Full-day holiday" or "Half-day holiday" determines how the day is counted: a full-day holiday is not a working day, a half-day holiday counts as half a day (e.g. 0.5 vacation day).';
	private const SCOPE_KEY = '"Statutory", "Company holiday" or "custom" determines where the entry comes from and whom it applies to: statutory holidays come from the official calendar of the region, company holidays apply organisation-wide, custom entries are your own individual days. Statutory holidays are usually full-day; in Switzerland some statutory days (e.g. Sechseläuten) are half-day and count as 0.5.';

	private const RETIRED_KEYS = [
		'"Type" determines whether a day is treated as a full-day holiday (not counted as a working day) or as a half-day holiday (e.g., 0.5 vacation day).',
		'"Scope" distinguishes between statutory holidays, organization-wide company holidays, and custom entries. Statutory holidays are usually full-day; in Switzerland some statutory days (e.g. Sechseläuten) are half-day and count as 0.5.',
	];

	/** Option labels the inline row renders — must be quoted in the legend. */
	private const KIND_LABELS = ['Full-day holiday', 'Half-day holiday'];
	private const SCOPE_LABELS = ['Statutory', 'Company holiday', 'custom'];

	/** @return list<string> */
	private static function locales(): array
	{
		$locales = [];
		foreach (glob(dirname(__DIR__, 3) . '/l10n/*.json') ?: [] as $file) {
			$locales[] = basename($file, '.json');
		}
		sort($locales);
		return $locales;
	}

	/** @return array<string, string> */
	private static function translations(string $locale): array
	{
		$file = dirname(__DIR__, 3) . '/l10n/' . $locale . '.json';
		$data = json_decode((string)file_get_contents($file), true);
		return $data['translations'] ?? [];
	}

	public function testTemplateReferencesTheNewMsgids(): void
	{
		$tpl = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/admin-holidays.php');
		$this->assertStringContainsString(self::KIND_KEY, $tpl, 'admin-holidays.php lost the kind legend msgid — catalog and template drifted apart');
		$this->assertStringContainsString(self::SCOPE_KEY, $tpl, 'admin-holidays.php lost the scope legend msgid — catalog and template drifted apart');
	}

	public function testEnglishPackCarriesBothKeys(): void
	{
		$trans = self::translations('en');
		foreach ([self::KIND_KEY, self::SCOPE_KEY] as $key) {
			$this->assertArrayHasKey($key, $trans, 'en.json missing legend msgid');
			$this->assertSame($key, $trans[$key], 'en.json must map legend msgid to itself');
		}
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/l10n/en.js');
		foreach ([self::KIND_KEY, self::SCOPE_KEY] as $key) {
			$this->assertStringContainsString(json_encode($key, JSON_UNESCAPED_UNICODE), $js, 'en.js missing legend msgid');
		}
	}

	public function testEveryLocaleTranslatesBothKeysInJsonAndJs(): void
	{
		$missingJson = $englishJson = $missingJs = [];
		foreach (self::locales() as $locale) {
			$trans = self::translations($locale);
			$js = (string)file_get_contents(dirname(__DIR__, 3) . '/l10n/' . $locale . '.js');
			foreach ([self::KIND_KEY, self::SCOPE_KEY] as $key) {
				if (!isset($trans[$key]) || $trans[$key] === '') {
					$missingJson[] = "$locale:$key";
					continue;
				}
				if ($locale !== 'en' && $trans[$key] === $key) {
					$englishJson[] = "$locale:$key";
				}
				if (!str_contains($js, json_encode($key, JSON_UNESCAPED_UNICODE))) {
					$missingJs[] = "$locale:$key";
				}
			}
		}
		$this->assertSame([], $missingJson, 'Missing legend keys in .json: ' . implode(' | ', $missingJson));
		$this->assertSame([], $englishJson, 'Untranslated legend keys (English fallback): ' . implode(' | ', $englishJson));
		$this->assertSame([], $missingJs, 'Missing legend keys in .js: ' . implode(' | ', $missingJs));
	}

	public function testLegendQuotesTheVisibleOptionLabelsVerbatim(): void
	{
		$failures = [];
		foreach (self::locales() as $locale) {
			$trans = self::translations($locale);
			foreach ([self::KIND_KEY => self::KIND_LABELS, self::SCOPE_KEY => self::SCOPE_LABELS] as $key => $labelKeys) {
				if (!isset($trans[$key])) {
					continue; // covered by testEveryLocaleTranslatesBothKeysInJsonAndJs
				}
				foreach ($labelKeys as $labelKey) {
					// A missing label falls back to the English msgid in the UI,
					// which is then also the text the legend must quote.
					$label = $trans[$labelKey] ?? $labelKey;
					if (!is_string($label) || $label === '') {
						$label = $labelKey;
					}
					if (!str_contains($trans[$key], $label)) {
						$failures[] = "$locale legend does not quote its rendered label '$label'";
					}
				}
			}
		}
		$this->assertSame([], $failures, "Legend text does not name the visible dropdown labels:\n" . implode("\n", $failures));
	}

	public function testRetiredMsgidsAreGone(): void
	{
		$leftovers = [];
		foreach (self::locales() as $locale) {
			$trans = self::translations($locale);
			$js = (string)file_get_contents(dirname(__DIR__, 3) . '/l10n/' . $locale . '.js');
			foreach (self::RETIRED_KEYS as $key) {
				if (isset($trans[$key])) {
					$leftovers[] = "$locale.json:$key";
				}
				if (str_contains($js, json_encode($key, JSON_UNESCAPED_UNICODE))) {
					$leftovers[] = "$locale.js:$key";
				}
			}
		}
		$this->assertSame([], $leftovers, 'Retired legend msgids still present: ' . implode(' | ', $leftovers));
	}
}
