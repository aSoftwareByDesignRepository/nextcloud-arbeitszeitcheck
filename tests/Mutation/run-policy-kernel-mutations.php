<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$test = $root . '/tests/Unit/Policy/PolicyKernelTest.php';
$phpunit = $root . '/vendor/bin/phpunit';
$mutants = [
	[
		$root . '/lib/Policy/PolicyScope.php',
		"\t\t\tself::USER => 4,",
		"\t\t\tself::USER => 0,",
		'user scope precedence removed',
	],
	[
		$root . '/lib/Policy/RevisionToken.php',
		"preg_match('/^[1-9]\\d*$/D', \$value) !== 1",
		"preg_match('/^[0-9.]+$/D', \$value) !== 1",
		'strict revision parser weakened',
	],
	[
		$root . '/lib/Policy/ImmutableTimeAccountContribution.php',
		"if (\$sourceType !== 'time_entry' && \$actualMinutes !== 0) {",
		"if (false) {",
		'actual-minute ownership guard removed',
	],
];

$run = static function () use ($phpunit, $test): int {
	exec(escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 ' . escapeshellarg($phpunit) . ' ' . escapeshellarg($test) . ' >/dev/null 2>&1', $output, $code);
	return $code;
};

if ($run() !== 0) {
	fwrite(STDERR, "Policy kernel baseline suite failed.\n");
	exit(1);
}

$failures = 0;
foreach ($mutants as [$file, $anchor, $replacement, $name]) {
	$original = file_get_contents($file);
	if (!is_string($original) || !str_contains($original, $anchor)) {
		fwrite(STDERR, "Mutation anchor missing: {$name}\n");
		$failures++;
		continue;
	}
	file_put_contents($file, str_replace($anchor, $replacement, $original));
	$code = $run();
	file_put_contents($file, $original);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$name}\n");
		$failures++;
	} else {
		fwrite(STDOUT, "KILLED: {$name}\n");
	}
}
exit($failures === 0 ? 0 : 1);
