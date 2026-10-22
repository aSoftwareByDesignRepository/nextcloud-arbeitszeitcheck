<?php

declare(strict_types=1);

/**
 * Controllers must forward day_fraction and must never bind client days (SEC-02 / AC-G13).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

class AbsenceHalfDayParamsForwardingContractTest extends TestCase
{
	public function testStoreForwardsDayFractionNeverDays(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../lib/Controller/AbsenceController.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString("day_fraction", $src);
		$this->assertMatchesRegularExpression(
			"/day_fraction['\"]?\\]\\s*=/",
			$src
		);
		// Must not assign request days into service data.
		$this->assertDoesNotMatchRegularExpression(
			"/\\\$data\\[['\"]days['\"]\\]\\s*=/",
			$src
		);
		$this->assertDoesNotMatchRegularExpression(
			"/\\\$data\\[['\"]working_days['\"]\\]\\s*=/",
			$src
		);
	}

	public function testManagerCreateForwardsDayFractionNeverDays(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../lib/Controller/ManagerController.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('day_fraction', $src);
		$this->assertStringContainsString('dayFraction', $src);
		$this->assertDoesNotMatchRegularExpression(
			"/\\\$data\\[['\"]days['\"]\\]\\s*=/",
			$src
		);
	}

	public function testMailDoesNotCastDaysToInt(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../lib/Service/AbsenceNotificationMailService.php');
		$this->assertNotFalse($src);
		$this->assertStringNotContainsString('(int)($absence->getDays()', $src);
		$this->assertStringContainsString('AbsenceTypeLabel::formatWorkingDays', $src);
		$this->assertStringContainsString("Days: %1\$s", $src);
	}

	public function testAllocationPrefersTrustedStoredDays(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../lib/Service/VacationAllocationService.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('isTrustedStoredVacationDays', $src);
		$this->assertStringContainsString('prospectiveDays', $src);
	}

	public function testEmployeeSubmitSetsDayFraction(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../templates/absences.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString("body.set('day_fraction'", $src);
		$this->assertStringContainsString('absence-day-fraction-group', $src);
		$this->assertStringContainsString('role="radiogroup"', $src);
		$this->assertStringContainsString('btn-report-sick-today', $src);
		$this->assertStringContainsString('absence-multi-day-half-tip', $src);
		$this->assertStringContainsString('prefillHalf', $src);
		$this->assertStringNotContainsString('HalfDayVacationShortcut', $src);
		$this->assertStringContainsString('absence-days-cell--half', $src);
	}

	public function testCreateAcceptsHalfQueryPrefill(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../lib/Controller/AbsenceController.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString("getParam('half'", $src);
		$this->assertStringContainsString("'prefillHalf'", $src);
		$this->assertStringContainsString('&half=1', $src);
	}

	public function testServiceScrubsClientDaysKeys(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../lib/Service/AbsenceService.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('scrubClientAuthoredDebitFields', $src);
		$this->assertMatchesRegularExpression(
			'/function createAbsence\([^{]+\{[^}]*scrubClientAuthoredDebitFields/s',
			$src
		);
		$this->assertMatchesRegularExpression(
			'/function updateAbsence\([^{]+\{[^}]*scrubClientAuthoredDebitFields/s',
			$src
		);
		$this->assertMatchesRegularExpression(
			'/function createApprovedAbsenceForEmployeeByManager\([^{]+\{[^}]*scrubClientAuthoredDebitFields/s',
			$src
		);
	}

	public function testWebFormMutationsRequireCsrf(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../lib/Controller/AbsenceController.php');
		$this->assertNotFalse($src);
		// Form POST store/updatePost must not be CSRF-exempt (cookie session browsers).
		$this->assertDoesNotMatchRegularExpression(
			'/\#\[NoCSRFRequired\]\s*\n\s*public function store\(/',
			$src
		);
		$this->assertDoesNotMatchRegularExpression(
			'/\#\[NoCSRFRequired\]\s*\n\s*public function updatePost\(/',
			$src
		);
	}
}
