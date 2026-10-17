<?php

declare(strict_types=1);

$appDir = dirname(__DIR__, 2);
$sourcePath = $appDir . '/tests/Support/LegacyUpgradeExecutionPlan.php';
$original = file_get_contents($sourcePath);
if ($original === false) {
	fwrite(STDERR, "Cannot read source.\n");
	exit(1);
}

$mutations = [
	'target pinned to source tag' => [
		'\'targetRef\' => \'WORKTREE\',' => '\'targetRef\' => \'SOURCE_TAG\',',
	],
	'parallel unsafe execution enabled' => [
		'\'maxParallel\' => 1,' => '\'maxParallel\' => 2,',
	],
	'backup restore evidence skipped' => [
		'\'restore_upgrade_backup\',' => '',
	],
	'vacation variants omitted' => [
		'\'captureVariants\' => $variants,' => '\'captureVariants\' => [],',
	],
	'schema family omitted' => [
		'\'schemaFamily\' => $family[\'key\'],' => '\'schemaFamily\' => \'\',',
	],
	'server image omitted' => [
		'\'serverImage\' => $runtime[\'serverImage\'],' => '\'serverImage\' => \'\',',
	],
	'compiled seed hash omitted' => [
		'\'seedHash\' => $compiledSeed[\'hash\'],' => '\'seedHash\' => \'\',',
	],
	'seed-plan hash removed' => [
		'\'seedPlanHash\' => LegacyBaselineCanonicalizer::hash($seedPlan),' => '\'seedPlanHash\' => \'\',',
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
	$command = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 ' . escapeshellarg($appDir . '/vendor/bin/phpunit') . ' ' . escapeshellarg($appDir . '/tests/Unit/Support/LegacyUpgradeExecutionPlanTest.php') . ' 2>&1';
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
