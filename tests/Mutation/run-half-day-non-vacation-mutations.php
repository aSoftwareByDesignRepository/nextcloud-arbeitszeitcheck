<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for opt-in non-vacation half-day (AZC-HALF-DAY-NON-VACATION).
 *
 * Each mutant must be KILLED by the named unit tests; a survivor means the
 * guard it targets is not actually pinned by tests.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

/**
 * @param list<string> $filters
 */
function run_unit_tests(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		rename($backup, $source);
	}
}

$suiteFilters = [
	'AbsenceHalfDayNonVacationTest',
	'AbsenceDayShapeTest',
	'HalfDayTypePolicyTest',
	'PaidAbsencePlannedHoursCreditServiceTest',
	'RecomputeAbsenceDaysScheduleTest',
];

echo "== baseline non-vacation half-day tests ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	[
		'name' => 'fail_closed_parse_loses_vacation_default',
		'file' => 'lib/Support/HalfDayTypePolicy.php',
		'from' => "if (!is_array(\$decoded) || !array_is_list(\$decoded)) {
			return [Absence::TYPE_VACATION];
		}",
		'to' => "if (!is_array(\$decoded) || !array_is_list(\$decoded)) {
			return [];
		}",
		'filters' => ['HalfDayTypePolicyTest::testEnabledTypesFromRawFailsClosedOnGarbage'],
	],
	[
		'name' => 'empty_list_honored_becomes_vacation',
		'file' => 'lib/Support/HalfDayTypePolicy.php',
		'from' => "			if (!in_array(\$v, \$out, true)) {
				\$out[] = \$v;
			}
		}
		return \$out;",
		'to' => "			if (!in_array(\$v, \$out, true)) {
				\$out[] = \$v;
			}
		}
		return \$out === [] ? [Absence::TYPE_VACATION] : \$out;",
		'filters' => ['HalfDayTypePolicyTest::testEnabledTypesFromRawHonoursEmptyList'],
	],
	[
		'name' => 'drop_type_gate',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => "			if (!\$fractionTrusted
				&& !HalfDayTypePolicy::isEnabledForType(\$type, \$this->halfDayEnabledTypes())) {",
		'to' => "			if (!\$fractionTrusted
				&& false) {",
		'filters' => [
			'AbsenceHalfDayNonVacationTest::testCreateSickHalfDayWhenDisabledThrows',
			'AbsenceHalfDayNonVacationTest::testManagerCreateSickHalfDayWhenDisabledThrows',
			'AbsenceHalfDayNonVacationTest::testUpdateExplicitFractionOnDisabledTypeThrows',
		],
	],
	[
		'name' => 'drop_legacy_range_fraction_guard',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => "			if (\$shape['legacy_range_fraction']) {
				throw new BusinessRuleException(",
		'to' => "			if (false) {
				throw new BusinessRuleException(",
		'filters' => ['AbsenceHalfDayNonVacationTest::testCreateSickMultiDayHalfRejected'],
	],
	[
		'name' => 'drop_fraction_multiply',
		'file' => 'lib/Support/AbsenceDayShape.php',
		'from' => "'days' => round(\$wStart * \$shape['start_fraction'], 4),",
		'to' => "'days' => round(\$wStart, 4),",
		'filters' => [
			'AbsenceDayShapeTest::testDebitSingleDayHalfWeightRelative',
			'AbsenceHalfDayNonVacationTest::testCreateSickHalfDayWhenEnabled',
			'AbsenceHalfDayNonVacationTest::testHalfDayOnHalfWeightDayStoresQuarter',
			'AbsenceHalfDayNonVacationTest::testManagerCreateApprovedSickHalfDay',
		],
	],
	[
		'name' => 'drop_boundary_fraction_multiply',
		'file' => 'lib/Support/AbsenceDayShape.php',
		'from' => "\$days = \$interior
			+ \$wStart * \$shape['start_fraction']
			+ \$wEnd * \$shape['end_fraction'];",
		'to' => "\$days = \$interior
			+ \$wStart
			+ \$wEnd;",
		'filters' => ['AbsenceDayShapeTest::testDebitBoundaryHalvesMultiDay'],
	],
	[
		'name' => 'drop_entitlement_bypass',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => "		if (\$this->isEntitlementDaysBoundType(\$type)) {
			\$this->assertNoBoundaryShapeInput(\$data);
			return \$this->computeWorkingDaysForUser(\$userId, \$start, \$end);
		}",
		'to' => "		if (false && \$this->isEntitlementDaysBoundType(\$type)) {
			\$this->assertNoBoundaryShapeInput(\$data);
			return \$this->computeWorkingDaysForUser(\$userId, \$start, \$end);
		}",
		'filters' => ['AbsenceHalfDayNonVacationTest::testEntitlementBoundTypeBypassesGate'],
	],
	[
		'name' => 'drop_trusted_preserve',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => "				\$storedFraction = \$storedDays / \$oldWeight;
				if (abs(\$storedFraction - 0.5) <= 0.011) {
					\$validateData['day_fraction'] = '0.5';
					\$fractionTrusted = true;
				}",
		'to' => "				\$storedFraction = \$storedDays / \$oldWeight;
				if (false && abs(\$storedFraction - 0.5) <= 0.011) {
					\$validateData['day_fraction'] = '0.5';
					\$fractionTrusted = true;
				}",
		'filters' => [
			'AbsenceHalfDayNonVacationTest::testUpdatePreservesTrustedSickHalfDay',
			'AbsenceHalfDayNonVacationTest::testUpdatePreservesTrustedQuarterOnHalfWeightDay',
			'AbsenceHalfDayNonVacationTest::testUpdateOmittedFractionSurvivesFlagOff',
		],
	],
	[
		'name' => 'conversion_loses_fraction',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => '		return round($targetWeight * $fraction, 4);',
		'to' => '		return $targetWeight;',
		'filters' => ['AbsenceServiceTest::testConvertVacationToSickCarriesStoredHalfDayFraction'],
	],
	[
		'name' => 'credit_literal_fraction_regress',
		'file' => 'lib/Service/PaidAbsencePlannedHoursCreditService.php',
		'from' => '		$fraction = (float)$rawDays / $weight;',
		'to' => '		$fraction = abs((float)$rawDays - 0.5) < 0.011 ? 0.5 : 1.0;',
		'filters' => ['PaidAbsencePlannedHoursCreditServiceTest::testHalfDayOnHalfWeightDayCreditsHalfPlanned'],
	],
	[
		'name' => 'stored_fraction_literal_regress',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => "		return abs(((float)\$days / \$weight) - 0.5) <= 0.011 ? '0.5' : '1';",
		'to' => "		return abs((float)\$days - 0.5) <= 0.011 ? '0.5' : '1';",
		'filters' => [
			'AbsenceHalfDayNonVacationTest::testStoredDayFractionDetectsQuarterOnHalfWeightDay',
			'AbsenceHalfDayNonVacationTest::testStoredDayFractionFullOnHalfWeightDayIsNotHalf',
		],
	],
	[
		'name' => 'repair_inflates_known_fractions',
		'file' => 'lib/Repair/RecomputeAbsenceDaysSchedule.php',
		'from' => 'foreach ([0.25, 0.5, 0.75] as $knownFraction) {',
		'to' => 'foreach ([] as $knownFraction) {',
		'filters' => ['RecomputeAbsenceDaysScheduleTest'],
	],
	[
		'name' => 'shape_ghost_slot_allowed',
		'file' => 'lib/Support/AbsenceDayShape.php',
		'from' => 'if ($slot !== null && $f >= 1.0 - self::EPS) {
				throw new ShapeInputException(self::ERR_SLOT_GHOST);',
		'to' => 'if (false) {
				throw new ShapeInputException(self::ERR_SLOT_GHOST);',
		'filters' => ['AbsenceDayShapeTest::testGhostSlotWithoutFractionThrows'],
	],
	[
		'name' => 'shape_same_slot_pair_allowed',
		'file' => 'lib/Support/AbsenceDayShape.php',
		'from' => 'if (isset($slots[$slot])) {
				return false;
			}',
		'to' => 'if (false) {
				return false;
			}',
		'filters' => ['AbsenceDayShapeTest::testSameSlotRejected'],
	],
	[
		'name' => 'shape_slotless_pair_allowed',
		'file' => 'lib/Support/AbsenceDayShape.php',
		'from' => 'if ($slot === null || !in_array($slot, [self::SLOT_AM, self::SLOT_PM], true)) {
				return false;
			}',
		'to' => 'if (false && ($slot === null || !in_array($slot, [self::SLOT_AM, self::SLOT_PM], true))) {
				return false;
			}',
		'filters' => ['AbsenceDayShapeTest::testSlotlessHalfCannotPair'],
	],
	[
		'name' => 'shape_overfill_allowed',
		'file' => 'lib/Support/AbsenceDayShape.php',
		'from' => 'return $sum <= 1.0 + self::EPS;',
		'to' => 'return true;',
		'filters' => ['AbsenceDayShapeTest::testDistinctSlotsOverfillRejected'],
	],
	[
		'name' => 'shape_full_shortcircuit_removed',
		'file' => 'lib/Support/AbsenceDayShape.php',
		'from' => "if (!\$shape['fractional']) {",
		'to' => "if (true) {",
		'filters' => ['AbsenceDayShapeTest::testDebitSingleDayHalfWeightRelative','AbsenceDayShapeTest::testDebitBoundaryHalvesMultiDay'],
	],
];

$failed = 0;
foreach ($mutations as $mutation) {
	$source = $appRoot . '/' . $mutation['file'];
	$backup = $source . '.mutbak';
	$from = $mutation['from'];
	$to = $mutation['to'];
	$contents = file_get_contents($source);
	if ($contents === false || !str_contains($contents, $from)) {
		fwrite(STDERR, "Mutation {$mutation['name']}: source fragment not found\n");
		$failed++;
		continue;
	}
	copy($source, $backup);
	file_put_contents($source, str_replace($from, $to, $contents));
	echo "== mutation {$mutation['name']} (expect FAIL) ==\n";
	$code = run_unit_tests($appRoot, $phpunit, $mutation['filters'] ?? $suiteFilters);
	restore($source, $backup);
	if ($code === 0) {
		fwrite(STDERR, "Mutation {$mutation['name']} was NOT caught by tests\n");
		$failed++;
	} else {
		echo "caught {$mutation['name']}\n";
	}
}

if ($failed > 0) {
	fwrite(STDERR, "Mutation gauntlet failed: {$failed}\n");
	exit(1);
}

echo "Non-vacation half-day mutation gauntlet OK\n";
exit(0);
