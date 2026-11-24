<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the effective-dated assignment machinery
 * (WorkingTimeModelAssignmentResolver + UserWorkingTimeAssignmentService +
 * the segmented-soll consumers). No Infection — exact-string patches.
 *
 * Usage:
 *   docker compose exec -u www-data -T nextcloud php /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-assignment-resolution-mutations.php
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

/**
 * @param list<string> $filters
 */
function run_unit_tests(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		copy($backup, $source); unlink($backup);
	}
}

$suiteFilters = [
	'WorkingTimeModelAssignmentResolverTest',
	'RangeSegmentationTest',
	'AssignmentWriteServiceTest',
	'testLegacyFallbackUsesVacationDaysOfCoveringWindow',
];

echo "== baseline assignment-resolution unit tests ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	// ---- WorkingTimeModelAssignmentResolver ------------------------------
	[
		'name' => 'end_date_becomes_exclusive',
		'file' => 'lib/Service/WorkingTimeModelAssignmentResolver.php',
		'from' => 'if ($end !== null && $end->format(\'Y-m-d\') < $dayYmd) {',
		'to' => 'if ($end !== null && $end->format(\'Y-m-d\') <= $dayYmd) {',
		'filters' => ['WorkingTimeModelAssignmentResolverTest::testEndDateIsInclusive'],
	],
	[
		'name' => 'tiebreak_earliest_start_wins',
		'file' => 'lib/Service/WorkingTimeModelAssignmentResolver.php',
		'from' => '|| $startYmd > $winnerStart',
		'to' => '|| $startYmd < $winnerStart',
		'filters' => ['WorkingTimeModelAssignmentResolverTest::testOverlapTiebreakLatestStartThenHighestId'],
	],
	[
		'name' => 'tiebreak_lowest_id_wins',
		'file' => 'lib/Service/WorkingTimeModelAssignmentResolver.php',
		'from' => '($startYmd === $winnerStart && $rowId > $winnerId)',
		'to' => '($startYmd === $winnerStart && $rowId < $winnerId)',
		'filters' => ['WorkingTimeModelAssignmentResolverTest::testOverlapTiebreakLatestStartThenHighestId'],
	],
	[
		'name' => 'strict_gap_reports_degraded',
		'file' => 'lib/Service/WorkingTimeModelAssignmentResolver.php',
		'from' => 'self::BASIS_UNASSIGNED_GAP, null, false',
		'to' => 'self::BASIS_DEGRADED, null, false',
		'filters' => [
			'WorkingTimeModelAssignmentResolverTest::testStrictGapPolicyOnUncoveredDay',
			'RangeSegmentationTest::testStrictPolicyGapMonthContributesZero',
		],
	],
	[
		'name' => 'lenient_flag_dropped',
		'file' => 'lib/Service/WorkingTimeModelAssignmentResolver.php',
		'from' => '$contract->assignmentId, true,',
		'to' => '$contract->assignmentId, false,',
		'filters' => ['WorkingTimeModelAssignmentResolverTest::testLenientGapPolicyFallsBackToRowCoveringToday'],
	],
	[
		'name' => 'segment_end_off_by_one',
		'file' => 'lib/Service/WorkingTimeModelAssignmentResolver.php',
		'from' => '(clone $cursor)->modify(\'-1 day\')',
		'to' => '(clone $cursor)->modify(\'-2 days\')',
		'filters' => ['WorkingTimeModelAssignmentResolverTest::testResolveRangeSplitsAtWindowBoundary'],
	],
	[
		'name' => 'adjacent_same_contract_not_merged',
		'file' => 'lib/Service/WorkingTimeModelAssignmentResolver.php',
		'from' => 'if (!$contract->isEquivalentTo($prevContract)) {',
		'to' => 'if (true) {',
		'filters' => ['WorkingTimeModelAssignmentResolverTest::testResolveRangeMergesAdjacentSameContractRows'],
	],
	[
		'name' => 'vacation_days_dropped_from_contract',
		'file' => 'lib/Service/WorkingTimeModelAssignmentResolver.php',
		'from' => ': (float)$assignment->getVacationDaysPerYear()',
		'to' => ': 0.0',
		'filters' => ['WorkingTimeModelAssignmentResolverTest::testCoveringRowResolvesItsModel'],
	],
	// ---- UserWorkingTimeAssignmentService --------------------------------
	[
		'name' => 'auto_end_two_days_early',
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => '$autoEnd->setEndDate((clone $start)->modify(\'-1 day\'));',
		'to' => '$autoEnd->setEndDate((clone $start)->modify(\'-2 days\'));',
		'filters' => ['AssignmentWriteServiceTest::testCreateAutoEndsImmediatePredecessorAndAuditsSplit'],
	],
	[
		'name' => 'delete_allows_today_start',
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => 'if ($start === null || $start <= $today) {',
		'to' => 'if ($start === null || $start < $today) {',
		'filters' => ['AssignmentWriteServiceTest::testDeleteTodayStartIsNotDeletable'],
	],
	[
		'name' => 'update_collides_with_itself',
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => '$endYmd, (int)$fresh->getId()',
		'to' => '$endYmd, null',
		'filters' => ['AssignmentWriteServiceTest::testUpdateOwnWindowSucceeds'],
	],
	[
		'name' => 'split_audit_event_renamed',
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => '\'user_working_time_model_assignment_split\'',
		'to' => '\'user_working_time_model_updated\'',
		'filters' => ['AssignmentWriteServiceTest::testCreateAutoEndsImmediatePredecessorAndAuditsSplit'],
	],
	[
		'name' => 'single_day_window_rejected',
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => 'if ($end !== null && $end < $start) {',
		'to' => 'if ($end !== null && $end <= $start) {',
		'filters' => ['AssignmentWriteServiceTest::testCreateSingleDayWindowIsValid'],
	],
	[
		'name' => 'tail_check_becomes_gte',
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => '$containsTail = $pEnd !== null && $endYmd !== null && $pEnd->format(\'Y-m-d\') > $endYmd;',
		'to' => '$containsTail = $pEnd !== null && $endYmd !== null && $pEnd->format(\'Y-m-d\') >= $endYmd;',
		'filters' => ['AssignmentWriteServiceTest::testCreateAutoEndsPredecessorEndingOnNewEnd'],
	],
	[
		'name' => 'predecessor_accepts_equal_start',
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => 'if ($rStart !== \'\' && $rStart < $startYmd',
		'to' => 'if ($rStart !== \'\' && $rStart <= $startYmd',
		'filters' => ['AssignmentWriteServiceTest::testCreateSameStartAsExistingRowIs409'],
	],
	[
		'name' => 'same_model_resend_revalidated',
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => 'if ((int)($params[\'modelId\'] ?? 0) === (int)$row->getWorkingTimeModelId()) {',
		'to' => 'if (false) {',
		'filters' => ['AssignmentWriteServiceTest::testUpdateRowWithDeletedModelStaysEditable'],
	],
	// ---- VacationEntitlementEngine ---------------------------------------
	[
		'name' => 'vacation_days_reads_weekly_hours',
		'file' => 'lib/Service/VacationEntitlementEngine.php',
		'from' => '$assignmentDays = $this->contractAt($userId, $asOfDate)?->vacationDaysPerYear;',
		'to' => '$assignmentDays = $this->contractAt($userId, $asOfDate)?->weeklyHours;',
		'filters' => ['testLegacyFallbackUsesVacationDaysOfCoveringWindow'],
	],
	// ---- OvertimeService --------------------------------------------------
	[
		'name' => 'scalars_mirroring_period_start',
		'file' => 'lib/Service/OvertimeService.php',
		'from' => '$endContract = $this->assignmentResolver()->resolveStrict($userId, $periodEndDay);',
		'to' => '$endContract = $this->assignmentResolver()->resolveStrict($userId, $periodStartDay);',
		'filters' => ['RangeSegmentationTest::testPiaSegmentationWithoutHoliday'],
	],
	[
		'name' => 'segmented_label_never_emitted',
		'file' => 'lib/Service/OvertimeService.php',
		'from' => '$requiredHoursBasis = count($resolvedSegments) === 1',
		'to' => '$requiredHoursBasis = count($resolvedSegments) >= 1',
		'filters' => ['RangeSegmentationTest::testPiaSegmentationWithoutHoliday'],
	],
	[
		'name' => 'holiday_weight_ignored',
		'file' => 'lib/Service/OvertimeService.php',
		'from' => 'fn (\DateTime $day): float => $this->holidayCalendarService->getHolidayWeightForUser($userId, $day),',
		'to' => 'fn (\DateTime $day): float => 0.0,',
		'filters' => ['RangeSegmentationTest::testPiaSegmentationSachsenHoliday'],
	],
];

