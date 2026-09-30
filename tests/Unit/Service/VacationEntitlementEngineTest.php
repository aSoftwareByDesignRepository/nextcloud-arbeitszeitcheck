<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\ModelVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleModule;
use OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSet;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamVacationPolicyMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignment;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

/**
 * Core engine regression tests. The layered-resolution matrix (L0/L1/L2/L3 +
 * inherit + tie-breakers) is exercised separately by
 * {@see LayeredVacationEntitlementEngineTest} to keep this file focused on
 * the pre-existing per-mode arithmetic.
 */
class VacationEntitlementEngineTest extends TestCase {
	private function makeEngine(
		UserVacationPolicyAssignmentMapper $policyMapper,
		TariffRuleSetMapper $ruleSetMapper,
		TariffRuleModuleMapper $moduleMapper,
		UserWorkingTimeModelMapper $userModelMapper,
		WorkingTimeModelMapper $workingTimeModelMapper,
		UserSettingsMapper $userSettingsMapper,
	): VacationEntitlementEngine {
		$orgMapper = $this->createMock(OrgVacationDefaultMapper::class);
		$orgMapper->method('findActiveByDate')->willReturn(null);
		$modelDefaultMapper = $this->createMock(ModelVacationDefaultMapper::class);
		$modelDefaultMapper->method('findActiveByModelAndDate')->willReturn(null);
		$teamPolicyMapper = $this->createMock(TeamVacationPolicyMapper::class);
		$teamPolicyMapper->method('findActiveByTeamIds')->willReturn([]);
		$teamMapper = $this->createMock(TeamMapper::class);
		$teamMapper->method('getParentMap')->willReturn([]);
		$teamMemberMapper = $this->createMock(TeamMemberMapper::class);
		$teamMemberMapper->method('findByUserId')->willReturn([]);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('1');

		return new VacationEntitlementEngine(
			$policyMapper,
			$ruleSetMapper,
			$moduleMapper,
			$userModelMapper,
			$workingTimeModelMapper,
			$userSettingsMapper,
			$orgMapper,
			$modelDefaultMapper,
			$teamPolicyMapper,
			$teamMapper,
			$teamMemberMapper,
			$config,
		);
	}

	public function testManualFixedReturnsConfiguredDays(): void {
		$policyMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$ruleSetMapper = $this->createMock(TariffRuleSetMapper::class);
		$moduleMapper = $this->createMock(TariffRuleModuleMapper::class);
		$userModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);

		$policy = new UserVacationPolicyAssignment();
		$policy->setVacationMode(Constants::VACATION_MODE_MANUAL_FIXED);
		$policy->setManualDays(31.0);
		$policy->setEffectiveFrom(new \DateTime('2026-01-01'));

		$policyMapper->method('findCurrentByUser')->willReturn($policy);

		$engine = $this->makeEngine($policyMapper, $ruleSetMapper, $moduleMapper, $userModelMapper, $workingTimeModelMapper, $userSettingsMapper);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-01-01'));

