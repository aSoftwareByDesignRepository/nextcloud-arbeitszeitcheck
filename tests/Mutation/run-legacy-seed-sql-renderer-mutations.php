<?php

declare(strict_types=1);

$appDir = dirname(__DIR__, 2);
$sourcePath = $appDir . '/tests/Support/LegacySeedSqlRenderer.php';
$original = file_get_contents($sourcePath);
if ($original === false) {
	fwrite(STDERR, "Cannot read source.\n");
	exit(1);
}

$mutations = [
	'unknown columns silently dropped' => [
		"if (\$unknown !== []) {\n\t\t\t\tthrow new \\RuntimeException('Compiled seed operation ' . \$operation['key'] . ' targets unknown columns on ' . \$target . ': ' . implode(',', \$unknown));\n\t\t\t}" => '',
	],
	'unresolved references default to zero' => [
		"\$references[\$ref] ?? throw new \\RuntimeException('Compiled seed reference cannot be resolved: ' . \$ref)" => "\$references[\$ref] ?? 0",
	],
	'required columns silently defaulted' => [
		"throw new \\RuntimeException('Compiled seed operation ' . \$operation['key'] . ' omits required column ' . \$target . '.' . \$column);" => 'continue;',
	],
	'postgres sequence reset omitted' => [
		"if (\$database === 'pgsql') {" => "if (\$database === 'never') {",
	],
	'legacy UTC storage ignored' => [
		"\$utcStorage = (\$compiled['capabilities']['localDatetimeStorage'] ?? true) !== true;" => "\$utcStorage = false;",
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
	$command = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 ' . escapeshellarg($appDir . '/vendor/bin/phpunit') . ' ' . escapeshellarg($appDir . '/tests/Unit/Support/LegacySeedSqlRendererTest.php') . ' 2>&1';
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
