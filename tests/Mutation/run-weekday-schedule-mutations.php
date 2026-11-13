<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for WeekdaySchedule (no Infection).
 *
 * Usage:
 *   docker compose exec -u www-data -T nextcloud php /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-weekday-schedule-mutations.php
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
	'WeekdayScheduleTest',
	'testWeekdayScheduleRequiredHoursForBanssWeek',
];

echo "== baseline WeekdaySchedule unit tests ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	[
		'name' => 'skip_unpaid_break_subtraction',
		'file' => 'lib/Support/WeekdaySchedule.php',
		'from' => 'return round(max(0.0, $gross - $unpaid), 4);',
		'to' => 'return round(max(0.0, $gross), 4);',
		'filters' => ['WeekdayScheduleTest::testBanssPresetNets'],
	],
	[
		'name' => 'ignore_holiday_fraction',
		'file' => 'lib/Support/WeekdaySchedule.php',
		'from' => '$total += $net * $fraction;',
		'to' => '$total += $net;',
		'filters' => ['WeekdayScheduleTest::testHalfDayFridayHoliday'],
	],
	[
		'name' => 'treat_paid_break_as_unpaid',
		'file' => 'lib/Support/WeekdaySchedule.php',
		'from' => 'if (!is_array($break) || !empty($break[\'paid\'])) {',
		'to' => 'if (!is_array($break)) {',
		'filters' => ['WeekdayScheduleTest::testPaidBreakDoesNotReduceNet'],
	],
	[
		'name' => 'accept_corrupt_schedule_at_runtime',
		'file' => 'lib/Support/WeekdaySchedule.php',
		'from' => 'if (self::validate($raw) !== []) {
			return null;
		}',
		'to' => 'if (false) {
			return null;
		}',
		'filters' => [
			'WeekdayScheduleTest::testTryFromBreakRulesIgnoresCorrupt',
			'testCorruptWeekdayScheduleFallsBackToLegacyWeeklyContract',
		],
	],
];

$failures = 0;
foreach ($mutations as $mutation) {
	$relative = $mutation['file'];
	$source = $appRoot . '/' . $relative;
	$backup = $source . '.mutbak';
	$filters = $mutation['filters'] ?? $suiteFilters;

	echo "== mutate: {$mutation['name']} ==\n";
	if (!is_file($source)) {
		fwrite(STDERR, "Missing source $source\n");
		$failures++;
		continue;
	}
	$contents = file_get_contents($source);
	if ($contents === false || !str_contains($contents, $mutation['from'])) {
		fwrite(STDERR, "Needle not found for {$mutation['name']}\n");
		$failures++;
		continue;
	}
	copy($source, $backup);
	file_put_contents($source, str_replace($mutation['from'], $mutation['to'], $contents));
	$code = run_unit_tests($appRoot, $phpunit, $filters);
	restore($source, $backup);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$mutation['name']} (tests still green)\n");
		$failures++;
	} else {
		echo "Killed: {$mutation['name']}\n";
	}
}

echo "== post-mutation baseline ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Source left broken after mutations\n");
	exit(1);
}

if ($failures > 0) {
	fwrite(STDERR, "$failures mutation(s) survived or failed to apply\n");
	exit(1);
}

echo "All WeekdaySchedule mutations killed.\n";
exit(0);
