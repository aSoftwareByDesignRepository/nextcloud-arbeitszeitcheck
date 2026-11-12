<?php

declare(strict_types=1);

/**
 * Central guard: block mutations when a calendar month is finalized.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\TimeEntry;

class MonthClosureGuard
{
	private MonthClosureService $monthClosureService;

	public function __construct(MonthClosureService $monthClosureService)
	{
		$this->monthClosureService = $monthClosureService;
	}

	public function assertTimeEntryMutable(TimeEntry $entry): void
	{
		$start = $entry->getStartTime();
		$end = $entry->getEndTime() ?? $start;
		if ($start === null) {
			return;
		}
		$this->monthClosureService->assertDateRangeMutable($entry->getUserId(), $start, $end);
	}

	public function assertAbsenceMutable(Absence $absence): void
	{
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		$this->monthClosureService->assertDateRangeMutable($absence->getUserId(), $start, $end);
	}

	/**
	 * @param \DateTime $day Any instant on that calendar day
	 */
	public function assertUserDayMutable(string $userId, \DateTime $day): void
	{
		$s = clone $day;
		$s->setTime(0, 0, 0);
		$e = clone $day;
		$e->setTime(23, 59, 59);
		$this->monthClosureService->assertDateRangeMutable($userId, $s, $e);
	}

	/**
	 * Mutability assert over an arbitrary [start, end] window — the range
	 * twin of {@see assertUserDayMutable()}.
	 */
	public function assertRangeMutable(string $userId, \DateTime $rangeStart, \DateTime $rangeEnd): void
	{
		$this->monthClosureService->assertDateRangeMutable($userId, $rangeStart, $rangeEnd);
	}

	/**
	 * Acquire the exclusive month-closure locks (month_closure tier —
	 * always LAST in {@see DbLockKeys::ORDER}) covering every calendar
	 * month overlapping [start, end] for $userId, and return the acquired
	 * keys for {@see releaseMutableRangeLocks()}.
	 *
	 * Writers MUST hold these across their whole check→commit window so a
	 * concurrent finalizeMonth/reopenMonth — which takes the same keys
	 * exclusive — can never interleave between the mutable assert and the
	 * write commit (the disjoint-lock TOCTOU closed on absences by
	 * 28e8125; this is the same contract for every other month-scoped
	 * writer).
	 *
	 * MUST be called before beginTransaction(): the DB locking provider
	 * writes oc_file_locks on the same connection, so an in-tx acquire is
	 * invisible to a concurrent finalize until commit.
	 *
	 * @return list<string> acquired keys
	 * @throws \OCP\Lock\LockedException when a finalize/reopen holds an
	 *         overlapping month (surface as retryable busy / LOCKED)
	 */
	public function acquireMutableRangeLocks(string $userId, \DateTimeInterface $rangeStart, \DateTimeInterface $rangeEnd): array
	{
		$keys = $this->monthClosureService->mutableRangeLockKeys($userId, $rangeStart, $rangeEnd);
		$this->monthClosureService->acquireMonthClosureTierLocks($keys);
		return $keys;
	}

	/**
	 * Acquire exclusive month-closure locks for a precomputed key set —
	 * e.g. the union of several ranges when an update moves a row between
	 * months.
	 *
	 * @param list<string> $keys
	 */
	public function acquireMutableRangeLockKeys(array $keys): void
	{
		$this->monthClosureService->acquireMonthClosureTierLocks($keys);
	}

	/**
	 * Month-closure lock keys for a single range without acquiring —
	 * callers merge several ranges' keys before one acquisition.
	 *
	 * @return list<string>
	 */
	public function mutableRangeLockKeys(string $userId, \DateTimeInterface $rangeStart, \DateTimeInterface $rangeEnd): array
	{
		return $this->monthClosureService->mutableRangeLockKeys($userId, $rangeStart, $rangeEnd);
	}

	/**
	 * Month-closure lock keys covering the months a time entry occupies:
	 * [start .. end], or [start .. now] while still open — an open row
	 * overlaps every later month in range queries, so a seal of a later
	 * month races its completion identically.
	 *
	 * @return list<string>
	 */
	public function timeEntryMutableLockKeys(TimeEntry $entry): array
	{
		$start = $entry->getStartTime();
		if ($start === null) {
			return [];
		}
		// +1 day pad: an open row's live month is resolved in the default
		// zone, which can sit one calendar day behind the storage zone at a
		// month boundary — over-covering a month beats missing the seal.
		$end = $entry->getEndTime() ?? (new \DateTime())->modify('+1 day');
		return $this->mutableRangeLockKeys($entry->getUserId(), $start, $end);
	}

	/**
	 * Release locks returned by {@see acquireMutableRangeLocks()} /
	 * acquired via {@see acquireMutableRangeLockKeys()}. Best effort —
	 * never masks a primary exception.
	 *
	 * @param list<string> $keys
	 */
	public function releaseMutableRangeLocks(array $keys): void
	{
		$this->monthClosureService->releaseMonthClosureTierLocks($keys);
	}
}
