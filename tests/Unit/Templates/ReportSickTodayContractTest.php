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
		self::assertStringContainsString(
			'$prefillType === \'\' || $prefillType === Absence::TYPE_VACATION',
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
