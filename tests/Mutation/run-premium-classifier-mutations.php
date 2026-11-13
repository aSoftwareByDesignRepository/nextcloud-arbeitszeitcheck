<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for PremiumSurchargeClassifier.
 *
 * Usage (Docker — required; host PHPUnit cannot bootstrap Nextcloud DB):
 *   docker compose exec -u www-data -T nextcloud php -d opcache.enable_cli=0 -d opcache.enable=0 \
 *     /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-premium-classifier-mutations.php
 *
 * Afterward repair bind-mount ownership (mutation rewrites sources as www-data).
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

$suiteFilters = ['PremiumSurchargeClassifierTest', 'PremiumPolicyTest', 'PremiumSurchargeServiceTest'];

echo "== baseline premium classifier unit tests ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	[
		'name' => 'ignore_night_window',
		'file' => 'lib/Support/PremiumSurchargeClassifier.php',
		'from' => 'if ($this->minuteInWindow($minuteOfDay, $ws, $we)) {
					$out[] = $cat;
				}',
		'to' => 'if (false) {
					$out[] = $cat;
				}',
		'filters' => ['PremiumSurchargeClassifierTest::testNightWindowSplitMondayEvening'],
	],
	[
		'name' => 'always_tag_all_matches',
		'file' => 'lib/Support/PremiumSurchargeClassifier.php',
		'from' => 'if ($stacking === PremiumPolicy::STACKING_MAX_SINGLE) {',
		'to' => 'if (false) {',
		'filters' => ['PremiumSurchargeClassifierTest::testMaxSingleRatePrefersSundayOverNight'],
	],
	[
		'name' => 'tagged_multi_sums_money',
		'file' => 'lib/Support/PremiumSurchargeClassifier.php',
		'from' => 'if ($stacking === PremiumPolicy::STACKING_ADDITIVE
				|| $stacking === PremiumPolicy::STACKING_MAX_SINGLE) {
				$totalValued += $valued;
			}',
		'to' => 'if (true) {
				$totalValued += $valued;
			}',
		'filters' => ['PremiumSurchargeClassifierTest::testTaggedMultiDoesNotSumMoney'],
	],
];

$failures = 0;
foreach ($mutations as $mutation) {
	$source = $appRoot . '/' . $mutation['file'];
	$backup = $source . '.mutbak';
	$filters = $mutation['filters'] ?? $suiteFilters;
	echo "== mutate: {$mutation['name']} ==\n";
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
		fwrite(STDERR, "SURVIVED: {$mutation['name']}\n");
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

echo "All PremiumSurchargeClassifier mutations killed.\n";
exit(0);
