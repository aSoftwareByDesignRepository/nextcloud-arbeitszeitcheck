<?php

declare(strict_types=1);

/**
 * Layered vacation entitlement resolution engine.
 *
 * Resolves an annual vacation entitlement (in days, rounded to 2 decimals) for
 * a given `(userId, asOfDate)` by walking a deterministic precedence chain:
 *
 *     L3 individual policy (non-inherit) ▸ L2 team policy ▸ L1 model default
 *                                       ▸ L0 organisation default
 *                                       ▸ legacy fallback
 *
 * Each layer can be configured to deliver a number via three shared "modes":
 *
 *  - `manual_fixed`        : raw days from `manual_days`
 *  - `model_based_simple`  : `30 × (work_days_per_week / 5)` using the user's
 *                            currently active working-time model on `asOfDate`
 *  - `tariff_rule_based`   : delegated to {@see TariffRuleSetMapper} + modules
 *
 * Output contract (consumed by {@see VacationAllocationService},
 * {@see EntitlementSnapshotService} and the admin/employee surfaces):
 *
 *     [
 *       'days' => float,                  // rounded to 2dp; 0 ≤ days ≤ 366
 *       'source' => 'manual'|'manual_exception'|'simple_model'|'tariff'|'layered',
 *       'ruleSetId' => int|null,
 *       'matchedLayer' => 'L0'|'L1'|'L2'|'L3'|'legacy',
 *       'trace' => [                      // algorithm_version = 1
 *         'algorithm_version' => int,
 *         'as_of_date' => 'Y-m-d',
 *         'layers_evaluated' => list<array>,
 *         'winner' => array,
 *         'inputs_redacted' => bool,
 *         ...
 *       ],
 *     ]
 *
 * Numeric invariant (`REQ-ENT-07`): `days` is finite, clamped to `[0, 366]`,
 * rounded to 2 decimal places at the API boundary via
 * {@see self::roundDays()}.
 *
 * @copyright Copyright (c) 2026 Alexander Mäule
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\ModelVacationDefault;
use OCA\ArbeitszeitCheck\Db\ModelVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\OrgVacationDefault;
use OCA\ArbeitszeitCheck\Db\OrgVacationDefaultMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleModule;
use OCA\ArbeitszeitCheck\Db\TariffRuleModuleMapper;
use OCA\ArbeitszeitCheck\Db\TariffRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamVacationPolicy;
use OCA\ArbeitszeitCheck\Db\TeamVacationPolicyMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignment;
use OCA\ArbeitszeitCheck\Db\UserVacationPolicyAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\TariffRuleSetUnavailableException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;

class VacationEntitlementEngine {
	/**
	 * Per-request memoisation key for team membership lookups. The engine is
	 * frequently called several times per request from
	 * {@see VacationAllocationService} (read-only stats, persisted
	 * computations, simulation). Calling out to TeamMemberMapper +
	 * TeamMapper::getParentMap on each call would N+1 against the DB; we
	 * cache for the lifetime of this *engine instance* (request scope) only.
	 *
	 * @var array<string, array{teamIds: list<int>, parentMap: array<int, int|null>}>
	 */
	private array $teamContextCache = [];

	/**
	 * REQ-WF-05 — Hypothetical team membership overrides for the simulator.
	 *
	 * A short-lived map keyed by user id whose values supplant the real
	 * `TeamMemberMapper::findByUserId()` lookup for that user, allowing the
	 * admin "what-if" simulator to ask "what would the entitlement be if this
	 * person were a member of team X?" without persisting any membership.
	 *
	 * Always cleared by the simulator after a single `computeFor*` call via
	 * {@see self::clearHypotheticalTeams()}. The engine is request-scoped so
	 * a leak here would only affect the current request, but we still take
	 * the belt-and-braces approach because background jobs reuse the same
	 * service instance.
	 *
	 * @var array<string, list<int>>
	 */
	private array $hypotheticalTeams = [];

	public function __construct(
		private UserVacationPolicyAssignmentMapper $policyMapper,
		private TariffRuleSetMapper $ruleSetMapper,
		private TariffRuleModuleMapper $ruleModuleMapper,
		private UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private WorkingTimeModelMapper $workingTimeModelMapper,
		private UserSettingsMapper $userSettingsMapper,
		private OrgVacationDefaultMapper $orgDefaultMapper,
		private ModelVacationDefaultMapper $modelDefaultMapper,
		private TeamVacationPolicyMapper $teamPolicyMapper,
		private TeamMapper $teamMapper,
		private TeamMemberMapper $teamMemberMapper,
		private IConfig $config,
		private ?VacationUnitService $vacationUnitService = null,
		private ?\OCA\ArbeitszeitCheck\Policy\PolicyScopeResolver $scopeResolver = null,
	) {
		$this->scopeResolver ??= new \OCA\ArbeitszeitCheck\Policy\PolicyScopeResolver();
	}

	/**
	 * Whether the layered resolution chain is active. Defaults to ON. Tenants
	 * can disable it by setting `layered_entitlements_enabled = 0` in app
	 * config; the engine then routes through the legacy "L3 only" path.
	 */
	public function isLayeredEnabled(): bool
	{
		return $this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_LAYERED_ENTITLEMENTS_ENABLED,
			'1'
		) !== '0';
	}

	/**
	 * Resolve entitlement for `(userId, asOfDate)`. See class docblock for
	 * output contract.
	 *
	 * @return array{days: float, source: string, ruleSetId: int|null, matchedLayer: string, trace: array}
	 */
	public function computeForDate(string $userId, \DateTimeInterface $asOfDate): array {
		$asOfDateOnly = new \DateTimeImmutable($asOfDate->format('Y-m-d'));
		$policy = $this->policyMapper->findCurrentByUser($userId, $asOfDateOnly);

		$layered = $this->isLayeredEnabled();
		$layersEvaluated = [];
		// True once any higher layer matched a policy row it could not deliver
		// (tariff mode with a NULL/dangling rule-set reference). Feeds the
		// top-level `degraded` flag on whichever lower layer ends up winning.
		$undeliverableLayers = false;

		// L3: explicit individual policy that does NOT defer to lower layers.
		if ($policy !== null && !$policy->isInherit()) {
			try {
				$resolved = $this->resolveFromPolicy($userId, $policy, $asOfDateOnly);
				$layersEvaluated[] = [
					'layer' => 'L3',
					'matched' => true,
					'policy_id' => $policy->getId(),
					'mode' => $policy->getVacationMode(),
					'days' => $resolved['days'],
				];
				return $this->finalise($resolved, 'L3', $asOfDateOnly, $layersEvaluated, false);
			} catch (TariffRuleSetUnavailableException $e) {
				// The L3 row demands tariff resolution but its rule set is
				// gone. Degrade to the lower layers like an `inherit` row
				// instead of fataling every surface that resolves this user.
				$undeliverableLayers = true;
				$this->logUndeliverableTariff('L3', $userId, $e);
				$layersEvaluated[] = [
					'layer' => 'L3',
					'matched' => false,
					'reason' => 'tariff_rule_set_unavailable',
					'policy_id' => $policy->getId(),
					'mode' => $policy->getVacationMode(),
					'rule_set_id' => $e->getRuleSetId(),
				];
			}
		}

		if ($policy !== null && $policy->isInherit()) {
			$layersEvaluated[] = [
				'layer' => 'L3',
				'matched' => false,
				'reason' => 'inherit',
				'policy_id' => $policy->getId(),
			];
		} elseif ($policy === null) {
			// An undeliverable non-inherit policy already produced its own
			// L3 row above — do not double-record it as 'no_policy'.
			$layersEvaluated[] = [
				'layer' => 'L3',
				'matched' => false,
				'reason' => 'no_policy',
			];
		}

		if (!$layered) {
			$legacy = $this->legacyFallback($userId, $asOfDateOnly);
			$layersEvaluated[] = [
				'layer' => 'legacy',
				'matched' => true,
				'reason' => 'layered_disabled',
				'days' => $legacy['days'],
			];
			return $this->finalise($legacy, 'legacy', $asOfDateOnly, $layersEvaluated, $undeliverableLayers);
		}

		// L2: team-attached policy. Tie-break by depth → priority → id.
		// `partial_history` is set when the caller asked for a back-dated
		// resolution: the membership table only reflects *current* state,
		// so an L2 match for a past `as_of_date` is best-effort. We surface
		// this in the trace (REQ-ENT-13 / EC-11) instead of silently
		// pretending the membership reflected history.
		$partialHistory = $this->asOfPredatesMembershipHistory($asOfDateOnly);
		$teamResolution = $this->resolveTeamLayer($userId, $asOfDateOnly);
		$teamLayerUndeliverable = false;
		if ($teamResolution !== null && !empty($teamResolution['undeliverable'])) {
			$undeliverableLayers = true;
			$teamLayerUndeliverable = true;
			$layersEvaluated[] = [
				'layer' => 'L2',
				'matched' => false,
				'reason' => 'tariff_rule_set_unavailable',
				'team_id' => $teamResolution['team_id'],
				'policy_id' => $teamResolution['policy_id'],
				'candidates' => $teamResolution['candidates'],
			];
			$teamResolution = null;
		}
		if ($teamResolution !== null) {
			$row = [
				'layer' => 'L2',
				'matched' => true,
				'team_id' => $teamResolution['team_id'],
				'team_depth' => $teamResolution['team_depth'],
				'priority' => $teamResolution['priority'],
				'policy_id' => $teamResolution['policy_id'],
				'mode' => $teamResolution['resolved']['source'],
				'days' => $teamResolution['resolved']['days'],
				'candidates' => $teamResolution['candidates'],
			];
			if ($partialHistory) {
				$row['partial_history'] = true;
			}
			if (!empty($teamResolution['hypothetical'])) {
				$row['hypothetical'] = true;
			}
			$layersEvaluated[] = $row;
			$winnerExtra = [
				'team_id' => $teamResolution['team_id'],
				'policy_id' => $teamResolution['policy_id'],
			];
			if ($partialHistory) {
				$winnerExtra['partial_history'] = true;
			}
			if (!empty($teamResolution['hypothetical'])) {
				$winnerExtra['hypothetical'] = true;
			}
			return $this->finalise($teamResolution['resolved'], 'L2', $asOfDateOnly, $layersEvaluated, $undeliverableLayers, $winnerExtra);
		}
		// REQ-WF-05: when an L2 lookup was attempted with a hypothetical
		// team override and produced no match, surface that explicitly in
		// the trace so the simulator UI can disambiguate "no team match"
		// from "team match suppressed by hypothetical override". An
		// undeliverable winner was already recorded with its own reason —
		// do not mislabel it 'no_team_match'.
		if (!$teamLayerUndeliverable) {
			$noMatchRow = ['layer' => 'L2', 'matched' => false, 'reason' => 'no_team_match'];
			if (array_key_exists($userId, $this->hypotheticalTeams)) {
				$noMatchRow['hypothetical'] = true;
			}
			$layersEvaluated[] = $noMatchRow;
		}

		// L1: model-attached default for user's active model on date.
		$modelResolution = $this->resolveModelLayer($userId, $asOfDateOnly);
		if ($modelResolution !== null && !empty($modelResolution['undeliverable'])) {
			$undeliverableLayers = true;
			$layersEvaluated[] = [
				'layer' => 'L1',
				'matched' => false,
				'reason' => 'tariff_rule_set_unavailable',
				'working_time_model_id' => $modelResolution['working_time_model_id'],
				'default_id' => $modelResolution['default_id'],
			];
			$modelResolution = null;
		}
		if ($modelResolution !== null) {
			$layersEvaluated[] = [
				'layer' => 'L1',
				'matched' => true,
				'working_time_model_id' => $modelResolution['working_time_model_id'],
				'default_id' => $modelResolution['default_id'],
				'mode' => $modelResolution['resolved']['source'],
				'days' => $modelResolution['resolved']['days'],
			];
			return $this->finalise($modelResolution['resolved'], 'L1', $asOfDateOnly, $layersEvaluated, $undeliverableLayers, [
				'working_time_model_id' => $modelResolution['working_time_model_id'],
				'default_id' => $modelResolution['default_id'],
			]);
		}
		$layersEvaluated[] = ['layer' => 'L1', 'matched' => false, 'reason' => 'no_model_default'];

		// L0: organisation default.
		$orgResolution = $this->resolveOrgLayer($userId, $asOfDateOnly);
		if ($orgResolution !== null && !empty($orgResolution['undeliverable'])) {
			$undeliverableLayers = true;
			$layersEvaluated[] = [
				'layer' => 'L0',
				'matched' => false,
				'reason' => 'tariff_rule_set_unavailable',
				'default_id' => $orgResolution['default_id'],
			];
			$orgResolution = null;
		}
		if ($orgResolution !== null) {
			$row = [
				'layer' => 'L0',
				'matched' => true,
				'default_id' => $orgResolution['default_id'],
				'mode' => $orgResolution['resolved']['source'],
				'days' => $orgResolution['resolved']['days'],
			];
			if ($orgResolution['collision']) {
				$row['degraded_org_default_collision'] = true;
			}
			$layersEvaluated[] = $row;
			$winnerExtra = ['default_id' => $orgResolution['default_id']];
			if ($orgResolution['collision']) {
				$winnerExtra['degraded_org_default_collision'] = true;
			}
			return $this->finalise($orgResolution['resolved'], 'L0', $asOfDateOnly, $layersEvaluated, $orgResolution['collision'] || $undeliverableLayers, $winnerExtra);
		}
		$layersEvaluated[] = ['layer' => 'L0', 'matched' => false, 'reason' => 'no_org_default'];

		// Safe default: legacy fallback. Marked degraded in trace.
		$legacy = $this->legacyFallback($userId, $asOfDateOnly);
		$layersEvaluated[] = [
			'layer' => 'legacy',
			'matched' => true,
			'reason' => 'safe_default',
			'days' => $legacy['days'],
		];
		return $this->finalise($legacy, 'legacy', $asOfDateOnly, $layersEvaluated, true);
	}

	/**
	 * Backwards-compatible single-policy preview used by the admin
	 * "What if I apply this draft?" simulation. The caller pins an
	 * unsaved {@see UserVacationPolicyAssignment} and the engine returns
	 * the resolved entitlement *as if* this were the current L3 row.
	 *
	 * When the assignment {@see UserVacationPolicyAssignment::isInherit()} is
	 * true, behaviour matches {@see self::computeForDate()} for the same
	 * user and date — including the layered feature flag (L2/L1/L0 are
	 * skipped entirely when {@see self::isLayeredEnabled()} is false) and
	 * the `partial_history` hint on L2 for back-dated `as_of_date` values.
	 *
	 * @return array{days: float, source: string, ruleSetId: int|null, matchedLayer: string, trace: array}
	 */
	public function computeForPolicy(string $userId, UserVacationPolicyAssignment $policy, \DateTimeInterface $asOfDate): array {
		$asOfDateOnly = new \DateTimeImmutable($asOfDate->format('Y-m-d'));
		$undeliverableLayers = false;
		if (!$policy->isInherit()) {
			try {
				$resolved = $this->resolveFromPolicy($userId, $policy, $asOfDateOnly);
				return $this->finalise($resolved, 'L3', $asOfDateOnly, [
					['layer' => 'L3', 'matched' => true, 'mode' => $policy->getVacationMode(), 'days' => $resolved['days'], 'simulated' => true],
				], false);
			} catch (TariffRuleSetUnavailableException $e) {
				// Simulated tariff draft references a deleted rule set — walk
				// the lower layers like an inherit draft instead of fataling.
				$undeliverableLayers = true;
				$this->logUndeliverableTariff('L3', $userId, $e);
			}
		}
		// Inherit-simulation (or an undeliverable draft): continue down the
		// chain just like computeForDate would.
		$layersEvaluated = [
			['layer' => 'L3', 'matched' => false, 'reason' => $policy->isInherit() ? 'inherit' : 'tariff_rule_set_unavailable', 'simulated' => true],
		];
		if (!$this->isLayeredEnabled()) {
			$legacy = $this->legacyFallback($userId, $asOfDateOnly);
			$layersEvaluated[] = [
				'layer' => 'legacy',
				'matched' => true,
				'reason' => 'layered_disabled',
				'days' => $legacy['days'],
			];
			return $this->finalise($legacy, 'legacy', $asOfDateOnly, $layersEvaluated, $undeliverableLayers);
		}
		$partialHistory = $this->asOfPredatesMembershipHistory($asOfDateOnly);
		$teamResolution = $this->resolveTeamLayer($userId, $asOfDateOnly);
		if ($teamResolution !== null && !empty($teamResolution['undeliverable'])) {
			$undeliverableLayers = true;
			$teamResolution = null;
		}
		if ($teamResolution !== null) {
			$row = [
				'layer' => 'L2',
				'matched' => true,
				'team_id' => $teamResolution['team_id'],
				'team_depth' => $teamResolution['team_depth'],
				'priority' => $teamResolution['priority'],
				'policy_id' => $teamResolution['policy_id'],
				'mode' => $teamResolution['resolved']['source'],
				'days' => $teamResolution['resolved']['days'],
				'candidates' => $teamResolution['candidates'],
			];
			if ($partialHistory) {
				$row['partial_history'] = true;
			}
			if (!empty($teamResolution['hypothetical'])) {
				$row['hypothetical'] = true;
			}
			$layersEvaluated[] = $row;
			$winnerExtra = [];
			if ($partialHistory) {
				$winnerExtra['partial_history'] = true;
			}
			if (!empty($teamResolution['hypothetical'])) {
				$winnerExtra['hypothetical'] = true;
			}
			return $this->finalise($teamResolution['resolved'], 'L2', $asOfDateOnly, $layersEvaluated, $undeliverableLayers, $winnerExtra);
		}
		$modelResolution = $this->resolveModelLayer($userId, $asOfDateOnly);
		if ($modelResolution !== null && !empty($modelResolution['undeliverable'])) {
			$undeliverableLayers = true;
			$modelResolution = null;
		}
		if ($modelResolution !== null) {
			$layersEvaluated[] = [
				'layer' => 'L1',
				'matched' => true,
				'working_time_model_id' => $modelResolution['working_time_model_id'],
				'days' => $modelResolution['resolved']['days'],
			];
			return $this->finalise($modelResolution['resolved'], 'L1', $asOfDateOnly, $layersEvaluated, $undeliverableLayers);
		}
		$orgResolution = $this->resolveOrgLayer($userId, $asOfDateOnly);
		if ($orgResolution !== null && !empty($orgResolution['undeliverable'])) {
			$undeliverableLayers = true;
			$orgResolution = null;
		}
		if ($orgResolution !== null) {
			$layersEvaluated[] = [
				'layer' => 'L0',
				'matched' => true,
				'days' => $orgResolution['resolved']['days'],
				'simulated' => true,
			] + ($orgResolution['collision'] ? ['degraded_org_default_collision' => true] : []);
			return $this->finalise($orgResolution['resolved'], 'L0', $asOfDateOnly, $layersEvaluated, $orgResolution['collision'] || $undeliverableLayers);
		}
		$legacy = $this->legacyFallback($userId, $asOfDateOnly);
		$layersEvaluated[] = ['layer' => 'legacy', 'matched' => true, 'days' => $legacy['days']];
		return $this->finalise($legacy, 'legacy', $asOfDateOnly, $layersEvaluated, true);
	}

	/**
	 * Build a redacted copy of a trace for self-service / employee-facing
	 * surfaces. Internal rule IDs, audit metadata, and HR descriptions
	 * (which may name other teams) are stripped; the **numeric** result
	 * and the chosen layer label remain so the employee can answer
	 * "how was this computed?" without leaking colleagues' team policy
	 * names. Trace already produced by {@see self::computeForDate()} can
	 * be passed in directly.
	 *
	 * @param array<string, mixed> $trace
	 * @return array<string, mixed>
	 */
	public function redactTraceForUser(array $trace): array
	{
		$redacted = [
			'algorithm_version' => $trace['algorithm_version'] ?? Constants::ENTITLEMENT_ALGORITHM_VERSION,
			'as_of_date' => $trace['as_of_date'] ?? null,
			'matched_layer' => $trace['matched_layer'] ?? ($trace['winner']['layer'] ?? 'unknown'),
			'inputs_redacted' => true,
		];
		// Surface a short, sanitised reason per layer (no IDs, no other-cohort
		// labels). We also pass through three employee-relevant flags that
		// are *generic* (no internal identifiers leak): `partial_history`
		// tells the user "your team membership for this past date is best
		// effort", and `degraded_org_default_collision` is intentionally
		// **not** included — that's an admin/data-quality concern, surfacing
		// it would only confuse end users.
		$publicLayers = [];
		foreach (($trace['layers_evaluated'] ?? []) as $row) {
			$publicRow = [
				'layer' => $row['layer'] ?? null,
				'matched' => (bool)($row['matched'] ?? false),
				'mode' => $row['mode'] ?? null,
			];
			if (!empty($row['partial_history'])) {
				$publicRow['partial_history'] = true;
			}
			$publicLayers[] = $publicRow;
		}
		$redacted['layers_evaluated'] = $publicLayers;
		$redacted['result_days'] = $trace['result_days'] ?? null;

		// Top-level "your calc is in a degraded state, please contact HR"
		// signal. We deliberately do NOT explain the specific reason here;
		// the admin trace has the full detail.
		if (!empty($trace['degraded'])) {
			$redacted['degraded'] = true;
		}

		// Inner flags worth surfacing without disclosing rule IDs:
		//  - `clamped`: tells the user their number was capped at the
		//    0..366 invariant. The *raw* value is stripped (no payroll
		//    misconfiguration details).
		$inner = $trace['inner'] ?? null;
		if (is_array($inner)) {
			if (!empty($inner['clamped'])) {
				$redacted['clamped'] = true;
			}
		}

		return $redacted;
	}

	/**
	 * Resolve the numeric entitlement using a concrete L3 policy.
	 *
	 * @return array{days: float, source: string, ruleSetId: int|null, trace: array}
	 */
	private function resolveFromPolicy(string $userId, UserVacationPolicyAssignment $policy, \DateTimeImmutable $asOfDate): array
	{
		$mode = $policy->getVacationMode();
		if ($mode === Constants::VACATION_MODE_MANUAL_FIXED || $mode === Constants::VACATION_MODE_MANUAL_EXCEPTION) {
			$rawDays = (float)($policy->getManualDays() ?? 0.0);
			$days = $this->roundDays($rawDays);
			$trace = [
				'mode' => $mode,
				'manual_days' => $days,
				'override_reason' => $policy->getOverrideReason(),
			];
			if ($this->wasClamped($rawDays, $days)) {
				$trace['clamped'] = true;
				$trace['raw_manual_days'] = round($rawDays, 4);
			}
			return [
				'days' => $days,
				'source' => $mode === Constants::VACATION_MODE_MANUAL_EXCEPTION ? 'manual_exception' : 'manual',
				'ruleSetId' => $policy->getTariffRuleSetId(),
				'trace' => $trace,
			];
		}
		if ($mode === Constants::VACATION_MODE_MODEL_BASED_SIMPLE) {
			return $this->resolveSimpleModel($userId, $asOfDate, $mode);
		}
		if ($mode === Constants::VACATION_MODE_TARIFF_RULE_BASED) {
			return $this->resolveTariff($userId, $policy->getTariffRuleSetId(), $asOfDate, $mode);
		}
		// Defensive: unknown mode → fall back to legacy default. Logs but
		// does not throw so the user-facing surfaces stay alive.
		\OCP\Log\logger('arbeitszeitcheck')->error(
			'VacationEntitlementEngine: unknown vacation_mode ' . var_export($mode, true) . ' for user ' . $userId,
			['app' => 'arbeitszeitcheck']
		);
		return $this->legacyFallback($userId, $asOfDate);
	}

	/**
	 * @return array{days: float, source: string, ruleSetId: int|null, trace: array}
	 */
	private function resolveSimpleModel(string $userId, \DateTimeImmutable $asOfDate, string $mode): array
	{
		$referenceDays = 30.0;
		$referenceWeekDays = 5.0;
		$workDaysPerWeek = 5.0;
		$degraded = null;
		$modelAssignment = $this->userWorkingTimeModelMapper->findByUserAndDate($userId, new \DateTime($asOfDate->format('Y-m-d')));
		if ($modelAssignment !== null) {
			try {
				$workingTimeModel = $this->workingTimeModelMapper->find($modelAssignment->getWorkingTimeModelId());
				$workDaysPerWeek = max(1.0, min(7.0, round((float)$workingTimeModel->getWorkDaysPerWeek(), 2)));
			} catch (\Throwable) {
				// EC-04: model deleted but the user still has an assignment
				// row referencing it. Falling back to the 5-day reference
				// would silently change a part-timer's entitlement, so we
				// flag this in the trace and log a warning. Admin UIs can
				// surface the flag instead of pretending nothing happened.
				$degraded = 'model_lookup_failed';
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					sprintf(
						'VacationEntitlementEngine: working time model #%d not found for user %s — falling back to %d work days/week (EC-04).',
						(int)$modelAssignment->getWorkingTimeModelId(),
						$userId,
						(int)$workDaysPerWeek,
					),
					['app' => 'arbeitszeitcheck']
				);
			}
		}
		$days = $this->roundDays($referenceDays * ($workDaysPerWeek / $referenceWeekDays));
		$trace = [
			'mode' => $mode,
			'formula' => 'reference_days * (work_days_per_week / reference_week_days)',
			'inputs' => [
				'reference_days' => $referenceDays,
				'work_days_per_week' => $workDaysPerWeek,
				'reference_week_days' => $referenceWeekDays,
			],
		];
		if ($degraded !== null) {
			$trace['degraded'] = $degraded;
		}
		return [
			'days' => $days,
			'source' => 'simple_model',
			'ruleSetId' => null,
			'trace' => $trace,
		];
	}

	/**
	 * @return array{days: float, source: string, ruleSetId: int|null, trace: array}
	 * @throws TariffRuleSetUnavailableException when the policy's rule set
	 *         reference is NULL or dangling (no FK on the column — a deleted
	 *         rule set leaves a live reference behind). Callers must catch it
	 *         and degrade to the next layer; letting it escape fatals every
	 *         surface that resolves this user's entitlement (admin user
	 *         detail, absence preview, allocation writes).
	 */
	private function resolveTariff(string $userId, ?int $ruleSetId, \DateTimeImmutable $asOfDate, string $mode): array
	{
		if ($ruleSetId === null) {
			throw new TariffRuleSetUnavailableException(null, 'Tariff mode requires an assigned rule set');
		}
		try {
			$ruleSet = $this->ruleSetMapper->find($ruleSetId);
		} catch (DoesNotExistException $e) {
			throw new TariffRuleSetUnavailableException($ruleSetId, 'Tariff rule set no longer exists', $e);
		}
		$modules = $this->ruleModuleMapper->findByRuleSetId($ruleSetId);
		$baseDays = 30.0;
		$referenceWeekDays = 5.0;
		$workDaysPerWeek = 5.0;
		$additional = 0.0;
		$deductions = 0.0;
		$rounding = 'commercial';
		$proRata = 'none';

		foreach ($modules as $module) {
			/** @var TariffRuleModule $module */
			$config = $module->getConfig();
			switch ($module->getModuleType()) {
				case 'base_formula':
					$baseDays = (float)($config['reference_days'] ?? $baseDays);
					$referenceWeekDays = (float)($config['reference_week_days'] ?? $referenceWeekDays);
					$workDaysPerWeek = (float)($config['work_days_per_week'] ?? $workDaysPerWeek);
					break;
				case 'additional_entitlements':
					$additional += (float)($config['days'] ?? 0.0);
					break;
				case 'deductions':
					$deductions += max(0.0, (float)($config['days'] ?? 0.0));
					break;
				case 'rounding_rule':
					$rounding = (string)($config['mode'] ?? $rounding);
					break;
				case 'pro_rata_rule':
					$proRata = \OCA\ArbeitszeitCheck\Support\TariffRuleModuleValidator::normalizeProRataMode(
						(string)($config['mode'] ?? $proRata),
					);
					break;
			}
		}

		if ($workDaysPerWeek <= 0.0) {
			$modelAssignment = $this->userWorkingTimeModelMapper->findByUserAndDate($userId, new \DateTime($asOfDate->format('Y-m-d')));
			if ($modelAssignment !== null) {
				try {
					$workingTimeModel = $this->workingTimeModelMapper->find($modelAssignment->getWorkingTimeModelId());
					$workDaysPerWeek = (float)$workingTimeModel->getWorkDaysPerWeek();
				} catch (\Throwable) {
					$workDaysPerWeek = 5.0;
				}
			}
		}

		$workDaysPerWeek = max(1.0, min(7.0, $workDaysPerWeek));
		$referenceWeekDays = max(1.0, min(7.0, $referenceWeekDays));
		$ceiling = $this->entitlementCeiling();
		$baseDays = max(0.0, min($ceiling, $baseDays));

		$computedRaw = $baseDays * ($workDaysPerWeek / max(1.0, $referenceWeekDays));
		$computedRaw += $additional;
		$computedRaw -= $deductions;
		$computed = max(0.0, min($ceiling, $computedRaw));
		$wasClamped = abs($computedRaw - $computed) > 0.0001;
		$computed = $this->applyRounding($computed, $rounding);
		$computed = $this->applyProRata($computed, $proRata, $asOfDate);
		$finalDays = $this->roundDays($computed);

		$trace = [
			'mode' => $mode,
			'rule_set' => [
				'id' => $ruleSet->getId(),
				'tariff_code' => $ruleSet->getTariffCode(),
				'version' => $ruleSet->getVersion(),
				'status' => $ruleSet->getStatus(),
			],
			'formula' => 'base + additional - deductions',
			'inputs' => [
				'base_reference_days' => $baseDays,
				'work_days_per_week' => $workDaysPerWeek,
				'reference_week_days' => $referenceWeekDays,
				'additional_days' => $additional,
				'deduction_days' => $deductions,
				'rounding' => $rounding,
				'pro_rata' => $proRata,
				'as_of_date' => $asOfDate->format('Y-m-d'),
			],
			'result_days' => $finalDays,
		];
		// EC-08: surface clamping so payroll auditors can spot rule-set
		// misconfigurations that would otherwise be invisible behind the
		// 0..366 invariant.
		if ($wasClamped) {
			$trace['clamped'] = true;
			$trace['raw_computed_days'] = round($computedRaw, 4);
		}
		// EC-05: rule set is no longer active (retired/draft) — surface
		// in the trace so the admin simulator and audit log can flag it.
		if ($ruleSet->getStatus() !== Constants::TARIFF_RULE_SET_STATUS_ACTIVE) {
			$trace['rule_set_status_warning'] = $ruleSet->getStatus();
		}
		return [
			'days' => $finalDays,
			'source' => 'tariff',
			'ruleSetId' => $ruleSet->getId(),
			'trace' => $trace,
		];
	}

	/**
	 * A layer matched a tariff-mode row whose rule set is unavailable.
	 * Logged at error level — this is a payroll-relevant misconfiguration
	 * the admin must repair (re-assign a live rule set or switch the layer
	 * to manual/simple). The layer is skipped and resolution continues
	 * down the chain; the trace carries `tariff_rule_set_unavailable`.
	 */
	private function logUndeliverableTariff(string $layer, string $userId, TariffRuleSetUnavailableException $e): void
	{
		\OCP\Log\logger('arbeitszeitcheck')->error(
			sprintf(
				'VacationEntitlementEngine: %s row for user %s selects tariff mode but rule set %s is unavailable — degrading to lower layers.',
				$layer,
				$userId,
				$e->getRuleSetId() === null ? '(null)' : '#' . $e->getRuleSetId(),
			),
			['app' => 'arbeitszeitcheck', 'exception' => $e]
		);
	}

	/**
	 * Resolve via L2. Returns null when the user is in no team with an
	 * active policy on `asOfDate`.
	 *
	 * @return array{
	 *   team_id: int,
	 *   team_depth: int,
	 *   priority: int,
	 *   policy_id: int,
	 *   resolved: array{days: float, source: string, ruleSetId: int|null, trace: array},
	 *   candidates: list<array{team_id: int, team_depth: int, priority: int, policy_id: int}>
	 * }|null
	 */
	private function resolveTeamLayer(string $userId, \DateTimeImmutable $asOfDate): ?array
	{
		$ctx = $this->getTeamContext($userId);
		if ($ctx['teamIds'] === []) {
			return null;
		}
		$policies = $this->teamPolicyMapper->findActiveByTeamIds($ctx['teamIds'], $asOfDate);
		if ($policies === []) {
			return null;
		}
		$hypothetical = !empty($ctx['hypothetical']);
		// Winner selection is delegated to the shared policy kernel so the
		// deepest-subtree → highest-priority → smallest-team_id ordering has
		// exactly one implementation. The candidate id carries team_id because
		// the stable final tie-break is `team_id ASC`. `teamDepths` carries the
		// authoritative depth — including the -1 sentinel for teams absent from
		// parentMap (stale memberships of deleted teams) — and doubles as the
		// TEAM-scope membership check for the resolver.
		$teamDepths = [];
		$candidates = [];
		foreach ($policies as $p) {
			$teamId = (int)$p->getTeamId();
			$depth = $this->computeTeamDepth($teamId, $ctx['parentMap']);
			$teamDepths[$teamId] = $depth;
			$effectiveFrom = $p->getEffectiveFrom();
			if ($effectiveFrom === null) {
				// Mirrors the mapper's `effective_from <= asOf` predicate: a
				// NULL start can never be active, so the row is not an eligible
				// candidate (unreachable via findActiveByTeamIds, but mocks
				// can inject it).
				continue;
			}
			$candidates[] = new \OCA\ArbeitszeitCheck\Policy\PolicyCandidate(
				$teamId,
				\OCA\ArbeitszeitCheck\Policy\PolicyScope::TEAM,
				(string)$teamId,
				new \OCA\ArbeitszeitCheck\Policy\EffectiveRange(
					$this->toImmutable($effectiveFrom),
					$p->getEffectiveTo() !== null ? $this->toImmutable($p->getEffectiveTo()) : null,
				),
				(int)$p->getPriority(),
				max(0, $depth),
				$p,
			);
		}
		$resolution = $this->scopeResolver->resolve(
			new \OCA\ArbeitszeitCheck\Policy\PolicyScopeContext($userId, null, [], $teamDepths),
			$asOfDate,
			$candidates,
		);
		$winnerCandidate = $resolution->winner;
		if ($winnerCandidate === null) {
			return null;
		}
		/** @var TeamVacationPolicy $winningPolicy */
		$winningPolicy = $winnerCandidate->value;
		$candList = array_map(static function (\OCA\ArbeitszeitCheck\Policy\PolicyCandidate $c) use ($teamDepths): array {
			/** @var TeamVacationPolicy $p */
			$p = $c->value;
			return [
				'team_id' => (int)$c->scopeId,
				'team_depth' => $teamDepths[$c->scopeId] ?? $c->depth,
				'priority' => $c->priority,
				'policy_id' => (int)$p->getId(),
			];
		}, $resolution->eligible);
		try {
			$resolved = $this->resolveFromLayerRow($userId, $winningPolicy->getVacationMode(), $winningPolicy->getManualDays(), $winningPolicy->getTariffRuleSetId(), $asOfDate);
		} catch (TariffRuleSetUnavailableException $e) {
			$this->logUndeliverableTariff('L2', $userId, $e);
			return [
				'undeliverable' => true,
				'team_id' => (int)$winnerCandidate->scopeId,
				'team_depth' => $teamDepths[$winnerCandidate->scopeId] ?? $winnerCandidate->depth,
				'priority' => $winnerCandidate->priority,
				'policy_id' => (int)$winningPolicy->getId(),
				'candidates' => $candList,
				'hypothetical' => $hypothetical,
			];
		}
		return [
			'team_id' => (int)$winnerCandidate->scopeId,
			'team_depth' => $teamDepths[$winnerCandidate->scopeId] ?? $winnerCandidate->depth,
			'priority' => $winnerCandidate->priority,
			'policy_id' => (int)$winningPolicy->getId(),
			'resolved' => $resolved,
			'candidates' => $candList,
			'hypothetical' => $hypothetical,
		];
	}

	/**
	 * @return array{working_time_model_id: int, default_id: int, resolved: array{days: float, source: string, ruleSetId: int|null, trace: array}}|null
	 */
	private function resolveModelLayer(string $userId, \DateTimeImmutable $asOfDate): ?array
	{
		$assignment = $this->userWorkingTimeModelMapper->findByUserAndDate($userId, new \DateTime($asOfDate->format('Y-m-d')));
		if ($assignment === null) {
			return null;
		}
		$modelId = (int)$assignment->getWorkingTimeModelId();
		$default = $this->modelDefaultMapper->findActiveByModelAndDate($modelId, $asOfDate);
		if ($default === null) {
			return null;
		}
		try {
			$resolved = $this->resolveFromLayerRow($userId, $default->getVacationMode(), $default->getManualDays(), $default->getTariffRuleSetId(), $asOfDate);
		} catch (TariffRuleSetUnavailableException $e) {
			$this->logUndeliverableTariff('L1', $userId, $e);
			return [
				'undeliverable' => true,
				'working_time_model_id' => $modelId,
				'default_id' => (int)$default->getId(),
			];
		}
		return [
			'working_time_model_id' => $modelId,
			'default_id' => (int)$default->getId(),
			'resolved' => $resolved,
		];
	}

	/**
	 * @return array{default_id: int, collision: bool, resolved: array{days: float, source: string, ruleSetId: int|null, trace: array}}|null
	 */
	private function resolveOrgLayer(string $userId, \DateTimeImmutable $asOfDate): ?array
	{
		$default = $this->orgDefaultMapper->findActiveByDate($asOfDate);
		if ($default === null) {
			return null;
		}
		// REQ-ENT-10: organisation defaults are supposed to be a single
		// active row per validity slot. If two rows are simultaneously
		// active we fail closed to the deterministic "latest effective_from
		// wins" pick from the mapper, but emit a critical log line *and*
		// surface a `degraded_org_default_collision` flag in the trace so
		// admins can repair the data instead of silently shipping the
		// wrong number.
		$collision = false;
		try {
			$activeCount = $this->orgDefaultMapper->countActiveByDate($asOfDate);
			if ($activeCount > 1) {
				$collision = true;
				\OCP\Log\logger('arbeitszeitcheck')->critical(
					sprintf(
						'VacationEntitlementEngine: %d active L0 organisation vacation defaults on %s — failing closed to row #%d (REQ-ENT-10).',
						$activeCount,
						$asOfDate->format('Y-m-d'),
						(int)$default->getId(),
					),
					['app' => 'arbeitszeitcheck']
				);
			}
		} catch (\Throwable) {
			// countActiveByDate is best-effort: never block resolution on diag.
		}
		try {
			$resolved = $this->resolveFromLayerRow($userId, $default->getVacationMode(), $default->getManualDays(), $default->getTariffRuleSetId(), $asOfDate);
		} catch (TariffRuleSetUnavailableException $e) {
			$this->logUndeliverableTariff('L0', $userId, $e);
			return [
				'undeliverable' => true,
				'default_id' => (int)$default->getId(),
				'collision' => $collision,
			];
		}
		return [
			'default_id' => (int)$default->getId(),
			'collision' => $collision,
			'resolved' => $resolved,
		];
	}

	/**
	 * Numeric resolution for one layer row (L0/L1/L2) given the row's mode +
	 * payload. Falls back to a clamped manual zero on internal failure rather
	 * than throwing so resolution down the chain still gets a chance to
	 * continue from the next layer.
	 *
	 * @return array{days: float, source: string, ruleSetId: int|null, trace: array}
	 */
	private function resolveFromLayerRow(string $userId, string $mode, ?float $manualDays, ?int $tariffRuleSetId, \DateTimeImmutable $asOfDate): array
	{
		if ($mode === Constants::VACATION_MODE_MANUAL_FIXED) {
			$rawDays = (float)($manualDays ?? 0.0);
			$days = $this->roundDays($rawDays);
			$trace = [
				'mode' => $mode,
				'manual_days' => $days,
			];
			if ($this->wasClamped($rawDays, $days)) {
				$trace['clamped'] = true;
				$trace['raw_manual_days'] = round($rawDays, 4);
			}
			return [
				'days' => $days,
				'source' => 'manual',
				'ruleSetId' => null,
				'trace' => $trace,
			];
		}
		if ($mode === Constants::VACATION_MODE_MODEL_BASED_SIMPLE) {
			return $this->resolveSimpleModel($userId, $asOfDate, $mode);
		}
		if ($mode === Constants::VACATION_MODE_TARIFF_RULE_BASED) {
			return $this->resolveTariff($userId, $tariffRuleSetId, $asOfDate, $mode);
		}
		// Unknown layer mode: clamp to zero with a degraded trace so the
		// admin surface can surface the misconfiguration without crashing.
		\OCP\Log\logger('arbeitszeitcheck')->error(
			'VacationEntitlementEngine: unknown layer vacation_mode ' . var_export($mode, true),
			['app' => 'arbeitszeitcheck']
		);
		return [
			'days' => 0.0,
			'source' => 'manual',
			'ruleSetId' => null,
			'trace' => [
				'mode' => $mode,
				'manual_days' => 0.0,
				'degraded' => 'unknown_mode',
			],
		];
	}

	/**
	 * Pre-layered-spec behaviour, kept as the deterministic "safe default"
	 * for tenants that have not yet configured any layer. Currently:
	 *  1. user's working-time model assignment `vacation_days_per_year`
	 *  2. user setting `vacation_days_per_year`
	 *  3. {@see Constants::DEFAULT_VACATION_DAYS_PER_YEAR}
	 *
	 * @return array{days: float, source: string, ruleSetId: int|null, trace: array}
	 */
	private function legacyFallback(string $userId, \DateTimeImmutable $asOfDate): array
	{
		$days = $this->resolveLegacyManualEntitlement($userId);
		return [
			'days' => $this->roundDays((float)$days),
			'source' => 'manual',
			'ruleSetId' => null,
			'trace' => [
				'mode' => 'legacy_default',
				'legacy_days' => (float)$days,
			],
		];
	}

	private function entitlementCeiling(): float
	{
		return $this->vacationUnitService?->isHoursMode() === true ? 4000.0 : 366.0;
	}

	private function resolveLegacyManualEntitlement(string $userId): float {
		$ceiling = $this->entitlementCeiling();
		$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
		if ($currentModel !== null && $currentModel->getVacationDaysPerYear() !== null) {
			$raw = (float)$currentModel->getVacationDaysPerYear();
			return max(0.0, min($ceiling, $raw));
		}
		$fromSettings = $this->userSettingsMapper->getFloatSetting(
			$userId,
			'vacation_days_per_year',
			(float)Constants::DEFAULT_VACATION_DAYS_PER_YEAR
		);
		return max(0.0, min($ceiling, $fromSettings));
	}

	/**
	 * Build the final result shape, attach the trace v1 envelope.
	 *
	 * @param array{days: float, source: string, ruleSetId: int|null, trace: array} $resolved
	 * @param list<array> $layersEvaluated
	 * @return array{days: float, source: string, ruleSetId: int|null, matchedLayer: string, trace: array}
	 */
	private function finalise(
		array $resolved,
		string $matchedLayer,
		\DateTimeImmutable $asOfDate,
		array $layersEvaluated,
		bool $degraded,
		array $extraWinnerFields = []
	): array {
		$days = $this->roundDays((float)$resolved['days']);
		$winner = array_merge([
			'layer' => $matchedLayer,
			'mode' => $resolved['source'],
			'days' => $days,
			'rule_set_id' => $resolved['ruleSetId'],
		], $extraWinnerFields);

		$trace = [
			'algorithm_version' => Constants::ENTITLEMENT_ALGORITHM_VERSION,
			'as_of_date' => $asOfDate->format('Y-m-d'),
			'matched_layer' => $matchedLayer,
			'layers_evaluated' => $layersEvaluated,
			'winner' => $winner,
			'inputs_redacted' => false,
			'result_days' => $days,
			'inner' => $resolved['trace'],
		];
		if ($degraded) {
			$trace['degraded'] = true;
		}
		// Preserve legacy "source" / "ruleSetId" keys verbatim so existing
		// callers (allocation service, dashboards) keep working.
		return [
			'days' => $days,
			'source' => $matchedLayer === 'L3' ? $resolved['source'] : ($matchedLayer === 'legacy' ? $resolved['source'] : 'layered'),
			'ruleSetId' => $resolved['ruleSetId'],
			'matchedLayer' => $matchedLayer,
			'trace' => $trace,
		];
	}

	/**
	 * Pin a hypothetical team membership list for `$userId` so the L2
	 * resolution layer treats the user *as if* they were a member of these
	 * teams (in addition to / instead of their real membership). Used by the
	 * admin what-if simulator (REQ-WF-05).
	 *
	 * Callers MUST pair this with {@see self::clearHypotheticalTeams()} in
	 * a `finally` block so the override never leaks to a subsequent
	 * resolution. The engine is request-scoped, but background jobs reuse
	 * the same instance and we don't want a simulator call to silently
	 * change a payroll snapshot.
	 *
	 * Pass an empty list to simulate "user is in *no* team", which is a
	 * legitimate what-if for offboarding scenarios.
	 *
	 * @param list<int> $teamIds
	 */
	public function setHypotheticalTeams(string $userId, array $teamIds): void
	{
		// Normalise: drop non-positive ids, deduplicate, force ints. We do
		// this here so callers don't have to re-do it.
		$clean = [];
		foreach ($teamIds as $raw) {
			$tid = (int)$raw;
			if ($tid <= 0) {
				continue;
			}
			if (!in_array($tid, $clean, true)) {
				$clean[] = $tid;
			}
		}
		$this->hypotheticalTeams[$userId] = $clean;
		// Drop any cached real-membership context for this user so the
		// override is honoured on the next L2 resolution.
		unset($this->teamContextCache[$userId]);
	}

	public function clearHypotheticalTeams(string $userId): void
	{
		unset($this->hypotheticalTeams[$userId]);
		unset($this->teamContextCache[$userId]);
	}

	/**
	 * Team membership context for a user, memoised per engine instance.
	 * Honours any hypothetical override set via
	 * {@see self::setHypotheticalTeams()} (simulator-only).
	 *
	 * @return array{teamIds: list<int>, parentMap: array<int, int|null>, hypothetical: bool}
	 */
	private function getTeamContext(string $userId): array
	{
		if (isset($this->teamContextCache[$userId])) {
			return $this->teamContextCache[$userId];
		}
		$hypothetical = array_key_exists($userId, $this->hypotheticalTeams);
		if ($hypothetical) {
			$teamIds = $this->hypotheticalTeams[$userId];
		} else {
			try {
				$memberships = $this->teamMemberMapper->findByUserId($userId);
			} catch (\Throwable) {
				$memberships = [];
			}
			$teamIds = [];
			foreach ($memberships as $m) {
				$tid = (int)$m->getTeamId();
				if (!in_array($tid, $teamIds, true)) {
					$teamIds[] = $tid;
				}
			}
		}
		try {
			$parentMap = $this->teamMapper->getParentMap();
		} catch (\Throwable) {
			$parentMap = [];
		}
		return $this->teamContextCache[$userId] = [
			'teamIds' => $teamIds,
			'parentMap' => $parentMap,
			'hypothetical' => $hypothetical,
		];
	}

	/**
	 * Single canonical rounding/clamping function for *all* entitlement
	 * outputs (REQ-ENT-07, GAP-01). Centralised so the rest of the codebase
	 * — VacationAllocationService, snapshot service, exports — can route
	 * through here and stay in sync. Half-up because that's what payroll
	 * tooling defaults to and matches the prior behaviour of
	 * `round($x, 2)` in PHP (PHP_ROUND_HALF_UP).
	 */
	public function roundDays(float $value): float
	{
		if (!is_finite($value)) {
			return 0.0;
		}
		$ceiling = $this->entitlementCeiling();
		$clamped = max(0.0, min($ceiling, $value));
		return round($clamped, 2, PHP_ROUND_HALF_UP);
	}

	/**
	 * Detect whether {@see self::roundDays()} had to clamp the value. Used
	 * by trace builders to surface `clamped=true` for auditors so
	 * misconfigured rule sets (negative manual days, accidentally adding
	 * 200% pro-rata) are visible instead of disappearing behind the
	 * unit ceiling (EC-08).
	 *
	 * Tolerance picks up below the 2dp rounding noise so 25.0 vs 25.001 is
	 * not flagged as "clamped" by accident.
	 */
	private function wasClamped(float $raw, float $finalDays): bool
	{
		if (!is_finite($raw)) {
			return true;
		}
		$ceiling = $this->entitlementCeiling();
		if ($raw < 0.0 || $raw > $ceiling) {
			return true;
		}
		// Below the 2dp rounding floor — not a clamp, just rounding noise.
		return false;
	}

	/**
	 * Whether `$asOfDate` is before the day this resolution runs. Used to
	 * surface a `partial_history` flag on L2 matches when the team
	 * membership table only reflects *current* state (REQ-ENT-13, EC-11).
	 * The check is intentionally coarse — we have no per-membership
	 * `valid_from` column yet — so any `as_of_date` strictly before today
	 * is treated as "best effort historical".
	 */
	private function asOfPredatesMembershipHistory(\DateTimeImmutable $asOfDate): bool
	{
		try {
			$today = new \DateTimeImmutable('today');
		} catch (\Throwable) {
			return false;
		}
		return $asOfDate->format('Y-m-d') < $today->format('Y-m-d');
	}

	/**
	 * Depth of `$teamId` in the parent tree (root = 0). Returns `-1` for
	 * unknown / circular teams. Cycle guard caps the walk at 64 levels;
	 * the team form validator rejects writes that introduce cycles, so a
	 * cycle reaching this code is a corrupted-DB scenario we degrade
	 * gracefully on.
	 *
	 * Implemented inside the engine (not on `TeamMapper`) deliberately:
	 * keeping it here means unit tests that mock `TeamMapper` cannot
	 * accidentally erase the tie-breaker by stubbing `computeDepth()` to a
	 * default-int 0.
	 *
	 * @param array<int, int|null> $parentMap
	 */
	private function toImmutable(\DateTimeInterface $date): \DateTimeImmutable
	{
		// Date-column semantics: effective_from/to are `date` type, so the
		// instant is meaningless — compare on Y-m-d only, which is also all
		// {@see EffectiveRange::contains()} ever inspects.
		return new \DateTimeImmutable($date->format('Y-m-d'));
	}

	private function computeTeamDepth(int $teamId, array $parentMap): int
	{
		if (!array_key_exists($teamId, $parentMap)) {
			return -1;
		}
		$depth = 0;
		$current = $teamId;
		$seen = [$current => true];
		while (($parent = $parentMap[$current] ?? null) !== null) {
			if (isset($seen[$parent])) {
				return -1;
			}
			$depth++;
			if ($depth > 64) {
				return -1;
			}
			$seen[$parent] = true;
			$current = $parent;
		}
		return $depth;
	}

	private function applyRounding(float $value, string $mode): float {
		return match ($mode) {
			'floor' => floor($value),
			'ceil' => ceil($value),
			'half_day' => round($value * 2.0) / 2.0,
			default => round($value, 2, PHP_ROUND_HALF_UP),
		};
	}

	private function applyProRata(float $value, string $mode, \DateTimeInterface $asOfDate): float {
		if ($mode === 'none') {
			return $value;
		}
		$month = (int)$asOfDate->format('n');
		if ($mode === 'monthly') {
			return ($value / 12.0) * $month;
		}
		if ($mode === 'daily') {
			$dayOfYear = (int)$asOfDate->format('z') + 1;
			$yearDays = (int)$asOfDate->format('L') === 1 ? 366 : 365;
			return ($value / $yearDays) * $dayOfYear;
		}
		return $value;
	}
}
