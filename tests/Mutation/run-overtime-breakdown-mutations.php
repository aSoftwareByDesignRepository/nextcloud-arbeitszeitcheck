<?php

declare(strict_types=1);

/**
 * Mutation contracts for the overtime absence-credit drill-down
 * (explainability endpoint + per-absence attribution, 2026-11).
 *
 * Covers: PaidAbsencePlannedHoursCreditService::creditBreakdownForRange
 * (credit clamp, overlap attribution, fully-worked classification,
 * id-keyed attribution), OvertimeBreakdownController (authZ gate,
 * future clamp, span cap, inverted range).
 *
 * Run:
 *   docker exec -u www-data nextcloud-app php -d opcache.enable_cli=0 \
 *     /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-overtime-breakdown-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';

$service = $appRoot . '/lib/Service/PaidAbsencePlannedHoursCreditService.php';
$controller = $appRoot . '/lib/Controller/OvertimeBreakdownController.php';
$overtime = $appRoot . '/lib/Service/OvertimeService.php';
foreach ([$service, $controller, $overtime] as $t) {
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

$serviceUnit = $appRoot . '/tests/Unit/Service/PaidAbsenceCreditBreakdownTest.php';
$integration = $appRoot . '/tests/Integration/OvertimeBreakdownIntegrationTest.php';
$controllerUnit = $appRoot . '/tests/Unit/Controller/OvertimeBreakdownControllerTest.php';

$mutations = [
	[
		'label' => 'credit_negative_clamp_dropped',
		'file' => $service,
		'from' => '$credit = max(0.0, min($row[\'target\'], $row[\'planned\'] - $worked));',
		'to' => '$credit = min($row[\'target\'], $row[\'planned\'] - $worked);',
		'test' => $serviceUnit,
	],
	[
		'label' => 'days_credited_threshold_relaxed',
		'file' => $service,
		'from' => 'if ($credit > 0.0001) {',
		'to' => 'if ($credit > -0.0001) {',
		'test' => $serviceUnit,
	],
	[
		'label' => 'fully_worked_threshold_tightened',
		'file' => $service,
		'from' => 'if ($itemTotal <= 0.0001) {',
		'to' => 'if ($itemTotal < -0.0001) {',
		'test' => $serviceUnit,
	],
	[
		'label' => 'overlap_attribution_dropped',
		'file' => $service,
		'from' => '$item[\'overlap_days\'] = count($cand[\'produced\']) - count($won);',
		'to' => '$item[\'overlap_days\'] = count($cand[\'produced\']);',
		'test' => $serviceUnit,
	],
	[
		'label' => 'unplanned_span_off_by_one',
		'file' => $service,
		'from' => 'return $dayEnd->diff($dayStart)->days + 1;',
		'to' => 'return $dayEnd->diff($dayStart)->days;',
		'test' => $serviceUnit,
	],
	[
		'label' => 'merge_tie_flips_winner',
		'file' => $service,
		'from' => 'if (!isset($targetByDay[$key]) || $dayPlan[0] > $targetByDay[$key][\'target\']) {',
		'to' => 'if (!isset($targetByDay[$key]) || $dayPlan[0] >= $targetByDay[$key][\'target\']) {',
		'test' => $serviceUnit,
	],
	[
		'label' => 'reduced_day_count_zeroed',
		'file' => $service,
		'from' => '$result[\'days_reduced\'] = count($reduceWin);',
		'to' => '$result[\'days_reduced\'] = 0;',
		'test' => $serviceUnit,
	],
	[
		'label' => 'approved_status_check_flipped',
		'file' => $service,
		'from' => 'if ($absence->getStatus() !== Absence::STATUS_APPROVED) {',
		'to' => 'if ($absence->getStatus() !== Absence::STATUS_PENDING) {',
		'test' => $serviceUnit,
	],
	[
		'label' => 'authz_gate_dropped',
		'file' => $controller,
		'from' => 'if (!$this->oversightScope->canOverseeUser($actor, $target)) {',
		'to' => 'if (false) {',
		'test' => $controllerUnit,
	],
	[
		'label' => 'future_clamp_dropped',
		'file' => $controller,
		'from' => "if (\$endDay > \$now) {\n\t\t\t\t\$endDay = clone \$now;\n\t\t\t}",
		'to' => '',
		'test' => $controllerUnit,
	],
	[
		'label' => 'span_cap_off_by_one',
		'file' => $controller,
		'from' => 'if ($endDay->diff($startDay)->days > self::MAX_RANGE_DAYS) {',
		'to' => 'if ($endDay->diff($startDay)->days >= self::MAX_RANGE_DAYS) {',
		'test' => $controllerUnit,
	],
	[
		'label' => 'inverted_custom_range_accepted',
		'file' => $controller,
		'from' => 'if ($startDay === null || $endDay === null || $endDay < $startDay) {',
		'to' => 'if ($startDay === null || $endDay === null) {',
		'test' => $controllerUnit,
	],
	[
		'label' => 'worked_by_day_end_boundary_unbounded',
		'file' => $overtime,
		'from' => '$to->setTime(23, 59, 59);',
		'to' => '$to->setTime(0, 0, 0);',
		'test' => $integration,
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
