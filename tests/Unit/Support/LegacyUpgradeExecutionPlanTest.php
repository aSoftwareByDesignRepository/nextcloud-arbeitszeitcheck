<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineManifest;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineScenario;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineSeedPlan;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyRuntimeProfileMap;
use OCA\ArbeitszeitCheck\Tests\Support\LegacySchemaFamilyMap;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeExecutionPlan;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeMatrix;
use PHPUnit\Framework\TestCase;

final class LegacyUpgradeExecutionPlanTest extends TestCase
{
	public function testBuildsIsolatedJobsForCurrentProductionSource(): void
	{
		$plan = self::plan();
		$this->assertSame(1, $plan['maxParallel']);
		$this->assertCount(4, $plan['jobs']);
		$this->assertCount(4, array_unique(array_column($plan['jobs'], 'composeProject')));
		$this->assertCount(4, array_unique(array_column($plan['jobs'], 'network')));
		$this->assertCount(4, array_unique(array_column($plan['jobs'], 'volumePrefix')));
		$this->assertCount(4, array_unique(array_column($plan['jobs'], 'artifactKey')));
		$this->assertCount(23, $plan['manifestCategories']);
		$this->assertSame(1, $plan['schemaFamilyCount']);
		$this->assertSame(1, $plan['runtimeProfileCount']);
		$this->assertSame(array_fill(0, 4, 'v1.8.0'), array_column($plan['jobs'], 'sourceTag'));
		$this->assertSame(['mysql', 'pgsql', 'mysql', 'pgsql'], array_column($plan['jobs'], 'database'));
		$this->assertSame(['nextcloud:32', 'nextcloud:32', 'nextcloud:35', 'nextcloud:35'], array_column($plan['jobs'], 'serverImage'));
		$this->assertSame(['days', 'hours'], $plan['jobs'][0]['captureVariants']);
		$this->assertGreaterThan(0, $plan['jobs'][0]['seedOperationCount']);
		$this->assertSame($plan['jobs'][0]['seedHash'], $plan['jobs'][3]['seedHash']);
		$this->assertNotEmpty($plan['seedPlanHash']);
		$this->assertNotEmpty($plan['planHash']);
	}

	public function testEveryJobCapturesBeforeAfterBackupRestoreAndTeardown(): void
	{
		foreach (self::plan()['jobs'] as $job) {
			$this->assertSame('WORKTREE', $job['targetRef']);
			$this->assertNotSame('', $job['schemaFamily']);
			$this->assertNotSame('', $job['runtimeProfile']);
			$this->assertMatchesRegularExpression('/^nextcloud:\d+$/', $job['serverImage']);
			$this->assertSame('tests/fixtures/upgrade-stack/' . $job['database'] . '.yml', $job['composeFile']);
			$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $job['seedHash']);
			$this->assertGreaterThan(0, $job['seedOperationCount']);
			$this->assertIsInt($job['maxSourceMigration']);
			$this->assertSame([
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
			], $job['steps']);
			$this->assertContains('days', $job['captureVariants']);
			$this->assertSame(
				$job['schemaCapabilities']['vacationDurationHours'],
				in_array('hours', $job['captureVariants'], true)
			);
			$this->assertStringStartsWith('azc-legacy-', $job['composeProject']);
			$this->assertStringNotContainsString('nextcloud', $job['composeProject']);
			$this->assertLessThanOrEqual(63, strlen($job['composeProject']));
		}
	}

	public function testPlanIsDeterministicAndSeedPlanSensitive(): void
	{
		$first = self::plan();
		$second = self::plan();
		$this->assertSame($first['planHash'], $second['planHash']);
		$seedPlan = self::seedPlan();
		$seedPlan['sections'][0]['order'] = 15;
		$matrix = self::matrix();
		$manifest = self::manifest();
		$changed = LegacyUpgradeExecutionPlan::build($matrix, $manifest, $seedPlan, self::scenario(), self::schemaMap(), self::runtimeMap());
		$this->assertNotSame($first['planHash'], $changed['planHash']);
	}

	private static function plan(): array
	{
		return LegacyUpgradeExecutionPlan::build(self::matrix(), self::manifest(), self::seedPlan(), self::scenario(), self::schemaMap(), self::runtimeMap());
	}

	private static function matrix(): array
	{
		return LegacyUpgradeMatrix::load(__DIR__ . '/../../fixtures/legacy-upgrade-matrix.json');
	}

	private static function manifest(): array
	{
		return LegacyBaselineManifest::load(__DIR__ . '/../../fixtures/legacy-baseline-manifest.json');
	}

	private static function seedPlan(): array
	{
		return LegacyBaselineSeedPlan::load(__DIR__ . '/../../fixtures/legacy-baseline-seed-plan.json', self::scenario());
	}

	private static function scenario(): array
	{
		return LegacyBaselineScenario::load(__DIR__ . '/../../fixtures/legacy-baseline-scenario.json');
	}

	private static function schemaMap(): LegacySchemaFamilyMap
	{
		return LegacySchemaFamilyMap::load(__DIR__ . '/../../fixtures/legacy-schema-families.json', self::matrix());
	}

	private static function runtimeMap(): LegacyRuntimeProfileMap
	{
		return LegacyRuntimeProfileMap::load(__DIR__ . '/../../fixtures/legacy-runtime-profiles.json', self::matrix());
	}
}
