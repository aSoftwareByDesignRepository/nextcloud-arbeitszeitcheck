<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

final readonly class PolicyResolution
{
	/**
	 * @param list<PolicyCandidate> $eligible Sorted eligible candidates —
	 *                                        the same ordering that produced
	 *                                        {@see $winner}. Lets consumers
	 *                                        build auditable "evaluated" lists
	 *                                        without re-implementing the sort.
	 */
	public function __construct(
		public ?PolicyCandidate $winner,
		public ?PolicyWinnerTrace $trace,
		public int $eligibleCount,
		public array $eligible = [],
	) {
	}
}
