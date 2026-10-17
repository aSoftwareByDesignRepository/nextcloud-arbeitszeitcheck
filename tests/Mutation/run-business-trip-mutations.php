#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: surgical source mutants in the business-trip
 * security core must be KILLED by the unit suite. A surviving mutant
 * proves a coverage hole.
 *
 * Targets:
 *  - BusinessTripService: snapshot-token binding, CAS fail-close, credit
 *    flag gate, ledger reversal on cancel, closed-month guard.
 *  - BusinessTripRuleCatalog: legal-review gate, stale-hash rejection,
 *    review voided on edit.
 *  - BusinessTripRolloutReadinessService: cutover gates.
 *
 * Serial and self-restoring — never run two copies in parallel on a
 * bind-mounted checkout.
 *
 * Run (container): php -d opcache.enable_cli=0 tests/Mutation/run-business-trip-mutations.php
 */

$root = dirname(__DIR__, 2);
$phpunit = $root . '/vendor/bin/phpunit';

/**
 * @var list<array{file:string, name:string, needle:string, replacement:string, runs:string}>
 */
$mutants = [
	// --- BusinessTripService ---
	[
		'file' => 'lib/Service/BusinessTripService.php',
		'name' => 'approve accepts a stale/missing snapshot token',
		'needle' => 'if ($snapshotToken === null || !hash_equals($freshToken, $snapshotToken)) {',
		'replacement' => 'if (false) {',
		'runs' => 'tests/Unit/Service/BusinessTripServiceTest.php',
	],
	[
		'file' => 'lib/Service/BusinessTripService.php',
		'name' => 'CAS write conflict silently proceeds',
		'needle' => 'if (!$this->trips->updateIfRevision($trip)) {',
		'replacement' => 'if (false) {',
		'runs' => 'tests/Unit/Service/BusinessTripServiceTest.php',
	],
	[
		'file' => 'lib/Service/BusinessTripService.php',
		'name' => 'credit flag inverted: top-ups post while disabled',
		'needle' => "if (\$this->config->isEnabled('business_trip_credit_enabled')) {",
		'replacement' => "if (!\$this->config->isEnabled('business_trip_credit_enabled')) {",
		'runs' => 'tests/Unit/Service/BusinessTripServiceTest.php',
	],
	[
		'file' => 'lib/Service/BusinessTripService.php',
		'name' => 'cancel no longer reverses posted top-ups',
		'needle' => "\$this->ledger->reverseAllUnderLock(\$trip, \$actor, \$reason ?? 'cancelled');",
		'replacement' => 'null;',
		'runs' => 'tests/Unit/Service/BusinessTripServiceTest.php',
	],
	[
		'file' => 'lib/Service/BusinessTripService.php',
		'name' => 'closed-month guard skipped before transition',
		'needle' => '$this->assertMonthsMutable($trip->getUserId(), $trip->getStartsAtUtc(), $trip->getEndsAtUtc());',
		'replacement' => 'null;',
		'runs' => 'tests/Unit/Service/BusinessTripServiceTest.php',
	],
	// --- BusinessTripRuleCatalog ---
	[
		'file' => 'lib/Service/BusinessTripRuleCatalog.php',
		'name' => 'activation skips the legal-review gate',
		'needle' => 'if ($target->getReviewedAt() === null || $target->getReviewedBy() === null || (string)$target->getReviewedBy() === \'\') {',
		'replacement' => 'if (false) {',
		'runs' => 'tests/Unit/Service/BusinessTripRuleCatalogTest.php',
	],
	[
		'file' => 'lib/Service/BusinessTripRuleCatalog.php',
		'name' => 'review stamps a config the reviewer never saw',
		'needle' => 'if (!hash_equals((string)$set->getConfigHash(), $configHash)) {',
		'replacement' => 'if (false) {',
		'runs' => 'tests/Unit/Service/BusinessTripRuleCatalogTest.php',
	],
	[
		'file' => 'lib/Service/BusinessTripRuleCatalog.php',
		'name' => 'draft edit keeps the stale legal review',
		'needle' => 'if ($newHash !== (string)$set->getConfigHash()) {',
		'replacement' => 'if (false) {',
		'runs' => 'tests/Unit/Service/BusinessTripRuleCatalogTest.php',
	],
	// --- BusinessTripRolloutReadinessService ---
	[
		'file' => 'lib/Service/BusinessTripRolloutReadinessService.php',
		'name' => 'pending legacy requests stop blocking cutover',
		'needle' => '\'ready\' => $pendingLegacy === 0',
		'replacement' => '\'ready\' => $pendingLegacy >= 0',
		'runs' => 'tests/Unit/Service/BusinessTripRolloutReadinessServiceTest.php',
	],
	[
		'file' => 'lib/Service/BusinessTripRolloutReadinessService.php',
		'name' => 'zero reachable rule coverage stops blocking cutover',
		'needle' => '$effectiveAssignments > 0,',
		'replacement' => '$effectiveAssignments >= 0,',
		'runs' => 'tests/Unit/Service/BusinessTripRolloutReadinessServiceTest.php',
	],
];

