<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the "report sick today" prefill.
 *
 * Usage (Docker, as www-data):
 *   php -d opcache.enable_cli=0 tests/Mutation/run-report-sick-today-mutations.php
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = 'phpunit';
foreach ([
	$appRoot . '/vendor/bin/phpunit',
	dirname($appRoot) . '/budgetcheck/vendor/bin/phpunit',
] as $candidate) {
	if (is_file($candidate)) {
		$phpunit = $candidate;
		break;
	}
}

/**
 * @param list<string> $filters
 */
function run_filters(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = escapeshellarg(PHP_BINARY)
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore_file(string $path, string $backup): void
{
	if (is_file($backup)) {
		rename($backup, $path);
	}
}

/**
 * @param array{file:string,from:string,to:string,filters:list<string>} $mutation
 */
function apply_and_expect_fail(string $appRoot, string $phpunit, string $name, array $mutation): bool
{
	$path = $appRoot . '/' . $mutation['file'];
	$backup = $path . '.mutation-bak';
	$original = file_get_contents($path);
	if ($original === false || !str_contains($original, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		return false;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($mutation['from'], $mutation['to'], $original);
	if ($mutated === $original) {
		restore_file($path, $backup);
		fwrite(STDERR, "Mutation replace had no effect for {$name}\n");
		return false;
	}
	file_put_contents($path, $mutated);
	echo "\n== mutation: {$name} ==\n";
	$code = run_filters($appRoot, $phpunit, $mutation['filters']);
	restore_file($path, $backup);
	if ($code === 0) {
		echo "MUTATION SURVIVED: {$name}\n";
		return false;
	}
	echo "killed {$name}\n";
	return true;
}

$filters = [
	'AbsenceCreateQueryPrefillTest',
	'ReportSickTodayContractTest',
];

$mutations = [
	'accept_any_type_string' => [
		'file' => 'lib/Util/AbsenceCreateQueryPrefill.php',
		'from' => 'return AbsenceTypeCatalog::isCreatable($type) ? $type : \'\';',
		'to' => 'return $type;',
		'filters' => ['AbsenceCreateQueryPrefillTest::testUnknownInactiveAndNonStringsPrefillNothing'],
	],
	'skip_trim' => [
		'file' => 'lib/Util/AbsenceCreateQueryPrefill.php',
		'from' => '$type = trim($raw);',
		'to' => '$type = $raw;',
		'filters' => ['AbsenceCreateQueryPrefillTest::testSickLeaveIsKept'],
	],
	'accept_arrays' => [
		'file' => 'lib/Util/AbsenceCreateQueryPrefill.php',
		'from' => "\t\tif (!is_string(\$raw)) {\n\t\t\treturn '';\n\t\t}",
		'to' => "\t\tif (false) {\n\t\t\treturn '';\n\t\t}",
		'filters' => ['AbsenceCreateQueryPrefillTest::testUnknownInactiveAndNonStringsPrefillNothing'],
	],
	'prefill_vacation_instead_of_sick' => [
		'file' => 'templates/absences.php',
		'from' => "'type' => Absence::TYPE_SICK_LEAVE,",
		'to' => "'type' => Absence::TYPE_VACATION,",
		'filters' => ['ReportSickTodayContractTest::testListShortcutPrefillsTodayAndSickLeaveWithoutSubmitting'],
	],
	'restore_half_day_query' => [
		'file' => 'templates/absences.php',
		'from' => "'type' => Absence::TYPE_SICK_LEAVE,\n\t]);",
		'to' => "'type' => Absence::TYPE_SICK_LEAVE,\n\t\t'half' => '1',\n\t]);",
		'filters' => ['ReportSickTodayContractTest::testListShortcutPrefillsTodayAndSickLeaveWithoutSubmitting'],
	],
	'drop_controller_prefill' => [
		'file' => 'lib/Controller/AbsenceController.php',
		'from' => "'prefillType' => \$prefillType,",
		'to' => "'prefillType' => '',",
		'filters' => ['ReportSickTodayContractTest::testListShortcutPrefillsTodayAndSickLeaveWithoutSubmitting'],
	],
];

echo "== baseline report-sick-today contracts ==\n";
$baseline = run_filters($appRoot, $phpunit, $filters);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$failed = [];
foreach ($mutations as $name => $mutation) {
	if (!apply_and_expect_fail($appRoot, $phpunit, $name, $mutation)) {
		$failed[] = $name;
	}
}

foreach ($mutations as $mutation) {
	$bak = $appRoot . '/' . $mutation['file'] . '.mutation-bak';
	if (is_file($bak)) {
		restore_file($appRoot . '/' . $mutation['file'], $bak);
	}
}

if ($failed !== []) {
	fwrite(STDERR, 'Surviving mutations: ' . implode(', ', $failed) . "\n");
	exit(1);
}

echo "\nAll report-sick-today mutations killed.\n";
exit(0);
