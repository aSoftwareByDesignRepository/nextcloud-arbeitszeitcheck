<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\L10n;

use PHPUnit\Framework\TestCase;

/**
 * Every catalog value must survive vsprintf.
 *
 * OC\L10N\L10NString::__toString() calls vsprintf($text, $parameters)
 * unconditionally — a bare '%' in a catalog value is parsed as a spec:
 * under a no-arg $l->t() call it throws ValueError (HTTP 500; real defect:
 * "Hours + % for reporting only…" crashed the month-closure PDF), and with
 * args it silently consumes one. '%%' is the only safe literal '%'.
 * '%n' is exempt — L10NString rewrites it to %count% before vsprintf.
 */
class CatalogSprintfSafetyTest extends TestCase
{
	/** A complete sprintf spec (position, flags, width, precision, conv). */
	private const SPEC_RE = '/%(\d+\$)?[-+ 0#]*\d*(?:\.\d+)?[bcdeEfFgGosuxX]/A';

	/**
	 * @return list<string>
	 */
	private static function unsafePercentSequences(string $value): array
	{
		$bad = [];
		$i = 0;
		while (($i = strpos($value, '%', $i)) !== false) {
			if (substr($value, $i, 2) === '%%' || substr($value, $i, 2) === '%n') {
				$i += 2;
				continue;
			}
			if (preg_match(self::SPEC_RE, $value, $m, 0, $i) === 1) {
				$spec = $m[0];
				// '% f'/'% s' — a space pad-flag directly before the conversion
				// char is never an intentional spec; it is a literal percent.
				if (preg_match('/% +[bcdeEfFgGosuxX]$/', $spec) === 1) {
					$bad[] = $spec;
				}
				$i += strlen($spec);
				continue;
			}
			$bad[] = substr($value, $i, 4);
			$i++;
		}
		return $bad;
	}

	public function testAllCatalogValuesAreSprintfSafe(): void
	{
		$l10nDir = dirname(__DIR__, 3) . '/l10n';
		$failures = [];
		foreach (glob($l10nDir . '/*.json') ?: [] as $file) {
			$lang = basename($file, '.json');
			$data = json_decode((string)file_get_contents($file), true);
			$this->assertIsArray($data['translations'] ?? null, "$lang.json has no translations map");
			foreach ($data['translations'] as $key => $value) {
				foreach (is_array($value) ? $value : [$value] as $segment) {
					if (!is_string($segment)) {
						continue;
					}
					foreach (self::unsafePercentSequences($segment) as $hit) {
						$failures[] = "$lang.json [$key]: bare % ($hit)";
					}
				}
			}
		}
		$this->assertSame([], $failures, "vsprintf-unsafe catalog values:\n" . implode("\n", $failures));
	}

	public function testKnownPercentStringsRoundTrip(): void
	{
		// Regression witnesses — these shipped with a bare '%' and 500'd the
		// premium month-closure PDF (ValueError under no-arg vsprintf).
		$values = [
			'Hours + %% for reporting only — not balance and not pay.',
			'Nur Stunden + %% für die Auswertung — nicht Saldo und keine Auszahlung.',
			'Zeitzuschläge (Stunden + %%)',
			'Horas + %% apenas para relatórios — sem Saldo e sem pagamento.',
		];
		foreach ($values as $v) {
			$this->assertStringContainsString('%', (string)vsprintf($v, []));
		}
	}
}
