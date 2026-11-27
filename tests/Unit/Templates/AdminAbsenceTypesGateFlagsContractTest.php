<?php

declare(strict_types=1);

/**
 * Template ↔ controller key parity for admin-absence-types.php.
 *
 * Regression guard for the paid-gate warning bug: the template read
 * $_['paidAbsencePlannedHoursCreditEnabled'] (the settings-storage key)
 * while AbsenceTypeAdminController::paidCreditGateFlags() emits
 * 'paidAbsenceCreditEnabled' — so the warning showed even while the paid
 * gate was ON. Every $_['key'] the template reads must exist as a
 * 'key' => emission in the controller or the shared shell-params trait.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

class AdminAbsenceTypesGateFlagsContractTest extends TestCase
{
	private function tpl(): string
	{
		$tpl = file_get_contents(dirname(__DIR__, 3) . '/templates/admin-absence-types.php');
		$this->assertNotFalse($tpl);
		return $tpl;
	}

	private function emitters(): string
	{
		$root = dirname(__DIR__, 3);
		$src = '';
		foreach ([
			'/lib/Controller/AbsenceTypeAdminController.php',
			'/lib/Controller/PageShellTrait.php',
		] as $file) {
			$part = file_get_contents($root . $file);
			$this->assertNotFalse($part);
			$src .= $part;
		}
		return $src;
	}

	public function testEveryTemplateParamIsEmittedByTheController(): void
	{
		$tpl = $this->tpl();
		$src = $this->emitters();

		// All $_['key'] reads in the template.
		preg_match_all('/\$_\[\'([A-Za-z0-9_]+)\'\]/', $tpl, $m);
		$keys = array_unique($m[1]);
		$this->assertNotEmpty($keys);

		// Injected by Nextcloud's template renderer, not by the controller.
		$framework = ['cspNonce'];

		foreach ($keys as $key) {
			if (in_array($key, $framework, true)) {
				continue;
			}
			$this->assertMatchesRegularExpression(
				'/' . preg_quote("'{$key}'", '/') . '\s*=>/',
				$src,
				"Template reads \$_['{$key}'] but no emit site produces '{$key}' => …"
			);
		}
	}

	public function testPaidGateFlagKeyMatchesTheEmitter(): void
	{
		$tpl = $this->tpl();
		$src = $this->emitters();

		// The emitter key and the template read must be the same name.
		$this->assertStringContainsString("'paidAbsenceCreditEnabled' =>", $src);
		$this->assertStringContainsString("\$_['paidAbsenceCreditEnabled']", $tpl);
		// The settings-storage key must NOT be used as a template var —
		// it is only the anchor href into the overtime-settings page.
		$this->assertStringNotContainsString("\$_['paidAbsencePlannedHoursCreditEnabled']", $tpl);
	}
}
