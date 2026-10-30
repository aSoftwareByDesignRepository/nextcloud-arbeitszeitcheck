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
		'from' => "if (\$userId === '' || (!\$this->isEnabled() && !\$this->isLeaveEffectsEnabled())) {",
		'to' => "if (\$userId === '') {",
	],
	[
		// The effect gate lives in AbsenceTypeCatalog::sollEffect — mutating the
		// call site proves the matrix is what stops unpaid/comp types from
		// being credited into Ist.
		'label' => 'drop_soll_effect_gate',
		'from' => "if (AbsenceTypeCatalog::sollEffect(\$type) !== \$effect) {",
		'to' => "if (false && AbsenceTypeCatalog::sollEffect(\$type) !== \$effect) {",
	],
	[
		// Leave types credit under the leave-effects flag, not the sick opt-in.
		// Killing this gate must re-open the vacation-Minusstunden bug.
		'label' => 'drop_leave_credit_gate',
		'from' => "\t\tif (in_array(\$type, self::LEAVE_CREDIT_TYPES, true)) {\n\t\t\treturn \$this->isLeaveEffectsEnabled();\n\t\t}",
		'to' => "\t\tif (in_array(\$type, self::LEAVE_CREDIT_TYPES, true)) {\n\t\t\treturn true;\n\t\t}",
	],
	[
		'label' => 'drop_reduction_leave_gate',
		'from' => "if (!\$this->isLeaveEffectsEnabled() || \$userId === '') {\n\t\t\treturn \$empty;\n\t\t}",
		'to' => "if (\$userId === '') {\n\t\t\treturn \$empty;\n\t\t}",
	],
	[
		// Suspensions must reduce Soll, not credit — inverting the effect
		// comparison would pay unpaid leave and strip sick/vacation credit.
		'label' => 'invert_soll_effect_check',
		'from' => "if (AbsenceTypeCatalog::sollEffect(\$type) !== \$effect) {",
		'to' => "if (AbsenceTypeCatalog::sollEffect(\$type) === \$effect) {",
	],
	[
		// Same worked-lookup line exists in expectedSollEffectHours — anchor on
		// the credit write to keep this mutation unique to creditHoursForRange.
		'label' => 'ignore_double_count_guard',
		'from' => "\$worked = max(0.0, (float)(\$workedHoursByDate[\$key] ?? 0.0));\n\t\t\t// Top the day up to its full planned Soll, capped at the absent\n\t\t\t// fraction: a half-day absence + worked other half must net zero,\n\t\t\t// not half a day's Minusstunden. (Was: target - worked, which\n\t\t\t// undercredited every half-day with worked hours.)\n\t\t\t\$credit = max(0.0, min(\$target, \$planned - \$worked));",
		'to' => "\$worked = 0.0;\n\t\t\t// Top the day up to its full planned Soll, capped at the absent\n\t\t\t// fraction: a half-day absence + worked other half must net zero,\n\t\t\t// not half a day's Minusstunden. (Was: target - worked, which\n\t\t\t// undercredited every half-day with worked hours.)\n\t\t\t\$credit = max(0.0, min(\$target, \$planned - \$worked));",
	],
	[
		// The half-day fix: credit must top up to full planned Soll capped at
		// the absent fraction — reverting to target - worked undercredits
		// every half-day with worked hours (the vacation-Minusstunden class).
		'label' => 'halfday_topup_reverted',
		'from' => "\$credit = max(0.0, min(\$target, \$planned - \$worked));\n\t\t\tif (\$credit > 0.0001) {\n\t\t\t\t\$bestCreditByDay[\$key] = \$credit;",
		'to' => "\$credit = max(0.0, \$target - \$worked);\n\t\t\tif (\$credit > 0.0001) {\n\t\t\t\t\$bestCreditByDay[\$key] = \$credit;",
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
