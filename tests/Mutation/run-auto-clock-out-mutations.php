#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: surgical source mutants in the auto clock-out planned-end
 * feature (PlannedEndResolver, AutoClockOutJob, TimeTrackingService write path)
 * must be KILLED by the unit suite. A surviving mutant proves a coverage hole.
 *
 * Runs each file's test filter against the mutated source in place and
 * restores the original in a finally block. Serial and self-restoring —
 * never run two copies in parallel on a bind-mounted checkout.
 *
 * Run (container): php -d opcache.enable_cli=0 tests/Mutation/run-auto-clock-out-mutations.php
 */

$root = dirname(__DIR__, 2);
$phpunit = $root . '/vendor/bin/phpunit';

/**
 * @var list<array{file:string, test:string, filter:string, mutants:list<array{name:string, needle:string, replacement:string}>}>
 */
$targets = [
	[
		'file' => 'lib/Service/PlannedEndResolver.php',
		'test' => 'tests/Unit/Service/PlannedEndResolverTest.php',
		'filter' => 'PlannedEndResolverTest',
		'mutants' => [
			[
				'name' => 'missing-start gate dropped',
				'needle' => 'if (!$start instanceof \DateTimeInterface) {',
				'replacement' => 'if (false) {',
			],
			[
				'name' => 'no-model gate dropped',
				'needle' => 'if ($model === null) {',
				'replacement' => 'if (false) {',
			],
			[
				'name' => 'on-demand gate dropped',
				'needle' => 'if ($model->isOnDemand()) {',
				'replacement' => 'if (false) {',
			],
			[
				'name' => 'holiday gate dropped',
				'needle' => 'if ($this->holidayService->isHolidayForUser($userId, $dayStart)) {',
				'replacement' => 'if (false) {',
			],
			[
				'name' => 'approved-absence gate dropped',
				'needle' => 'if ($this->hasApprovedAbsence($userId, $dayStart, $dayEnd)) {',
				'replacement' => 'if (false) {',
			],
			[
				'name' => 'invalid-end boundary weakened (<= to <)',
				'needle' => 'if ($end->getTimestamp() <= $start->getTimestamp()) {',
				'replacement' => 'if ($end->getTimestamp() < $start->getTimestamp()) {',
			],
			[
				'name' => 'cutover boundary tightened (> to >=)',
				'needle' => 'if ($end->getTimestamp() > $cutover->getTimestamp()) {',
				'replacement' => 'if ($end->getTimestamp() >= $cutover->getTimestamp()) {',
			],
			[
				'name' => 'approved status swapped to pending',
				'needle' => '$absence->getStatus() === Absence::STATUS_APPROVED',
				'replacement' => '$absence->getStatus() === Absence::STATUS_PENDING',
			],
			[
				'name' => 'current-model fallback dropped',
				'needle' => '?? $this->userWorkingTimeModelMapper->findCurrentByUser($userId);',
				'replacement' => '?? null;',
			],
			[
				'name' => 'model cache: array_key_exists weakened to isset',
				'needle' => 'if (array_key_exists($modelId, $modelCache)) {',
				'replacement' => 'if (isset($modelCache[$modelId])) {',
			],
			[
				'name' => 'weekend gate: >= 6 weakened to > 6 (Sunday hole)',
				'needle' => 'if ($weekday >= 6 || $dailyHours <= 0.0) {',
				'replacement' => 'if ($weekday > 6 || $dailyHours <= 0.0) {',
			],
			[
				'name' => 'zero-hours gate: <= weakened to <',
				'needle' => 'if ($weekday >= 6 || $dailyHours <= 0.0) {',
				'replacement' => 'if ($weekday >= 6 || $dailyHours < 0.0) {',
			],
			[
				'name' => 'schedule end column swapped to start',
				'needle' => '$endMinutes = (int)end($blocks)[1];',
				'replacement' => '$endMinutes = (int)end($blocks)[0];',
			],
			[
				'name' => 'required break dropped from flexible end',
				'needle' => "'+' . (\$netSeconds + \$breakSeconds) . ' seconds'",
				'replacement' => "'+' . \$netSeconds . ' seconds'",
			],
		],
	],
	[
		'file' => 'lib/BackgroundJob/AutoClockOutJob.php',
		'test' => 'tests/Unit/BackgroundJob/AutoClockOutJobTest.php',
		'filter' => 'AutoClockOutJobTest',
		'mutants' => [
			[
				'name' => 'enabled flag inverted',
				'needle' => "Constants::CONFIG_AUTO_CLOCK_OUT_ENABLED, '0') !== '1') {",
				'replacement' => "Constants::CONFIG_AUTO_CLOCK_OUT_ENABLED, '0') === '1') {",
			],
			[
				'name' => 'cutover-hour gate inverted',
				'needle' => 'if ((int)$nowApp->format(\'G\') !== $hour) {',
				'replacement' => 'if ((int)$nowApp->format(\'G\') === $hour) {',
			],
			[
				'name' => 'disabled-user gate dropped',
				'needle' => 'if (!$user->isEnabled()) {',
				'replacement' => 'if (false) {',
			],
			[
				'name' => 'access-restriction gate dropped',
				'needle' => 'if (!$this->permissionService->isUserAllowedByAccessGroups($userId)) {',
				'replacement' => 'if (false) {',
			],
			[
				'name' => 'pending-open-session gate dropped',
				'needle' => '|| $this->timeEntryMapper->findPendingOpenSessionByUser($userId) !== null) {',
				'replacement' => ') {',
			],
			[
				'name' => 'paused removed from eligible statuses',
				'needle' => 'TimeEntry::STATUS_ACTIVE, TimeEntry::STATUS_BREAK, TimeEntry::STATUS_PAUSED',
				'replacement' => 'TimeEntry::STATUS_ACTIVE, TimeEntry::STATUS_BREAK',
			],
			[
				'name' => 'today-scope day check inverted',
				'needle' => '$this->timeZoneService->dayKeyInStorage($start) !== $todayKey',
				'replacement' => '$this->timeZoneService->dayKeyInStorage($start) === $todayKey',
			],
			[
				'name' => 'overlap check inverted',
				'needle' => 'findOverlapping($userId, $start, $plannedEnd, (int)$entry->getId()) !== []',
				'replacement' => 'findOverlapping($userId, $start, $plannedEnd, (int)$entry->getId()) === []',
			],
			[
				'name' => 'race reason code swapped',
				'needle' => 'if ($e->getReasonCode() === BusinessRuleCode::NOT_CLOCKED_IN) {',
				'replacement' => 'if ($e->getReasonCode() === BusinessRuleCode::ALREADY_CLOCKED_IN) {',
			],
			[
				'name' => 'hour clamp: lower bound dropped',
				'needle' => 'return max(0, min(23, $hour));',
				'replacement' => 'return min(23, $hour);',
			],
		],
	],
	[
		'file' => 'lib/Service/TimeTrackingService.php',
		'test' => 'tests/Unit/Service/TimeTrackingServiceTest.php',
		'filter' => 'testAutoClockOut',
		'mutants' => [
			[
				'name' => 'scanned-entry id match inverted',
				'needle' => '(int)$entry->getId() !== $entryId',
				'replacement' => '(int)$entry->getId() === $entryId',
			],
			// NOTE: no "> now → >= now" mutant — the equality boundary depends
			// on wall-clock "now" and cannot be pinned deterministically; the
			// mutant would be equivalent, not a coverage signal.
			[
				'name' => 'end-after-start boundary weakened (<= to <)',
				'needle' => 'if ($start === null || $end->getTimestamp() <= $start->getTimestamp()) {',
				'replacement' => 'if ($start === null || $end->getTimestamp() < $start->getTimestamp()) {',
			],
			[
				'name' => 'paused branch swapped to break',
				'needle' => 'if ($entry->getStatus() === TimeEntry::STATUS_PAUSED) {',
				'replacement' => 'if ($entry->getStatus() === TimeEntry::STATUS_BREAK) {',
			],
			[
				'name' => 'ended reason swapped to manual on write path',
				'needle' => "\$userId,\n\t\t\t\tTimeEntry::ENDED_REASON_AUTO_PLANNED_END,",
				'replacement' => "\$userId,\n\t\t\t\tTimeEntry::ENDED_REASON_MANUAL_CLOCK_OUT,",
			],
			[
				'name' => 'capture source swapped to manual on write path',
				'needle' => "\t\t\t\tConstants::AUDIT_CAPTURE_SOURCE_AUTO_CLOCK_OUT\n\t\t\t);",
				'replacement' => "\t\t\t\t'manual'\n\t\t\t);",
			],
		],
	],
];

