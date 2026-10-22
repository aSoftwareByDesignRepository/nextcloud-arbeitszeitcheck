#!/usr/bin/env php
<?php
/**
 * Mutation smoke: AdminBatchUserIds must reject oversize / empty batches.
 * Exit 0 only if baseline passes and a sabotaged MAX check would fail tests.
 */
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$phpunit = $root . '/vendor/bin/phpunit';
$config = $root . '/phpunit.xml';
$test = $root . '/tests/Unit/Support/AdminBatchUserIdsTest.php';

function runFilter(string $phpunit, string $config, string $test): int {
	$cmd = escapeshellarg($phpunit) . ' -c ' . escapeshellarg($config)
		. ' ' . escapeshellarg($test) . ' 2>&1';
	passthru($cmd, $code);
	return $code;
}

if (!is_file($phpunit)) {
	fwrite(STDERR, "phpunit missing\n");
	exit(1);
}

echo "== baseline AdminBatchUserIds ==\n";
if (runFilter($phpunit, $config, $test) !== 0) {
	fwrite(STDERR, "Baseline must pass\n");
	exit(1);
}

$path = $root . '/lib/Support/AdminBatchUserIds.php';
$backup = file_get_contents($path);
if ($backup === false) {
	fwrite(STDERR, "Cannot read AdminBatchUserIds\n");
	exit(1);
}

// Mutate: remove oversize rejection
$mutated = str_replace(
	"if (count(\$userIds) > \$max) {\n\t\t\treturn [\n\t\t\t\t'ok' => false,\n\t\t\t\t'error' => 'batch_too_large',\n\t\t\t\t'httpStatus' => 400,\n\t\t\t];\n\t\t}",
	"if (false) {\n\t\t\treturn [\n\t\t\t\t'ok' => false,\n\t\t\t\t'error' => 'batch_too_large',\n\t\t\t\t'httpStatus' => 400,\n\t\t\t];\n\t\t}",
	$backup
);
if ($mutated === $backup) {
	fwrite(STDERR, "Mutation pattern not applied\n");
	exit(1);
}
file_put_contents($path, $mutated);
echo "== mutated (expect FAIL) ==\n";
$mutCode = runFilter($phpunit, $config, $test);
file_put_contents($path, $backup);

if ($mutCode === 0) {
	fwrite(STDERR, "Mutation survived — tests too weak\n");
	exit(1);
}

echo "Mutation killed. OK.\n";
exit(0);
