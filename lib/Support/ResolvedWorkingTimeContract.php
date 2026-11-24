<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Db\WorkingTimeModel;

/**
 * Immutable resolution result of {@see \OCA\ArbeitszeitCheck\Service\WorkingTimeModelAssignmentResolver}.
 *
 * Carries the effective contract for one day: the working-time model (if any),
 * the concrete Soll inputs (schedule OR scalar hours), the resolution basis for
 * provenance/rendering, and the assignment row that produced it.
 */
final class ResolvedWorkingTimeContract
{
	/**
	 * @param bool $lenientFallback true when lenient resolution fell back to a
	 *        non-covering window (assignment active today / legacy defaults).
	 */
	public function __construct(
		public readonly ?WorkingTimeModel $model,
		public readonly ?WeekdaySchedule $schedule,
		public readonly float $dailyHours,
		public readonly float $weeklyHours,
		public readonly string $basis,
		public readonly ?int $assignmentId,
		public readonly bool $lenientFallback = false,
		public readonly ?float $vacationDaysPerYear = null,
	) {
	}

	/** Two contracts are segment-equal when the resolved calc inputs match. */
	public function isEquivalentTo(self $other): bool
	{
		return $this->basis === $other->basis
			&& ($this->model?->getId() ?? 0) === ($other->model?->getId() ?? 0)
			&& abs($this->dailyHours - $other->dailyHours) < 1e-9
			&& abs($this->weeklyHours - $other->weeklyHours) < 1e-9;
	}
}
