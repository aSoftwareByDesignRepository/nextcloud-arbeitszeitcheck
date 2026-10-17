<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineManifest;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineScenario;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineSeedPlan;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyRuntimeProfileMap;
use OCA\ArbeitszeitCheck\Tests\Support\LegacySchemaFamilyMap;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeExecutionPlan;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeMatrix;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
foreach ([
	'LegacyBaselineCanonicalizer',
	'LegacyBaselineManifest',
	'LegacyBaselineScenario',
	'LegacyBaselineSeedCompiler',
	'LegacyBaselineSeedPlan',
	'LegacyRuntimeProfileMap',
	'LegacySchemaFamilyMap',
	'LegacyUpgradeExecutionPlan',
	'LegacyUpgradeMatrix',
] as $class) {
	require_once $root . '/tests/Support/' . $class . '.php';
}

try {
	$scenario = LegacyBaselineScenario::load($root . '/tests/fixtures/legacy-baseline-scenario.json');
	$seedPlan = LegacyBaselineSeedPlan::load($root . '/tests/fixtures/legacy-baseline-seed-plan.json', $scenario);
	$manifest = LegacyBaselineManifest::load($root . '/tests/fixtures/legacy-baseline-manifest.json');
	$matrix = LegacyUpgradeMatrix::load($root . '/tests/fixtures/legacy-upgrade-matrix.json');
	$schemaMap = LegacySchemaFamilyMap::load($root . '/tests/fixtures/legacy-schema-families.json', $matrix);
	$runtimeMap = LegacyRuntimeProfileMap::load($root . '/tests/fixtures/legacy-runtime-profiles.json', $matrix);
	$plan = LegacyUpgradeExecutionPlan::build($matrix, $manifest, $seedPlan, $scenario, $schemaMap, $runtimeMap);
	if (count($plan['jobs']) !== 4) {
		throw new RuntimeException('Phase 0 execution plan must contain the v1.8.0 MySQL/PostgreSQL jobs on Nextcloud 32 and 35.');
	}
	foreach ($plan['jobs'] as $job) {
		$composeFile = $root . '/' . ($job['composeFile'] ?? '');
		if (!is_file($composeFile)) {
			throw new RuntimeException('Missing isolated Compose stack for job ' . ($job['jobId'] ?? 'unknown'));
		}
	}
	printf(
		"Phase 0 baseline contracts OK: %d jobs, %d schema families, %d runtime profiles, plan %s.\n",
		count($plan['jobs']),
		$plan['schemaFamilyCount'],
		$plan['runtimeProfileCount'],
		substr($plan['planHash'], 0, 16),
	);
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
