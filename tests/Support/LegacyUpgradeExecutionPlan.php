<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

final class LegacyUpgradeExecutionPlan
{
	private const REQUIRED_STEPS = [
		'checkout_source',
		'provision_isolated_stack',
		'seed_legacy_scenario',
		'capture_pre_upgrade_snapshot',
		'checkout_target',
		'run_upgrade',
		'capture_post_upgrade_snapshot',
		'create_upgrade_backup',
		'restore_upgrade_backup',
		'compare_canonical_hashes',
		'teardown_isolated_stack',
	];

	public static function build(array $matrix, array $manifest, array $seedPlan, array $scenario, LegacySchemaFamilyMap $schemaMap, LegacyRuntimeProfileMap $runtimeMap): array
	{
		$jobs = [];
		foreach ($matrix['sourceTags'] as $tag) {
			$family = $schemaMap->familyForTag($tag);
			$runtime = $runtimeMap->profileForTag($tag);
			$capabilities = $schemaMap->capabilitiesForTag($tag);
			$compiledSeed = LegacyBaselineSeedCompiler::compile($scenario, $seedPlan, $capabilities);
			$variants = array_values(array_filter(
				array_column($scenario['vacationModes'], 'key'),
				static fn (string $variant): bool => $variant !== 'hours' || ($capabilities['vacationDurationHours'] ?? false) === true
			));
			foreach ($matrix['serverImages'] as $serverImage) {
				$serverVersion = substr($serverImage, strlen('nextcloud:'));
				foreach ($matrix['databases'] as $database) {
					$slug = strtr($tag, ['.' => '-']) . '-nc' . $serverVersion . '-' . $database;
					$project = 'azc-legacy-' . $slug;
					if (strlen($project) > 63) {
						throw new \RuntimeException('Legacy upgrade project name exceeds the Docker-safe limit.');
					}
					$jobs[] = [
						'sourceTag' => $tag,
						'targetRef' => 'WORKTREE',
						'database' => $database,
						'composeFile' => 'tests/fixtures/upgrade-stack/' . $database . '.yml',
						'composeProject' => $project,
						'network' => $project . '-net',
						'volumePrefix' => $project . '-vol',
						'credentialRef' => 'AZC_LEGACY_' . strtoupper(strtr($database . '_' . $tag . '_NC' . $serverVersion, ['.' => '_', '-' => '_'])),
						'artifactKey' => 'phase0/' . $tag . '/nc' . $serverVersion . '/' . $database,
						'scenario' => 'legacy-baseline-scenario.json',
						'seedPlan' => 'legacy-baseline-seed-plan.json',
						'seedHash' => $compiledSeed['hash'],
						'seedOperationCount' => count($compiledSeed['operations']),
						'baselineManifest' => 'legacy-baseline-manifest.json',
						'schemaFamily' => $family['key'],
						'maxSourceMigration' => $family['maxMigration'],
						'runtimeProfile' => $runtime['key'] . '-nc' . $serverVersion,
						'serverImage' => $serverImage,
						'sourceNextcloudMin' => $runtime['sourceMin'],
						'sourceNextcloudMax' => $runtime['sourceMax'],
						'schemaCapabilities' => $capabilities,
						'captureVariants' => $variants,
						'steps' => self::REQUIRED_STEPS,
					];
				}
			}
		}

		return [
			'format' => 1,
			'maxParallel' => 1,
			'forbidSharedServices' => ['nextcloud', 'mariadb', 'nextcloud-app', 'nextcloud-mariadb'],
			'manifestCategories' => $manifest['categories'],
			'schemaFamilyCount' => count($schemaMap->families()),
			'runtimeProfileCount' => count($runtimeMap->profiles()),
			'seedPlanHash' => LegacyBaselineCanonicalizer::hash($seedPlan),
			'jobs' => $jobs,
			'planHash' => LegacyBaselineCanonicalizer::hash(['jobs' => $jobs, 'categories' => $manifest['categories'], 'seedPlan' => $seedPlan, 'vacationModes' => $scenario['vacationModes'], 'schemaFamilies' => $schemaMap->families(), 'runtimeProfiles' => $runtimeMap->profiles()]),
		];
	}
}
