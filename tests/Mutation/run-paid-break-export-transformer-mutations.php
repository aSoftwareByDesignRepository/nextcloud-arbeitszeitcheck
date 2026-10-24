#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: surgical source mutants in TimeEntryExportTransformer
 * must be KILLED by the unit suite. A surviving mutant proves a coverage
 * hole in the paid-basis / TH-06 explicit-column contract.
 *
 * Runs the transformer test file against each mutated source in place and
 * restores the original in a finally block. Serial and self-restoring —
 * never run two copies in parallel on a bind-mounted checkout.
 *
 * Run (container): php -d opcache.enable_cli=0 tests/Mutation/run-paid-break-export-transformer-mutations.php
 */

$root = dirname(__DIR__, 2);
$target = $root . '/lib/Service/TimeEntryExportTransformer.php';
$phpunit = $root . '/vendor/bin/phpunit';
$original = file_get_contents($target);
if ($original === false || !is_writable($target)) {
	// Bind-mounted sources are alex-owned: run in the container as the
	// default (root) user — www-data cannot restore, and -u 1000 makes the
	// spawned phpunit bail on NC's config-dir writability check (exit 0,
	// no tests → every mutant "survives").
	fwrite(STDERR, "FAIL: {$target} not readable/writable by this process.\n");
	exit(1);
}

$testFile = 'tests/Unit/Service/TimeEntryExportTransformerTest.php';

/**
 * @param list<array{name:string, needle:string, replacement:string}> $mutants
 */
$mutants = [
	[
		'name' => 'paid basis: working_hours falls back to net duration (head path)',
		'needle' => "\$totalWorkingHours = \$entry->getPaidDurationHours();",
		'replacement' => "\$totalWorkingHours = \$entry->getWorkingDurationHours();",
	],
	[
		'name' => 'paid basis: working_hours falls back to net duration (row path)',
		'needle' => "'working_hours' => \$entry->getPaidDurationHours(),",
		'replacement' => "'working_hours' => \$entry->getWorkingDurationHours(),",
	],
	[
		'name' => 'TH-06: net column reports paid duration (swap)',
		'needle' => "'net_working_hours' => \$entry->getWorkingDurationHours(),",
		'replacement' => "'net_working_hours' => \$entry->getPaidDurationHours(),",
	],
	[
		'name' => 'TH-06: paid-break column zeroed (row path)',
		'needle' => "'paid_break_hours' => \$entry->getPaidBreakHours(),",
		'replacement' => "'paid_break_hours' => 0.0,",
	],
	[
		'name' => 'TH-06 split: net segment not prorated',
		'needle' => "? round(\$entry->getWorkingDurationHours() * \$ratio, self::HOUR_ROUND_PRECISION)",
		'replacement' => "? round(\$entry->getWorkingDurationHours(), self::HOUR_ROUND_PRECISION)",
	],
	[
		'name' => 'TH-06 split: paid-break segment not prorated',
		'needle' => "'paid_break_hours' => round(\$entry->getPaidBreakHours() * \$ratio, self::HOUR_ROUND_PRECISION),",
		'replacement' => "'paid_break_hours' => round(\$entry->getPaidBreakHours(), self::HOUR_ROUND_PRECISION),",
	],
];

function runSuite(string $phpunit, string $testFile, string $root): int
{
	$out = [];
	$cmd = sprintf(
		'cd %s && php -d opcache.enable_cli=0 %s --testsuite unit --filter TimeEntryExportTransformerTest --do-not-cache-result %s 2>&1',
		escapeshellarg($root),
		escapeshellarg($phpunit),
		escapeshellarg($testFile)
	);
	exec($cmd, $out, $code);
	$joined = implode("\n", $out ?? []);
	// Bootstrap failures exit 0 WITHOUT running tests — never let silence
	// count as "killed".
	if (!str_contains($joined, 'OK (') && !str_contains($joined, 'FAILURES!') && !str_contains($joined, 'ERRORS!')) {
		fwrite(STDERR, "FAIL: spawned phpunit produced no test verdict — harness broken.\n{$joined}\n");
		exit(2);
	}
	return $code;
}

$killed = 0;
$survived = [];

try {
	// Baseline: unmutated suite must pass or the gauntlet is meaningless.
	if (runSuite($phpunit, $testFile, $root) !== 0) {
		fwrite(STDERR, "FAIL: baseline transformer suite already red — fix before mutating.\n");
		exit(1);
	}
	echo "OK: baseline suite green\n";

	foreach ($mutants as $m) {
		if (!str_contains($original, $m['needle'])) {
			fwrite(STDERR, "FAIL: needle not found for '{$m['name']}' — source drifted, update the mutant.\n");
			exit(1);
		}
		if (file_put_contents($target, str_replace($m['needle'], $m['replacement'], $original)) === false) {
			fwrite(STDERR, "FAIL: could not write mutant '{$m['name']}' to {$target}\n");
			exit(1);
		}
		$code = runSuite($phpunit, $testFile, $root);
		if ($code === 0) {
			$survived[] = $m['name'];
			fwrite(STDERR, "SURVIVED: {$m['name']}\n");
		} else {
			$killed++;
			echo "KILLED: {$m['name']}\n";
		}
	}
} finally {
	if (file_put_contents($target, $original) === false
		|| file_get_contents($target) !== $original) {
		fwrite(STDERR, "FATAL: restore of {$target} failed — file may still be mutated!\n");
		exit(2);
	}
}

if ($survived !== []) {
	fwrite(STDERR, "\n" . count($survived) . " mutant(s) survived — coverage holes:\n - " . implode("\n - ", $survived) . "\n");
	exit(1);
}
echo "\nAll {$killed} export-transformer mutants killed.\n";
exit(0);
