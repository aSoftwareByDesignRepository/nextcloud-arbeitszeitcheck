<?php

declare(strict_types=1);

/**
 * Auto-approve pending absences that have no assignable approver under current team rules
 * (fixes rows stuck before the approver-resolution fix).
 *
 * Idempotent: safe to run multiple times.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Repair;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class ReleaseStuckPendingAbsences implements IRepairStep
{
	private const CORRECTION_CHUNK = 200;

	public function __construct(
		private AbsenceMapper $absenceMapper,
		private AbsenceService $absenceService,
		private TimeEntryMapper $timeEntryMapper,
		private TimeEntryCorrectionService $correctionService,
		private TeamResolverService $teamResolver,
	) {
	}

	public function getName(): string
	{
		return 'Release pending absences with no assignable approver';
	}

	public function run(IOutput $output): void
	{
		$pending = $this->absenceMapper->findByStatus(Absence::STATUS_PENDING);
		$fixed = 0;
		foreach ($pending as $absence) {
			try {
				if ($this->absenceService->autoApprovePendingIfNoAssignableManager($absence->getId())) {
					$fixed++;
				}
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->warning(
					'ReleaseStuckPendingAbsences: failed for absence ' . $absence->getId(),
					['app' => 'arbeitszeitcheck', 'exception' => $e]
				);
			}
		}
		if ($fixed > 0) {
			$output->info(sprintf('Auto-approved %d pending absence(s) that had no assignable approver.', $fixed));
		}

		// B1-MH-03: same predicate applied to pending time-entry corrections —
		// a stuck correction blocks payroll just like a stuck absence.
		// Approved rows leave the pending set, so page 0 is re-read each round;
		// a seen-set both avoids re-evaluating rows and terminates the loop once
		// every remaining pending row has an assignable approver.
		$fixedCorrections = 0;
		$seen = [];
		while (true) {
			$pendingCorrections = $this->timeEntryMapper->findPendingApproval(self::CORRECTION_CHUNK, 0);
			if ($pendingCorrections === []) {
				break;
			}
			$newlySeen = 0;
			foreach ($pendingCorrections as $entry) {
				$entryId = (int)$entry->getId();
				if (isset($seen[$entryId])) {
					continue;
				}
				$seen[$entryId] = true;
				$newlySeen++;
				try {
					if ($this->teamResolver->hasAssignableManagerForEmployee($entry->getUserId())) {
						continue;
					}
					$this->correctionService->autoApprove($entry);
					$fixedCorrections++;
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'ReleaseStuckPendingAbsences: failed for correction entry ' . $entry->getId(),
						['app' => 'arbeitszeitcheck', 'exception' => $e]
					);
				}
			}
			if ($newlySeen === 0 || count($pendingCorrections) < self::CORRECTION_CHUNK) {
				break;
			}
		}
		if ($fixedCorrections > 0) {
			$output->info(sprintf('Auto-approved %d pending time-entry correction(s) that had no assignable approver.', $fixedCorrections));
		}
	}
}
