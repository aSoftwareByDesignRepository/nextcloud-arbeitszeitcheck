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
		$this->assertStringContainsString('AbsenceTypeCatalog::isSollCreditable', $src);

		// The creditable-type whitelist lives in isSollCreditable — sick and
		// sick-child are creditable, unpaid leave must never join them.
		$catalog = (string)file_get_contents(
			dirname(__DIR__, 3) . '/lib/Support/AbsenceTypeCatalog.php'
		);
		$pos = strpos($catalog, 'function isSollCreditable');
		$this->assertNotFalse($pos);
		$body = substr($catalog, $pos, 1200);
		$this->assertStringContainsString('TYPE_SICK_LEAVE', $body);
		$this->assertStringNotContainsString('TYPE_UNPAID_LEAVE', $body);
	}
}
