<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\PolicyCohortMembershipMapper;
use OCA\ArbeitszeitCheck\Policy\PolicyScopeContext;

final class PolicyCohortResolver
{
	public function __construct(
		private readonly WorkforceSuiteConfig $config,
		private readonly PolicyCohortMembershipMapper $memberships,
	) {
	}

	public function effectiveCohortIds(string $userId, \DateTimeInterface $date): array
	{
		if (!$this->config->isEnabled('policy_cohorts_enabled')) {
			return [];
		}
		$ids = array_map(
			static fn ($membership): string => (string)$membership->getCohortId(),
			$this->memberships->findEffectiveForUser($userId, $date->format('Y-m-d')),
		);
		$ids = array_values(array_unique($ids, SORT_STRING));
		sort($ids, SORT_STRING);
		return $ids;
	}

	public function scopeContext(string $userId, ?string $modelId, array $teamDepths, \DateTimeInterface $date): PolicyScopeContext
	{
		return new PolicyScopeContext($userId, $modelId, $this->effectiveCohortIds($userId, $date), $teamDepths);
	}
}
