<?php

declare(strict_types=1);

/**
 * Layered resolution-chain regression tests for
 * {@see \OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine}.
 *
 * Covers the precedence matrix mandated by REQ-ENT-01..13 and the
 * tie-breakers documented in
 * `pm/app-ideas/arbeitszeitcheck/vacation-entitlement-hierarchy.md`:
 *
 *  - L3 explicit wins over every lower layer
 *  - L3 inherit defers to L2 → L1 → L0 → legacy
 *  - L2 deepest team in the subtree wins, then higher priority, then smaller id
 *  - L1 only picks up if the user has an active working-time-model assignment
 *  - L0 catches everything that nothing else matched
 *  - legacy fallback is emitted as "degraded" so admins can spot
 *    unconfigured tenants in the audit log
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\ModelVacationDefault;
use OCA\ArbeitszeitCheck\Db\ModelVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\OrgVacationDefault;
use OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMember;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamVacationPolicy;
use OCA\ArbeitszeitCheck\Db\TeamVacationPolicyMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignment;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleModule;
use OCA\ArbeitszeitCheck\Db\TariffRuleSet;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class LayeredVacationEntitlementEngineTest extends TestCase
{
	/**
	 * Build an engine with empty (un-stubbed) mocks. PHPUnit's `createMock`
	 * already provides "natural defaults" (null for nullable, empty array
	 * for array return types, empty string for string, etc.), so tests only
	 * need to stub the methods they care about — and stubbing on the same
	 * mock instance later in the test always overrides the natural default
	 * cleanly (no "stub-on-already-stubbed-method" ambiguity).
	 *
	 * Exception: `IConfig::getAppValue` returns `string` and defaults to ''
	 * which would make the layered flag check `'' !== '0'` evaluate to
	 * true, so layered remains enabled by default — that is the intended
	 * behaviour for these tests.
	 *
	 * @return array{0: VacationEntitlementEngine, 1: array<string, object>}
	 */
	private function makeEngine(array $overrides = []): array
	{
		$policyMapper = $overrides['policy'] ?? $this->createMock(UserVacationPolicyAssignmentMapper::class);
		$ruleSetMapper = $overrides['ruleSet'] ?? $this->createMock(TariffRuleSetMapper::class);
		$moduleMapper = $overrides['module'] ?? $this->createMock(TariffRuleModuleMapper::class);
		$userModelMapper = $overrides['userModel'] ?? $this->createMock(UserWorkingTimeModelMapper::class);
		$workingTimeModelMapper = $overrides['workingTimeModel'] ?? $this->createMock(WorkingTimeModelMapper::class);
		$userSettingsMapper = $overrides['userSettings'] ?? $this->createMock(UserSettingsMapper::class);
		$orgMapper = $overrides['org'] ?? $this->createMock(OrgVacationDefaultMapper::class);
		$modelDefaultMapper = $overrides['modelDefault'] ?? $this->createMock(ModelVacationDefaultMapper::class);
		$teamPolicyMapper = $overrides['teamPolicy'] ?? $this->createMock(TeamVacationPolicyMapper::class);
		$teamMapper = $overrides['team'] ?? $this->createMock(TeamMapper::class);
		$teamMemberMapper = $overrides['teamMember'] ?? $this->createMock(TeamMemberMapper::class);
		$config = $overrides['config'] ?? $this->createMock(IConfig::class);

		$engine = new VacationEntitlementEngine(
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
		return [$engine, [
			'policy' => $policyMapper,
			'ruleSet' => $ruleSetMapper,
			'module' => $moduleMapper,
			'userModel' => $userModelMapper,
			'workingTimeModel' => $workingTimeModelMapper,
			'userSettings' => $userSettingsMapper,
			'org' => $orgMapper,
			'modelDefault' => $modelDefaultMapper,
			'teamPolicy' => $teamPolicyMapper,
			'team' => $teamMapper,
			'teamMember' => $teamMemberMapper,
			'config' => $config,
		]];
	}

	private function makePolicy(bool $inherit, ?float $manualDays = 30.0, string $mode = Constants::VACATION_MODE_MANUAL_FIXED): UserVacationPolicyAssignment
	{
		$p = new UserVacationPolicyAssignment();
		$p->setId(123);
		$p->setVacationMode($mode);
		$p->setManualDays($manualDays);
		$p->setInheritLowerLayers($inherit);
		$p->setEffectiveFrom(new \DateTime('2026-01-01'));
		return $p;
	}

	private function makeOrgDefault(float $days): OrgVacationDefault
	{
		$o = new OrgVacationDefault();
		$o->setId(1);
		$o->setVacationMode(Constants::VACATION_MODE_MANUAL_FIXED);
		$o->setManualDays($days);
		$o->setEffectiveFrom(new \DateTime('2025-01-01'));
		return $o;
	}

	private function makeModelDefault(int $modelId, float $days): ModelVacationDefault
	{
		$m = new ModelVacationDefault();
		$m->setId(11);
		$m->setWorkingTimeModelId($modelId);
		$m->setVacationMode(Constants::VACATION_MODE_MANUAL_FIXED);
		$m->setManualDays($days);
		$m->setEffectiveFrom(new \DateTime('2025-01-01'));
		return $m;
	}

	private function makeTeamPolicy(int $id, int $teamId, float $days, int $priority = 0): TeamVacationPolicy
	{
		$t = new TeamVacationPolicy();
		$t->setId($id);
		$t->setTeamId($teamId);
		$t->setVacationMode(Constants::VACATION_MODE_MANUAL_FIXED);
		$t->setManualDays($days);
		$t->setPriority($priority);
		$t->setEffectiveFrom(new \DateTime('2025-01-01'));
		return $t;
	}

	private function makeMember(int $teamId): TeamMember
	{
		$m = new TeamMember();
		$m->setTeamId($teamId);
		$m->setUserId('u1');
		return $m;
	}

	/* ---------------------------------------------------------------- *
	 * Cross-layer precedence — 16-case matrix (REQ-ENT-01, REQ-ENT-02)
	 * ---------------------------------------------------------------- */

	public function testL3ExplicitWinsOverAllLowerLayers(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['policy']->method('findCurrentByUser')->willReturn($this->makePolicy(false, 33.0));
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(20.0));
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(33.0, $result['days']);
		$this->assertSame('L3', $result['matchedLayer']);
	}

	public function testL3InheritFallsThroughToOrgDefault(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['policy']->method('findCurrentByUser')->willReturn($this->makePolicy(true));
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(22.0));
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(22.0, $result['days']);
		$this->assertSame('L0', $result['matchedLayer']);
		$this->assertSame('layered', $result['source']);
	}

	public function testInheritSentinelModeBehavesLikeInheritFlag(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$policy = new UserVacationPolicyAssignment();
		$policy->setVacationMode(Constants::VACATION_MODE_INHERIT);
		$policy->setInheritLowerLayers(false);
		$policy->setEffectiveFrom(new \DateTime('2026-01-01'));
		$mocks['policy']->method('findCurrentByUser')->willReturn($policy);
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(24.0));
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(24.0, $result['days']);
		$this->assertSame('L0', $result['matchedLayer']);
	}

	public function testL2WinsOverL1AndL0WhenUserInTeamWithPolicy(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['policy']->method('findCurrentByUser')->willReturn(null);
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(5)]);
		$mocks['team']->method('getParentMap')->willReturn([5 => null]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([$this->makeTeamPolicy(101, 5, 28.0)]);
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(20.0));

		$asn = new UserWorkingTimeModel();
		$asn->setWorkingTimeModelId(7);
		$mocks['userModel']->method('findByUserAndDate')->willReturn($asn);
		$mocks['modelDefault']->method('findActiveByModelAndDate')->willReturn($this->makeModelDefault(7, 25.0));

		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(28.0, $result['days']);
		$this->assertSame('L2', $result['matchedLayer']);
		$this->assertSame(101, $result['trace']['winner']['policy_id']);
	}

	public function testL1WinsOverL0WhenNoTeamMatch(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['policy']->method('findCurrentByUser')->willReturn(null);

		$asn = new UserWorkingTimeModel();
		$asn->setWorkingTimeModelId(7);
		$mocks['userModel']->method('findByUserAndDate')->willReturn($asn);
		$mocks['modelDefault']->method('findActiveByModelAndDate')->willReturn($this->makeModelDefault(7, 26.5));

		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(20.0));

		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(26.5, $result['days']);
		$this->assertSame('L1', $result['matchedLayer']);
	}

	public function testL0AppliesWhenL1L2L3AllAbsent(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(27.0));
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(27.0, $result['days']);
		$this->assertSame('L0', $result['matchedLayer']);
	}

	public function testLegacyFallbackEmittedWhenNothingConfigured(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['userSettings']->method('getFloatSetting')->willReturn(25.0);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(25.0, $result['days']);
		$this->assertSame('legacy', $result['matchedLayer']);
		$this->assertTrue($result['trace']['degraded'] ?? false);
	}

	public function testFeatureFlagDisablesLayerResolution(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default) {
				if ($key === Constants::CONFIG_LAYERED_ENTITLEMENTS_ENABLED) {
					return '0';
				}
				return $default;
			});
		[$engine, $mocks] = $this->makeEngine(['config' => $config]);
		$mocks['org']->expects($this->never())->method('findActiveByDate');
		$mocks['userSettings']->method('getFloatSetting')->willReturn(25.0);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(25.0, $result['days']);
		$this->assertSame('legacy', $result['matchedLayer']);
	}

	/* ---------------------------------------------------------------- *
	 * L2 tie-breakers (REQ-ENT-08)
	 * ---------------------------------------------------------------- */

	public function testL2DeepestTeamSubtreeWins(): void
	{
		// Tree: 1 → 2 → 3 (user in 2 and 3; 3 is deeper).
		[$engine, $mocks] = $this->makeEngine();
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(2), $this->makeMember(3)]);
		$mocks['team']->method('getParentMap')->willReturn([1 => null, 2 => 1, 3 => 2]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([
			$this->makeTeamPolicy(11, 2, 25.0),
			$this->makeTeamPolicy(12, 3, 28.0),
		]);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(28.0, $result['days']);
		$this->assertSame(3, $result['trace']['winner']['team_id']);
	}

	public function testL2HigherPriorityWinsOnEqualDepth(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(2), $this->makeMember(4)]);
		// Both depth 1 under root 1.
		$mocks['team']->method('getParentMap')->willReturn([1 => null, 2 => 1, 4 => 1]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([
			$this->makeTeamPolicy(21, 2, 20.0, 0),
			$this->makeTeamPolicy(22, 4, 30.0, 5), // higher priority
		]);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(30.0, $result['days']);
		$this->assertSame(4, $result['trace']['winner']['team_id']);
	}

	public function testL2SmallerTeamIdWinsOnEqualDepthAndPriority(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(5), $this->makeMember(9)]);
		$mocks['team']->method('getParentMap')->willReturn([1 => null, 5 => 1, 9 => 1]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([
			$this->makeTeamPolicy(31, 9, 20.0, 0),
			$this->makeTeamPolicy(32, 5, 30.0, 0),
		]);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(30.0, $result['days']);
		$this->assertSame(5, $result['trace']['winner']['team_id']);
	}

	public function testL2CandidatesListedInTraceIncludesAllMatches(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(2), $this->makeMember(3)]);
		$mocks['team']->method('getParentMap')->willReturn([1 => null, 2 => 1, 3 => 2]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([
			$this->makeTeamPolicy(11, 2, 25.0),
			$this->makeTeamPolicy(12, 3, 28.0),
		]);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$l2 = null;
		foreach ($result['trace']['layers_evaluated'] as $row) {
			if (($row['layer'] ?? null) === 'L2') {
				$l2 = $row;
				break;
			}
		}
		$this->assertNotNull($l2);
		$this->assertCount(2, $l2['candidates']);
	}

	/* ---------------------------------------------------------------- *
	 * REQ-WF-05 — what-if hypothetical team membership simulation
	 * ---------------------------------------------------------------- */

	public function testHypotheticalTeamsOverrideRealMembershipForL2Resolution(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		// Real membership: user is in team 1 (no policy → falls through).
		// We don't even need findByUserId to be called since the override
		// short-circuits the real lookup.
		$mocks['teamMember']->expects($this->never())->method('findByUserId');
		$mocks['team']->method('getParentMap')->willReturn([1 => null, 5 => 1, 9 => 1]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')
			->willReturn([$this->makeTeamPolicy(101, 9, 32.0)]);
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(20.0));

		$engine->setHypotheticalTeams('u1', [9]);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));

		$this->assertSame(32.0, $result['days']);
		$this->assertSame('L2', $result['matchedLayer']);
		$this->assertTrue($result['trace']['winner']['hypothetical'] ?? false);
		$l2 = null;
		foreach ($result['trace']['layers_evaluated'] as $row) {
			if (($row['layer'] ?? null) === 'L2') {
				$l2 = $row;
				break;
			}
		}
		$this->assertNotNull($l2);
		$this->assertTrue($l2['hypothetical'] ?? false);
	}

	public function testClearHypotheticalTeamsRestoresRealMembership(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['teamMember']->method('findByUserId')->willReturn([]);
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(20.0));

		$engine->setHypotheticalTeams('u1', [9]);
		$engine->clearHypotheticalTeams('u1');
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));

		$this->assertSame('L0', $result['matchedLayer']);
		$this->assertSame(20.0, $result['days']);
	}

	public function testHypotheticalTeamsAreSanitised(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['team']->method('getParentMap')->willReturn([1 => null, 5 => 1]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')
			->with($this->callback(static function ($ids) {
				// 0, -1 and duplicates are filtered out before reaching the
				// policy mapper.
				return is_array($ids) && $ids === [5];
			}))
			->willReturn([$this->makeTeamPolicy(77, 5, 26.0)]);

		$engine->setHypotheticalTeams('u1', [5, 0, -1, 5]);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));

		$this->assertSame(26.0, $result['days']);
		$this->assertSame('L2', $result['matchedLayer']);
	}

	/* ---------------------------------------------------------------- *
	 * Edge cases (REQ-ENT-08..10)
	 * ---------------------------------------------------------------- */

	public function testZeroTeamsSkipsL2Gracefully(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(21.0));
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(21.0, $result['days']);
		$this->assertSame('L0', $result['matchedLayer']);
	}

	public function testEntitlementClampedAt366(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$over = $this->makeOrgDefault(99999.0);
		// Bypass entity validate, we want the raw payload through the engine.
		$mocks['org']->method('findActiveByDate')->willReturn($over);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(366.0, $result['days']);
	}

	public function testEntitlementClampedAtZeroForNegative(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$neg = $this->makeOrgDefault(-5.0);
		$mocks['org']->method('findActiveByDate')->willReturn($neg);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(0.0, $result['days']);
	}

	public function testTraceEnvelopeIncludesAlgorithmVersionAndAsOfDate(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(25.0));
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-04-15'));
		$this->assertSame(Constants::ENTITLEMENT_ALGORITHM_VERSION, $result['trace']['algorithm_version']);
		$this->assertSame('2026-04-15', $result['trace']['as_of_date']);
		$this->assertSame('L0', $result['trace']['matched_layer']);
		$this->assertArrayHasKey('winner', $result['trace']);
		$this->assertArrayHasKey('layers_evaluated', $result['trace']);
	}

	public function testRedactedTraceStripsRuleIdsAndDescriptions(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(2)]);
		$mocks['team']->method('getParentMap')->willReturn([1 => null, 2 => 1]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([$this->makeTeamPolicy(11, 2, 28.0)]);
		$full = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'))['trace'];

		$redacted = $engine->redactTraceForUser($full);

		$this->assertTrue($redacted['inputs_redacted']);
		$this->assertSame('L2', $redacted['matched_layer']);
		// No raw IDs allowed
		foreach ($redacted['layers_evaluated'] as $row) {
			$this->assertArrayNotHasKey('policy_id', $row);
			$this->assertArrayNotHasKey('team_id', $row);
			$this->assertArrayNotHasKey('rule_set', $row);
			$this->assertArrayNotHasKey('description', $row);
		}
	}

	/* ---------------------------------------------------------------- *
	 * Inherit-cascade: ensure inherit propagates through every layer
	 * ---------------------------------------------------------------- */

	public function testInheritWithL2MatchUsesL2(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['policy']->method('findCurrentByUser')->willReturn($this->makePolicy(true));
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(2)]);
		$mocks['team']->method('getParentMap')->willReturn([1 => null, 2 => 1]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([$this->makeTeamPolicy(7, 2, 27.5)]);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(27.5, $result['days']);
		$this->assertSame('L2', $result['matchedLayer']);
	}

	public function testInheritWithoutAnyLayerFallsThroughToLegacy(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['policy']->method('findCurrentByUser')->willReturn($this->makePolicy(true));
		$mocks['userSettings']->method('getFloatSetting')->willReturn(20.0);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(20.0, $result['days']);
		$this->assertSame('legacy', $result['matchedLayer']);
	}

	/* ---------------------------------------------------------------- *
	 * Simulation path
	 * ---------------------------------------------------------------- */

	public function testComputeForPolicyInheritDelegatesToChain(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$inherit = $this->makePolicy(true);
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(23.0));
		$result = $engine->computeForPolicy('u1', $inherit, new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(23.0, $result['days']);
		$this->assertSame('L0', $result['matchedLayer']);
	}

	public function testComputeForPolicyExplicitOverridesLowerLayers(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$explicit = $this->makePolicy(false, 18.0);
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(99.0));
		$result = $engine->computeForPolicy('u1', $explicit, new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(18.0, $result['days']);
		$this->assertSame('L3', $result['matchedLayer']);
	}

	/**
	 * Simulator inherit-path must mirror {@see VacationEntitlementEngine::computeForDate}
	 * when layered resolution is disabled: L3 inherit → legacy only, never L0/L1/L2.
	 */
	public function testComputeForPolicyInheritWhenLayeredDisabledUsesLegacy(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default) {
				if ($key === Constants::CONFIG_LAYERED_ENTITLEMENTS_ENABLED) {
					return '0';
				}
				return $default;
			});
		[$engine, $mocks] = $this->makeEngine(['config' => $config]);
		$inherit = $this->makePolicy(true);
		$mocks['org']->expects($this->never())->method('findActiveByDate');
		$mocks['teamPolicy']->expects($this->never())->method('findActiveByTeamIds');
		$mocks['userSettings']->method('getFloatSetting')->willReturn(21.0);
		$result = $engine->computeForPolicy('u1', $inherit, new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(21.0, $result['days']);
		$this->assertSame('legacy', $result['matchedLayer']);
		$legacyStep = null;
		foreach ($result['trace']['layers_evaluated'] as $row) {
			if (($row['layer'] ?? null) === 'legacy' && ($row['reason'] ?? null) === 'layered_disabled') {
				$legacyStep = $row;
				break;
			}
		}
		$this->assertNotNull($legacyStep, 'trace must record layered_disabled legacy step');
	}

	/**
	 * REQ-ENT-13 / EC-11: inherit simulation with an L2 win must surface
	 * partial_history when as_of_date is strictly before today (membership
	 * table is current-state only).
	 */
	public function testComputeForPolicyInheritSurfacesPartialHistoryForPastAsOfDate(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$inherit = $this->makePolicy(true);
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(2)]);
		$mocks['team']->method('getParentMap')->willReturn([2 => null]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([$this->makeTeamPolicy(7, 2, 26.0)]);
		$past = new \DateTimeImmutable('2020-06-15');
		$result = $engine->computeForPolicy('u1', $inherit, $past);
		$this->assertSame('L2', $result['matchedLayer']);
		$this->assertTrue($result['trace']['winner']['partial_history'] ?? false);
		$l2Row = null;
		foreach ($result['trace']['layers_evaluated'] as $row) {
			if (($row['layer'] ?? null) === 'L2' && !empty($row['matched'])) {
				$l2Row = $row;
				break;
			}
		}
		$this->assertNotNull($l2Row);
		$this->assertTrue($l2Row['partial_history'] ?? false);
	}

	/* ---------------------------------------------------------------- *
	 * Additional matrix: ensure trace `winner` keys are present per layer
	 * ---------------------------------------------------------------- */

	public function testWinnerCarriesPolicyIdForL3(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['policy']->method('findCurrentByUser')->willReturn($this->makePolicy(false, 26.0));
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame('L3', $result['trace']['winner']['layer']);
	}

	public function testWinnerCarriesDefaultIdForL0(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(25.0));
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(1, $result['trace']['winner']['default_id']);
	}

	public function testWinnerCarriesModelIdForL1(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$asn = new UserWorkingTimeModel();
		$asn->setWorkingTimeModelId(9);
		$mocks['userModel']->method('findByUserAndDate')->willReturn($asn);
		$mocks['modelDefault']->method('findActiveByModelAndDate')->willReturn($this->makeModelDefault(9, 23.0));
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(9, $result['trace']['winner']['working_time_model_id']);
	}

	public function testWinnerCarriesTeamIdAndPolicyIdForL2(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(3)]);
		$mocks['team']->method('getParentMap')->willReturn([3 => null]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([$this->makeTeamPolicy(55, 3, 24.0)]);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(3, $result['trace']['winner']['team_id']);
		$this->assertSame(55, $result['trace']['winner']['policy_id']);
	}

	/* ---------------------------------------------------------------- *
	 * .5 boundary regression (GAP-01 / REQ-ENT-12)
	 * ---------------------------------------------------------------- */

	public function testHalfDayBoundariesPreservedAcrossLayers(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(27.5));
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(27.5, $result['days']);
		// Trace days must match exactly — no int truncation anywhere.
		$this->assertSame(27.5, $result['trace']['winner']['days']);
	}

	/* ---------------------------------------------------------------- *
	 * Degraded-state trace flags
	 * (REQ-ENT-10, EC-04, EC-05, EC-08, EC-11)
	 * ---------------------------------------------------------------- */

	public function testL0CollisionSurfacesDegradedFlagInTrace(): void
	{
		// REQ-ENT-10: two L0 rows active on the same date → fail closed
		// to the deterministic winner from `findActiveByDate` *and* mark
		// the trace as degraded so an admin can repair the data.
		[$engine, $mocks] = $this->makeEngine();
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(20.0));
		$mocks['org']->method('countActiveByDate')->willReturn(2);

		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));

		$this->assertSame('L0', $result['matchedLayer']);
		$this->assertSame(20.0, $result['days']);
		$this->assertTrue($result['trace']['degraded'] ?? false, 'trace.degraded must be true on L0 collision');
		$this->assertTrue($result['trace']['winner']['degraded_org_default_collision'] ?? false);
		$l0Row = null;
		foreach ($result['trace']['layers_evaluated'] as $row) {
			if (($row['layer'] ?? null) === 'L0') {
				$l0Row = $row;
				break;
			}
		}
		$this->assertNotNull($l0Row);
		$this->assertTrue($l0Row['degraded_org_default_collision'] ?? false);
	}

	public function testL0NoCollisionLeavesTraceClean(): void
	{
		// Counter-test: a single active row must NOT raise the degraded flag.
		[$engine, $mocks] = $this->makeEngine();
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(25.0));
		$mocks['org']->method('countActiveByDate')->willReturn(1);

		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame('L0', $result['matchedLayer']);
		$this->assertArrayNotHasKey('degraded', $result['trace']);
		$this->assertArrayNotHasKey('degraded_org_default_collision', $result['trace']['winner']);
	}

	public function testModelLookupFailedFlagWhenWorkingTimeModelGone(): void
	{
		// EC-04: user has an L3 model-based-simple policy and a
		// UserWorkingTimeModel assignment, but the referenced
		// WorkingTimeModel row was deleted. The engine must NOT silently
		// fall back to the 5-day reference week — it must keep computing
		// (so the user-facing surface stays alive) but raise a
		// `degraded='model_lookup_failed'` flag.
		[$engine, $mocks] = $this->makeEngine();
		$policy = $this->makePolicy(false, null, Constants::VACATION_MODE_MODEL_BASED_SIMPLE);
		$mocks['policy']->method('findCurrentByUser')->willReturn($policy);

		$asn = new UserWorkingTimeModel();
		$asn->setWorkingTimeModelId(99);
		$mocks['userModel']->method('findByUserAndDate')->willReturn($asn);
		$mocks['workingTimeModel']
			->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('gone'));

		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame('L3', $result['matchedLayer']);
		$this->assertSame('model_lookup_failed', $result['trace']['inner']['degraded'] ?? null);
	}

	public function testTariffRuleSetStatusWarningWhenNotActive(): void
	{
		// EC-05: a retired/draft tariff rule set is still consultable via
		// L3 (e.g. payroll's looking at an old simulation). The engine
		// must surface `rule_set_status_warning` so the admin UI can
		// disambiguate "this isn't your *current* tariff".
		[$engine, $mocks] = $this->makeEngine();
		$policy = $this->makePolicy(false, null, Constants::VACATION_MODE_TARIFF_RULE_BASED);
		$policy->setTariffRuleSetId(77);
		$mocks['policy']->method('findCurrentByUser')->willReturn($policy);

		$ruleSet = new \OCA\ArbeitszeitCheck\Db\TariffRuleSet();
		$ruleSet->setId(77);
		$ruleSet->setTariffCode('IGM-2020');
		$ruleSet->setVersion(1);
		$ruleSet->setStatus(Constants::TARIFF_RULE_SET_STATUS_RETIRED);
		$mocks['ruleSet']->method('find')->willReturn($ruleSet);
		$mocks['module']->method('findByRuleSetId')->willReturn([]);

		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame('L3', $result['matchedLayer']);
		$this->assertSame(
			Constants::TARIFF_RULE_SET_STATUS_RETIRED,
			$result['trace']['inner']['rule_set_status_warning'] ?? null,
		);
	}

	public function testManualDaysClampedRaisesClampedFlag(): void
	{
		// EC-08: a misconfigured policy with `manualDays = 9999` must
		// land at the 366 invariant cap *and* explicitly surface that
		// fact via `clamped=true` + `raw_manual_days`, otherwise
		// auditors lose the only signal that the rule was misconfigured.
		[$engine, $mocks] = $this->makeEngine();
		$policy = $this->makePolicy(false, 9999.0);
		$mocks['policy']->method('findCurrentByUser')->willReturn($policy);

		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(366.0, $result['days']);
		$this->assertTrue($result['trace']['inner']['clamped'] ?? false);
		$this->assertSame(9999.0, $result['trace']['inner']['raw_manual_days'] ?? null);
	}

	public function testManualDaysInRangeDoesNotRaiseClampedFlag(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$policy = $this->makePolicy(false, 30.0);
		$mocks['policy']->method('findCurrentByUser')->willReturn($policy);

		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(30.0, $result['days']);
		$this->assertArrayNotHasKey('clamped', $result['trace']['inner']);
	}

	public function testL0ManualClampedRaisesClampedFlagOnLayerRow(): void
	{
		// Same invariant for L0/L1/L2 — the layer-row path goes through
		// `resolveFromLayerRow`, not `resolveFromPolicy`, so we exercise
		// it explicitly.
		[$engine, $mocks] = $this->makeEngine();
		$over = $this->makeOrgDefault(-50.0);
		$mocks['org']->method('findActiveByDate')->willReturn($over);
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(0.0, $result['days']);
		$this->assertTrue($result['trace']['inner']['clamped'] ?? false);
		$this->assertSame(-50.0, $result['trace']['inner']['raw_manual_days'] ?? null);
	}

	public function testL2PartialHistoryFlagForBackdatedResolution(): void
	{
		// EC-11 / REQ-ENT-13: the membership table only reflects current
		// state, so a back-dated L2 resolution must be flagged
		// `partial_history=true` instead of silently pretending the
		// membership reflected history.
		[$engine, $mocks] = $this->makeEngine();
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(2)]);
		$mocks['team']->method('getParentMap')->willReturn([1 => null, 2 => 1]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([$this->makeTeamPolicy(7, 2, 25.0)]);

		// A date in the distant past relative to test "today".
		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2020-01-15'));
		$this->assertSame('L2', $result['matchedLayer']);
		$this->assertTrue($result['trace']['winner']['partial_history'] ?? false);
		$l2 = null;
		foreach ($result['trace']['layers_evaluated'] as $row) {
			if (($row['layer'] ?? null) === 'L2') {
				$l2 = $row;
				break;
			}
		}
		$this->assertNotNull($l2);
		$this->assertTrue($l2['partial_history'] ?? false);
	}

	public function testL2NoPartialHistoryForFutureResolution(): void
	{
		// Negative control: forward-dated resolution must NOT carry the
		// partial_history flag because the membership table is current.
		[$engine, $mocks] = $this->makeEngine();
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(2)]);
		$mocks['team']->method('getParentMap')->willReturn([1 => null, 2 => 1]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([$this->makeTeamPolicy(7, 2, 25.0)]);

		$future = (new \DateTimeImmutable('today'))->modify('+30 days');
		$result = $engine->computeForDate('u1', $future);
		$this->assertSame('L2', $result['matchedLayer']);
		$this->assertArrayNotHasKey('partial_history', $result['trace']['winner']);
	}

	/* ---------------------------------------------------------------- *
	 * Redacted-trace flag pass-through (REQ-SEC-05)
	 * ---------------------------------------------------------------- */

	public function testRedactedTracePassesThroughDegradedFlag(): void
	{
		// Top-level `degraded` survives redaction so the employee gets a
		// "please contact HR" hint, but no internal reason is disclosed.
		[$engine, $mocks] = $this->makeEngine();
		$mocks['userSettings']->method('getFloatSetting')->willReturn(25.0);
		$full = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'))['trace'];
		$this->assertTrue($full['degraded'] ?? false);

		$redacted = $engine->redactTraceForUser($full);
		$this->assertTrue($redacted['degraded'] ?? false);
		$this->assertArrayNotHasKey('inner', $redacted);
	}

	public function testRedactedTracePassesThroughClampedFlag(): void
	{
		// `clamped` survives redaction, but `raw_manual_days` / `raw_computed_days`
		// must NOT leak — they would disclose the misconfigured raw value.
		[$engine, $mocks] = $this->makeEngine();
		$mocks['policy']->method('findCurrentByUser')->willReturn($this->makePolicy(false, 9999.0));
		$full = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'))['trace'];

		$redacted = $engine->redactTraceForUser($full);
		$this->assertTrue($redacted['clamped'] ?? false);
		$this->assertArrayNotHasKey('raw_manual_days', $redacted);
		$this->assertArrayNotHasKey('raw_computed_days', $redacted);
		$this->assertArrayNotHasKey('inner', $redacted);
	}

	public function testRedactedTracePassesThroughPartialHistoryPerLayer(): void
	{
		// L2 `partial_history` flag is generic enough to keep — it tells
		// the user "this is best effort for past dates" without naming
		// teams or memberships.
		[$engine, $mocks] = $this->makeEngine();
		$mocks['teamMember']->method('findByUserId')->willReturn([$this->makeMember(2)]);
		$mocks['team']->method('getParentMap')->willReturn([1 => null, 2 => 1]);
		$mocks['teamPolicy']->method('findActiveByTeamIds')->willReturn([$this->makeTeamPolicy(7, 2, 25.0)]);
		$full = $engine->computeForDate('u1', new \DateTimeImmutable('2020-01-15'))['trace'];

		$redacted = $engine->redactTraceForUser($full);
		$l2 = null;
		foreach ($redacted['layers_evaluated'] as $row) {
			if (($row['layer'] ?? null) === 'L2') {
				$l2 = $row;
				break;
			}
		}
		$this->assertNotNull($l2);
		$this->assertTrue($l2['partial_history'] ?? false);
		// No internal IDs in the redacted L2 row
		$this->assertArrayNotHasKey('team_id', $l2);
		$this->assertArrayNotHasKey('policy_id', $l2);
		$this->assertArrayNotHasKey('candidates', $l2);
	}

	public function testRedactedTraceDoesNotLeakOrgCollisionFlag(): void
	{
		// `degraded_org_default_collision` is admin-only — it would
		// confuse end users and reveal a data quality issue they cannot
		// act on. The top-level `degraded` flag is the only signal they
		// see.
		[$engine, $mocks] = $this->makeEngine();
		$mocks['org']->method('findActiveByDate')->willReturn($this->makeOrgDefault(20.0));
		$mocks['org']->method('countActiveByDate')->willReturn(2);
		$full = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'))['trace'];

		$redacted = $engine->redactTraceForUser($full);
		$this->assertTrue($redacted['degraded'] ?? false);
		foreach ($redacted['layers_evaluated'] as $row) {
			$this->assertArrayNotHasKey('degraded_org_default_collision', $row);
		}
	}

	public function testL0ModelBasedSimpleModeResolvesViaSimpleModel(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$o = new OrgVacationDefault();
		$o->setId(1);
		$o->setVacationMode(Constants::VACATION_MODE_MODEL_BASED_SIMPLE);
		$o->setEffectiveFrom(new \DateTime('2025-01-01'));
		$mocks['org']->method('findActiveByDate')->willReturn($o);

		$assignment = new UserWorkingTimeModel();
		$assignment->setUserId('u1');
		$assignment->setWorkingTimeModelId(7);
		$mocks['userModel']->method('findByUserAndDate')->willReturn($assignment);
		$model = new WorkingTimeModel();
		$model->setWorkDaysPerWeek(3.0);
		$mocks['workingTimeModel']->method('find')->willReturn($model);

		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		// 30 reference days * (3/5) = 18
		$this->assertSame(18.0, $result['days']);
		$this->assertSame('L0', $result['matchedLayer']);
	}

	public function testL0TariffRuleBasedModeResolvesViaRuleSet(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$o = new OrgVacationDefault();
		$o->setId(1);
		$o->setVacationMode(Constants::VACATION_MODE_TARIFF_RULE_BASED);
		$o->setTariffRuleSetId(42);
		$o->setEffectiveFrom(new \DateTime('2025-01-01'));
		$mocks['org']->method('findActiveByDate')->willReturn($o);

		$mocks['ruleSet']->method('find')->with(42)->willReturn(new TariffRuleSet());
		$mod = new TariffRuleModule();
		$mod->setModuleType('additional_entitlements');
		$mod->setConfig(['days' => 5.0]);
		$mocks['module']->method('findByRuleSetId')->with(42)->willReturn([$mod]);

		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		// base 30 (5/5) + 5 additional = 35
		$this->assertSame(35.0, $result['days']);
		$this->assertSame('L0', $result['matchedLayer']);
	}

	public function testL0UnknownVacationModeDegradesToZero(): void
	{
		[$engine, $mocks] = $this->makeEngine();
		$o = new OrgVacationDefault();
		$o->setId(1);
		$o->setVacationMode('bogus_mode');
		$o->setEffectiveFrom(new \DateTime('2025-01-01'));
		$mocks['org']->method('findActiveByDate')->willReturn($o);

		$result = $engine->computeForDate('u1', new \DateTimeImmutable('2026-06-01'));
		$this->assertSame(0.0, $result['days']);
		$this->assertSame('L0', $result['matchedLayer']);
		$layerTrace = json_encode($result['trace']);
		$this->assertStringContainsString('unknown_mode', $layerTrace);
	}
}
