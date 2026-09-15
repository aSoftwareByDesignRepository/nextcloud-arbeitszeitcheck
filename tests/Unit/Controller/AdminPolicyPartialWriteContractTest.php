<?php

declare(strict_types=1);

/**
 * Partial POSTs to updateNotificationSettings must not wipe other sections.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

class AdminPolicyPartialWriteContractTest extends TestCase
{
	public function testUpdateNotificationSettingsGatesHrTrafficAndBankWrites(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/AdminController.php');
		$this->assertNotFalse($src);
		// Never gate HR on bare `enabled` alone (query/body pollution).
		$this->assertStringContainsString(
			"\$hasHrSection = array_key_exists('hrNotificationsEnabled', \$params)",
			$src
		);
		$this->assertStringContainsString("array_key_exists('recipients', \$params)", $src);
		$this->assertStringContainsString("array_key_exists('matrix', \$params)", $src);
		$this->assertStringNotContainsString(
			"\$hasHrSection = array_key_exists('enabled', \$params)",
			$src
		);
		$this->assertStringContainsString(
			"\$hasTrafficSection = array_key_exists('overtimeTrafficLightEnabled', \$params)",
			$src
		);
		$this->assertStringContainsString(
			"\$hasBankSection = array_key_exists('overtimeBankEnabled', \$params)",
			$src
		);
		$this->assertStringContainsString('if ($hasHrSection) {', $src);
		$this->assertStringContainsString('if ($hasTrafficSection) {', $src);
		$this->assertStringContainsString('if ($hasBankSection) {', $src);
		$this->assertStringContainsString("\$writeMax = array_key_exists('overtimeBankMaxHours', \$params)", $src);
		$this->assertStringContainsString("\$writeHrEnabled = array_key_exists('hrNotificationsEnabled', \$params)", $src);
		$this->assertStringContainsString("if (\$hrWrite['writeEnabled']) {", $src);
		$this->assertStringContainsString("match (\$policyScope)", $src);
		// Premium / DATEV / vacation keys remain opt-in via isset / array_key_exists.
		$this->assertStringContainsString("array_key_exists('premiumPolicy', \$params)", $src);
		$this->assertStringContainsString("array_key_exists('paidAbsencePlannedHoursCreditEnabled', \$params)", $src);
		$this->assertStringContainsString('if (!isset($params[$paramKey]))', $src);
		$this->assertStringContainsString('Pre-validate carryover max', $src);
	}

	public function testAdminPolicyJsCollectsOnlyPresentSections(): void
	{
		$js = file_get_contents(dirname(__DIR__, 3) . '/js/admin-notifications.js');
		$this->assertNotFalse($js);
		$this->assertStringContainsString('function initForm(form)', $js);
		$this->assertStringContainsString("const hasHr = !!form.querySelector('#hrNotificationsEnabled')", $js);
		$this->assertStringContainsString("const hasTraffic = !!form.querySelector('#overtimeTrafficLightEnabled')", $js);
		$this->assertStringContainsString("const hasBank = !!form.querySelector('#overtimeBankEnabled')", $js);
		$this->assertStringContainsString("const hasPremium = !!form.querySelector('#premiumSurchargesEnabled')", $js);
		$this->assertStringContainsString("const hasPaidAbsenceCredit = !!form.querySelector('#paidAbsencePlannedHoursCreditEnabled')", $js);
		$this->assertStringContainsString("const hasVacation = !!form.querySelector('input[name=\"vacationYearMode\"]')", $js);
		$this->assertStringContainsString('form.admin-policy-settings-form', $js);
		$this->assertStringContainsString('if (hasHr) {', $js);
		$this->assertStringContainsString('if (hasBank) {', $js);
		$this->assertStringContainsString('if (hasPaidAbsenceCredit) {', $js);
		$this->assertStringContainsString('if (hasPremium) {', $js);
		$this->assertStringContainsString('if (hasVacation) {', $js);
		$this->assertStringContainsString('payload.hrNotificationsEnabled = enabled', $js);
		$this->assertStringContainsString("policyScope: String(form.getAttribute('data-policy-scope')", $js);
		$this->assertStringContainsString('const domChecked = function (selector)', $js);
		// Dependent fields stay enabled so values still submit when master toggle is off.
		$this->assertStringContainsString('el.disabled = false', $js);
		$this->assertStringContainsString("el.setAttribute('aria-disabled'", $js);
		$this->assertStringNotContainsString('el.disabled = !on', $js);
	}
}
