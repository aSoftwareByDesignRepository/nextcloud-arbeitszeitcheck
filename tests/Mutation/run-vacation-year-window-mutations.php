<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for VacationYearWindowResolver (Phase B).
 *
 * Usage:
 *   docker compose exec -u www-data -T nextcloud php /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-vacation-year-window-mutations.php
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

$suiteFilters = ['VacationYearWindowResolverTest'];

echo "== baseline VacationYearWindowResolver unit tests ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	[
		'name' => 'skip_feb29_clamp',
		'file' => 'lib/Service/VacationYearWindowResolver.php',
		'from' => 'if (!checkdate($month, $day, $year)) {
			// Feb 29 in non-leap → Feb 28
			$day = (int)(new \DateTimeImmutable(sprintf(\'%04d-%02d-01\', $year, $month)))->format(\'t\');
		}',
		'to' => 'if (false) {
			$day = (int)(new \DateTimeImmutable(sprintf(\'%04d-%02d-01\', $year, $month)))->format(\'t\');
		}',
		'filters' => ['VacationYearWindowResolverTest::testFeb29ClampsInNonLeapYear'],
	],
	[
		'name' => 'off_by_one_end_exclusive',
		'file' => 'lib/Service/VacationYearWindowResolver.php',
		'from' => 'if ($asOfDay < $next) {',
		'to' => 'if ($asOfDay <= $next) {',
		'filters' => ['VacationYearWindowResolverTest::testAnniversaryNextWindowAfterBoundary'],
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

echo "All VacationYearWindowResolver mutations killed.\n";
exit(0);
