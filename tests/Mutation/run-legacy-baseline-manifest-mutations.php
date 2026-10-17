<?php

declare(strict_types=1);

$appDir = dirname(__DIR__, 2);
$sourcePath = $appDir . '/tests/Support/LegacyBaselineManifest.php';
$original = file_get_contents($sourcePath);
if ($original === false) {
	fwrite(STDERR, "Cannot read source.\n");
	exit(1);
}

$mutations = [
	'category-order guard removed' => [
		'if (!is_array($categories) || $categories !== self::REQUIRED_CATEGORIES) {' => 'if (!is_array($categories)) {',
	],
	'duplicate probe guard removed' => [
		'if (isset($seen[$key])) {' => 'if (false) {',
	],
	'missing-probe guard removed' => [
		'if (array_diff(self::REQUIRED_CATEGORIES, array_keys($covered)) !== []) {' => 'if (false) {',
	],
	'required hash guard removed' => [
		'if ($missingHashes !== []) {' => 'if (false) {',
	],
];

$results = [];
foreach ($mutations as $name => $replacement) {
	$from = array_key_first($replacement);
	$to = $replacement[$from];
	$mutated = str_replace($from, $to, $original, $count);
	if ($count !== 1) {
		fwrite(STDERR, "Mutation {$name} did not apply exactly once.\n");
		file_put_contents($sourcePath, $original);
		exit(1);
	}
	file_put_contents($sourcePath, $mutated);
	$command = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 ' . escapeshellarg($appDir . '/vendor/bin/phpunit') . ' ' . escapeshellarg($appDir . '/tests/Unit/Support/LegacyBaselineManifestTest.php') . ' 2>&1';
	exec($command, $output, $exit);
	$results[$name] = $exit === 0 ? 'SURVIVED' : 'KILLED';
	$output = [];
	file_put_contents($sourcePath, $original);
}

foreach ($results as $name => $result) {
	echo $result . ': ' . $name . PHP_EOL;
}
if (in_array('SURVIVED', $results, true)) {
	exit(2);
}
