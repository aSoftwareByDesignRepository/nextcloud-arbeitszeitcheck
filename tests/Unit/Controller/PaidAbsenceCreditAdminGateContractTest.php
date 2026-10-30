<?php

declare(strict_types=1);

/**
 * Contract: paid-absence credit toggle is org-admin only and CSRF-protected.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

class PaidAbsenceCreditAdminGateContractTest extends TestCase
{
	public function testUpdateNotificationSettingsRequiresCsrfAndAppAdminGate(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/AdminController.php');
		$pos = strpos($src, 'public function updateNotificationSettings(): JSONResponse');
		$this->assertNotFalse($pos);
		$window = substr($src, max(0, $pos - 400), 500);
		$this->assertStringContainsString('#[NoAdminRequired]', $window);
		$this->assertStringNotContainsString('#[NoCSRFRequired]', $window);
		$this->assertStringContainsString("array_key_exists('paidAbsencePlannedHoursCreditEnabled', \$params)", $src);
	}

	public function testDefaultConfigIsOff(): void
	{
		$src = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Constants.php');
		$this->assertStringContainsString(
			"CONFIG_PAID_ABSENCE_PLANNED_HOURS_CREDIT_DEFAULT = '0'",
			$src
		);
	}

	public function testCreditServiceRejectsUnpaidInTypeMatrix(): void
	{
		$src = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Service/PaidAbsencePlannedHoursCreditService.php'
		);
		// The per-type effect matrix (sollEffect) is what routes an absence to
		// credit / reduce / none — unpaid leave lands in reduce_soll, never in
		// the Ist credit.
		$this->assertStringContainsString('AbsenceTypeCatalog::sollEffect', $src);

		// The valuation matrix is the single source of truth: sick + paid
		// leave credit Ist; suspensions reduce Soll and must never join the
		// credit set. isSollCreditable is a thin alias for sollEffect, so pin
		// the matrix itself.
		$catalog = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Support/AbsenceTypeCatalog.php'
		);
		$pos = strpos($catalog, 'SOLL_EFFECT_MATRIX = [');
		$this->assertNotFalse($pos);
		$body = substr($catalog, $pos, 1400);
		$this->assertStringContainsString('TYPE_SICK_LEAVE => self::SOLL_EFFECT_CREDIT_IST', $body);
		$this->assertStringContainsString('TYPE_VACATION => self::SOLL_EFFECT_CREDIT_IST', $body);
		$this->assertStringContainsString('TYPE_UNPAID_LEAVE => self::SOLL_EFFECT_REDUCE_SOLL', $body);
		$this->assertStringContainsString('TYPE_PARENTAL_LEAVE => self::SOLL_EFFECT_REDUCE_SOLL', $body);
		// Fail-closed: an ORDER type missing from the matrix throws, never
		// silently degrades to NONE (that silence caused vacation Minusstunden).
		$this->assertStringContainsString('LogicException', $catalog);
	}
}
