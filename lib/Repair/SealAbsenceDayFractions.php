<?php

declare(strict_types=1);

/**
 * Seal explicit day fractions for legacy single-day half absences (EC-18).
 *
 * Rows created before the fraction columns existed carry the half implicitly
 * in `days` — a retroactive schedule-weight change could reinterpret that
 * stored value. This repair persists `start_day_fraction = 0.5` where the
 * stored debit PROVABLY encodes a half (days/weight ≈ 0.5), and leaves the
 * slot NULL — a legacy row cannot prove which half was taken, so it stays
 * ineligible for complementary same-day pairing (fail closed).
 *
 * Conservative: only an unambiguous 0.5 ratio is sealed; a ratio ≈ 1.0 or
 * anything else leaves the columns NULL (NULL means "full coverage" for the
 * overlap/credit semantics anyway). Idempotent: sealed rows are excluded by
 * the candidate query.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Repair;

use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class SealAbsenceDayFractions implements IRepairStep
{
	public function __construct(
		private AbsenceMapper $absenceMapper,
		private HolidayService $holidayCalendarService
	) {
	}

	public function getName(): string
	{
		return 'Seal explicit day fractions for legacy single-day half absences';
	}

	public function run(IOutput $output): void
	{
		$absences = $this->absenceMapper->findUnsealedSingleDayRows();
		$count = count($absences);

		if ($count === 0) {
			return;
		}

		$output->info(sprintf('Checking %d legacy single-day absence(s) for sealable half-day fractions', $count));
		$output->startProgress($count);
		$sealed = 0;

		foreach ($absences as $absence) {
			try {
				$start = $absence->getStartDate();
				$days = $absence->getDays();
				if ($start === null || $days === null || !is_finite((float)$days)) {
					$output->advance();
					continue;
				}

				$weight = $this->holidayCalendarService->computeWorkingDaysForUser(
					$absence->getUserId(),
					$start,
					$start
				);
				if (!is_finite($weight) || $weight <= 0.011) {
					$output->advance();
					continue;
				}

				$ratio = (float)$days / $weight;
				if (abs($ratio - 0.5) < 0.011) {
					$absence->setStartDayFraction(0.5);
					$absence->setUpdatedAt(new \DateTime());
					$this->absenceMapper->update($absence);
					$sealed++;
				}
			} catch (\Throwable $e) {
				// best-effort: a repair must continue past a bad row; each
				// failure is logged with the absence id for follow-up.
				\OCP\Log\logger('arbeitszeitcheck')->error(
					'SealAbsenceDayFractions: failed for absence ' . $absence->getId(),
					['exception' => $e]
				);
			}
			$output->advance();
		}

		$output->finishProgress();
		$output->info(sprintf('Sealed explicit half-day fraction on %d of %d legacy absence(s)', $sealed, $count));
	}
}
