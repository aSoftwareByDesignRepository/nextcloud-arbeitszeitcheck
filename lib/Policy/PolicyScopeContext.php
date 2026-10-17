<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

final readonly class PolicyScopeContext
{
	public function __construct(
		public string $userId,
		public ?string $modelId,
		public array $cohortIds,
		public array $teamDepths,
	) {
		if (trim($userId) === '') {
			throw new \InvalidArgumentException('Policy scope context requires a user.');
		}
	}

	public function matches(PolicyCandidate $candidate): bool
	{
		return match ($candidate->scope) {
			PolicyScope::ORG => true,
			PolicyScope::MODEL => $this->modelId !== null && hash_equals($this->modelId, $candidate->scopeId),
			PolicyScope::POLICY_COHORT => in_array($candidate->scopeId, $this->cohortIds, true),
			PolicyScope::TEAM => array_key_exists($candidate->scopeId, $this->teamDepths),
			PolicyScope::USER => hash_equals($this->userId, $candidate->scopeId),
		};
	}
}
