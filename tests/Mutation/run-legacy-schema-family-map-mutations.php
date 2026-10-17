<?php

declare(strict_types=1);

$appDir = dirname(__DIR__, 2);
$sourcePath = $appDir . '/tests/Support/LegacySchemaFamilyMap.php';
$original = file_get_contents($sourcePath);
if ($original === false) {
	fwrite(STDERR, "Cannot read source.\n");
	exit(1);
}

$mutations = [
	'matrix coverage guard removed' => [
		'if (array_keys($covered) !== $matrix[\'sourceTags\']) {' => 'if (false) {',
	],
	'family ordering guard removed' => [
		'if ($versions !== array_values(array_unique($versions)) || $versions !== self::sorted($versions) || min($versions) !== $family[\'minMigration\'] || max($versions) !== $family[\'maxMigration\'] || $family[\'maxMigration\'] <= $previousMax) {' => 'if (false) {',
	],
	'duplicate tag guard removed' => [
		'if (!is_string($tag) || isset($covered[$tag])) {' => 'if (false) {',
	],
	'closure capability shifted late' => [
		'\'monthClosures\' => $max >= 1014,' => '\'monthClosures\' => $max >= 1020,',
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
	$command = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 ' . escapeshellarg($appDir . '/vendor/bin/phpunit') . ' ' . escapeshellarg($appDir . '/tests/Unit/Support/LegacySchemaFamilyMapTest.php') . ' 2>&1';
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
