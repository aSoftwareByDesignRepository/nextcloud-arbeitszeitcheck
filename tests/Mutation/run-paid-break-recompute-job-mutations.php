#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: surgical source mutants in PaidBreakRecomputeJob must
 * be KILLED by the unit suite. A surviving mutant proves a coverage hole.
 *
 * Runs the job test file against each mutated source in place and restores
 * the original in a finally block. Serial and self-restoring — never run
 * two copies in parallel on a bind-mounted checkout.
 *
 * Run (container): php -d opcache.enable_cli=0 tests/Mutation/run-paid-break-recompute-job-mutations.php
 * Run (host):      bash ../../docker/run-app-phpunit.sh arbeitszeitcheck --testsuite unit --filter PaidBreakRecomputeJobTest
 *                  (sanity path only — real mutants need the in-container run)
 */

$root = dirname(__DIR__, 2);
$target = $root . '/lib/BackgroundJob/PaidBreakRecomputeJob.php';
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

/**
 * @param list<array{name:string, needle:string, replacement:string, runs:string}> $mutants
 */
$mutants = [
	[
		'name' => 'dedupe inversion: different target counts as queued',
		'needle' => 'if (($arg[$k] ?? null) !== $v) {',
		'replacement' => 'if (($arg[$k] ?? null) === $v) {',
		'runs' => 'tests/Unit/BackgroundJob/PaidBreakRecomputeJobTest.php',
	],
	[
		'name' => 'user-branch inversion: empty-string uid becomes the worklist',
		'needle' => "if (is_string(\$userId) && \$userId !== '') {",
		'replacement' => "if (is_string(\$userId) && \$userId === '') {",
		'runs' => 'tests/Unit/BackgroundJob/PaidBreakRecomputeJobTest.php',
	],
	[
		'name' => 'window end: +1 day flipped to -1 day (end-date excluded)',
		'needle' => "'to' => \$end !== null ? (clone \$end)->modify('+1 day') : null,",
		'replacement' => "'to' => \$end !== null ? (clone \$end)->modify('-1 day') : null,",
		'runs' => 'tests/Unit/BackgroundJob/PaidBreakRecomputeJobTest.php',
	],
	[
		'name' => 'entry counter resets per chunk: audit under-reports continuation totals',
		'needle' => '$stats[\'entries\'] += count($entries);',
		'replacement' => '$stats[\'entries\'] = count($entries);',
		'runs' => 'tests/Unit/BackgroundJob/PaidBreakRecomputeJobTest.php',
	],
	[
		'name' => 'full-chunk check inverted: short page reschedules, full page stops',
		'needle' => 'if (count($entries) === $remaining) {',
		'replacement' => 'if (count($entries) !== $remaining) {',
		'runs' => 'tests/Unit/BackgroundJob/PaidBreakRecomputeJobTest.php',
	],
	[
		'name' => 'cursor resets removed: next window starts at stale after-id',
		'needle' => '$afterId = 0;',
		'replacement' => '',
		'runs' => 'tests/Unit/BackgroundJob/PaidBreakRecomputeJobTest.php',
	],
	[
		'name' => 'user set never accumulates: audit reports zero users',
		'needle' => '$stats[\'users\'][$item[\'user\']] = true;',
		'replacement' => '',
		'runs' => 'tests/Unit/BackgroundJob/PaidBreakRecomputeJobTest.php',
	],
	[
		'name' => 'dedupe marker never cleared: post-completion enqueues swallowed (dedupe_marker_lost_enqueue)',
		'needle' => '$this->clearEnqueueMarker($modelId, is_string($userId) ? $userId : null);',
		'replacement' => '',
		'runs' => 'tests/Unit/BackgroundJob/PaidBreakRecomputeJobTest.php',
	],
	[
		'name' => 'marker add unguarded: dedupe block removed entirely',
		'needle' => 'if ($markerCache !== null && !$markerCache->add($markerKey, 1, self::ENQUEUE_MARKER_TTL)) {',
		'replacement' => 'if (false) {',
		'runs' => 'tests/Unit/BackgroundJob/PaidBreakRecomputeJobTest.php',
	],
];

function runSuite(string $phpunit, string $testFile, string $root): int
{
	// --do-not-cache-result: a root-run spawned phpunit must not drop a
	// root-owned .phpunit.result.cache onto the bind-mounted app dir.
	$cmd = sprintf(
		'cd %s && php -d opcache.enable_cli=0 %s --testsuite unit --filter PaidBreakRecomputeJobTest --do-not-cache-result %s 2>&1',
		escapeshellarg($root),
		escapeshellarg($phpunit),
		escapeshellarg($testFile)
	);
	exec($cmd, $out, $code);
	$joined = implode("\n", $out ?? []);
	// Bootstrap failures (e.g. NC config-dir writability under a wrong user)
	// exit 0 WITHOUT running tests — never let silence count as "killed".
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
	if (runSuite($phpunit, 'tests/Unit/BackgroundJob/PaidBreakRecomputeJobTest.php', $root) !== 0) {
		fwrite(STDERR, "FAIL: baseline job suite already red — fix before mutating.\n");
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
		$code = runSuite($phpunit, $m['runs'], $root);
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
echo "\nAll {$killed} paid-break-recompute-job mutants killed.\n";
exit(0);
