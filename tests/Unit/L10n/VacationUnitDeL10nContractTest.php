<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\L10n;

use PHPUnit\Framework\TestCase;

class VacationUnitDeL10nContractTest extends TestCase
{
	/** @return list<string> */
	private function requiredKeys(): array
	{
		return [
			'Vacation unit',
			'Most organisations use days. Switch to hours only if you book vacation as an hour budget.',
			'What happens when I switch?',
			'Switching runs a one-time conversion of open balances — it is not a simple label change. Existing customers who stay on days are unchanged.',
			'Current unit: days (default)',
			'Current unit: hours',
			'Organisation unit',
			'Hours per day (for conversion)',
			'Used only when converting days ↔ hours. Day-to-day booking still follows each person’s work schedule.',
			'38.5 h week tip',
			'Use 7.7 (= 38.5 ÷ 5) so open day balances convert fairly.',
			'Employee apps are updated and show hours correctly',
			'Required before enabling hours. Old apps still say “days”.',
			'Apply unit change',
			'Select Days or Hours, then Apply. Hours needs the app confirmation above.',
			'Converting vacation unit…',
			'Vacation unit converted successfully.',
			'Could not convert vacation unit.',
			'Tick the Employee app confirmation checkbox before converting to hours.',
			'Same unit selected. Apply updates the hours-per-day factor only (balances stay as they are).',
			'Hours per day must be between 0.25 and 24.',
			'Confirm that Employee apps are updated before enabling vacation in hours.',
			'A vacation unit conversion is already running. Wait a moment and try again.',
			'Vacation unit migration is in progress. Please try again in a moment.',
			'Half day',
			'Set half day',
			'Half day today',
			'Half day (next workday)',
			'Full day',
			'Day length',
			'Full day or half day for this date.',
			'Which half?',
			'Morning',
			'Afternoon',
			'Half-day vacation request submitted successfully',
			'Need a half day plus full days? Submit the half day as its own request, then the remaining days.',
			'Take half of the first or last day — the days in between stay full.',
			'First day',
			'Last day',
			'Morning half',
			'Afternoon half',
			'This request uses 0.5 vacation day.',
			'1 full day',
			'Set one full day',
			'The database schema is outdated. Run Nextcloud upgrade (occ upgrade) or update ArbeitszeitCheck, then try again.',
		];
	}

	public function testGermanVacationUnitStringsAreTranslated(): void
	{
		$path = dirname(__DIR__, 3) . '/l10n/de.json';
		$raw = file_get_contents($path);
		$this->assertNotFalse($raw);
		$data = json_decode($raw, true);
		$this->assertIsArray($data);
		$trans = $data['translations'] ?? [];
		$this->assertIsArray($trans);

		$missing = [];
		$english = [];
		foreach ($this->requiredKeys() as $key) {
			if (!isset($trans[$key])) {
				$missing[] = $key;
				continue;
			}
			if ($trans[$key] === $key) {
				$english[] = $key;
			}
		}

		$this->assertSame([], $missing, 'Missing de.json keys: ' . implode(', ', $missing));
		$this->assertSame([], $english, 'Untranslated de.json values: ' . implode(', ', $english));
	}
}
