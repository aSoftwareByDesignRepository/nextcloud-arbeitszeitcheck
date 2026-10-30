<?php

declare(strict_types=1);

/**
 * Contract: the leave-Soll-effects matrix is wired end to end —
 * default-on flag, admin write path, JS payload, service reduction method.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

class LeaveSollEffectsGateContractTest extends TestCase
{
	public function testLeaveEffectsDefaultIsOn(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Constants.php');
		$this->assertStringContainsString(
			"CONFIG_LEAVE_SOLL_EFFECTS_DEFAULT = '1'",
			$src,
		);
	}

	public function testAdminWritePathHandlesLeaveEffectsFlag(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/AdminController.php');
		$this->assertStringContainsString("array_key_exists('leaveSollEffectsEnabled', \$params)", $src);
		$this->assertStringContainsString('Constants::CONFIG_LEAVE_SOLL_EFFECTS', $src);
		$this->assertStringContainsString("'leaveSollEffectsEnabled' => \$this->appConfig->getAppValueString(", $src);
	}

	public function testAdminJsSubmitsLeaveEffectsFlag(): void
	{
		$js = (string)file_get_contents(dirname(__DIR__, 3) . '/js/admin-notifications.js');
		$this->assertStringContainsString("const hasLeaveSollEffects = !!form.querySelector('#leaveSollEffectsEnabled')", $js);
		$this->assertStringContainsString('payload.leaveSollEffectsEnabled', $js);
	}

	public function testTemplateRendersLeaveEffectsToggle(): void
	{
		$tpl = (string)file_get_contents(
			dirname(__DIR__, 3) . '/templates/partials/admin-policy-overtime-bank.php'
		);
		$this->assertStringContainsString('id="leaveSollEffectsEnabled"', $tpl);
		$this->assertStringContainsString('id="paidAbsencePlannedHoursCreditEnabled"', $tpl);
	}

	public function testServiceExposesSollReductionAndLeaveGate(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/PaidAbsencePlannedHoursCreditService.php'
		);
		$this->assertStringContainsString('function sollReductionHoursForRange', $src);
		$this->assertStringContainsString('function isLeaveEffectsEnabled', $src);
		$this->assertStringContainsString('AbsenceTypeCatalog::sollEffect', $src);
	}

	public function testOvertimeServiceSubtractsReductionAndReportsIt(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/OvertimeService.php'
		);
		$this->assertStringContainsString('sollReductionHoursForRange', $src);
		$this->assertStringContainsString("'soll_reduction_hours'", $src);
	}
}
