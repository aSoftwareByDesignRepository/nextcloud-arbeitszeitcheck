#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: surgical source mutants in BreakPolicyResolver must be
 * KILLED by the unit suite. A surviving mutant proves a coverage hole.
 *
 * Runs the resolver test file against each mutated source in place and
 * restores the original in a finally block. Serial and self-restoring —
 * never run two copies in parallel on a bind-mounted checkout.
 *
 * Run (container): php -d opcache.enable_cli=0 tests/Mutation/run-break-policy-resolver-mutations.php
 * Run (host):      bash ../../docker/run-app-phpunit.sh arbeitszeitcheck --testsuite unit --filter BreakPolicyResolverTest
 *                  (sanity path only — real mutants need the in-container run)
 */

$root = dirname(__DIR__, 2);
$target = $root . '/lib/Support/BreakPolicyResolver.php';
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
		'name' => 'tier threshold: exclusive > becomes inclusive >=',
		'needle' => 'if ($workSeconds > $threshold) {',
		'replacement' => 'if ($workSeconds >= $threshold) {',
		'runs' => 'tests/Unit/Support/BreakPolicyResolverTest.php',
	],
	[
		'name' => 'effective required: max() collapses to min()',
		'needle' => '$max = max($law, $org, $model);',
		'replacement' => '$max = min($law, $org, $model);',
		'runs' => 'tests/Unit/Support/BreakPolicyResolverTest.php',
	],
	[
		'name' => 'org floor: max() weakens to min()',
		'needle' => '$floor = max($floor, $orgFloor);',
		'replacement' => '$floor = min($floor, $orgFloor);',
		'runs' => 'tests/Unit/Support/BreakPolicyResolverTest.php',
	],
	[
		'name' => 'model floor: max() weakens to min()',
		'needle' => '$floor = max($floor, $modelFloor);',
		'replacement' => '$floor = min($floor, $modelFloor);',
		'runs' => 'tests/Unit/Support/BreakPolicyResolverTest.php',
	],
	[
		'name' => 'tier merge order flips descending',
		'needle' => "usort(\$tiers, static fn (array \$a, array \$b): int => \$a['afterHours'] <=> \$b['afterHours']);",
		'replacement' => "usort(\$tiers, static fn (array \$a, array \$b): int => \$b['afterHours'] <=> \$a['afterHours']);",
		'runs' => 'tests/Unit/Support/BreakPolicyResolverTest.php',
	],
	[
		'name' => 'enforced flag inverted',
		'needle' => "return \$this->orgConfig()['enforced'] === true;",
		'replacement' => "return \$this->orgConfig()['enforced'] !== true;",
		'runs' => 'tests/Unit/Support/BreakPolicyResolverTest.php',
	],
	[
		'name' => 'window wrap removed: overnight windows collapse',
		'needle' => "if (\$this->timeToMinutes((string)\$window['end']) < \$this->timeToMinutes((string)\$window['start'])) {",
		'replacement' => "if (false) {",
		'runs' => 'tests/Unit/Support/BreakPolicyResolverTest.php',
	],
	[
		'name' => 'tier requirement: max() collapses to min()',
		'needle' => "\$required = max(\$required, (int)\$tier['breakMinutes']);",
		'replacement' => "\$required = min(\$required, (int)\$tier['breakMinutes']);",
		'runs' => 'tests/Unit/Support/BreakPolicyResolverTest.php',
	],
];

function runSuite(string $phpunit, string $testFile, string $root): int
{
	// --do-not-cache-result: a root-run spawned phpunit must not drop a
	// root-owned .phpunit.result.cache onto the bind-mounted app dir.
	$cmd = sprintf(
		'cd %s && php -d opcache.enable_cli=0 %s --testsuite unit --filter BreakPolicyResolverTest --do-not-cache-result %s 2>&1',
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
	if (runSuite($phpunit, 'tests/Unit/Support/BreakPolicyResolverTest.php', $root) !== 0) {
		fwrite(STDERR, "FAIL: baseline resolver suite already red — fix before mutating.\n");
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
	fwrite(STDERR, "FAIL: " . count($survived) . " mutant(s) survived — coverage hole.\n");
	exit(1);
}
echo "All {$killed} break-policy-resolver mutants killed.\n";
