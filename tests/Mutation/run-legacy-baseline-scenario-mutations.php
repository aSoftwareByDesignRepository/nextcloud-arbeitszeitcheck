<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$target = $root . '/tests/Support/LegacyBaselineScenario.php';
$test = $root . '/tests/Unit/Support/LegacyBaselineScenarioTest.php';
$phpunit = $root . '/vendor/bin/phpunit';
$original = file_get_contents($target);
if ($original === false || !is_file($phpunit)) {
	fwrite(STDERR, "Legacy scenario mutation prerequisites missing.\n");
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
	fwrite(STDERR, "Legacy scenario baseline suite failed.\n");
	exit(1);
}

$mutants = [
	'vacation mode coverage removed' => ["if (\$modes !== ['days', 'hours']) {", "if (false) {"],
	'cancelled absence coverage removed' => ["['pending', 'approved', 'rejected', 'cancelled']", "['pending', 'approved', 'rejected']"],
	'unique-key guard disabled' => ["\t\tif (in_array(null, \$values, true) || count(\$values) !== count(array_unique(\$values, SORT_REGULAR))) {", "\t\tif (false) {"],
	'cross-reference guard disabled' => ["\t\t\tif (!isset(\$absences[\$substitution['absence'] ?? '']) || !isset(\$users[\$substitution['substitute'] ?? ''])) {", "\t\t\tif (false) {"],
	'ghost-membership guard disabled' => ["\t\tif (!self::contains(\$scenario['users'] ?? [], static fn (array \$user): bool => (\$user['deleted'] ?? false) === true && (\$user['staleTeamRows'] ?? []) !== [])) {", "\t\tif (false) {"],
	'completed-break guard disabled' => ["\t\tif (!self::contains(\$scenario['timeEntries'] ?? [], static fn (array \$entry): bool => (\$entry['status'] ?? null) === 'completed' && (\$entry['breaks'] ?? []) !== [])) {", "\t\tif (false) {"],
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
