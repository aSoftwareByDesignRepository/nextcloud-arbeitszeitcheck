<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$target = $root . '/tests/Support/LegacyBaselineCanonicalizer.php';
$test = $root . '/tests/Unit/Support/LegacyBaselineCanonicalizerTest.php';
$phpunit = $root . '/vendor/bin/phpunit';
$original = file_get_contents($target);
if ($original === false || !is_file($phpunit)) {
	fwrite(STDERR, "Canonicalizer mutation prerequisites missing.\n");
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
	fwrite(STDERR, "Canonicalizer baseline suite failed.\n");
	exit(1);
}

$mutants = [
	'map ordering removed' => ["\t\t\tksort(\$value, SORT_STRING);", "\t\t\tksort(\$value, SORT_STRING);\n\t\t\t\$value = array_reverse(\$value, true);"],
	'list ordering erased' => ["\t\tif (!array_is_list(\$value)) {", "\t\tif (array_is_list(\$value)) {\n\t\t\tusort(\$value, static fn (mixed \$a, mixed \$b): int => strcmp(json_encode(\$a, JSON_THROW_ON_ERROR), json_encode(\$b, JSON_THROW_ON_ERROR)));\n\t\t}\n\n\t\tif (!array_is_list(\$value)) {"],
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
