<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for DACH labour-law / break-split / region core (no Infection).
 *
 * Baseline unit tests must pass, then known-bad source mutations must be killed
 * by BreakSplitValidatorTest, LaborLawProfileFactoryTest, and RegionRegistryTest.
 *
 * Usage (Docker):
 *   docker compose exec -T nextcloud php /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-dach-core-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

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
	'BreakSplitValidatorTest',
	'LaborLawProfileFactoryTest',
	'RegionRegistryTest',
	'BreakCountableTest',
	'TimeEntryClockPayloadBuilderTest',
	'TimeEntryTest',
	'ComplianceServiceTest',
	'RegionListDedupGuardTest',
	'AdminSettingsDachParityTest',
	'SwissCantonL10nGuardTest',
	'ComplianceControllerTest::testDashboardLeadFollowsUserLaborLawCountryOverride',
];

echo "== baseline DACH core unit tests ==\n";
$baseline = run_unit_tests($appRoot, $phpunit, $suiteFilters);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	[
		'name' => 'skip_absolute_weekly_clock_in',
		'file' => 'lib/Service/ComplianceService.php',
		'from' => "if (\$profile->weeklyAbsoluteMaxHours !== null && !\$this->checkAbsoluteWeeklyHoursLimit(\$userId)) {",
		'to' => "if (false && \$profile->weeklyAbsoluteMaxHours !== null && !\$this->checkAbsoluteWeeklyHoursLimit(\$userId)) {",
		'filters' => ['ComplianceServiceTest::testSwissAbsoluteWeeklyWarnsAtClockIn'],
	],

	[
		'name' => 'sum_only_always_true',
		'file' => 'lib/Support/BreakSplitValidator.php',
		'from' => "if (\$allowedPatterns === null) {\n\t\t\treturn true;\n\t\t}",
		'to' => "if (\$allowedPatterns === null) {\n\t\t\treturn false;\n\t\t}",
		'filters' => ['BreakSplitValidatorTest'],
	],
	[
		'name' => 'skip_total_gate',
		'file' => 'lib/Support/BreakSplitValidator.php',
		'from' => "if (\$total < \$requiredMinutes) {\n\t\t\treturn false;\n\t\t}",
		'to' => "if (false && \$total < \$requiredMinutes) {\n\t\t\treturn false;\n\t\t}",
		'filters' => ['BreakSplitValidatorTest'],
	],
	[
		'name' => 'accept_wrong_portion_count',
		'file' => 'lib/Support/BreakSplitValidator.php',
		'from' => "if (count(\$portionMinutes) !== count(\$patternMins)) {\n\t\t\treturn false;\n\t\t}",
		'to' => "if (false && count(\$portionMinutes) !== count(\$patternMins)) {\n\t\t\treturn false;\n\t\t}",
		'filters' => ['BreakSplitValidatorTest'],
	],
	[
		'name' => 'drop_negative_clamp',
		'file' => 'lib/Support/BreakSplitValidator.php',
		'from' => '$total += max(0, (int)$minutes);',
		'to' => '$total += (int)$minutes;',
		'filters' => ['BreakSplitValidatorTest'],
	],
	[
		'name' => 'swiss_weekly_always_50',
		'file' => 'lib/Support/LaborLawProfileFactory.php',
		'from' => 'return ($value === 50.0) ? 50.0 : 45.0;',
		'to' => 'return 50.0;',
		'filters' => ['LaborLawProfileFactoryTest'],
	],
	[
		'name' => 'ignore_user_labor_law_override',
		'file' => 'lib/Support/LaborLawProfileFactory.php',
		'from' => '$resolved = RegionRegistry::isSupportedCountry($override) ? $override : $instance;',
		'to' => '$resolved = $instance;',
		'filters' => ['LaborLawProfileFactoryTest'],
	],
	[
		'name' => 'legacy_codes_not_germany',
		'file' => 'lib/Support/RegionRegistry.php',
		'from' => "if (\$dash === false) {\n\t\t\treturn self::COUNTRY_DE;\n\t\t}",
		'to' => "if (\$dash === false) {\n\t\t\treturn self::COUNTRY_AT;\n\t\t}",
		'filters' => ['RegionRegistryTest'],
	],
	[
		'name' => 'empty_breaks_omitted_from_clock_payload',
		'file' => 'lib/Support/TimeEntryClockPayloadBuilder.php',
		'from' => "if (\$breaks === []) {\n\t\t\t\t\$result['breaks'] = [];\n\t\t\t} else {",
		'to' => "if (\$breaks === []) {\n\t\t\t\t// mutated: omit empty breaks so clearing is lost\n\t\t\t} else {",
		'filters' => ['TimeEntryClockPayloadBuilderTest::testEmptyBreaksClearProposal'],
	],
	[
		'name' => 'break_countable_always_fifteen',
		'file' => 'lib/Support/BreakCountable.php',
		'from' => "if (\$minBreakMinutes === null || \$minBreakMinutes <= 0) {\n\t\t\treturn self::DEFAULT_MIN_MINUTES;\n\t\t}\n\n\t\treturn \$minBreakMinutes;",
		'to' => "if (\$minBreakMinutes === null || \$minBreakMinutes <= 0) {\n\t\t\treturn self::DEFAULT_MIN_MINUTES;\n\t\t}\n\n\t\treturn self::DEFAULT_MIN_MINUTES;",
		'filters' => ['BreakCountableTest', 'TimeEntryClockPayloadBuilderTest', 'TimeEntryTest'],
	],
	[
		// CH ArG 5.5 h must not become "5" in employee-facing messages.
		'name' => 'format_hours_truncates_fraction',
		'file' => 'lib/Support/LaborLawProfile.php',
		'from' => "if (abs(\$hours - round(\$hours)) < 0.001) {\n\t\t\treturn (string)(int)round(\$hours);\n\t\t}\n\n\t\treturn rtrim(rtrim(number_format(\$hours, 1, '.', ''), '0'), '.');",
		'to' => "return (string)(int)\$hours;",
		'filters' => ['LaborLawProfileFactoryTest'],
	],
	[
		'name' => 'resolve_default_region_ignores_country_match',
		'file' => 'lib/Support/RegionRegistry.php',
		'from' => "if (\$region === ''\n\t\t\t|| !self::isValidRegion(\$region)\n\t\t\t|| self::countryOf(\$region) !== \$country) {\n\t\t\treturn \$fallback;\n\t\t}",
		'to' => "if (\$region === ''\n\t\t\t|| !self::isValidRegion(\$region)) {\n\t\t\treturn \$fallback;\n\t\t}",
		'filters' => ['RegionRegistryTest::testResolveDefaultRegionForCountryRejectsCrossBorderOrphans'],
	],
	[
		'name' => 'nc_admin_settings_drops_swiss_weekly_cap',
		'file' => 'lib/Settings/AdminSettings.php',
		'from' => "'weeklyAbsoluteMaxHours' => \$this->readConfiguredSwissWeeklyAbsoluteMax(),",
		'to' => "'weeklyAbsoluteMaxHours' => 45,",
		'filters' => ['AdminSettingsDachParityTest::testSwissFiftyHourCapSurvivesNcAdminSettingsPayload'],
	],
	[
		'name' => 'compliance_lead_ignores_user_override',
		'file' => 'lib/Controller/ComplianceController.php',
		'from' => "\$country = \$userId !== null && \$userId !== ''\n\t\t\t? \$this->laborLawProfileFactory->getEffectiveCountry(\$userId)\n\t\t\t: \$this->laborLawProfileFactory->getConfiguredCountry();",
		'to' => "\$country = \$this->laborLawProfileFactory->getConfiguredCountry();",
		'filters' => ['ComplianceControllerTest::testDashboardLeadFollowsUserLaborLawCountryOverride'],
	],
];