		// L3 winners pass the resolved mode through as the response source, not
		// the umbrella 'layered' label.
		$this->assertSame('manual', $result['source']);
		$this->assertSame('L3', $result['matchedLayer']);
		$this->assertEquals(31.0, $result['days']);
		$this->assertSame(Constants::ENTITLEMENT_ALGORITHM_VERSION, $result['trace']['algorithm_version']);
		$this->assertFalse($result['trace']['inputs_redacted']);
	}

	public function testManualFixedClampsAndUnknownModeDegrades(): void {
		$mkPolicy = static function (string $mode, ?float $days): \OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignment {
			$p = new \OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignment();
			$p->setVacationMode($mode);
			$p->setManualDays($days);
			$p->setEffectiveFrom(new \DateTime('2026-01-01'));
			return $p;
		};

		// arm 1: manual days above ceiling -> clamped trace surfaces raw value
		$policyMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$policyMapper->method('findCurrentByUser')->willReturn($mkPolicy(Constants::VACATION_MODE_MANUAL_FIXED, 99999.0));
		$engine = $this->makeEngine(
			$policyMapper,
			$this->createMock(TariffRuleSetMapper::class),
			$this->createMock(TariffRuleModuleMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			$this->createMock(UserSettingsMapper::class),
		);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-01-01'));
		$this->assertLessThan(99999.0, $result['days']);

		// arm 2: unknown layer mode -> degraded zero-day result, no crash
		$policyMapper2 = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$policyMapper2->method('findCurrentByUser')->willReturn($mkPolicy('bogus_mode', null));
		$engine2 = $this->makeEngine(
			$policyMapper2,
			$this->createMock(TariffRuleSetMapper::class),
			$this->createMock(TariffRuleModuleMapper::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			$this->createMock(UserSettingsMapper::class),
		);
		$result2 = $engine2->computeForDate('u1', new \DateTimeImmutable('2026-01-01'));
		$this->assertSame(0.0, $result2['days']);
	}

	public function testTariffRuleBasedCalculatesFormulaAndRounding(): void {
		$policyMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$ruleSetMapper = $this->createMock(TariffRuleSetMapper::class);
		$moduleMapper = $this->createMock(TariffRuleModuleMapper::class);
		$userModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);

		$policy = new UserVacationPolicyAssignment();
		$policy->setVacationMode(Constants::VACATION_MODE_TARIFF_RULE_BASED);
		$policy->setTariffRuleSetId(12);
		$policy->setEffectiveFrom(new \DateTime('2026-01-01'));
		$policyMapper->method('findCurrentByUser')->willReturn($policy);

		$ruleSet = new TariffRuleSet();
		$ruleSet->setId(12);
		$ruleSet->setTariffCode('TVOD');
		$ruleSet->setVersion('2026-01');
		$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_ACTIVE);
		$ruleSetMapper->method('find')->with(12)->willReturn($ruleSet);

		$base = new TariffRuleModule();
		$base->setModuleType('base_formula');
		$base->setConfig(['reference_days' => 30, 'work_days_per_week' => 4, 'reference_week_days' => 5]);
		$add = new TariffRuleModule();
		$add->setModuleType('additional_entitlements');
		$add->setConfig(['days' => 1.5]);
		$round = new TariffRuleModule();
		$round->setModuleType('rounding_rule');
		$round->setConfig(['mode' => 'half_day']);
		$moduleMapper->method('findByRuleSetId')->with(12)->willReturn([$base, $add, $round]);

		$engine = $this->makeEngine($policyMapper, $ruleSetMapper, $moduleMapper, $userModelMapper, $workingTimeModelMapper, $userSettingsMapper);
		$result = $engine->computeForDate('u2', new \DateTimeImmutable('2026-06-15'));

		$this->assertSame('tariff', $result['source']);
		$this->assertEquals(25.5, $result['days']);
		$this->assertSame(12, $result['ruleSetId']);
		$this->assertSame('L3', $result['matchedLayer']);
	}

	public function testSimpleModelFormulaGoldenCases(): void {
		$policyMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$ruleSetMapper = $this->createMock(TariffRuleSetMapper::class);
		$moduleMapper = $this->createMock(TariffRuleModuleMapper::class);
		$userModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);

		$policy = new UserVacationPolicyAssignment();
		$policy->setVacationMode(Constants::VACATION_MODE_TARIFF_RULE_BASED);
		$policy->setTariffRuleSetId(42);
		$policy->setEffectiveFrom(new \DateTime('2026-01-01'));
		$policyMapper->method('findCurrentByUser')->willReturn($policy);

		$ruleSet = new TariffRuleSet();
		$ruleSet->setId(42);
		$ruleSet->setTariffCode('TVOD');
		$ruleSet->setVersion('2026-01');
		$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_ACTIVE);
		$ruleSetMapper->method('find')->willReturn($ruleSet);

		$makeBase = static function (float $workDays): TariffRuleModule {
			$m = new TariffRuleModule();
			$m->setModuleType('base_formula');
			$m->setConfig([
				'reference_days' => 30,
				'work_days_per_week' => $workDays,
				'reference_week_days' => 5,
			]);
			return $m;
		};

		$moduleMapper->method('findByRuleSetId')->willReturnOnConsecutiveCalls(
			[$makeBase(4.0)],
			[$makeBase(5.0)],
			[$makeBase(3.0)]
		);
		$engine = $this->makeEngine($policyMapper, $ruleSetMapper, $moduleMapper, $userModelMapper, $workingTimeModelMapper, $userSettingsMapper);
		$this->assertEquals(24.0, $engine->computeForDate('u', new \DateTimeImmutable('2026-01-01'))['days']);
		$this->assertEquals(30.0, $engine->computeForDate('u', new \DateTimeImmutable('2026-01-01'))['days']);
		$this->assertEquals(18.0, $engine->computeForDate('u', new \DateTimeImmutable('2026-01-01'))['days']);
	}

	public function testModelBasedSimpleUsesWorkingModelDaysPerWeek(): void {
		$policyMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$ruleSetMapper = $this->createMock(TariffRuleSetMapper::class);
		$moduleMapper = $this->createMock(TariffRuleModuleMapper::class);
		$userModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);

		$policy = new UserVacationPolicyAssignment();
		$policy->setVacationMode(Constants::VACATION_MODE_MODEL_BASED_SIMPLE);
		$policy->setEffectiveFrom(new \DateTime('2026-01-01'));
		$policyMapper->method('findCurrentByUser')->willReturn($policy);

		$userModelAssignment = new UserWorkingTimeModel();
		$userModelAssignment->setWorkingTimeModelId(7);
		$userModelMapper->method('findByUserAndDate')->willReturn($userModelAssignment);

		$workingTimeModel = new WorkingTimeModel();
		$workingTimeModel->setWorkDaysPerWeek(4.0);
		$workingTimeModelMapper->method('find')->with(7)->willReturn($workingTimeModel);

		$engine = $this->makeEngine($policyMapper, $ruleSetMapper, $moduleMapper, $userModelMapper, $workingTimeModelMapper, $userSettingsMapper);
		$result = $engine->computeForDate('u4', new \DateTimeImmutable('2026-06-01'));

		$this->assertSame('simple_model', $result['source']);
		$this->assertEquals(24.0, $result['days']);
	}

	/**
	 * GAP-01 regression: the canonical rounder must use HALF_UP at 2 decimals,
	 * never truncate to int, and clamp to [0, 366].
	 *
	 * @dataProvider roundDaysProvider
	 */
	public function testRoundDaysCanonicalPolicy(float $input, float $expected): void
	{
		$policyMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$ruleSetMapper = $this->createMock(TariffRuleSetMapper::class);
		$moduleMapper = $this->createMock(TariffRuleModuleMapper::class);
		$userModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$engine = $this->makeEngine($policyMapper, $ruleSetMapper, $moduleMapper, $userModelMapper, $workingTimeModelMapper, $userSettingsMapper);

		$this->assertSame($expected, $engine->roundDays($input));
	}

	/**
	 * @return list<array{float, float}>
	 */
	public function roundDaysProvider(): array
	{
		return [
			[27.5, 27.5],
			[27.555, 27.56],
			[27.554, 27.55],
			[27.005, 27.01],
			[0.0, 0.0],
			[-1.0, 0.0],
			[366.0, 366.0],
			[1000.0, 366.0],
			[NAN, 0.0],
			[INF, 0.0],
		];
	}

	public function testTariffProRataModesScaleEntitlement(): void {
		$policyMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$ruleSetMapper = $this->createMock(TariffRuleSetMapper::class);
		$moduleMapper = $this->createMock(TariffRuleModuleMapper::class);
		$userModelMapper = $this->createMock(UserWorkingTimeModelMapper::class);
		$workingTimeModelMapper = $this->createMock(WorkingTimeModelMapper::class);
		$userSettingsMapper = $this->createMock(UserSettingsMapper::class);

		$policy = new UserVacationPolicyAssignment();
		$policy->setVacationMode(Constants::VACATION_MODE_TARIFF_RULE_BASED);
		$policy->setTariffRuleSetId(12);
		$policy->setEffectiveFrom(new \DateTime('2026-01-01'));
		$policyMapper->method('findCurrentByUser')->willReturn($policy);

		$ruleSet = new TariffRuleSet();
		$ruleSet->setId(12);
		$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_ACTIVE);
		$ruleSetMapper->method('find')->willReturn($ruleSet);

		$base = new TariffRuleModule();
		$base->setModuleType('base_formula');
		$base->setConfig(['reference_days' => 30, 'work_days_per_week' => 5, 'reference_week_days' => 5]);
		$round = new TariffRuleModule();
		$round->setModuleType('rounding_rule');
		$round->setConfig(['mode' => 'none']);
		$proRata = new TariffRuleModule();
		$proRata->setModuleType('pro_rata_rule');
		$proRata->setConfig(['mode' => 'monthly']);
		$moduleMapper->method('findByRuleSetId')->willReturn([$base, $round, $proRata]);

		$engine = $this->makeEngine($policyMapper, $ruleSetMapper, $moduleMapper, $userModelMapper, $workingTimeModelMapper, $userSettingsMapper);

		// 30 days annual, monthly pro-rata at June 15 -> 30/12*6 = 15
		$result = $engine->computeForDate('u2', new \DateTimeImmutable('2026-06-15'));
		$this->assertEquals(15.0, $result['days']);
		$this->assertSame('monthly', $result['trace']['inputs']['pro_rata'] ?? $result['trace']['pro_rata'] ?? 'monthly');
	}

	public function testTariffProRataDailyMode(): void {
		$policyMapper = $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$ruleSetMapper = $this->createMock(TariffRuleSetMapper::class);
		$moduleMapper = $this->createMock(TariffRuleModuleMapper::class);

		$policy = new UserVacationPolicyAssignment();
		$policy->setVacationMode(Constants::VACATION_MODE_TARIFF_RULE_BASED);
		$policy->setTariffRuleSetId(12);
		$policy->setEffectiveFrom(new \DateTime('2026-01-01'));
		$policyMapper->method('findCurrentByUser')->willReturn($policy);

		$ruleSet = new TariffRuleSet();
		$ruleSet->setId(12);
		$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_ACTIVE);
		$ruleSetMapper->method('find')->willReturn($ruleSet);

		$base = new TariffRuleModule();
		$base->setModuleType('base_formula');
		$base->setConfig(['reference_days' => 36.5, 'work_days_per_week' => 5, 'reference_week_days' => 5]);
		$round = new TariffRuleModule();
		$round->setModuleType('rounding_rule');
		$round->setConfig(['mode' => 'none']);
		$proRata = new TariffRuleModule();
		$proRata->setModuleType('pro_rata_rule');
		$proRata->setConfig(['mode' => 'daily']);
		$moduleMapper->method('findByRuleSetId')->willReturn([$base, $round, $proRata]);

		$engine = $this->makeEngine(
			$policyMapper, $ruleSetMapper, $moduleMapper,
			$this->createMock(UserWorkingTimeModelMapper::class),
			$this->createMock(WorkingTimeModelMapper::class),
			$this->createMock(UserSettingsMapper::class),
		);

		// day 183 of 365 -> 36.5/365*183 = 18.3
		$result = $engine->computeForDate('u2', new \DateTimeImmutable('2026-07-02'));
		$this->assertEqualsWithDelta(18.3, $result['days'], 0.05);
	}
}