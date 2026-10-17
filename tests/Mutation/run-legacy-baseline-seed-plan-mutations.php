<?php

declare(strict_types=1);

$appDir = dirname(__DIR__, 2);
$sourcePath = $appDir . '/tests/Support/LegacyBaselineSeedPlan.php';
$original = file_get_contents($sourcePath);
if ($original === false) {
	fwrite(STDERR, "Cannot read source.\n");
	exit(1);
}

$mutations = [
	'unknown-table guard removed' => [
		'if (!in_array($target, ArbeitszeitCheckTableCatalog::TABLES, true) && !in_array($target, $plan[\'externalTargets\'] ?? [], true)) {' => 'if (false) {',
	],
	'duplicate section/order guard removed' => [
		'if (isset($covered[$name]) || isset($orders[$order])) {' => 'if (false) {',
	],
	'translation contract removed' => [
		'if (!is_array($translations) || array_diff($actual, array_keys($translations)) !== [] || array_diff(array_keys($translations), $actual) !== []) {' => 'if (false) {',
	],
	'persisted-value guard removed' => [
		'if (!in_array($value, $allowed, true)) {' => 'if (false) {',
	],
	'reopen evidence guard removed' => [
		'if (array_diff($required, $targets) !== [] || ($plan[\'stateEvidence\'][\'months.reopened\'][\'openStatus\'] ?? null) !== \'open\' || ($plan[\'stateEvidence\'][\'months.reopened\'][\'finalizedStatus\'] ?? null) !== \'finalized\') {' => 'if (false) {',
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
	$command = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 ' . escapeshellarg($appDir . '/vendor/bin/phpunit') . ' ' . escapeshellarg($appDir . '/tests/Unit/Support/LegacyBaselineSeedPlanTest.php') . ' 2>&1';
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