function runSuite(string $phpunit, string $testFile, string $filter, string $root): int
{
	// --do-not-cache-result: a root-run spawned phpunit must not drop a
	// root-owned .phpunit.result.cache onto the bind-mounted app dir.
	$cmd = sprintf(
		'cd %s && php -d opcache.enable_cli=0 %s --testsuite unit --filter %s --do-not-cache-result %s 2>&1',
		escapeshellarg($root),
		escapeshellarg($phpunit),
		escapeshellarg($filter),
		escapeshellarg($testFile)
	);
	exec($cmd, $out, $code);
	$joined = implode("\n", $out ?? []);
	// Bootstrap failures (e.g. NC config-dir writability under a wrong user)
	// exit 0 WITHOUT running tests — never let silence count as "killed".
	if (!str_contains($joined, 'OK (') && !str_contains($joined, 'FAILURES!') && !str_contains($joined, 'ERRORS!')) {
		fwrite(STDERR, "FAIL: spawned phpunit produced no test verdict — harness broken.\n{$joined}\n");
		exit(2);
	}
	return $code;
}

$killed = 0;
$survived = [];

// Pre-validate everything BEFORE the first mutation: exit() never runs
// finally blocks, so any early bail after a mutant was written would leak
// mutated source onto the bind mount.
$sources = [];
foreach ($targets as $target) {
	$path = $root . '/' . $target['file'];
	$original = file_get_contents($path);
	if ($original === false || !is_writable($path)) {
		fwrite(STDERR, "FAIL: {$path} not readable/writable by this process.\n");
		exit(1);
	}
	foreach ($target['mutants'] as $m) {
		if (!str_contains($original, $m['needle'])) {
			fwrite(STDERR, "FAIL: needle not found for '{$m['name']}' in {$target['file']} — source drifted, update the mutant.\n");
			exit(1);
		}
	}
	$sources[$path] = $original;
}