$failedToKill = [];
foreach ($mutations as $mutation) {
	$name = $mutation['name'];
	$source = $appRoot . '/' . $mutation['file'];
	$backup = $source . '.mutation-bak';
	$filters = $mutation['filters'] ?? $suiteFilters;

	echo "\n== mutation: {$name} ==\n";
	if (!is_file($source)) {
		$failedToKill[] = $name . ' (missing file)';
		continue;
	}
	$original = file_get_contents($source);
	if ($original === false || !str_contains($original, $mutation['from'])) {
		$failedToKill[] = $name . ' (anchor missing)';
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		continue;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($mutation['from'], $mutation['to'], $original);
	if ($mutated === $original || file_put_contents($source, $mutated) === false) {
		$failedToKill[] = $name . ' (replace failed)';
		restore($source, $backup);
		continue;
	}
	$code = run_unit_tests($appRoot, $phpunit, $filters);
	restore($source, $backup);
	if ($code === 0) {
		$failedToKill[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	} else {
		echo "killed {$name}\n";
	}
}

// Ensure no leftover backups if a previous run crashed.
foreach (glob($appRoot . '/lib/Support/*.mutation-bak') ?: [] as $leftover) {
	$restored = preg_replace('/\.mutation-bak$/', '', $leftover);
	if (is_string($restored) && !is_file($restored) && is_file($leftover)) {
		rename($leftover, $restored);
	} elseif (is_file($leftover)) {
		unlink($leftover);
	}
}

if ($failedToKill !== []) {
	fwrite(STDERR, "Mutations not killed: " . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll DACH core mutations killed.\n";
exit(0);
