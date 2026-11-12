#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: surgical source mutants in the manager trip queue
 * contract must be KILLED by the unit suite. A surviving mutant proves a
 * coverage hole.
 *
 * Targets:
 *  - BusinessTripManagerController: manager scope filter on the queue
 *    index (fail-closed membership), employee display-name resolution
 *    with deleted-user fallback, and the submitted/waiting timestamp the
 *    review table renders.
 *
 * Serial and self-restoring — never run two copies in parallel on a
 * bind-mounted checkout.
 *
 * Run (container): PHPUNIT_BIN=/tmp/phpunit.phar \
 *   php -d opcache.enable_cli=0 tests/Mutation/run-manager-trip-queue-mutations.php
 */

$root = dirname(__DIR__, 2);
$phpunit = getenv('PHPUNIT_BIN') ?: $root . '/vendor/bin/phpunit';
if (!is_file($phpunit) && is_file('/tmp/phpunit.phar')) {
	$phpunit = '/tmp/phpunit.phar';
}

$testFile = 'tests/Unit/Controller/BusinessTripManagerControllerTest.php';

/**
 * @var list<array{file:string, name:string, needle:string, replacement:string, runs:string}>
 */
$mutants = [
	[
		'file' => 'lib/Controller/BusinessTripManagerController.php',
		'name' => 'queue scope filter leaks out-of-team submitted trips',
		'needle' => 'if ($members === null || isset($members[(string)$trip->getUserId()])) {',
		'replacement' => 'if ($members === null || isset($members[(string)$trip->getUserId()]) || true) {',
		'runs' => $testFile,
	],
	[
		'file' => 'lib/Controller/BusinessTripManagerController.php',
		'name' => 'employee column degrades to raw uid, display name never resolved',
		'needle' => "'displayName' => \$this->displayName((string)\$trip->getUserId()),",
		'replacement' => "'displayName' => (string)\$trip->getUserId(),",
		'runs' => $testFile,
	],
	[
		'file' => 'lib/Controller/BusinessTripManagerController.php',
		'name' => 'submitted/waiting timestamp silently dropped from the wire',
		'needle' => "'updatedAt' => \$trip->getUpdatedAt()?->format('c'),",
		'replacement' => "'updatedAt' => null,",
		'runs' => $testFile,
	],
	[
		'file' => 'lib/Controller/BusinessTripManagerController.php',
		'name' => 'uid fallback always wins — real display names never surface',
		'needle' => "\$this->displayNames[\$uid] = \$name !== '' ? \$name : \$uid;",
		'replacement' => "\$this->displayNames[\$uid] = \$uid;",
		'runs' => $testFile,
	],
	[
		'file' => 'lib/Controller/BusinessTripManagerController.php',
		'name' => 'display-name resolution skipped entirely (memo never filled)',
		'needle' => "if (!array_key_exists(\$uid, \$this->displayNames)) {",
		'replacement' => 'if (false) {',
		'runs' => $testFile,
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
	// Judge by the printed verdict, not the exit code: PHPUnit 11 also
	// exits nonzero when only a runner warning fired (e.g. no coverage
	// driver in a phar-only container) while every test still passed.
	if (str_contains($joined, 'FAILURES!') || str_contains($joined, 'ERRORS!')) {
		return 1;
	}
	if (str_contains($joined, 'OK (') || str_contains($joined, 'OK, but there were issues!')) {
		return 0;
	}
	// Bootstrap failures can exit 0 WITHOUT running tests — never let
	// silence count as "killed".
	fwrite(STDERR, "FAIL: spawned phpunit produced no test verdict — harness broken.\n{$joined}\n");
	exit(2);
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
