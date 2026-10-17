<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$target = $root . '/tests/Support/LegacyUpgradeMatrix.php';
$test = $root . '/tests/Unit/Support/LegacyUpgradeMatrixTest.php';
$phpunit = $root . '/vendor/bin/phpunit';
$original = file_get_contents($target);
if ($original === false || !is_file($phpunit)) {
	fwrite(STDERR, "Legacy matrix mutation prerequisites missing.\n");
	exit(1);
}

$run = static function () use ($phpunit, $test): int {
	$command = escapeshellarg(PHP_BINARY)
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' ' . escapeshellarg($test)
		. ' > /dev/null 2>&1';
	exec($command, $output, $code);
	return $code;
};

if ($run() !== 0) {
	fwrite(STDERR, "Legacy matrix baseline suite failed.\n");
	exit(1);
}

$mutants = [
	'database parity disabled' => ["\t\tif ((\$matrix['databases'] ?? null) !== ['mysql', 'pgsql']) {", "\t\tif (false) {"],
	'duplicate rejection disabled' => ["\t\tif (count(\$tags) !== count(array_unique(\$tags, SORT_STRING))) {", "\t\tif (false) {"],
	'semantic ordering disabled' => ["\t\tif (\$sorted !== \$tags) {", "\t\tif (false) {"],
];

$failures = 0;
foreach ($mutants as $name => [$anchor, $replacement]) {
	if (!str_contains($original, $anchor)) {
		fwrite(STDERR, "Mutation anchor missing: {$name}\n");
		$failures++;
		continue;
	}
	file_put_contents($target, str_replace($anchor, $replacement, $original));
	$code = $run();
	file_put_contents($target, $original);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$name}\n");
		$failures++;
	} else {
		fwrite(STDOUT, "KILLED: {$name}\n");
	}
}

file_put_contents($target, $original);
exit($failures === 0 ? 0 : 1);
