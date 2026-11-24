<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\L10n;

use PHPUnit\Framework\TestCase;

/**
 * Danish verb/noun contract: 'Decline' renders on verb-action buttons
 * (decline a substitution offer, decline a correction/cancellation request).
 * 'Nedgang' is a NOUN (downturn, descent) — never the act of declining a
 * request. The da catalog must map the verb key to 'Afslå', and no
 * verb-action key may carry a noun mistranslation.
 */
class DeclineActionDaL10nContractTest extends TestCase
{
	/** Button/menu keys whose source text is decline/reject-as-a-verb. */
	private const VERB_KEYS = [
		'Decline',
		'Decline cancellation',
		'Decline correction',
		'Decline substitution',
		'Reject',
		'Reject Request',
		'Reject selected',
	];

	/** @return array<string, string> */
	private static function translations(): array
	{
		$data = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/l10n/da.json'), true);
		return $data['translations'] ?? [];
	}

	public function testDanishDeclineMapsToAflsaInJsonAndJs(): void
	{
		$trans = self::translations();
		$this->assertSame('Afslå', $trans['Decline'] ?? null, "da.json 'Decline' must be the verb 'Afslå', not a noun");
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/l10n/da.js');
		$this->assertStringContainsString('"Decline" : "Afslå"', $js, 'da.js must ship the healed verb mapping — regenerate via scripts/regenerate-l10n-js.php');
	}

	public function testNoVerbActionKeyRendersNedgang(): void
	{
		$trans = self::translations();
		$bad = [];
		foreach (self::VERB_KEYS as $key) {
			if (($trans[$key] ?? null) === 'Nedgang') {
				$bad[] = $key;
			}
		}
		$this->assertSame([], $bad, "da verb-action keys mistranslated as the noun 'Nedgang': " . implode(' | ', $bad));
	}

	public function testDanishDeclinedBySubstituteIsSaneParticiple(): void
	{
		$trans = self::translations();
		$value = $trans['Declined by substitute'] ?? '';
		$this->assertStringContainsString('Afvist', $value, "da 'Declined by substitute' must read as a declined status participle (e.g. 'Afvist af …')");
		$this->assertStringNotContainsString('Nedgang', $value);
	}
}
