<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

class ManagerAbsencesHoursLayoutContractTest extends TestCase
{
	public function testManagerAbsencesTemplateIncludesHoursRecordingSurface(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../templates/manager-absences.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('id="manager-absence-record-hours-field"', $src);
		$this->assertStringContainsString('id="manager-absence-record-hours"', $src);
		$this->assertStringContainsString('manager-absence-hours-preset', $src);
		$this->assertStringContainsString('data-hours-range', $src);
		$this->assertStringContainsString('id="manager-absence-record-hours-preview"', $src);
		$this->assertStringContainsString('id="employee-absences-duration-heading"', $src);
		$this->assertStringContainsString('vacationUnit', $src);
	}

	public function testManagerAbsencesJsAcceptsZeroHourEstimate(): void
	{
		$js = file_get_contents(dirname(__DIR__, 2) . '/../js/manager-absences.js');
		$this->assertNotFalse($js);
		$this->assertStringContainsString('j.hours < 0', $js);
		$this->assertStringNotContainsString('j.hours <= 0', $js);
	}

	public function testUsagePreviewsRenderBelowReasonField(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../templates/manager-absences.php');
		$this->assertNotFalse($src);
		$reasonField = strpos($src, 'manager-scope-page__reason-field');
		$fractionPreview = strpos($src, 'id="manager-absence-day-fraction-preview"');
		$hoursPreview = strpos($src, 'id="manager-absence-record-hours-preview"');
		$actions = strpos($src, 'manager-scope-page__record-actions');
		$this->assertNotFalse($reasonField);
		$this->assertNotFalse($fractionPreview);
		$this->assertNotFalse($hoursPreview);
		$this->assertNotFalse($actions);
		// Usage previews (vacation days / hours debit) sit between the reason
		// textarea and the save action — above the button, below the field.
		$this->assertLessThan($fractionPreview, $reasonField);
		$this->assertLessThan($hoursPreview, $reasonField);
		$this->assertLessThan($actions, $fractionPreview);
		$this->assertLessThan($actions, $hoursPreview);
		// Both start hidden and stay polite live regions after the move.
		$this->assertMatchesRegularExpression(
			'/id="manager-absence-day-fraction-preview"[^>]*role="status"[^>]*aria-live="polite"[^>]*hidden/',
			$src
		);
		$this->assertMatchesRegularExpression(
			'/id="manager-absence-record-hours-preview"[^>]*role="status"[^>]*aria-live="polite"[^>]*hidden/',
			$src
		);
	}

	public function testDaysConsumptionBadgeRendersBelowReasonWithEstimateEndpoint(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../templates/manager-absences.php');
		$this->assertNotFalse($src);
		$reasonField = strpos($src, 'manager-scope-page__reason-field');
		$consumption = strpos($src, 'id="manager-absence-record-days-consumption"');
		$actions = strpos($src, 'manager-scope-page__record-actions');
		$this->assertNotFalse($consumption);
		$this->assertLessThan($consumption, $reasonField);
		$this->assertLessThan($actions, $consumption);
		$this->assertMatchesRegularExpression(
			'/id="manager-absence-record-days-consumption"[^>]*role="status"[^>]*aria-live="polite"[^>]*hidden/',
			$src
		);
		// The template exposes the manager-scoped schedule-aware estimate URL.
		$this->assertStringContainsString('estimateEmployeeAbsenceDaysUrl', $src);
		$this->assertStringContainsString('manager.estimateEmployeeAbsenceDays', $src);
	}

	public function testManagerAbsencesJsGuardsConsumptionEstimateAgainstStaleResponses(): void
	{
		$js = file_get_contents(dirname(__DIR__, 2) . '/../js/manager-absences.js');
		$this->assertNotFalse($js);
		$this->assertStringContainsString('daysConsumptionSeq', $js);
		$this->assertStringContainsString('daysConsumptionAbort', $js);
		$this->assertStringContainsString('mySeq !== daysConsumptionSeq', $js);
		// picker.clear() during reset fires onChange — the hook must stay inert
		// or it re-fills the just-cleared hours/schedule state (hours orgs).
		$this->assertStringContainsString('recordFormResetting', $js);
		$this->assertStringContainsString('if (recordFormResetting)', $js);
		// Manager copy says "absence", not "request" — and pluralization is
		// locale-correct via n().
		$this->assertStringContainsString('This absence uses %n vacation days.', $js);
		$this->assertStringNotContainsString('This request uses %s vacation days.', $js);
	}

	public function testManagerAbsencesJsKeepsHalfDayControlForNonVacationInHoursMode(): void
	{
		$js = file_get_contents(dirname(__DIR__, 2) . '/../js/manager-absences.js');
		$this->assertNotFalse($js);
		// AC-G11: in a vacation_unit=hours org only *vacation* loses the
		// day-length control — enabled non-vacation types keep it.
		$this->assertStringContainsString(
			"const typeFractionCapable = daysMode || typeSel.value !== 'vacation';",
			$js
		);
	}
}
