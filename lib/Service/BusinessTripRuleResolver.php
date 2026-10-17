<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignment;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleAssignmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSetMapper;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;

/**
 * Effective-dated rule-set resolution per local day (spec §9.3):
 * precedence ORG → MODEL → TEAM → POLICY_COHORT → USER; within a scope,
 * higher priority wins, then smallest assignment id. Team membership is
 * not historically versioned — back-dated resolution ignores operational
 * teams rather than silently reinterpreting the past.
 */
final class BusinessTripRuleResolver
{
	public function __construct(
		private readonly BusinessTripRuleAssignmentMapper $assignments,
		private readonly BusinessTripRuleSetMapper $ruleSets,
		private readonly UserWorkingTimeModelMapper $userModels,
		private readonly TeamMemberMapper $teamMembers,
		private readonly TeamMapper $teams,
		private readonly PolicyCohortResolver $cohorts,
	) {
	}

	/**
	 * Resolve the winning rule set for one local date, or null when nothing
	 * matches (the caller decides whether that blocks the trip).
	 */
	public function resolveForDay(string $userId, \DateTimeInterface $localDate): ?BusinessTripRuleSet
	{
		$asOf = $localDate->format('Y-m-d');
		$context = $this->scopeContext($userId, new \DateTimeImmutable($asOf));
		$winner = $this->winner($this->assignments->findEffective($asOf), $context);
		if ($winner === null) {
			return null;
		}
		try {
			$ruleSet = $this->ruleSets->find((int)$winner->getRuleSetId());
		} catch (\Throwable) {
			return null;
		}
		// Only rule sets that can legally drive a calculation resolve.
		if ($ruleSet->getStatus() !== BusinessTripRuleSet::STATUS_ACTIVE) {
			return null;
		}
		if ($ruleSet->getValidFrom() !== null && $ruleSet->getValidFrom()->format('Y-m-d') > $asOf) {
			return null;
		}
		if ($ruleSet->getValidTo() !== null && $ruleSet->getValidTo()->format('Y-m-d') < $asOf) {
			return null;
		}
		return $ruleSet;
	}

	/** @param list<BusinessTripRuleAssignment> $assignments */
	private function winner(array $assignments, array $context): ?BusinessTripRuleAssignment
	{
		$eligible = array_values(array_filter($assignments, static function (BusinessTripRuleAssignment $a) use ($context): bool {
			$scopeId = (string)$a->getScopeId();
			return match ($a->getScopeType()) {
				BusinessTripRuleAssignment::SCOPE_ORG => true,
				BusinessTripRuleAssignment::SCOPE_MODEL => $context['modelId'] !== null && hash_equals($context['modelId'], $scopeId),
				BusinessTripRuleAssignment::SCOPE_TEAM => array_key_exists($scopeId, $context['teamDepths']),
				BusinessTripRuleAssignment::SCOPE_GROUP => in_array($scopeId, $context['cohortIds'], true),
				BusinessTripRuleAssignment::SCOPE_USER => hash_equals($context['userId'], $scopeId),
				default => false,
			};
		}));
		usort($eligible, static function (BusinessTripRuleAssignment $a, BusinessTripRuleAssignment $b) use ($context): int {
			// Most specific scope wins (USER > COHORT > TEAM > MODEL > ORG).
			$scope = self::scopePrecedence((string)$b->getScopeType()) <=> self::scopePrecedence((string)$a->getScopeType());
			if ($scope !== 0) {
				return $scope;
			}
			$priority = $b->getPriority() <=> $a->getPriority();
			if ($priority !== 0) {
				return $priority;
			}
			// Within team scope, deeper teams are more specific.
			if ($a->getScopeType() === BusinessTripRuleAssignment::SCOPE_TEAM) {
				$depth = ($context['teamDepths'][(string)$b->getScopeId()] ?? 0) <=> ($context['teamDepths'][(string)$a->getScopeId()] ?? 0);
				if ($depth !== 0) {
					return $depth;
				}
			}
			return (int)$a->getId() <=> (int)$b->getId();
		});
		return $eligible[0] ?? null;
	}

	private static function scopePrecedence(string $scopeType): int
	{
		return match ($scopeType) {
			BusinessTripRuleAssignment::SCOPE_ORG => 0,
			BusinessTripRuleAssignment::SCOPE_MODEL => 1,
			BusinessTripRuleAssignment::SCOPE_TEAM => 2,
			BusinessTripRuleAssignment::SCOPE_GROUP => 3,
			BusinessTripRuleAssignment::SCOPE_USER => 4,
			default => -1,
		};
	}

	/** @return array{userId:string,modelId:?string,cohortIds:list<string>,teamDepths:array<string,int>} */
	private function scopeContext(string $userId, \DateTimeImmutable $asOf): array
	{
		$modelId = null;
		try {
			$assignment = $this->userModels->findByUserAndDate($userId, new \DateTime($asOf->format('Y-m-d')));
			if ($assignment !== null) {
				$modelId = (string)(int)$assignment->getWorkingTimeModelId();
			}
		} catch (\Throwable) {
			$modelId = null;
		}

		$teamDepths = [];
		if ($asOf >= new \DateTimeImmutable('today')) {
			try {
				$parentMap = $this->teams->getParentMap();
				foreach ($this->teamMembers->findByUserId($userId) as $membership) {
					$teamId = (int)$membership->getTeamId();
					$teamDepths[(string)$teamId] = $this->teams->computeDepth($teamId, $parentMap);
				}
			} catch (\Throwable) {
				$teamDepths = [];
			}
		}

		return [
			'userId' => $userId,
			'modelId' => $modelId,
			'cohortIds' => $this->cohorts->effectiveCohortIds($userId, $asOf),
			'teamDepths' => $teamDepths,
		];
	}
}
