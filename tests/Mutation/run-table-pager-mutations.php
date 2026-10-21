<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the shared table footer.
 *
 * Usage (Docker, as www-data, --no-configuration so PHPUnit does not write a results file):
 *   php -d opcache.enable_cli=0 tests/Mutation/run-table-pager-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = dirname($appRoot) . '/budgetcheck/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	fwrite(STDERR, "phpunit not found\n");
	exit(1);
}

function restore_file(string $path, string $backup): void {
	if (is_file($backup)) {
		rename($backup, $path);
	}
}

/**
 * @param array{file:string,from:string,to:string} $mutation
 */
function apply_and_expect_fail(string $appRoot, string $phpunit, string $name, array $mutation): bool {
	$path = $appRoot . '/' . $mutation['file'];
	$backup = $path . '.mutation-bak';
	$original = file_get_contents($path);
	if ($original === false || !str_contains($original, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		return false;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($mutation['from'], $mutation['to'], $original);
	if ($mutated === $original) {
		restore_file($path, $backup);
		fwrite(STDERR, "Mutation replace had no effect for {$name}\n");
		return false;
	}
	file_put_contents($path, $mutated);
	echo "\n== mutation: {$name} ==\n";
	$cmd = escapeshellarg(PHP_BINARY)
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' --bootstrap ' . escapeshellarg($appRoot . '/tests/bootstrap.php')
		. ' --no-configuration '
		. escapeshellarg($appRoot . '/tests/Unit/Support/TablePagerTest.php');
	passthru($cmd, $code);
	restore_file($path, $backup);
	if ($code === 0) {
		echo "MUTATION SURVIVED: {$name}\n";
		return false;
	}
	echo "killed {$name}\n";
	return true;
}

$mutations = [
	'page one is written into the query' => [
		'file' => 'lib/Support/TablePager.php',
		'from' => 'if ($page > 1) {',
		'to' => 'if ($page > 0) {',
	],
	'default size is written into the query' => [
		'file' => 'lib/Support/TablePager.php',
		'from' => 'if ($perPage !== self::DEFAULT_SIZE) {',
		'to' => 'if (true) {',
	],
	'unknown filter keys are copied' => [
		'file' => 'lib/Support/TablePager.php',
		'from' => 'foreach ($allowKeys as $key) {',
		'to' => 'foreach (array_keys($filters) as $key) {',
	],
	'a capitalised page key is accepted' => [
		'file' => 'lib/Support/TablePager.php',
		'from' => "if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,40}$/', \$raw) === 1) {",
		'to' => 'if ($raw !== \'\') {',
	],
	'page 99 of 8 is not clamped' => [
		'file' => 'lib/Support/TablePager.php',
		'from' => 'return min($page, $pageCount);',
		'to' => 'return $page;',
	],
	'fifteen is an allowed page size' => [
		'file' => 'lib/Support/TablePager.php',
		'from' => 'return self::DEFAULT_SIZE;',
		'to' => 'return 15;',
	],
];

$killed = 0;
foreach ($mutations as $name => $mutation) {
	if (apply_and_expect_fail($appRoot, $phpunit, $name, $mutation)) {
		$killed++;
	}
}

echo "\n{$killed}/" . count($mutations) . " mutations killed\n";
exit($killed === count($mutations) ? 0 : 1);
