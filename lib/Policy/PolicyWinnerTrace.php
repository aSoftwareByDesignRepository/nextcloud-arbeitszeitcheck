<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

final readonly class PolicyWinnerTrace
{
	public function __construct(
		public int $candidateId,
		public string $scope,
		public string $scopeId,
		public int $priority,
		public int $depth,
	) {
	}

	public static function fromCandidate(PolicyCandidate $candidate, bool $redacted): self
	{
		return new self(
			$candidate->id,
			$candidate->scope->value,
			$redacted ? '<redacted>' : $candidate->scopeId,
			$candidate->priority,
			$candidate->depth,
		);
	}
}
