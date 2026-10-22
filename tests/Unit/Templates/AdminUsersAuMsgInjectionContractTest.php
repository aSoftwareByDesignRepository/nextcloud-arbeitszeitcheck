<?php

declare(strict_types=1);

/**
 * Every auMsg('key', 'English…') used by admin-users.js and admin-user-detail.js
 * must be injected into window.ArbeitszeitCheck.l10n by
 * templates/partials/admin-user-edit-l10n.php.
 * Otherwise DE (and other locales) silently show English fallbacks — Kraft bulk dialog.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

final class AdminUsersAuMsgInjectionContractTest extends TestCase
{
	/**
	 * @return list<string>
	 */
	private function auMsgKeys(string $js): array
	{
		preg_match_all("/auMsg\(\s*'([^']+)'/", $js, $matches);
		return array_values(array_unique($matches[1] ?? []));
	}

	public function testEveryAuMsgKeyIsInjectedOnAdminUsersPage(): void
	{
		$appRoot = dirname(__DIR__, 3);
		$l10nPartial = (string)file_get_contents($appRoot . '/templates/partials/admin-user-edit-l10n.php');

		$sources = [
			'js/admin-users.js',
			'js/admin-user-detail.js',
		];
		$missing = [];
		foreach ($sources as $rel) {
			$js = (string)file_get_contents($appRoot . '/' . $rel);
			foreach ($this->auMsgKeys($js) as $key) {
				// Exact property assignment — avoid prefix false-positives
				// (e.g. holidayRegionMUTATED still containing holidayRegion).
				$needle = 'window.ArbeitszeitCheck.l10n.' . $key . ' =';
				if (!str_contains($l10nPartial, $needle)) {
					$missing[] = $rel . ':' . $key;
				}
			}
		}

		$this->assertSame(
			[],
			$missing,
			'Missing window.ArbeitszeitCheck.l10n injections for auMsg keys: ' . implode(', ', $missing)
		);
	}

	public function testBulkDialogEnglishMsgidsExistInEnCatalog(): void
	{
		$appRoot = dirname(__DIR__, 3);
		$en = json_decode((string)file_get_contents($appRoot . '/l10n/en.json'), true, 512, JSON_THROW_ON_ERROR);
		$translations = $en['translations'] ?? $en;
		$required = [
			'Holiday region',
			'Confirm apply',
			'Recommended for vacation',
			'Put people on a team and use team vacation rules (L2).',
			'Open teams',
			'Advanced: same personal vacation rule (L3)',
			'Unsaved changes',
			'Leave without saving',
			'Stay on page',
		];
		foreach ($required as $id) {
			$this->assertArrayHasKey($id, $translations, 'en.json missing msgid: ' . $id);
		}
	}
}
