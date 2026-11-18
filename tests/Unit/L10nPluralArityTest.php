<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Plural msgid arrays (_singular_::_plural_ combined keys) must carry exactly
 * as many elements as the consuming runtime can select — `translatePlural`
 * indexes `translation[pluralFunction(n)]` with no bounds check, so an
 * under-filled array hands `undefined` to `_build` (TypeError mid-render) and
 * an over-filled one ships dead cells.
 *
 * The nplurals authority is @nextcloud/l10n `getPlural` in the shipped NC
 * core bundle (dist/chunks/translation-*.mjs) — `OC.L10N.register` ignores
 * the gettext nplurals argument on this NC version, so a catalog's declared
 * `pluralForm` is NOT the contract. Server-side, Symfony PluralizationRules
 * resolves the same table (tr/zh -> 1 form, pl -> 3, rest -> 2).
 *
 * Class binding: l10n_plural_array_nplurals_mismatch.
 * Ported from apps/homecheck tests/Unit/L10nPluralArityTest.php.
 */
final class L10nPluralArityTest extends TestCase
{
	/** Runtime nplurals per shipped locale, per @nextcloud/l10n getPlural. */
	private const NPLURALS = [
		'en' => 2, 'de' => 2, 'fr' => 2, 'es' => 2, 'da' => 2, 'nl' => 2,
		'it' => 2, 'sv' => 2, 'nb' => 2, 'pt' => 2, 'pt_BR' => 2,
		'pl' => 3,
		'tr' => 1, 'zh_CN' => 1, 'zh_TW' => 1,
	];

	private string $root;

	protected function setUp(): void
	{
		parent::setUp();
		$this->root = dirname(__DIR__, 2);
	}

	public function testRosterCoversEveryShippedCatalog(): void
	{
		$shipped = array_map(
			static fn (string $p): string => basename($p, '.json'),
			glob($this->root . '/l10n/*.json') ?: [],
		);
		$unrostered = array_values(array_diff($shipped, array_keys(self::NPLURALS)));
		self::assertSame(
			[],
			$unrostered,
			'shipped l10n catalogs missing from the nplurals roster: ' . implode(', ', $unrostered),
		);
		foreach (self::NPLURALS as $locale => $_) {
			self::assertFileExists(
				$this->root . '/l10n/' . $locale . '.json',
				'rostered locale has no shipped catalog: ' . $locale,
			);
		}
	}

	public function testPluralArraysMatchLocaleNplurals(): void
	{
		foreach (self::NPLURALS as $locale => $nplurals) {
			$path = $this->root . '/l10n/' . $locale . '.json';
			$decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
			self::assertIsArray($decoded);
			foreach (($decoded['translations'] ?? []) as $key => $value) {
				if (!str_contains((string) $key, '_::_')) {
					continue;
				}
				self::assertIsArray(
					$value,
					$locale . ' plural msgid is not a JSON array: ' . $key,
				);
				self::assertCount(
					$nplurals,
					$value,
					$locale . ' plural msgid has ' . count($value) . ' form(s), runtime getPlural selects '
					. $nplurals . ': ' . $key,
				);
				foreach ($value as $index => $form) {
					self::assertIsString($form, $locale . ' plural form must be a string: ' . $key);
					self::assertNotSame('', trim($form), $locale . ' plural form is empty: ' . $key);
				}
				if ($nplurals === 1) {
					// The only reachable element is selected for EVERY count —
					// a literal-'1' singular here renders a constant "1" for all n.
					self::assertStringContainsString(
						'%n',
						$value[0],
						$locale . ' single-form locale ships a non-%n index-0 element (renders a constant for every count): ' . $key,
					);
				} else {
					// Every element reachable for n != 1 must carry the %n count.
					foreach (array_slice($value, 1) as $index => $form) {
						self::assertStringContainsString(
							'%n',
							$form,
							$locale . ' plural form ' . ($index + 1) . ' lacks %n (constant for plural counts): ' . $key,
						);
					}
				}
			}
		}
	}
}
