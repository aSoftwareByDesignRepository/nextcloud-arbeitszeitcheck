<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the tariff-rule-set-unavailable degradation path
 * (VacationEntitlementEngine::resolveTariff / layer degradation).
 *
 * Defect class: a vacation policy in tariff mode whose tariff_rule_set_id is
 * NULL or dangling used to fatal computeForDate() — taking down
 * GET /api/admin/users/{uid} (1.9.7 "Benutzerdetails konnten nicht geladen
 * werden"). These mutants break each protection leg individually; every one
 * must be KILLED by VacationEntitlementEngineTest.
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
		rename($backup, $source);
	}
}

$suiteFilters = [
	'VacationEntitlementEngineTest::testTariffNullRuleSetIdDegradesToLegacySafeDefault',
	'VacationEntitlementEngineTest::testTariffDanglingRuleSetIdDegradesToOrgDefault',
	'VacationEntitlementEngineTest::testTeamPolicyDanglingRuleSetDegradesToOrgDefault',
	'VacationEntitlementEngineTest::testValidTariffPolicyStillWinsAtL3NotDegraded',
	'VacationEntitlementEngineTest::testTariffRuleBasedCalculatesFormulaAndRounding',
	'VacationEntitlementEngineTest::testTariffProRataModesScaleEntitlement',
	'LayeredVacationEntitlementEngineTest',
];

echo "== baseline tariff-degradation tests ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	[
		'name' => 'null_ruleset_not_rejected',
		'file' => 'lib/Service/VacationEntitlementEngine.php',
		'from' => 'if ($ruleSetId === null) {
			throw new TariffRuleSetUnavailableException(null, \'Tariff mode requires an assigned rule set\');
		}',
		'to' => 'if (false) {
			throw new TariffRuleSetUnavailableException(null, \'Tariff mode requires an assigned rule set\');
		}',
		'filters' => ['VacationEntitlementEngineTest::testTariffNullRuleSetIdDegradesToLegacySafeDefault'],
	],
	[
		'name' => 'dangling_ruleset_unwrapped',
		'file' => 'lib/Service/VacationEntitlementEngine.php',
		'from' => 'try {
			$ruleSet = $this->ruleSetMapper->find($ruleSetId);
		} catch (DoesNotExistException $e) {
			throw new TariffRuleSetUnavailableException($ruleSetId, \'Tariff rule set no longer exists\', $e);
		}',
		'to' => '$ruleSet = $this->ruleSetMapper->find($ruleSetId);',
		'filters' => [
			'VacationEntitlementEngineTest::testTariffDanglingRuleSetIdDegradesToOrgDefault',
			'VacationEntitlementEngineTest::testTeamPolicyDanglingRuleSetDegradesToOrgDefault',
		],
	],
	[
		'name' => 'l3_catch_wrong_type',
		'file' => 'lib/Service/VacationEntitlementEngine.php',
		'from' => '} catch (TariffRuleSetUnavailableException $e) {',
		'to' => '} catch (\DomainException $e) {',
		'filters' => ['VacationEntitlementEngineTest::testTariffNullRuleSetIdDegradesToLegacySafeDefault'],
	],
	[
		'name' => 'team_undeliverable_treated_as_match',
		'file' => 'lib/Service/VacationEntitlementEngine.php',
		'from' => 'if ($teamResolution !== null && !empty($teamResolution[\'undeliverable\'])) {',
		'to' => 'if ($teamResolution !== null && false) {',
		'filters' => ['VacationEntitlementEngineTest::testTeamPolicyDanglingRuleSetDegradesToOrgDefault'],
	],
	[
		'name' => 'degraded_flag_dropped_at_l0',
		'file' => 'lib/Service/VacationEntitlementEngine.php',
		'from' => '$orgResolution[\'collision\'] || $undeliverableLayers',
		'to' => '$orgResolution[\'collision\']',
		'filters' => ['VacationEntitlementEngineTest::testTariffDanglingRuleSetIdDegradesToOrgDefault'],
	],
	[
		'name' => 'trace_reason_label_swapped',
		'file' => 'lib/Service/VacationEntitlementEngine.php',
		'from' => '\'reason\' => \'tariff_rule_set_unavailable\',',
		'to' => '\'reason\' => \'unavailable\',',
		'filters' => [
			'VacationEntitlementEngineTest::testTariffNullRuleSetIdDegradesToLegacySafeDefault',
			'VacationEntitlementEngineTest::testTariffDanglingRuleSetIdDegradesToOrgDefault',
			'VacationEntitlementEngineTest::testTeamPolicyDanglingRuleSetDegradesToOrgDefault',
		],
	],
];

$failed = 0;
foreach ($mutations as $m) {
	$source = $appRoot . '/' . $m['file'];
	$backup = $source . '.mutbak';
	$filters = $m['filters'] ?? $suiteFilters;
	echo "== mutate: {$m['name']} ==\n";
	$src = file_get_contents($source);
	if ($src === false || !str_contains($src, $m['from'])) {
		fwrite(STDERR, "Mutation source fragment not found: {$m['name']}\n");
		$failed++;
		continue;
	}
	copy($source, $backup);
	file_put_contents($source, str_replace($m['from'], $m['to'], $src));
	$code = run_unit_tests($appRoot, $phpunit, $filters);
	restore($source, $backup);
	if ($code === 0) {
		fwrite(STDERR, "SURVIVED mutation {$m['name']} (tests should have failed)\n");
		$failed++;
	} else {
		echo "KILLED {$m['name']}\n";
	}
}

if ($failed > 0) {
	fwrite(STDERR, "Mutation gauntlet failed: {$failed}\n");
	exit(1);
}
echo "All tariff-unavailable mutations killed.\n";
exit(0);
