<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

/**
 * A contiguous run of days inside a resolved range that share one effective
 * contract. Both dates are inclusive; serialized as Y-m-d wire strings.
 */
final class AssignmentSegment
{
	public function __construct(
		public readonly \DateTime $start,
		public readonly \DateTime $end,
		public readonly ResolvedWorkingTimeContract $contract,
	) {
	}
}
