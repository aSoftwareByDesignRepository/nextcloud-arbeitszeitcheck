<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

final readonly class PolicyCandidate
{
	public function __construct(
		public int $id,
		public PolicyScope $scope,
		public string $scopeId,
		public EffectiveRange $effectiveRange,
		public int $priority,
		public int $depth,
		public mixed $value,
	) {
		if ($id < 1 || trim($scopeId) === '' || $depth < 0) {
			throw new \InvalidArgumentException('Invalid policy candidate.');
		}
	}
}
