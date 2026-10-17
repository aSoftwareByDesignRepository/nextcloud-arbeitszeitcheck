#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: surgical source mutants in the special-entitlement
 * security core must be KILLED by the unit suite. A surviving mutant
 * proves a coverage hole.
 *
 * Targets (special-leave-entitlements SPEC §Mutation/Adversarial Targets):
 *  - SpecialEntitlementResolver: priority inversion, team-depth inversion,
 *    `add` degrading to `replace`.
 *  - SpecialEntitlementService: reservation removed from the availability
 *    formula, disabled-type gate removed.
 *  - SpecialEntitlementAdminService: form-encoded "false" parsed truthy,
 *    stale revision accepted (CAS fail-open).
 *
 * Serial and self-restoring — never run two copies in parallel on a
 * bind-mounted checkout.
 *
 * Run (container): php -d opcache.enable_cli=0 tests/Mutation/run-special-entitlement-mutations.php
 */

$root = dirname(__DIR__, 2);
$phpunit = $root . '/vendor/bin/phpunit';

/**
 * @var list<array{file:string, name:string, needle:string, replacement:string, runs:string}>
 */
$mutants = [
	// --- SpecialEntitlementResolver ---
	[
		'file' => 'lib/Service/SpecialEntitlementResolver.php',
		'name' => 'L1 priority sort inverted',
		'needle' => '($b->getPriority() <=> $a->getPriority()) ?: ((int)$a->getId() <=> (int)$b->getId())',
		'replacement' => '($a->getPriority() <=> $b->getPriority()) ?: ((int)$a->getId() <=> (int)$b->getId())',
		'runs' => 'tests/Unit/Service/SpecialEntitlementResolverTest.php',
	],
	[
		'file' => 'lib/Service/SpecialEntitlementResolver.php',
		'name' => 'team-depth comparison inverted',
		'needle' => '($teamDepths[(string)$b->getScopeId()] ?? 0) <=> ($teamDepths[(string)$a->getScopeId()] ?? 0)',
		'replacement' => '($teamDepths[(string)$a->getScopeId()] ?? 0) <=> ($teamDepths[(string)$b->getScopeId()] ?? 0)',
		'runs' => 'tests/Unit/Service/SpecialEntitlementResolverTest.php',
	],
	[
		'file' => 'lib/Service/SpecialEntitlementResolver.php',
		'name' => 'add operation degrades to replace',
		'needle' => '$amount += $policyAmount;',
		'replacement' => '$amount = $policyAmount;',
		'runs' => 'tests/Unit/Service/SpecialEntitlementResolverTest.php',
	],
	// --- SpecialEntitlementService ---
	[
		'file' => 'lib/Service/SpecialEntitlementService.php',
		'name' => 'reservation removed from availability formula',
		'needle' => "\$available = \$resolution['computedAmount'] + \$ledgerBalance - \$reserved;",
		'replacement' => "\$available = \$resolution['computedAmount'] + \$ledgerBalance;",
		'runs' => 'tests/Unit/Service/SpecialEntitlementServiceTest.php',
	],
	[
		'file' => 'lib/Service/SpecialEntitlementService.php',
		'name' => 'disabled entitlement type stays creatable',
		'needle' => 'if ($type === null || !$type->getActive()) {',
		'replacement' => 'if ($type === null) {',
		'runs' => 'tests/Unit/Service/SpecialEntitlementServiceTest.php',
	],
	// --- SpecialEntitlementAdminService ---
	[
		'file' => 'lib/Service/SpecialEntitlementAdminService.php',
		'name' => 'form-encoded "false" parsed as truthy',
		'needle' => "return \$value === true || \$value === 1 || \$value === '1' || \$value === 'true' || \$value === 'on';",
		'replacement' => 'return (bool)$value;',
		'runs' => 'tests/Unit/Service/SpecialEntitlementAdminServiceTest.php',
	],
	[
		'file' => 'lib/Service/SpecialEntitlementAdminService.php',
		'name' => 'stale/unknown revision falls back to current data',
		'needle' => 'if ((int)$revision !== $current) {',
		'replacement' => 'if (false) {',
		'runs' => 'tests/Unit/Service/SpecialEntitlementAdminServiceTest.php',
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
$baselineDone = [];

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
