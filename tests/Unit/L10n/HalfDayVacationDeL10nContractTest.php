<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\L10n;

use PHPUnit\Framework\TestCase;

/**
 * Half-day vacation (days mode) must ship complete EN + translated DE strings
 * in both JSON and JS packs (Nextcloud loads .js in the browser).
 */
class HalfDayVacationDeL10nContractTest extends TestCase
{
	/** @return list<string> */
	private function requiredKeys(): array
	{
		return [
			'Half day',
			'Full day',
			'Day length',
			'Set half day',
			'Set one full day',
			'1 full day',
			'Half day today',
			'Half day (next workday)',
			'Request a half day of vacation for today',
			'Request a half day of vacation for the next workday',
			'Full day or half day for this date.',
			'Which half?',
			'Morning',
			'Afternoon',
			'Need a half day plus full days? Submit the half day as its own request, then the remaining days.',
			'Take half of the first or last day — the days in between stay full.',
			'First day',
			'Last day',
			'Morning half',
			'Afternoon half',
			'Half day is only for a single day. This request will use full working days.',
			'This request uses 0.5 vacation day.',
			'This request uses 1 vacation day.',
			'Half-day vacation request submitted successfully',
			'Half-day vacation is only allowed when start and end are the same day. To take half of the first or last day of a longer period, use the boundary half-day options.',
			'This day is not a full working day; half-day vacation cannot be booked.',
			'Invalid day fraction. Use full day or half day.',
			'Vacation day amount is inconsistent with the selected dates.',
			'Total vacation hours for the whole request (not per day). “Full range” and “1 full day” use your work model (e.g. shorter Friday), not a fixed 8 hours. Public holidays reduce the final debit.',
		];
	}

	public function testEnglishPackContainsHalfDayKeys(): void
	{
		$root = dirname(__DIR__, 3) . '/l10n';
		$json = json_decode((string)file_get_contents($root . '/en.json'), true);
		$this->assertIsArray($json);
		$trans = $json['translations'] ?? [];
		$js = (string)file_get_contents($root . '/en.js');
		$missingJson = [];
		$missingJs = [];
		foreach ($this->requiredKeys() as $key) {
			if (!isset($trans[$key])) {
				$missingJson[] = $key;
			}
			if (!str_contains($js, '"' . $key . '"')) {
				$missingJs[] = $key;
			}
		}
		$this->assertSame([], $missingJson, 'Missing en.json: ' . implode(' | ', $missingJson));
		$this->assertSame([], $missingJs, 'Missing en.js: ' . implode(' | ', $missingJs));
	}

	public function testGermanPackTranslatesHalfDayKeys(): void
	{
		$root = dirname(__DIR__, 3) . '/l10n';
		$json = json_decode((string)file_get_contents($root . '/de.json'), true);
		$this->assertIsArray($json);
		$trans = $json['translations'] ?? [];
		$js = (string)file_get_contents($root . '/de.js');
		$missing = [];
		$english = [];
		$missingJs = [];
		$jsEnglish = [];
		foreach ($this->requiredKeys() as $key) {
			if (!isset($trans[$key])) {
				$missing[] = $key;
				continue;
			}
			if ($trans[$key] === $key) {
				$english[] = $key;
			}
			if (!str_contains($js, '"' . $key . '"')) {
				$missingJs[] = $key;
				continue;
			}
			// de.js value must not be the English source string
			if (preg_match('/\t\t"' . preg_quote($key, '/') . '"\s*:\s*"' . preg_quote($key, '/') . '"/', $js) === 1) {
				$jsEnglish[] = $key;
			}
		}
		$this->assertSame([], $missing, 'Missing de.json: ' . implode(' | ', $missing));
		$this->assertSame([], $english, 'Untranslated de.json: ' . implode(' | ', $english));
		$this->assertSame([], $missingJs, 'Missing de.js: ' . implode(' | ', $missingJs));
		$this->assertSame([], $jsEnglish, 'Untranslated de.js: ' . implode(' | ', $jsEnglish));
	}
}
