<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Pins manager-dashboard absence-type map → template l10n inject → AbsenceTypeLabel.
 * Prevents English fallbacks when a type is added to the API but not to JS/template.
 */
class ManagerDashboardAbsenceTypeL10nContractTest extends TestCase
{
	public function testJsMapKeysMatchAbsenceTypeLabelAndTemplateInject(): void
	{
		$js = (string) file_get_contents(dirname(__DIR__, 3) . '/js/manager-dashboard.js');
		$tpl = (string) file_get_contents(dirname(__DIR__, 3) . '/templates/manager-dashboard.php');
		// AbsenceTypeLabel delegates to the canonical AbsenceTypeCatalog —
		// pin the catalog source so every API type has a translated label.
		$labelSrc = (string) file_get_contents(dirname(__DIR__, 3) . '/lib/Support/AbsenceTypeCatalog.php');

		self::assertMatchesRegularExpression(
			'/var ABSENCE_TYPE_TO_L10N_KEY\s*=\s*\{([\s\S]*?)\};/',
			$js,
			'manager-dashboard.js must declare ABSENCE_TYPE_TO_L10N_KEY',
		);
		preg_match('/var ABSENCE_TYPE_TO_L10N_KEY\s*=\s*\{([\s\S]*?)\};/', $js, $m);
		$mapBody = $m[1];

		$expected = [
			'vacation' => 'Vacation',
			'sick_leave' => 'Sick leave',
			'personal_leave' => 'Personal leave',
			'parental_leave' => 'Parental leave',
			'special_leave' => 'Special leave',
			'unpaid_leave' => 'Unpaid leave',
			'home_office' => 'Home office',
			'business_trip' => 'Business trip',
			'child_sick' => 'Sick Child',
			'overtime_comp' => 'Compensatory Time Off',
		];

		foreach ($expected as $code => $msgid) {
			self::assertMatchesRegularExpression(
				'/' . preg_quote($code, '/') . '\s*:\s*[\'"]' . preg_quote($msgid, '/') . '[\'"]/',
				$mapBody,
				"JS map must map {$code} → l10n msgid {$msgid}",
			);
			self::assertStringContainsString(
				"\$l->t('{$msgid}')",
				$tpl,
				"manager-dashboard.php must inject translated {$msgid}",
			);
			// Catalog keys are Absence::TYPE_* constants (strtoupper(code));
			// JS-map msgids may intentionally differ in casing from the
			// catalog's Title-Case labels — pin translation presence, not
			// msgid equality between the two label sets.
			$constName = 'Absence::TYPE_' . strtoupper($code);
			self::assertMatchesRegularExpression(
				'/' . preg_quote($constName, '/') . '\s*=>\s*\$l->t\(\'/',
				$labelSrc,
				"AbsenceTypeCatalog must translate {$code}",
			);
		}

		self::assertStringContainsString('return t(msgKey, msgKey)', $js, 'Map values must render via t(), never as raw English');
		self::assertStringContainsString('absenceTypeDisplay(typeCode)', $js);
	}
}
