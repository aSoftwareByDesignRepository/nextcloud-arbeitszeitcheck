<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

class ReportSickTodayContractTest extends TestCase
{
	public function testListShortcutPrefillsTodayAndSickLeaveWithoutSubmitting(): void
	{
		$template = (string)file_get_contents(dirname(__DIR__, 3) . '/templates/absences.php');
		self::assertMatchesRegularExpression(
			"/if \\(\\\$mode === 'list'\\) \\{\s*"
			. "\\\$reportSickTodayUrl = \\\$urlGenerator->linkToRoute\\('arbeitszeitcheck\\.absence\\.create', \\[\s*"
			. "'start' => \\\$today->format\\('Y-m-d'\\),\s*"
			. "'end' => \\\$today->format\\('Y-m-d'\\),\s*"
			. "'type' => Absence::TYPE_SICK_LEAVE,\s*"
			. '\\]\\);/s',
			$template
		);
		self::assertStringNotContainsString('HalfDayVacationShortcut', $template);
		self::assertStringNotContainsString('btn-half-day-today', $template);
		self::assertStringNotContainsString("'half' => '1'", $template);
		self::assertSame(2, substr_count($template, "\$l->t('Report sick today')"));
		self::assertStringContainsString('id="btn-report-sick-today"', $template);
		self::assertStringContainsString('id="btn-report-sick-today-empty"', $template);
		self::assertStringContainsString('id="absence-day-fraction-half"', $template);
		self::assertStringContainsString(
			'$prefillType !== \'\' && $prefillType === $typeKey',
			$template
		);
		// AZC-HALF-DAY-NON-VACATION: the day-length control is gated by the org
		// allowlist (typeSupportsHalfDay), not a hard-coded vacation check — a
		// sick-today deep link only reveals it when sick_leave is enabled AND
		// prefillHalf (half=1) is set, never from the type param alone.
		// AC-G11: in a vacation_unit=hours org only *vacation* loses the
		// control — enabled non-vacation types stay offerable.
		self::assertStringContainsString(
			'$typeSupportsHalfDay = in_array($absenceFormType, $halfDayEnabledTypes, true)',
			$template
		);
		self::assertStringContainsString(
			'($absenceFormType !== Absence::TYPE_VACATION || !$isVacationHours)',
			$template
		);
		self::assertStringContainsString(
			'&& $typeSupportsHalfDay',
			$template
		);
		// AC-G11 (JS side): the runtime gate must mirror the PHP head — the
		// control initializes regardless of vacation unit and only vacation
		// is withheld in hours mode. A blanket data-days-mode early return
		// would strand enabled non-vacation types in hours-mode orgs.
		self::assertStringContainsString(
			"return t !== 'vacation' || daysMode;",
			$template
		);
		self::assertStringNotContainsString(
			"group.getAttribute('data-days-mode') !== '1') {\n                return;",
			$template
		);

		$controller = (string)file_get_contents(dirname(__DIR__, 3) . '/lib/Controller/AbsenceController.php');
		self::assertStringContainsString(
			'$prefillType = AbsenceCreateQueryPrefill::type($this->request->getParam(\'type\', \'\'));',
			$controller
		);
		self::assertStringContainsString("'prefillType' => \$prefillType,", $controller);

		$de = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/l10n/de.json'), true);
		self::assertIsArray($de);
		self::assertSame('Heute krankmelden', $de['translations']['Report sick today'] ?? null);
	}
}
