<?php

declare(strict_types=1);

/**
 * Effective work-location default resolution (Workforce Policy Suite, Phase 3).
 *
 * Winner selection for the scope chain (user → team → model → org) runs
 * through the shared {@see PolicyScopeResolver} kernel so the ordering —
 * scope precedence, team depth, priority, smallest policy id — has exactly
 * one implementation across policy domains. Only locations that are still
 * creatable (active catalog codes) may win; a policy pointing at a
 * deactivated code is ineligible rather than silently reinterpreted.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicy;
use OCA\ArbeitszeitCheck\Db\WorkLocationDefaultPolicyMapper;
use OCA\ArbeitszeitCheck\Policy\EffectiveRange;
use OCA\ArbeitszeitCheck\Policy\PolicyCandidate;
use OCA\ArbeitszeitCheck\Policy\PolicyScope;
use OCA\ArbeitszeitCheck\Policy\PolicyScopeContext;
use OCA\ArbeitszeitCheck\Policy\PolicyScopeResolver;
use OCA\ArbeitszeitCheck\Support\WorkLocation;

final class WorkLocationDefaultResolver
{
	public const TRACE_VERSION = 1;

	public function __construct(
		private readonly WorkLocationDefaultPolicyMapper $policyMapper,
		private readonly WorkLocationCatalog $catalog,
		private readonly TeamMemberMapper $teamMemberMapper,
		private readonly TeamMapper $teamMapper,
		private readonly UserWorkingTimeModelMapper $userModelMapper,
		private readonly PolicyScopeResolver $scopeResolver,
	) {
	}

	/**
	 * Effective default location for (user, localDate) from the policy chain.
	 * Returns null when no policy applies — callers then fall back to the
	 * legacy user setting and finally {@see WorkLocation::FALLBACK}.
	 *
	 * @return array{code:string,source:string,policyId:int|null,trace:array}|null
	 */
	public function resolve(string $userId, \DateTimeInterface $localDate): ?array
	{
		$scopes = [[WorkLocationDefaultPolicy::SCOPE_USER, $userId]];
		$scopes[] = [WorkLocationDefaultPolicy::SCOPE_ORG, WorkLocationDefaultPolicy::ORG_SCOPE_ID];

		$teamDepths = [];
		try {
			$parentMap = $this->teamMapper->getParentMap();
		} catch (\Throwable) {
			$parentMap = [];
		}
		try {
			foreach ($this->teamMemberMapper->findByUserId($userId) as $m) {
				$teamId = (int)$m->getTeamId();
				$depth = $this->teamMapper->computeDepth($teamId, $parentMap);
				$teamDepths[(string)$teamId] = $depth;
				$scopes[] = [WorkLocationDefaultPolicy::SCOPE_TEAM, (string)$teamId];
			}
		} catch (\Throwable) {
			// No team infrastructure — user/model/org scopes still resolve.
		}

		$modelId = null;
		try {
			$assignment = $this->userModelMapper->findByUserAndDate($userId, new \DateTime($localDate->format('Y-m-d')));
			if ($assignment !== null) {
				$modelId = (string)(int)$assignment->getWorkingTimeModelId();
				$scopes[] = [WorkLocationDefaultPolicy::SCOPE_MODEL, $modelId];
			}
		} catch (\Throwable) {
			$modelId = null;
		}

		$dateStr = $localDate->format('Y-m-d');
		$candidates = [];
		foreach ($this->policyMapper->findEffectiveForScopes($scopes, $dateStr) as $policy) {
			$code = (string)$policy->getLocationCode();
			if (!$this->catalog->isCreatable($code)) {
				continue;
			}
			$effectiveFrom = $policy->getEffectiveFrom();
			if ($effectiveFrom === null) {
				continue;
			}
			$teamDepth = 0;
			if ($policy->getScopeType() === WorkLocationDefaultPolicy::SCOPE_TEAM) {
				$teamDepth = $teamDepths[(string)$policy->getScopeId()] ?? 0;
			}
			$candidates[] = new PolicyCandidate(
				(int)$policy->getId(),
				PolicyScope::from($policy->getScopeType()),
				(string)$policy->getScopeId(),
				new EffectiveRange(
					$this->toImmutable($effectiveFrom),
					$policy->getEffectiveTo() !== null ? $this->toImmutable($policy->getEffectiveTo()) : null,
				),
				(int)$policy->getPriority(),
				max(0, $teamDepth),
				$code,
			);
		}

		$resolution = $this->scopeResolver->resolve(
			new PolicyScopeContext($userId, $modelId, [], $teamDepths),
			$localDate,
			$candidates,
		);
		$winner = $resolution->winner;
		if ($winner === null) {
			return null;
		}

		$code = (string)$winner->value;
		return [
			'code' => $code,
			'source' => 'policy_' . $winner->scope->value,
			'policyId' => $winner->id,
			'trace' => [
				'version' => self::TRACE_VERSION,
				'winner_scope' => $winner->scope->value,
				'winner_scope_id' => $winner->scopeId,
				'winner_policy_id' => $winner->id,
				'eligible_count' => $resolution->eligibleCount,
				'candidates' => array_map(static function (PolicyCandidate $c): array {
					return [
						'policy_id' => $c->id,
						'scope' => $c->scope->value,
						'scope_id' => $c->scopeId,
						'priority' => $c->priority,
						'team_depth' => $c->scope === PolicyScope::TEAM ? $c->depth : null,
					];
				}, $resolution->eligible),
			],
		];
	}

	/**
	 * Admin/support trace: like {@see resolve()} but never returns null —
	 * falls through to the built-in fallback so the full chain is visible.
	 *
	 * @return array{code:string,source:string,policyId:int|null,trace:array}
	 */
	public function explain(string $userId, \DateTimeInterface $localDate): array
	{
		$resolved = $this->resolve($userId, $localDate);
		if ($resolved !== null) {
			return $resolved;
		}
		return [
			'code' => WorkLocation::FALLBACK,
			'source' => 'fallback',
			'policyId' => null,
			'trace' => [
				'version' => self::TRACE_VERSION,
				'winner_scope' => null,
				'winner_scope_id' => null,
				'winner_policy_id' => null,
				'eligible_count' => 0,
				'candidates' => [],
			],
		];
	}

	private function toImmutable(\DateTimeInterface $date): \DateTimeImmutable
	{
		// Date-column semantics: only Y-m-d is significant (same convention as
		// VacationEntitlementEngine::toImmutable).
		return new \DateTimeImmutable($date->format('Y-m-d'));
	}
}
