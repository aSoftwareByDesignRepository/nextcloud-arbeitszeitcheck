<?php

declare(strict_types=1);

/**
 * Backfill working days for absences with days=NULL (legacy records).
 *
 * Ensures vacation stats and display use correct, state-aware working-day values.
 * Idempotent: safe to run multiple times.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Repair;

use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class BackfillAbsenceDays implements IRepairStep
{
	public function __construct(
		private AbsenceMapper $absenceMapper,
		private HolidayService $holidayCalendarService
	) {
	}

	public function getName(): string
	{
		return 'Backfill working days for legacy absences';
	}

	public function run(IOutput $output): void
	{
		$absences = $this->absenceMapper->findWithNullDays();
		$count = count($absences);

		if ($count === 0) {
			return;
		}

		$output->info(sprintf('Backfilling working days for %d absence(s)', $count));
		$output->startProgress($count);
		$updated = 0;

		foreach ($absences as $absence) {
			try {
				$start = $absence->getStartDate();
				$end = $absence->getEndDate();
				if (!$start || !$end || $start > $end) {
					$output->advance();
					continue;
				}

				$days = $this->holidayCalendarService->computeWorkingDaysForUser(
					$absence->getUserId(),
					$start,
					$end
				);

				$absence->setDays(round($days, 2));
				$absence->setUpdatedAt(new \DateTime());
				$this->absenceMapper->update($absence);
				$updated++;
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error(
					'BackfillAbsenceDays: failed for absence ' . $absence->getId(),
					['exception' => $e]
				);
			}
			$output->advance();
		}

		$output->finishProgress();
		$output->info(sprintf('Backfilled %d of %d absence(s)', $updated, $count));
	}
}
