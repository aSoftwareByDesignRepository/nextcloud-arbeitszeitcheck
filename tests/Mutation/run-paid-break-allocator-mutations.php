#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: surgical source mutants in PaidBreakAllocator must be
 * KILLED by the unit suite. A surviving mutant proves a coverage hole.
 *
 * Runs the allocator test file against each mutated source in place and
 * restores the original in a finally block. Serial and self-restoring —
 * never run two copies in parallel on a bind-mounted checkout.
 *
 * Run (container): php -d opcache.enable_cli=0 tests/Mutation/run-paid-break-allocator-mutations.php
 * Run (host):      bash ../../docker/run-app-phpunit.sh arbeitszeitcheck --testsuite unit --filter PaidBreakAllocatorTest
 *                  (sanity path only — real mutants need the in-container run)
 */

$root = dirname(__DIR__, 2);
$target = $root . '/lib/Service/PaidBreakAllocator.php';
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
		'name' => 'policy flag inverted: enabled pays nothing, disabled pays',
		'needle' => "if (\$policy['count_as_work'] !== true) {",
		'replacement' => "if (\$policy['count_as_work'] === true) {",
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'cap unit: minutes never scaled to seconds',
		'needle' => ": \$policy['cap_minutes'] * 60;",
		'replacement' => ": \$policy['cap_minutes'];",
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'distribution: min() flips to max() — cap bound ignored',
		'needle' => "\$entryPaid = min(\$row['seconds'], \$poolRest);",
		'replacement' => "\$entryPaid = max(\$row['seconds'], \$poolRest);",
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'widening skipped: span-seeded set never refetched for the day range',
		'needle' => 'if ($rangeStart >= $fetchedStart && $rangeEnd <= $fetchedEnd) {',
		'replacement' => 'if (true) {',
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'widening floor: fetchedStart takes max() — earlier days unfetched',
		'needle' => '$fetchedStart = min($fetchedStart, $rangeStart);',
		'replacement' => '$fetchedStart = max($fetchedStart, $rangeStart);',
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'widening ceiling: fetchedEnd takes min() — later days unfetched',
		'needle' => '$fetchedEnd = max($fetchedEnd, $rangeEnd);',
		'replacement' => '$fetchedEnd = min($fetchedEnd, $rangeEnd);',
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'convergence never breaks: loop runs to bound then throws',
		'needle' => '			if ($rangeStart >= $fetchedStart && $rangeEnd <= $fetchedEnd) {' . "\n" . '				break;',
		'replacement' => '			if ($rangeStart >= $fetchedStart && $rangeEnd <= $fetchedEnd) {' . "\n" . '				continue;',
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'allocation order reversed: latest entry drains the pool first',
		'needle' => "usort(\$perEntry, static fn (array \$a, array \$b): int => \$a['start'] <=> \$b['start'] ?: \$a['id'] <=> \$b['id']);",
		'replacement' => "usort(\$perEntry, static fn (array \$a, array \$b): int => \$b['start'] <=> \$a['start'] ?: \$b['id'] <=> \$a['id']);",
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'participant filter inverted: only non-participants get paid',
		'needle' => 'if ($id === null || !in_array($entry->getStatus(), self::PARTICIPATING_STATUSES, true)) {',
		'replacement' => 'if ($id === null || in_array($entry->getStatus(), self::PARTICIPATING_STATUSES, true)) {',
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'floor boundary: exactly-at-floor break no longer counts',
		'needle' => "if (\$floorSeconds > 0 && (\$portion['end'] - \$portion['start']) < \$floorSeconds) {",
		'replacement' => "if (\$floorSeconds > 0 && (\$portion['end'] - \$portion['start']) <= \$floorSeconds) {",
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'rejected swap: rejected entries keep their pool share',
		'needle' => '$entry->getStatus() === TimeEntry::STATUS_REJECTED',
		'replacement' => '$entry->getStatus() !== TimeEntry::STATUS_REJECTED',
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'finalized-month inversion: closed months recomputed, open skipped',
		'needle' => 'if ($monthFinality[$monthKey]) {',
		'replacement' => 'if (!$monthFinality[$monthKey]) {',
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'idempotency skip inverted: equal snapshots rewritten, diffs skipped',
		'needle' => 'if ($entry->getPaidBreakSeconds() === $target) {',
		'replacement' => 'if ($entry->getPaidBreakSeconds() !== $target) {',
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
	[
		'name' => 'window extension collapses: entry start before span ignored',
		'needle' => '$winStart = min($winStart, $start->getTimestamp());',
		'replacement' => '$winStart = max($winStart, $start->getTimestamp());',
		'runs' => 'tests/Unit/Service/PaidBreakAllocatorTest.php',
	],
];

function runSuite(string $phpunit, string $testFile, string $root): int
{
	// --do-not-cache-result: a root-run spawned phpunit must not drop a
	// root-owned .phpunit.result.cache onto the bind-mounted app dir.
	$cmd = sprintf(
		'cd %s && php -d opcache.enable_cli=0 %s --testsuite unit --filter PaidBreakAllocatorTest --do-not-cache-result %s 2>&1',
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
	if (runSuite($phpunit, 'tests/Unit/Service/PaidBreakAllocatorTest.php', $root) !== 0) {
		fwrite(STDERR, "FAIL: baseline allocator suite already red — fix before mutating.\n");
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
echo "\nAll {$killed} paid-break-allocator mutants killed.\n";
exit(0);
