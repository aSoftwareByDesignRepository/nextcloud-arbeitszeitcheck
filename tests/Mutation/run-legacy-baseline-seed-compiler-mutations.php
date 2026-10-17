<?php

declare(strict_types=1);

$appDir = dirname(__DIR__, 2);
$sourcePath = $appDir . '/tests/Support/LegacyBaselineSeedCompiler.php';
$original = file_get_contents($sourcePath);
if ($original === false) {
	fwrite(STDERR, "Cannot read source.\n");
	exit(1);
}

$mutations = [
	'absence type translation bypassed' => [
		"foreach (\$scenario['absences'] as \$absence) {\n\t\t\t\$type = self::translate(\$plan, 'absences.type', \$absence['type']);\n\t\t\tif (!self::absenceSupported(\$absence, \$type, \$capabilities)) {" => "foreach (\$scenario['absences'] as \$absence) {\n\t\t\t\$type = \$absence['type'];\n\t\t\tif (!self::absenceSupported(\$absence, \$type, \$capabilities)) {",
	],
	'final reopen revision omitted' => [
		'for ($version = 1; $version <= $month[\'revision\']; $version++) {' => 'for ($version = 1; $version < $month[\'revision\']; $version++) {',
	],
	'ghost membership rows omitted' => [
		"foreach (\$scenario['users'] as \$user) {\n\t\t\tforeach (array_merge(\$user['teams'] ?? [], \$user['staleTeamRows'] ?? []) as \$team) {\n\t\t\t\t\$operations[] = self::op('users', 'at_team_members'" => "foreach (\$scenario['users'] as \$user) {\n\t\t\tforeach (array_merge(\$user['teams'] ?? [], []) as \$team) {\n\t\t\t\t\$operations[] = self::op('users', 'at_team_members'",
	],
	'legacy idempotency hash invented' => [
		'$data[\'payload_hash\'] = $record[\'payloadHash\'];' => '$data[\'payload_hash\'] = \'invented\';',
	],
	'work-location capability ignored' => [
		'if (($capabilities[\'workLocation\'] ?? false) === true) {' => 'if (true) {',
	],
	'plan coverage check removed' => [
		'self::assertPlanCoverage($operations, $plan, $capabilities);' => '',
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
	$command = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 ' . escapeshellarg($appDir . '/vendor/bin/phpunit') . ' ' . escapeshellarg($appDir . '/tests/Unit/Support/LegacyBaselineSeedCompilerTest.php') . ' 2>&1';
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
