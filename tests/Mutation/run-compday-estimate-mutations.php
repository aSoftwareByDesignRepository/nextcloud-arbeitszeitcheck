<?php

declare(strict_types=1);

/**
 * Mutation contracts for the corrupt-`daily_hours` hardening
 * (customer report 2026-11-19: +133 h → "≈ 1 Abbummeltag" via daily=78).
 *
 * Covers: WorkingTimeModel::validate() daily bound,
 * OvertimeService::getAverageDailyPlannedHours bound+fallback+rotation path,
 * CompDaysEstimateService::estimate fail-closed divisor,
 * PaidAbsencePlannedHoursCreditService::resolveContractForDate bound.
 *
 * Run:
 *   docker exec -u www-data nextcloud-app php -d opcache.enable_cli=0 \
 *     /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-compday-estimate-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';

$targets = [
	$appRoot . '/lib/Db/WorkingTimeModel.php',
	$appRoot . '/lib/Service/OvertimeService.php',
	$appRoot . '/lib/Service/CompDaysEstimateService.php',
	$appRoot . '/lib/Service/PaidAbsencePlannedHoursCreditService.php',
	$appRoot . '/lib/Repair/HealCorruptDailyHours.php',
];
foreach ($targets as $t) {
	if (!is_file($t)) {
		fwrite(STDERR, "Missing target $t\n");
		exit(1);
	}
}

$runTests = static function (string $testFile) use ($appRoot, $phpunit): int {
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --colors=never ' . escapeshellarg($testFile) . ' 2>&1';
	exec($cmd, $out, $code);
	return $code;
};

$mutations = [
	[
		'label' => 'validate_daily_bound_dropped',
		'file' => $appRoot . '/lib/Db/WorkingTimeModel.php',
		'from' => "if (\$this->dailyHours > 24) {\n            \$errors['dailyHours'] = 'Daily hours cannot exceed 24';\n        }",
		'to' => '',
		'test' => $appRoot . '/tests/Unit/Db/WorkingTimeModelTest.php',
	],
	[
		'label' => 'estimate_divisor_bound_dropped',
		'file' => $appRoot . '/lib/Service/CompDaysEstimateService.php',
		'from' => 'if ($basis === \'on_demand\' || !is_finite($daily) || $daily <= 0.0 || $daily > 24.0) {',
		'to' => 'if ($basis === \'on_demand\' || $daily <= 0.0) {',
		'test' => $appRoot . '/tests/Unit/Service/CompDaysEstimateServiceTest.php',
	],
	[
		'label' => 'avg_daily_bound_dropped',
		'file' => $appRoot . '/lib/Service/OvertimeService.php',
		'from' => 'if (!is_finite($dailyHours) || $dailyHours <= 0.0 || $dailyHours > 24.0) {',
		'to' => 'if ($dailyHours <= 0.0) {',
		'test' => $appRoot . '/tests/Unit/Service/OvertimeServiceTest.php',
	],
	[
		'label' => 'avg_daily_weekly_fallback_divisor',
		'file' => $appRoot . '/lib/Service/OvertimeService.php',
		'from' => '$dailyHours = $weeklyHours > 0.0 ? $weeklyHours / 5.0 : 0.0;',
		'to' => '$dailyHours = $weeklyHours > 0.0 ? $weeklyHours / 7.0 : 0.0;',
		'test' => $appRoot . '/tests/Unit/Service/OvertimeServiceTest.php',
	],
	[
		'label' => 'rotation_path_dropped',
		'file' => $appRoot . '/lib/Service/OvertimeService.php',
		'from' => "if (\$this->dutyRotationSollProvider !== null && \$this->dutyRotationSollProvider->isEnabledForOrg()) {\n\t\t\t\$rotation = \$this->rotationAverageDailyPlannedHours(\$userId);\n\t\t\tif (\$rotation !== null) {\n\t\t\t\treturn \$rotation;\n\t\t\t}\n\t\t}",
		'to' => '',
		'test' => $appRoot . '/tests/Unit/Service/OvertimeServiceTest.php',
	],
	[
		'label' => 'rotation_scheduled_only_dropped',
		'file' => $appRoot . '/lib/Service/OvertimeService.php',
		'from' => "if (\$net > 0.0001) {\n\t\t\t\t\$sum += \$net;\n\t\t\t\t\$scheduledDays++;\n\t\t\t}",
		'to' => "\$sum += \$net;\n\t\t\t\$scheduledDays++;",
		'test' => $appRoot . '/tests/Unit/Service/OvertimeServiceTest.php',
	],
	[
		'label' => 'credit_daily_bound_dropped',
		'file' => $appRoot . '/lib/Service/PaidAbsencePlannedHoursCreditService.php',
		'from' => 'if (is_finite($modelDaily) && $modelDaily > 0.0 && $modelDaily <= 24.0) {',
		'to' => 'if (is_finite($modelDaily) && $modelDaily > 0.0) {',
		'test' => $appRoot . '/tests/Unit/Service/PaidAbsencePlannedHoursCreditServiceTest.php',
	],
	[
		'label' => 'credit_weekly_derivation_dropped',
		'file' => $appRoot . '/lib/Service/PaidAbsencePlannedHoursCreditService.php',
		'from' => "if (\$derived > 0.0 && \$derived <= 24.0) {\n\t\t\t\t\t\$dailyHours = \$derived;\n\t\t\t\t}",
		'to' => '',
		'test' => $appRoot . '/tests/Unit/Service/PaidAbsencePlannedHoursCreditServiceTest.php',
	],
	[
		'label' => 'heal_upper_bound_raised',
		'file' => $appRoot . '/lib/Repair/HealCorruptDailyHours.php',
		'from' => 'private const DAILY_MAX = 24.0;',
		'to' => 'private const DAILY_MAX = 48.0;',
		'test' => $appRoot . '/tests/Unit/Repair/HealCorruptDailyHoursTest.php',
	],
	[
		'label' => 'heal_fallback_changed',
		'file' => $appRoot . '/lib/Repair/HealCorruptDailyHours.php',
		'from' => 'private const DAILY_FALLBACK = 8.0;',
		'to' => 'private const DAILY_FALLBACK = 12.0;',
		'test' => $appRoot . '/tests/Unit/Repair/HealCorruptDailyHoursTest.php',
	],
	[
		'label' => 'heal_weekly_derivation_dropped',
		'file' => $appRoot . '/lib/Repair/HealCorruptDailyHours.php',
		'from' => '$derived = $weekly > 0.0 ? round($weekly / 5, 2) : 0.0;',
		'to' => '$derived = $weekly;',
		'test' => $appRoot . '/tests/Unit/Repair/HealCorruptDailyHoursTest.php',
	],
	[
		'label' => 'heal_on_demand_guard_dropped',
		'file' => $appRoot . '/lib/Repair/HealCorruptDailyHours.php',
		'from' => ". ' OR (`daily_hours` <= 0 AND `type` <> \\'on_demand\\')'",
		'to' => ". ' OR `daily_hours` <= 0'",
		'test' => $appRoot . '/tests/Unit/Repair/HealCorruptDailyHoursTest.php',
	],
];

echo "== baselines ==\n";
$testFiles = array_unique(array_column($mutations, 'test'));
foreach ($testFiles as $tf) {
	if ($runTests($tf) !== 0) {
		fwrite(STDERR, "Baseline $tf must pass before mutation run\n");
		exit(1);
	}
}
echo "baselines green\n\n";

$killed = 0;
$survived = [];
foreach ($mutations as $m) {
	$original = file_get_contents($m['file']);
	if ($original === false || !str_contains($original, $m['from'])) {
		fwrite(STDERR, "[{$m['label']}] anchor not found — runner drifted\n");
		$survived[] = $m['label'] . ' (anchor drift)';
		continue;
	}
	file_put_contents($m['file'], str_replace($m['from'], $m['to'], $original));
	$code = $runTests($m['test']);
	file_put_contents($m['file'], $original);

	if ($code !== 0) {
		$killed++;
		echo "[KILLED]   {$m['label']}\n";
	} else {
		$survived[] = $m['label'];
		echo "[SURVIVOR] {$m['label']}\n";
	}
}

echo "\n== result: $killed killed, " . count($survived) . " survived ==\n";
if ($survived !== []) {
	foreach ($survived as $s) {
		echo "  - $s\n";
	}
	exit(1);
}
exit(0);