$failures = 0;
foreach ($mutations as $mutation) {
	$relative = $mutation['file'];
	$source = $appRoot . '/' . $relative;
	$backup = $source . '.mutbak';
	$filters = $mutation['filters'] ?? $suiteFilters;

	echo "== mutate: {$mutation['name']} ==\n";
	if (!is_file($source)) {
		fwrite(STDERR, "Missing source $source\n");
		$failures++;
		continue;
	}
	$contents = file_get_contents($source);
	if ($contents === false || !str_contains($contents, $mutation['from'])) {
		fwrite(STDERR, "Needle not found for {$mutation['name']}\n");
		$failures++;
		continue;
	}
	copy($source, $backup);
	file_put_contents($source, str_replace($mutation['from'], $mutation['to'], $contents));
	$code = run_unit_tests($appRoot, $phpunit, $filters);
	restore($source, $backup);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED: {$mutation['name']} (tests still green)\n");
		$failures++;
	} else {
		echo "Killed: {$mutation['name']}\n";
	}
}

echo "== post-mutation baseline ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Source left broken after mutations\n");
	exit(1);
}

if ($failures > 0) {
	fwrite(STDERR, "$failures mutation(s) survived or failed to apply\n");
	exit(1);
}

echo "All assignment-resolution mutations killed.\n";
exit(0);
