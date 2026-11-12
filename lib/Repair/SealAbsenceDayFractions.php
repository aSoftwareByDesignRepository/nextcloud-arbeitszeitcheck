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

use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class SealAbsenceDayFractions implements IRepairStep
{
	public function __construct(
		private IDBConnection $db,
		private HolidayService $holidayCalendarService
	) {
	}

	public function getName(): string
	{
		return 'Seal explicit day fractions for legacy single-day half absences';
	}

	public function run(IOutput $output): void
	{
		// Candidate scan and writes go through the raw query builder on
		// purpose: this repair step is a new class, but AbsenceMapper /
		// Absence may already be loaded in their PRE-RELEASE shape during an
		// in-request upgrade. findUnsealedSingleDayRows() and the
		// *DayFraction entity accessors do not exist there — calling them
		// would throw an Error that escapes Repair::run (which only catches
		// \Exception) and stalls the whole upgrade (symbol skew, cf. #45).
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'user_id', 'start_date', 'days')
			->from('at_absences')
			->where($qb->expr()->isNull('start_day_fraction'))
			->andWhere($qb->expr()->isNull('end_day_fraction'))
			->andWhere($qb->expr()->eq('start_date', 'end_date'))
			->orderBy('id', 'ASC');
		$rows = $qb->executeQuery()->fetchAll();
		$count = count($rows);

		if ($count === 0) {
			return;
		}

		$output->info(sprintf('Checking %d legacy single-day absence(s) for sealable half-day fractions', $count));
		$output->startProgress($count);
		$sealed = 0;

		foreach ($rows as $row) {
			$id = (int)$row['id'];
			try {
				$days = $row['days'] === null ? null : (float)$row['days'];
				if ($row['start_date'] === null || $days === null || !is_finite($days)) {
					$output->advance();
					continue;
				}
				$start = new \DateTime((string)$row['start_date']);

				$weight = $this->holidayCalendarService->computeWorkingDaysForUser(
					(string)$row['user_id'],
					$start,
					$start
				);
				if (!is_finite($weight) || $weight <= 0.011) {
					$output->advance();
					continue;
				}

				$ratio = $days / $weight;
				if (abs($ratio - 0.5) < 0.011) {
					$update = $this->db->getQueryBuilder();
					$update->update('at_absences')
						->set('start_day_fraction', $update->createNamedParameter(0.5))
						->set('updated_at', $update->createNamedParameter(
							(new \DateTime())->format('Y-m-d H:i:s')
						))
						->where($update->expr()->eq('id', $update->createNamedParameter($id)));
					$update->executeStatement();
					$sealed++;
				}
			} catch (\Throwable $e) {
				// best-effort: a repair must continue past a bad row; each
				// failure is logged with the absence id for follow-up.
				\OCP\Log\logger('arbeitszeitcheck')->error(
					'SealAbsenceDayFractions: failed for absence ' . $id,
					['exception' => $e]
				);
			}
			$output->advance();
		}

		$output->finishProgress();
		$output->info(sprintf('Sealed explicit half-day fraction on %d of %d legacy absence(s)', $sealed, $count));
	}
}
