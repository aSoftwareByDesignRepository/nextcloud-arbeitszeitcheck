<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineScenario;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineSeedCompiler;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineSeedPlan;
use OCA\ArbeitszeitCheck\Tests\Support\LegacySchemaFamilyMap;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeMatrix;
use PHPUnit\Framework\TestCase;

final class LegacyBaselineSeedCompilerTest extends TestCase
{
	public function testCompilesDeterministicOperationsForEveryPlannedWrite(): void
	{
		$compiled = self::compile();
		$again = self::compile();
		$this->assertSame($compiled['hash'], $again['hash']);
		$this->assertGreaterThan(50, count($compiled['operations']));
		foreach ($compiled['operations'] as $operation) {
			$this->assertNotSame('', $operation['key']);
			$this->assertNotSame('', $operation['target']);
		}
	}

	public function testTranslatesPersistedStatesAndReferences(): void
	{
		$operations = self::compile()['operations'];
		$absences = self::operationsFor($operations, 'at_absences', 'insert');
		$this->assertContains('sick_leave', array_column(array_column($absences, 'data'), 'type'));
		$this->assertContains('custom_bereavement', array_column(array_column($absences, 'data'), 'type'));
		$entries = self::operationsFor($operations, 'at_entries', 'insert');
		$this->assertContains('paused', array_column(array_column($entries, 'data'), 'status'));
		$this->assertContains('external', array_column(array_column($entries, 'data'), 'work_location'));
		$assignments = self::operationsFor($operations, 'at_user_models', 'insert');
		$this->assertSame('ref:model:standard', $assignments[0]['data']['working_time_model_id']);
	}

	public function testCompilesReopenedMonthWithRevisionEvidence(): void
	{
		$operations = self::compile()['operations'];
		$closures = self::operationsFor($operations, 'at_month_closure', 'insert');
		$reopened = array_values(array_filter($closures, static fn (array $op): bool => $op['key'] === 'closure:employee_days:2025-12'))[0];
		$this->assertSame('open', $reopened['data']['status']);
		$this->assertArrayHasKey('reopened_at', $reopened['data']);
		$this->assertSame('legacy_seed', $reopened['data']['reopened_by']);
		$revisions = array_values(array_filter(
			self::operationsFor($operations, 'at_month_closure_revision', 'insert'),
			static fn (array $op): bool => $op['data']['closure_id'] === 'ref:closure:employee_days:2025-12'
		));
		$this->assertCount(2, $revisions);
	}

	public function testPreservesGhostMembershipAndLegacyIdempotencyNulls(): void
	{
		$operations = self::compile()['operations'];
		$ghostMemberships = array_values(array_filter(
			self::operationsFor($operations, 'at_team_members', 'insert'),
			static fn (array $op): bool => $op['data']['user_id'] === 'azc_deleted_ghost'
		));
		$this->assertCount(1, $ghostMemberships);
		$this->assertSame('azc_deleted_ghost', $ghostMemberships[0]['data']['user_id']);
		foreach (self::operationsFor($operations, 'at_mob_stamp_idem', 'insert') as $record) {
			$this->assertNull($record['data']['payload_hash']);
		}
		$pending = array_values(array_filter(
			self::operationsFor($operations, 'at_mob_stamp_idem', 'insert'),
			static fn (array $op): bool => $op['key'] === 'mobile:legacy-mobile-pending'
		))[0];
		$this->assertSame('{"pending":true}', $pending['data']['response_json']);
		$completed = array_values(array_filter(
			self::operationsFor($operations, 'at_mob_stamp_idem', 'insert'),
			static fn (array $op): bool => $op['key'] === 'mobile:legacy-mobile-complete'
		))[0];
		$replay = json_decode($completed['data']['response_json'], true, 512, JSON_THROW_ON_ERROR);
		$this->assertTrue($replay['success']);
		$this->assertSame('completed', $replay['timeEntry']['status']);
		$this->assertCount(2, self::operationsFor($operations, 'at_kiosk_stamp_idem', 'insert'));
	}

