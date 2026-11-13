<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for days-mode half-day vacation (ADR-01 + SEC-01).
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
		copy($backup, $source); unlink($backup);
	}
}

$suiteFilters = [
	'VacationAllocationHalfDayDaysModeTest',
	'AbsenceHalfDayDaysModeTest',
	'AbsenceHalfDayParamsForwardingContractTest',
	'HalfDayVacationShortcutTest',
	'HalfDayVacationDeL10nContractTest',
];

echo "== baseline half-day days-mode tests ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	[
		'name' => 'drop_trusted_getDays_preference',
		'file' => 'lib/Service/VacationAllocationService.php',
		'from' => 'if ($rawStored !== null
				&& is_finite((float)$rawStored)
				&& (float)$rawStored > 0
				&& $calendarTotal > 0.0001
				&& self::isTrustedStoredVacationDays($absence, (float)$rawStored, $calendarTotal)
			) {',
		'to' => 'if (false && $rawStored !== null
				&& is_finite((float)$rawStored)
				&& (float)$rawStored > 0
				&& $calendarTotal > 0.0001
				&& self::isTrustedStoredVacationDays($absence, (float)$rawStored, $calendarTotal)
			) {',
		'filters' => ['VacationAllocationHalfDayDaysModeTest::testSplitConsumptionTrustedHalfDayUsesStoredDays', 'VacationAllocationHalfDayDaysModeTest::testProspectiveHalfDayDecreasesRemainingByHalf'],
	],
	[
		'name' => 'skip_integrity_gate',
		'file' => 'lib/Service/VacationAllocationService.php',
		'from' => '&& self::isTrustedStoredVacationDays($absence, (float)$rawStored, $calendarTotal)',
		'to' => '&& true /* mutated: skip isTrustedStoredVacationDays */',
		'filters' => ['VacationAllocationHalfDayDaysModeTest::testSplitConsumptionUntrustedMultiDayFallsBackToCalendar'],
	],
	[
		'name' => 'drop_update_half_preserve',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => "\$validateData['day_fraction'] = '0.5';",
		'to' => "/* mutated: drop half preserve */",
		'filters' => ['AbsenceHalfDayDaysModeTest::testUpdateOmittingDayFractionPreservesTrustedHalfDay'],
	],
	[
		'name' => 'allow_invalid_quarter_fraction',
		'file' => 'lib/Service/AbsenceService.php',
		'from' => 'if (abs($v - 0.5) < 1e-9) {
			return 0.5;
		}
		throw new BusinessRuleException(',
		'to' => 'if (abs($v - 0.5) < 1e-9) {
			return 0.5;
		}
		if (abs($v - 0.25) < 1e-9) {
			return 0.25;
		}
		throw new BusinessRuleException(',
		'filters' => ['AbsenceHalfDayDaysModeTest::testInvalidFractionRejected'],
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

echo "Half-day days-mode mutation gauntlet OK\n";
exit(0);