// Belt and suspenders: exit()/die() skip finally blocks, so a shutdown hook
// is the only guaranteed restore for a mid-loop fatal.
register_shutdown_function(static function () use ($sources): void {
	foreach ($sources as $path => $original) {
		$current = @file_get_contents($path);
		if ($current !== false && $current !== $original) {
			@file_put_contents($path, $original);
			fwrite(STDERR, "shutdown restore applied to {$path}\n");
		}
	}
});

foreach ($targets as $target) {
	$path = $root . '/' . $target['file'];
	$original = $sources[$path];

	try {
		// Baseline: unmutated suite must pass or the gauntlet is meaningless.
		if (runSuite($phpunit, $target['test'], $target['filter'], $root) !== 0) {
			fwrite(STDERR, "FAIL: baseline {$target['filter']} suite already red — fix before mutating.\n");
			exit(1);
		}
		echo "OK: baseline {$target['filter']} green\n";

		foreach ($target['mutants'] as $m) {
			if (file_put_contents($path, str_replace($m['needle'], $m['replacement'], $original)) === false) {
				fwrite(STDERR, "FAIL: could not write mutant '{$m['name']}' to {$path}\n");
				exit(1);
			}
			$code = runSuite($phpunit, $target['test'], $target['filter'], $root);
			if ($code === 0) {
				$survived[] = "{$target['file']}: {$m['name']}";
				fwrite(STDERR, "SURVIVED: {$target['file']}: {$m['name']}\n");
			} else {
				$killed++;
				echo "KILLED: {$target['file']}: {$m['name']}\n";
			}
		}
	} finally {
		if (file_put_contents($path, $original) === false
			|| file_get_contents($path) !== $original) {
			fwrite(STDERR, "FATAL: restore of {$path} failed — file may still be mutated!\n");
			exit(2);
		}
	}
}

if ($survived !== []) {
	fwrite(STDERR, "FAIL: " . count($survived) . " mutant(s) survived — coverage hole.\n");
	exit(1);
}
echo "All {$killed} auto-clock-out mutants killed.\n";