	public function testRejectsCompilerOutputWhenPlanTargetIsNotEmitted(): void
	{
		$scenario = self::scenario();
		$plan = self::plan($scenario);
		foreach ($plan['sections'] as &$section) {
			if ($section['scenario'] === 'audit') {
				$section['writes'][0]['operation'] = 'update';
			}
		}
		unset($section);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('do not match the seed plan');
		LegacyBaselineSeedCompiler::compile($scenario, $plan, self::currentCapabilities());
	}

	public function testCompilesHistoricallyReachableStatesForProductionSourceSchema(): void
	{
		$scenario = self::scenario();
		$capabilities = self::schemaMap()->capabilitiesForTag('v1.8.0');
		$compiled = LegacyBaselineSeedCompiler::compile($scenario, self::plan($scenario), $capabilities);
		$targets = array_unique(array_column($compiled['operations'], 'target'));
		$this->assertContains('external.nextcloud_users', $targets);
		$this->assertContains('at_entries', $targets);
		$this->assertContains('at_absences', $targets);
		$this->assertContains('at_teams', $targets);
		$this->assertContains('at_month_closure', $targets);
		$this->assertContains('at_tariff_rule_sets', $targets);
		$this->assertContains('at_mob_stamp_idem', $targets);
		$this->assertNotContains('at_custom_absence', $targets);
		$models = self::operationsFor($compiled['operations'], 'at_models', 'insert');
		$this->assertArrayHasKey('work_days_per_week', $models[0]['data']);
		$entries = self::operationsFor($compiled['operations'], 'at_entries', 'insert');
		$this->assertArrayHasKey('breaks', $entries[0]['data']);
		$this->assertArrayNotHasKey('work_location', $entries[0]['data']);
		$absences = self::operationsFor($compiled['operations'], 'at_absences', 'insert');
		$this->assertNotContains('custom_bereavement', array_column(array_column($absences, 'data'), 'type'));
		$this->assertContains('duration_hours', array_keys($absences[0]['data']));
		$substitutions = self::operationsFor($compiled['operations'], 'at_absences', 'update');
		$this->assertSame(['substitution:vacation_days_approved', 'substitution:vacation_hours_pending'], array_column($substitutions, 'key'));
		$audits = self::operationsFor($compiled['operations'], 'at_audit', 'insert');
		$this->assertSame(['audit:1', 'audit:2', 'audit:3'], array_column($audits, 'key'));
	}

	private static function compile(): array
	{
		$scenario = self::scenario();
		return LegacyBaselineSeedCompiler::compile($scenario, self::plan($scenario), self::currentCapabilities());
	}

	private static function scenario(): array
	{
		return LegacyBaselineScenario::load(__DIR__ . '/../../fixtures/legacy-baseline-scenario.json');
	}

	private static function plan(array $scenario): array
	{
		return LegacyBaselineSeedPlan::load(__DIR__ . '/../../fixtures/legacy-baseline-seed-plan.json', $scenario);
	}

	private static function schemaMap(): LegacySchemaFamilyMap
	{
		$matrix = LegacyUpgradeMatrix::load(__DIR__ . '/../../fixtures/legacy-upgrade-matrix.json');
		return LegacySchemaFamilyMap::load(__DIR__ . '/../../fixtures/legacy-schema-families.json', $matrix);
	}

	private static function currentCapabilities(): array
	{
		return [
			'teams' => true,
			'absenceSubstitutions' => true,
			'entryBreaksJson' => true,
			'monthClosures' => true,
			'localDatetimeStorage' => true,
			'tariffs' => true,
			'workDaysPerWeek' => true,
			'vacationDurationHours' => true,
			'mobileKioskIdempotency' => true,
			'personalModels' => true,
			'layeredVacationDefaults' => true,
			'customAbsenceTypes' => true,
			'workLocation' => true,
			'idempotencyPayloadHash' => true,
		];
	}

	private static function operationsFor(array $operations, string $target, string $operation): array
	{
		return array_values(array_filter($operations, static fn (array $op): bool => $op['target'] === $target && $op['operation'] === $operation));
	}
}
