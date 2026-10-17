<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineScenario;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineSeedPlan;
use PHPUnit\Framework\TestCase;

final class LegacyBaselineSeedPlanTest extends TestCase
{
	private const PLAN = __DIR__ . '/../../fixtures/legacy-baseline-seed-plan.json';
	private const SCENARIO = __DIR__ . '/../../fixtures/legacy-baseline-scenario.json';

	public function testPlanCoversScenarioInDeterministicWriteOrder(): void
	{
		$scenario = LegacyBaselineScenario::load(self::SCENARIO);
		$plan = LegacyBaselineSeedPlan::load(self::PLAN, $scenario);
		$this->assertCount(13, $plan['sections']);
		$this->assertSame('finalized', $plan['stateTranslations']['months.status']['closed']);
		$this->assertSame('open', $plan['stateTranslations']['months.status']['reopened']);
		$this->assertSame('sick_leave', $plan['stateTranslations']['absences.type']['sick']);
	}

	public function testRejectsUnknownTableTarget(): void
	{
		$this->expectPlanFailure(
			static function (array &$plan): void {
				$plan['sections'][3]['writes'][0]['target'] = 'at_deleted_table';
			},
			'unknown or retired table'
		);
	}

	public function testRejectsMissingScenarioSection(): void
	{
		$this->expectPlanFailure(
			static function (array &$plan): void {
				array_pop($plan['sections']);
			},
			'exactly once'
		);
	}

	public function testRejectsDuplicateWriteOrder(): void
	{
		$this->expectPlanFailure(
			static function (array &$plan): void {
				$plan['sections'][1]['order'] = $plan['sections'][0]['order'];
			},
			'duplicate sections or write orders'
		);
	}

	public function testRejectsStaleTranslation(): void
	{
		$this->expectPlanFailure(
			static function (array &$plan): void {
				$plan['stateTranslations']['months.status']['archived'] = 'finalized';
			},
			'incomplete or stale state translations'
		);
	}

	public function testRejectsInvalidPersistedStatus(): void
	{
		$this->expectPlanFailure(
			static function (array &$plan): void {
				$plan['stateTranslations']['months.status']['closed'] = 'closed';
			},
			'invalid persisted value'
		);
	}

	public function testRejectsNonPersistableCustomAbsenceCode(): void
	{
		$this->expectPlanFailure(
			static function (array &$plan): void {
				$plan['stateTranslations']['absences.type']['custom_bereavement'] = 'bereavement_local';
			},
			'invalid persisted value'
		);
	}

	public function testRejectsReopenedMonthWithoutRevisionEvidence(): void
	{
		$this->expectPlanFailure(
			static function (array &$plan): void {
				foreach ($plan['sections'] as &$section) {
					if ($section['scenario'] === 'months') {
						$section['writes'] = [['target' => 'at_month_closure', 'operation' => 'insert']];
					}
				}
			},
			'reopened-month revision evidence'
		);
	}

	private function expectPlanFailure(callable $mutate, string $message): void
	{
		$scenario = LegacyBaselineScenario::load(self::SCENARIO);
		$plan = json_decode(file_get_contents(self::PLAN), true, 512, JSON_THROW_ON_ERROR);
		$mutate($plan);
		$path = tempnam(sys_get_temp_dir(), 'azc-seed-plan-');
		file_put_contents($path, json_encode($plan));
		try {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage($message);
			LegacyBaselineSeedPlan::load($path, $scenario);
		} finally {
			unlink($path);
		}
	}
}
