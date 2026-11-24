<?php

declare(strict_types=1);

/**
 * Estimate-hours endpoints must stay wired to VacationHoursDebitService (companion + web).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

class VacationHoursEstimateEndpointContractTest extends TestCase
{
	public function testEmployeeEstimateEndpointDocumentsDebitShape(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/AbsenceController.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('function estimateVacationHours', $src);
		$this->assertStringContainsString('VacationHoursDebitService', $src);
		$this->assertStringContainsString('estimateForUserRange', $src);
		$this->assertStringContainsString("'weekday_nets'", $src);
		$this->assertStringContainsString("'one_day_hours'", $src);
		$this->assertStringContainsString("'average_daily'", $src);
		$this->assertStringContainsString("'basis'", $src);
	}

	public function testManagerEstimateEndpointDocumentsAclAndDebitShape(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/ManagerController.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('function estimateEmployeeVacationHours', $src);
		$this->assertStringContainsString('canManageEmployee', $src);
		$this->assertStringContainsString('estimateForUserRange', $src);
		$this->assertStringContainsString("'weekday_nets'", $src);
	}

	public function testRoutesExposeEstimateHours(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/appinfo/routes.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('/api/absences/estimate-hours', $src);
		$this->assertStringContainsString('/api/manager/employee-absences/estimate-hours', $src);
	}

	public function testValidatePathPassesUserAndRangeIntoResolve(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/AbsenceService.php');
		$this->assertNotFalse($src);
		$this->assertMatchesRegularExpression(
			'/resolveVacationDurationHours\(\s*\$data,\s*\(float\)\$totalRequested,\s*\$userId,\s*\$startDate,\s*\$endDate\s*\)/',
			$src,
			'validateAbsenceData must use schedule-aware debit for entitlement checks'
		);
		$this->assertStringContainsString(
			'if ($hoursMode && ($durationHours === null || $durationHours < 0.01))',
			$src,
			'hours mode rejects empty debit (schedule-aware); Mon–Fri day count must not block Sat schedule work'
		);
		$this->assertStringContainsString(
			'schedule-/holiday-aware debit is authoritative',
			$src
		);
	}

	public function testDebitServiceNeverInventSafeDaysOne(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/VacationHoursDebitService.php');
		$this->assertNotFalse($src);
		$this->assertStringNotContainsString('$workingDays > 0.009 ? $workingDays : 1.0', $src);
		// Per-segment equivalent of the old $safeDays clamp — never invent a
		// working day for weekend/holiday-only ranges.
		$this->assertStringContainsString(
			'$wd = max(0.0, $this->holidayService->computeWorkingDaysForUser(',
			$src
		);
	}

	public function testWidgetDefaultsToUnawareClient(): void
	{
		$src = file_get_contents(dirname(__DIR__, 3) . '/lib/Service/DashboardWidgetDataService.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString(
			'bool $vacationUnitAwareClient = false',
			$src,
			'Q8 fail-closed: default unaware'
		);
		$desklet = file_get_contents(dirname(__DIR__, 3) . '/lib/Dashboard/EmployeeStatusWidget.php');
		$this->assertNotFalse($desklet);
		$this->assertStringContainsString('getEmployeeWidgetData($userId, true)', $desklet);
	}
}
