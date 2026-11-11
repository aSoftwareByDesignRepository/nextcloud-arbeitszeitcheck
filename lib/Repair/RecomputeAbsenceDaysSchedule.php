<?php

declare(strict_types=1);

/**
 * Repair step: recompute absence `days` with the schedule-aware counter
 * for open (pending/approved, start >= today, non-finalized months)
 * absences. spec: absence-day-accuracy-pack-2026-11 (US-402).
 *
 * Idempotent: only rows where stored != computed are written, each with
 * an audit event. Half-day fraction debits and zero-computed rows are
 * never touched. Re-runnable after a mid-run failure.
 */

namespace OCA\ArbeitszeitCheck\Repair;

use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Support\ScheduledWorkdayCounter;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

class RecomputeAbsenceDaysSchedule implements IRepairStep
{
	private const BATCH = 500;
	private const STATUSES = ['pending', 'substitute_pending', 'approved'];

	public function __construct(
		private IDBConnection $db,
		private AbsenceMapper $absenceMapper,
		private AuditLogMapper $auditLogMapper,
		private ScheduledWorkdayCounter $counter,
		private MonthClosureService $monthClosureService,
		private ILockingProvider $lockingProvider,
	) {
	}

	public function getName(): string
	{
		return 'Recompute absence working days with the work-model schedule (open absences only)';
	}

	public function run(IOutput $output): void
	{
		$today = (new \DateTime('today'))->format('Y-m-d');
		$checked = 0; $changed = 0; $skipped = 0;
		$offset = 0;
		/** @var array<string, ?WeekdaySchedule> $scheduleCache memoized per user (no N+1) */
		$scheduleCache = [];
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')->from('at_absences')
				->where($qb->expr()->in('status', $qb->createNamedParameter(self::STATUSES, IQueryBuilder::PARAM_STR_ARRAY)))
				->andWhere($qb->expr()->gte('start_date', $qb->createNamedParameter($today)))
				->orderBy('id', 'ASC')
				->setMaxResults(self::BATCH)
				->setFirstResult($offset);
			$ids = array_map('intval', $qb->executeQuery()->fetchAll(\PDO::FETCH_COLUMN));
			foreach ($ids as $id) {
				$checked++;
				try {
					if ($this->recomputeRow($id, $scheduleCache)) {
						$changed++;
					} else {
						$skipped++;
					}
				} catch (\Throwable $e) {
					$skipped++;
					$output->warning('absence ' . $id . ': ' . $e->getMessage());
				}
			}
			$offset += self::BATCH;
			$output->info(sprintf(
				'absence days recompute progress: %d checked, %d updated, %d skipped',
				$checked, $changed, $skipped
			));
		} while (count($ids) === self::BATCH);
		$output->info(sprintf('absence days recompute: %d checked, %d updated, %d skipped', $checked, $changed, $skipped));
	}

	/**
	 * @param array<string, ?WeekdaySchedule> $scheduleCache
	 */
	private function recomputeRow(int $id, array &$scheduleCache): bool
	{
		$absence = $this->absenceMapper->find($id);
		$stored = $absence->getDays();
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		if ($start === null || $end === null) {
			return false;
		}
		$userId = $absence->getUserId();
		try {
			$this->monthClosureService->assertDateRangeMutable($userId, $start, $end);
		} catch (MonthFinalizedException $e) {
			return false;
		}
		if (!array_key_exists($userId, $scheduleCache)) {
			$scheduleCache[$userId] = $this->counter->scheduleForUser($userId);
		}
		$computed = $this->counter->daysForSchedule($scheduleCache[$userId], $userId, $start, $end);
		// FM-01: never zero out a positive stored value; NULL days are only
		// backfilled when the computed weight is positive (DC-05).
		if ($computed <= 0.0001) {
			return false;
		}
		if ($stored !== null) {
			// Fraction debits (day_fraction) are request input, not calendar
			// weight — never re-inflate them (FM-02, generalized: ratio, not
			// a literal days≈0.5 test which missed every non-unit weight).
			// Fractions only exist on single-day rows and only ever carry a
			// ratio in the write-path contract {0.25, 0.5, 0.75} (generic
			// half-day + entitlement-bound quarters) — any OTHER under-stored
			// ratio is corruption the repair must still fix, and a multi-day
			// under-stored row is legit schedule expansion, not a fraction.
			$singleDay = $start->format('Y-m-d') === $end->format('Y-m-d');
			if ($singleDay && (float)$stored < $computed - 0.011) {
				$ratio = (float)$stored / $computed;
				foreach ([0.25, 0.5, 0.75] as $knownFraction) {
					if (abs($ratio - $knownFraction) < 0.02) {
						return false;
					}
				}
			}
			if (abs($computed - (float)$stored) < 0.011) {
				return false;
			}
		}

		// FM-04: user-mutation lock + CAS-light re-read — a live mutation
		// between our read and the write must drift-skip, never clobber.
		$lockKey = DbLockKeys::absenceUser($userId);
		try {
			$this->lockingProvider->acquireLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE, 'Absence days recompute');
		} catch (\Throwable) {
			return false; // held by a live mutation — rerun picks this row up
		}
		try {
			$fresh = $this->absenceMapper->find($id);
			$freshStamp = $fresh->getUpdatedAt()?->format('c') ?? '';
			$origStamp = $absence->getUpdatedAt()?->format('c') ?? '';
			if ($freshStamp !== $origStamp) {
				return false;
			}
			$old = $stored !== null ? (float)$stored : null;
			$fresh->setDays($computed);
			$this->absenceMapper->update($fresh);
			$this->auditLogMapper->logAction(
				'system', 'absence_days_recomputed', 'absence', $id,
				['days' => $old], ['days' => $computed], 'system'
			);
			return true;
		} finally {
			$this->lockingProvider->releaseLock($lockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}
}