function runSuite(string $phpunit, string $testFile, string $root, string $filter): int
{
	// --do-not-cache-result: a root-run spawned phpunit must not drop a
	// root-owned .phpunit.result.cache onto the bind-mounted app dir.
	$cmd = sprintf(
		'cd %s && php -d opcache.enable_cli=0 %s --testsuite unit --filter %s --do-not-cache-result %s 2>&1',
		escapeshellarg($root),
		escapeshellarg($phpunit),
		escapeshellarg($filter),
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
$originals = [];

try {
	foreach ($mutants as $m) {
		$target = $root . '/' . $m['file'];
		$originals[$m['file']] ??= file_get_contents($target);
		$original = $originals[$m['file']];
		if ($original === false || !is_writable($target)) {
			fwrite(STDERR, "FAIL: {$target} not readable/writable by this process.\n");
			exit(1);
		}

		$filter = basename($m['runs'], '.php');
		// Baseline per test file: unmutated suite must pass or the gauntlet
		// is meaningless.
		if (!isset($baselineDone[$m['runs']])) {
			if (runSuite($phpunit, $m['runs'], $root, $filter) !== 0) {
				fwrite(STDERR, "FAIL: baseline {$filter} already red — fix before mutating.\n");
				exit(1);
			}
			$baselineDone[$m['runs']] = true;
			echo "OK: baseline {$filter} green\n";
		}

		if (!str_contains($original, $m['needle'])) {
			fwrite(STDERR, "FAIL: needle not found for '{$m['name']}' — source drifted, update the mutant.\n");
			exit(1);
		}
		if (substr_count($original, $m['needle']) > 1) {
			fwrite(STDERR, "FAIL: needle for '{$m['name']}' is ambiguous — make it unique.\n");
			exit(1);
		}
		if (file_put_contents($target, str_replace($m['needle'], $m['replacement'], $original)) === false) {
			fwrite(STDERR, "FAIL: could not write mutant '{$m['name']}' to {$target}\n");
			exit(1);
		}
		$code = runSuite($phpunit, $m['runs'], $root, $filter);
		file_put_contents($target, $original);
		if ($code === 0) {
			$survived[] = $m['name'];
			fwrite(STDERR, "SURVIVED: {$m['name']}\n");
		} else {
			$killed++;
			echo "KILLED: {$m['name']}\n";
		}
	}
} finally {
	foreach ($originals as $file => $contents) {
		file_put_contents($root . '/' . $file, $contents);
	}
}

$total = count($mutants);
echo "\n{$killed}/{$total} mutants killed\n";
if ($survived !== []) {
	fwrite(STDERR, 'Surviving mutants: ' . implode('; ', $survived) . "\n");
	exit(1);
}
echo "Mutation gauntlet clean.\n";
exit(0);
