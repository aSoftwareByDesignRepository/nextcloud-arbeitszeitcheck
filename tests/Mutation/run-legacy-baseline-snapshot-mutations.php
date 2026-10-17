<?php

declare(strict_types=1);

$appDir = dirname(__DIR__, 2);
$sourcePath = $appDir . '/tests/Support/LegacyBaselineSnapshot.php';
$original = file_get_contents($sourcePath);
if ($original === false) {
	fwrite(STDERR, "Cannot read source.\n");
	exit(1);
}

$mutations = [
	'variant guard removed' => [
		'if ($variant === \'\') {' => 'if (false) {',
	],
	'variant comparison removed' => [
		' || ($expected[\'variant\'] ?? null) !== ($actual[\'variant\'] ?? null)' => '',
	],
	'result-count guard removed' => [
		'if (count($results) !== count($endpoints)) {' => 'if (false) {',
	],
	'failed-probe guard removed' => [
		'if ($result[\'status\'] !== 200) {' => 'if (false) {',
	],
	'latency metrics guard removed' => [
		'if (!isset($result[\'metrics\']) || !is_int($result[\'metrics\'][\'queryCount\'] ?? null) || !is_numeric($result[\'metrics\'][\'durationMs\'] ?? null)) {' => 'if (false) {',
	],
	'exclusion paths ignored' => [
		'\'payload\' => self::removeExcludedPaths($result[\'payload\'], $manifest[\'comparisons\'][\'exclude\']),' => '\'payload\' => $result[\'payload\'],',
	],
	'actual-category union removed' => [
		'array_unique(array_merge(array_keys($expected[\'payloads\'] ?? []), array_keys($actual[\'payloads\'] ?? [])))' => 'array_keys($expected[\'payloads\'] ?? [])',
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
	$command = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 ' . escapeshellarg($appDir . '/vendor/bin/phpunit') . ' ' . escapeshellarg($appDir . '/tests/Unit/Support/LegacyBaselineSnapshotTest.php') . ' 2>&1';
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
