<?php

declare(strict_types=1);

/**
 * Deterministic special-entitlement policy resolver.
 *
 * The resolver is deliberately side-effect free: callers acquire locks and
 * persist snapshots/period rows. L2 uses the special-entitlement ordering
 * (priority, protected group before operational team, team depth, id), which
 * differs intentionally from a generic "most specific scope always wins"
 * resolution.
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicy;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementPolicyMapper;
use OCA\ArbeitszeitCheck\Db\SpecialEntitlementType;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Exception\SpecialEntitlementException;

final class SpecialEntitlementResolver
{
	public const ALGORITHM_VERSION = '1';
	public const MAX_AMOUNT = 4000.0;
	private const EPSILON = 0.0000001;

	public function __construct(
		private readonly SpecialEntitlementPolicyMapper $policies,
		private readonly UserWorkingTimeModelMapper $userModels,
		private readonly TeamMemberMapper $teamMembers,
		private readonly TeamMapper $teams,
		private readonly PolicyCohortResolver $cohorts,
		private readonly UserEmploymentSettingsService $employment,
	) {
	}

	/** @return array{periodStart:\DateTimeImmutable,periodEnd:\DateTimeImmutable} */
	public function periodFor(SpecialEntitlementType $type, string $userId, \DateTimeInterface $asOf): array
	{
		$date = new \DateTimeImmutable($asOf->format('Y-m-d'));
		return match ($type->getPeriodType()) {
			SpecialEntitlementType::PERIOD_CALENDAR_YEAR => [
				'periodStart' => new \DateTimeImmutable($date->format('Y') . '-01-01'),
				'periodEnd' => new \DateTimeImmutable($date->format('Y') . '-12-31'),
			],
			SpecialEntitlementType::PERIOD_SERVICE_YEAR => $this->serviceYear($userId, $date),
			SpecialEntitlementType::PERIOD_ONE_TIME => [
				'periodStart' => new \DateTimeImmutable('1970-01-01'),
				'periodEnd' => new \DateTimeImmutable('9999-12-31'),
			],
			default => throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid entitlement period type.', 400, ['periodType' => 'invalid']),
		};
	}

	/**
	 * @param list<SpecialEntitlementPolicy> $extraPolicies hypothetical policies
	 *   merged into the evaluated set for impact previews (never persisted)
	 * @return array{
	 *   computedAmount:float,enabled:bool,periodStart:\DateTimeImmutable,
	 *   periodEnd:\DateTimeImmutable,asOfDate:\DateTimeImmutable,trace:array<string,mixed>
	 * }
	 */
	public function resolve(SpecialEntitlementType $type, string $userId, \DateTimeInterface $asOf, array $extraPolicies = []): array
	{
		$asOfDate = new \DateTimeImmutable($asOf->format('Y-m-d'));
		$period = $this->periodFor($type, $userId, $asOfDate);
		$context = $this->scopeContext($userId, $asOfDate);
		$activePolicies = $this->policies->findActiveForType((int)$type->getId(), $asOfDate->format('Y-m-d'));
		foreach ($extraPolicies as $extra) {
			$from = $extra->getEffectiveFrom();
			$to = $extra->getEffectiveTo();
			if (($from === null || $from->format('Y-m-d') <= $asOfDate->format('Y-m-d'))
				&& ($to === null || $to->format('Y-m-d') >= $asOfDate->format('Y-m-d'))) {
				$activePolicies[] = $extra;
			}
		}
		$layers = [
			'L0' => $this->matching($activePolicies, SpecialEntitlementPolicy::SCOPE_ORG, $context),
			'L1' => $this->matching($activePolicies, SpecialEntitlementPolicy::SCOPE_MODEL, $context),
			'L2' => array_merge(
				$this->matching($activePolicies, SpecialEntitlementPolicy::SCOPE_GROUP, $context),
				$this->matching($activePolicies, SpecialEntitlementPolicy::SCOPE_TEAM, $context),
			),
			'L3' => $this->matching($activePolicies, SpecialEntitlementPolicy::SCOPE_USER, $context),
		];

		$amount = 0.0;
		$enabled = false;
		$trace = [
			'algorithm_version' => self::ALGORITHM_VERSION,
			'as_of_date' => $asOfDate->format('Y-m-d'),
			'entitlement_type_id' => (int)$type->getId(),
			'unit' => (string)$type->getUnit(),
			'period' => [
				'type' => (string)$type->getPeriodType(),
				'start' => $period['periodStart']->format('Y-m-d'),
				'end' => $period['periodEnd']->format('Y-m-d'),
			],
			'layers_evaluated' => [],
			'inputs_redacted' => false,
			'team_history_reliable' => $context['teamHistoryReliable'],
		];

		foreach (['L0', 'L1', 'L2', 'L3'] as $layer) {
			$winner = $layer === 'L2'
				? $this->chooseL2($layers[$layer], $context['teamDepths'])
				: $this->chooseSingleScope($layers[$layer]);
			$row = [
				'layer' => $layer,
				'matched' => $winner !== null,
				'candidates' => array_map([$this, 'policyTrace'], $layers[$layer]),
			];
			if ($winner === null) {
				$row['reason'] = 'no_policy';
				$trace['layers_evaluated'][] = $row;
				continue;
			}

			$before = $amount;
			$wasEnabled = $enabled;
			$this->applyOperation($winner, $amount, $enabled);
			$row += [
				'winner' => $this->policyTrace($winner),
				'before' => $this->formatAmount($before),
				'after' => $this->formatAmount($amount),
				'enabled_before' => $wasEnabled,
				'enabled_after' => $enabled,
			];
			$trace['layers_evaluated'][] = $row;
		}

		if (abs($amount) < self::EPSILON) {
			$amount = 0.0;
		}
		if (!is_finite($amount) || $amount < -self::EPSILON || $amount > self::MAX_AMOUNT + self::EPSILON) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Resolved entitlement is outside the supported range.', 400, ['amount' => 'range']);
		}
		$amount = $this->formatAmount($amount);
		$trace['winner'] = [
			'computed_amount' => $amount,
			'enabled' => $enabled,
		];

		return [
			'computedAmount' => $amount,
			'enabled' => $enabled,
			'periodStart' => $period['periodStart'],
			'periodEnd' => $period['periodEnd'],
			'asOfDate' => $asOfDate,
			'trace' => $trace,
		];
	}

	/** @return array{modelId:?string,cohortIds:list<string>,teamDepths:array<string,int>,teamHistoryReliable:bool} */
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

		// Operational team membership is not historically versioned. For a
		// back-dated date, using it would silently reinterpret the past.
		$teamHistoryReliable = $asOf >= new \DateTimeImmutable('today');
		$teamDepths = [];
		if ($teamHistoryReliable) {
			try {
				$parentMap = $this->teams->getParentMap();
				foreach ($this->teamMembers->findByUserId($userId) as $membership) {
					$teamId = (int)$membership->getTeamId();
					$teamDepths[(string)$teamId] = $this->teams->computeDepth($teamId, $parentMap);
				}
			} catch (\Throwable) {
				$teamDepths = [];
				$teamHistoryReliable = false;
			}
		}

		return [
			'userId' => $userId,
			'modelId' => $modelId,
			'cohortIds' => $this->cohorts->effectiveCohortIds($userId, $asOf),
			'teamDepths' => $teamDepths,
			'teamHistoryReliable' => $teamHistoryReliable,
		];
	}

	/**
	 * @param list<SpecialEntitlementPolicy> $policies
	 * @return list<SpecialEntitlementPolicy>
	 */
	private function matching(array $policies, string $scope, array $context): array
	{
		return array_values(array_filter($policies, static function (SpecialEntitlementPolicy $policy) use ($scope, $context): bool {
			if ($policy->getScopeType() !== $scope) {
				return false;
			}
			$scopeId = (string)$policy->getScopeId();
			return match ($scope) {
				SpecialEntitlementPolicy::SCOPE_ORG => $scopeId === SpecialEntitlementPolicy::ORG_SCOPE_ID,
				SpecialEntitlementPolicy::SCOPE_MODEL => $context['modelId'] !== null && hash_equals((string)$context['modelId'], $scopeId),
				SpecialEntitlementPolicy::SCOPE_GROUP => in_array($scopeId, $context['cohortIds'], true),
				SpecialEntitlementPolicy::SCOPE_TEAM => array_key_exists($scopeId, $context['teamDepths']),
				SpecialEntitlementPolicy::SCOPE_USER => hash_equals($context['userId'] ?? '', $scopeId),
				default => false,
			};
		}));
	}

	/** @param list<SpecialEntitlementPolicy> $policies */
	private function chooseSingleScope(array $policies): ?SpecialEntitlementPolicy
	{
		usort($policies, static fn (SpecialEntitlementPolicy $a, SpecialEntitlementPolicy $b): int =>
			($b->getPriority() <=> $a->getPriority()) ?: ((int)$a->getId() <=> (int)$b->getId())
		);
		return $policies[0] ?? null;
	}

	/**
	 * Protected groups are more specific than operational teams at L2.
	 * Team depth only participates when operational team policies compete;
	 * it cannot promote an operational-team rule above a protected group.
	 *
	 * @param list<SpecialEntitlementPolicy> $policies
	 * @param array<string,int> $teamDepths
	 */
	private function chooseL2(array $policies, array $teamDepths): ?SpecialEntitlementPolicy
	{
		usort($policies, static function (SpecialEntitlementPolicy $a, SpecialEntitlementPolicy $b) use ($teamDepths): int {
			$priority = $b->getPriority() <=> $a->getPriority();
			if ($priority !== 0) {
				return $priority;
			}
			$scope = self::scopeRank($a) <=> self::scopeRank($b);
			if ($scope !== 0) {
				return $scope;
			}
			if ($a->getScopeType() === SpecialEntitlementPolicy::SCOPE_TEAM) {
				$depth = ($teamDepths[(string)$b->getScopeId()] ?? 0) <=> ($teamDepths[(string)$a->getScopeId()] ?? 0);
				if ($depth !== 0) {
					return $depth;
				}
			}
			return (int)$a->getId() <=> (int)$b->getId();
		});
		return $policies[0] ?? null;
	}

	private static function scopeRank(SpecialEntitlementPolicy $policy): int
	{
		return $policy->getScopeType() === SpecialEntitlementPolicy::SCOPE_GROUP ? 0 : 1;
	}

	private function applyOperation(SpecialEntitlementPolicy $policy, float &$amount, bool &$enabled): void
	{
		$operation = (string)$policy->getOperation();
		$policyAmount = $policy->getAmount() !== null ? (float)$policy->getAmount() : null;
		match ($operation) {
			SpecialEntitlementPolicy::OP_INHERIT => null,
			SpecialEntitlementPolicy::OP_ADD => (function () use (&$amount, &$enabled, $policyAmount): void {
				$this->assertPolicyAmount($policyAmount);
				$amount += $policyAmount;
				$enabled = true;
			})(),
			SpecialEntitlementPolicy::OP_REPLACE => (function () use (&$amount, &$enabled, $policyAmount): void {
				$this->assertPolicyAmount($policyAmount);
				$amount = $policyAmount;
				$enabled = true;
			})(),
			SpecialEntitlementPolicy::OP_DISABLE => (function () use (&$amount, &$enabled): void {
				$amount = 0.0;
				$enabled = false;
			})(),
			default => throw new SpecialEntitlementException('VALIDATION_ERROR', 'Invalid policy operation.', 400, ['operation' => 'invalid']),
		};
	}

	private function assertPolicyAmount(?float $amount): void
	{
		if ($amount === null || !is_finite($amount) || $amount < 0.0 || $amount > self::MAX_AMOUNT) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Policy amount is outside the supported range.', 400, ['amount' => 'range']);
		}
	}

	/** @return array<string,mixed> */
	private function policyTrace(SpecialEntitlementPolicy $policy): array
	{
		return [
			'policy_id' => (int)$policy->getId(),
			'version' => (int)$policy->getVersion(),
			'scope_type' => (string)$policy->getScopeType(),
			'scope_id' => (string)$policy->getScopeId(),
			'operation' => (string)$policy->getOperation(),
			'amount' => $policy->getAmount() !== null ? $this->formatAmount((float)$policy->getAmount()) : null,
			'tariff_rule_set_id' => $policy->getTariffRuleSetId(),
			'priority' => (int)$policy->getPriority(),
			'effective_from' => $policy->getEffectiveFrom()?->format('Y-m-d'),
			'effective_to' => $policy->getEffectiveTo()?->format('Y-m-d'),
		];
	}

	private function serviceYear(string $userId, \DateTimeImmutable $asOf): array
	{
		$start = $this->employment->getEmploymentStart($userId);
		if ($start === null) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'Employment start is required for service-year entitlements.', 400, ['employmentStart' => 'required']);
		}
		$startDate = new \DateTimeImmutable($start->format('Y-m-d'));
		if ($asOf < $startDate) {
			throw new SpecialEntitlementException('VALIDATION_ERROR', 'The requested date is before employment start.', 400, ['start_date' => 'before_employment']);
		}
		$month = (int)$startDate->format('n');
		$day = (int)$startDate->format('j');
		$anniversary = $this->anniversary((int)$asOf->format('Y'), $month, $day);
		if ($asOf < $anniversary) {
			$periodStart = $this->anniversary((int)$asOf->format('Y') - 1, $month, $day);
			$periodEnd = $anniversary->sub(new \DateInterval('P1D'));
		} else {
			$periodStart = $anniversary;
			$periodEnd = $this->anniversary((int)$asOf->format('Y') + 1, $month, $day)->sub(new \DateInterval('P1D'));
		}
		return ['periodStart' => $periodStart, 'periodEnd' => $periodEnd];
	}

	private function anniversary(int $year, int $month, int $day): \DateTimeImmutable
	{
		if ($month === 2 && $day === 29 && !checkdate(2, 29, $year)) {
			return new \DateTimeImmutable(sprintf('%04d-02-28', $year));
		}
		return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
	}

	private function formatAmount(float $amount): float
	{
		return round($amount, 2, PHP_ROUND_HALF_UP);
	}
}
