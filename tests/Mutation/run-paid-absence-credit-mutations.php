<?php

declare(strict_types=1);

/**
 * Mutation contracts for paid-absence planned-hours credit gate (#40).
 *
 * Run:
 *   docker compose exec -u www-data nextcloud php -d opcache.enable_cli=0 \
 *     /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-paid-absence-credit-mutations.php
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$servicePath = $appRoot . '/lib/Service/PaidAbsencePlannedHoursCreditService.php';
$phpunit = $appRoot . '/vendor/bin/phpunit';
$testFile = $appRoot . '/tests/Unit/Service/PaidAbsencePlannedHoursCreditServiceTest.php';

if (!is_file($servicePath) || !is_file($phpunit) || !is_file($testFile)) {
	fwrite(STDERR, "Missing service, phpunit, or test file\n");
	exit(1);
}

$original = file_get_contents($servicePath);
if ($original === false) {
	fwrite(STDERR, "Cannot read service\n");
	exit(1);
}

$runTests = static function () use ($phpunit, $testFile): int {
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
		. ' --colors=never ' . escapeshellarg($testFile)
		. ' 2>&1';
	exec($cmd, $out, $code);
	return $code;
};

echo "== baseline ==\n";
$baseline = $runTests();
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline PaidAbsencePlannedHoursCreditServiceTest must pass before mutation run\n");
	exit(1);
}

$mutations = [
	[
		'label' => 'drop_enabled_gate',
		'from' => "if (!\$this->isEnabled() || \$userId === '') {\n\t\t\treturn ['hours' => 0.0, 'days_credited' => 0];\n\t\t}",
		'to' => "if (\$userId === '') {\n\t\t\treturn ['hours' => 0.0, 'days_credited' => 0];\n\t\t}",
	],
	[
		'label' => 'credit_unpaid_leave',
		'from' => "public const CREDITABLE_TYPES = [\n\t\tAbsence::TYPE_SICK_LEAVE,\n\t];",
		'to' => "public const CREDITABLE_TYPES = [\n\t\tAbsence::TYPE_SICK_LEAVE,\n\t\tAbsence::TYPE_UNPAID_LEAVE,\n\t];",
	],
	[
		'label' => 'ignore_double_count_guard',
		'from' => "\$worked = max(0.0, (float)(\$workedHoursByDate[\$key] ?? 0.0));\n\t\t\t\t\$credit = max(0.0, \$target - \$worked);",
		'to' => "\$worked = 0.0;\n\t\t\t\t\$credit = max(0.0, \$target - \$worked);",
	],
];

$failures = 0;
$backup = $servicePath . '.mutation-bak';
if (!copy($servicePath, $backup)) {
	fwrite(STDERR, "Cannot create mutation backup\n");
	exit(1);
}

foreach ($mutations as $mutation) {
	$label = $mutation['label'];
	$mutated = str_replace($mutation['from'], $mutation['to'], $original, $count);
	if ($count !== 1) {
		fwrite(STDERR, "Mutation $label: expected 1 replacement, got $count\n");
		$failures++;
		continue;
	}
	file_put_contents($servicePath, $mutated);
	echo "\n== mutation: $label ==\n";
	$code = $runTests();
	if ($code === 0) {
		fwrite(STDERR, "SURVIVOR: mutation $label was not killed by tests\n");
		$failures++;
	} else {
		echo "killed\n";
	}
	file_put_contents($servicePath, $original);
}

@unlink($backup);

if ($failures > 0) {
	fwrite(STDERR, "\n$failures mutation contract failure(s)\n");
	exit(1);
}

fwrite(STDOUT, "\nAll paid-absence credit mutation contracts passed.\n");
exit(0);
