<?php

declare(strict_types=1);

/**
 * Deterministic paid-break allocation (TVöD §6 Abs. 1 S. 2 shift-work).
 *
 * Semantics — identical to the statutory break deduction the engine already
 * applies, plus an attribution step:
 *
 * - Per calendar day (storage timezone) and user, the deductible break pool
 *   is the union of every "counting" break portion that falls inside the
 *   day: JSON `breaks[]` and the legacy breakStart/breakEnd pair, clipped to
 *   the entry span and to the day, filtered by the model's
 *   `min_break_minutes` floor, then merged with the same interval merge the
 *   legal calculator uses (overlapping portions count once).
 * - When the user's working-time model (resolved as-of that day, so a
 *   retroactive recompute honours the historical assignment) carries
 *   `breaks_count_as_work = true`, up to `paid_break_cap_minutes` of that
 *   pool is paid. Cap absent ⇒ the whole pool is paid; cap 0 ⇒ nothing.
 * - The paid amount is attributed to entries by earliest break chronology:
 *   breaks are paid in the order they happened, and a break that crosses
 *   midnight is paid for the day it started on.
 * - The result is persisted as `paid_break_seconds` on each entry — a
 *   snapshot payroll surfaces can read without re-deriving model history.
 *
 * Hard rules:
 * - CALLER MUST HOLD the per-user mutation lock
 *   ({@see TimeTrackingService::withUserMutationLock()}). The day pool spans
 *   entries; without the lock two writers could interleave reads/writes.
 * - REJECTED entries never participate: they produced no working time, so
 *   their breaks cannot consume the pool and their stored value stays 0.
 * - Finalized months are never touched (NN-04): days inside a finalized
 *   month are skipped during computation and entries of a finalized month
 *   are never written. Skips are reported so the recompute job can audit
 *   them by name.
 * - Idempotent + convergent: the same day with the same recorded breaks and
 *   the same as-of policy always yields the same snapshot, regardless of
 *   what ran before (EC-14).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCP\DB\Exception as DbException;
use Psr\Log\LoggerInterface;

class PaidBreakAllocator
{
	/**
	 * Bound on day-window widening passes in {@see recomputeSpan()}. Each pass
	 * widens the fetch by at least the new day range; realistic data converges
	 * in ≤2 (boundary-crossing entries). Exhaustion means pathological
	 * contiguous chains — fail closed rather than allocate on a partial set.
	 */
	private const MAX_WINDOW_EXPAND_PASSES = 8;

	/** Statutory-capable statuses — breaks of these entries build the day pool. */
	private const PARTICIPATING_STATUSES = [
		TimeEntry::STATUS_ACTIVE,
		TimeEntry::STATUS_BREAK,
		TimeEntry::STATUS_PAUSED,
		TimeEntry::STATUS_COMPLETED,
		TimeEntry::STATUS_PENDING_APPROVAL,
	];

	public function __construct(
		private TimeEntryMapper $timeEntryMapper,
		private BreakPolicyResolver $breakPolicyResolver,
		private TimeZoneService $timeZoneService,
		private MonthClosureService $monthClosureService,
		private LoggerInterface $logger,
		private ?\OCA\ArbeitszeitCheck\Db\AuditLogMapper $auditLogMapper = null,
	) {
	}

	/**
	 * Recompute the paid-break snapshot for every entry of $userId that
	 * overlaps [$from, $to]. Entry spans are followed to their full extent
	 * (an overnight entry is recomputed for both days it touches).
	 *
	 * CALLER MUST HOLD the user mutation lock.
	 *
	 * @return array{
	 *     changed: int,
	 *     days_recomputed: int,
	 *     skipped_finalized: list<string>,
	 * }
	 */
	public function recomputeSpan(string $userId, \DateTimeInterface $from, \DateTimeInterface $to): array
	{
		$result = ['changed' => 0, 'days_recomputed' => 0, 'skipped_finalized' => []];
		if ($to->getTimestamp() <= $from->getTimestamp()) {
			return $result;
		}

		$fromDt = \DateTime::createFromInterface($from);
		$toDt = \DateTime::createFromInterface($to);

		try {
			$entries = $this->timeEntryMapper->findOverlapping($userId, $fromDt, $toDt);
		} catch (DbException $e) {
			// A dead DB cannot produce a trustworthy snapshot; let the caller's
			// transaction roll back rather than persist a partial allocation.
			$this->logger->error('PaidBreakAllocator: overlap lookup failed', [
				'app' => 'arbeitszeitcheck',
				'userId' => $userId,
				'exception' => $e,
			]);
			throw $e;
		}
		if ($entries === []) {
			return $result;
		}

		$now = $this->timeZoneService->nowInStorage();
		$nowTs = $now->getTimestamp();

		// Follow every touched entry to its full span so the day pool is
		// complete on the first and last day as well. The day pool is shared
		// per CALENDAR DAY, not per overlapping span: an entry that only
		// shares a day (not the caller's time range) still consumes the same
		// capped pool, so the entry set must be re-fetched for every day the
		// loop will iterate — not just the original overlap window.
		// Fixed-point widening: boundary-crossing entries can extend the day
		// range, which can in turn pull in more entries on the newly covered
		// days. Bounded because unbounded growth would loop on pathological
		// contiguous entry chains.
		$winStart = $from->getTimestamp();
		$winEnd = $to->getTimestamp();
		$fetchedStart = $winStart;
		$fetchedEnd = $winEnd;
		for ($pass = 0; $pass < self::MAX_WINDOW_EXPAND_PASSES; $pass++) {
			foreach ($entries as $entry) {
				$start = $entry->getStartTime();
				if ($start !== null) {
					$winStart = min($winStart, $start->getTimestamp());
				}
				$end = $entry->getEndTime() ?? $now;
				$winEnd = max($winEnd, $end->getTimestamp());
			}
			$rangeStart = $this->storageMidnight($winStart)->getTimestamp();
			$rangeEnd = $this->storageMidnight($winEnd - 1)->getTimestamp() + 86400;
			if ($rangeStart >= $fetchedStart && $rangeEnd <= $fetchedEnd) {
				break;
			}
			$fetchedStart = min($fetchedStart, $rangeStart);
			$fetchedEnd = max($fetchedEnd, $rangeEnd);
			$entries = $this->timeEntryMapper->findOverlapping(
				$userId,
				(new \DateTime('@' . $fetchedStart))->setTimezone($this->timeZoneService->storageTimeZone()),
				(new \DateTime('@' . $fetchedEnd))->setTimezone($this->timeZoneService->storageTimeZone())
			);
		}
		if ($pass === self::MAX_WINDOW_EXPAND_PASSES) {
			// Fail closed: persisting an allocation computed on a partial
			// entry set is exactly the bug this guard exists to prevent.
			throw new \RuntimeException(
				'PaidBreakAllocator: day-pool window did not stabilise after '
				. self::MAX_WINDOW_EXPAND_PASSES . ' widening passes for user ' . $userId
			);
		}

		// Per-day allocation. $shares[entryId] accumulates paid seconds.
		$shares = [];
		$monthFinality = []; // 'Y-m' => bool cache
		$skippedSet = [];

		$cursor = \DateTime::createFromInterface($this->storageMidnight($winStart));
		$lastDayStart = $this->storageMidnight($winEnd - 1);

		while ($cursor->getTimestamp() <= $lastDayStart->getTimestamp()) {
			$dayStart = $cursor->getTimestamp();
			$dayEnd = $dayStart + 86400;

			$monthKey = $cursor->format('Y-m');
			if (!array_key_exists($monthKey, $monthFinality)) {
				$monthFinality[$monthKey] = $this->monthClosureService->isMonthFinalized(
					$userId,
					(int)$cursor->format('Y'),
					(int)$cursor->format('n')
				);
			}
			if ($monthFinality[$monthKey]) {
				$skippedSet[$monthKey] = true;
				$cursor->modify('+1 day');
				continue;
			}

			$this->allocateDay($entries, $userId, $dayStart, $dayEnd, $nowTs, $shares);
			$result['days_recomputed']++;
			$cursor->modify('+1 day');
		}

		// Persist diffs only — no rewrite storms, no spurious audit churn.
		foreach ($entries as $entry) {
			$id = $entry->getId();
			if ($id === null) {
				continue;
			}
			$target = $entry->getStatus() === TimeEntry::STATUS_REJECTED
				? 0
				: ($shares[$id] ?? 0);
			$target = max(0, $target);
			if ($entry->getPaidBreakSeconds() === $target) {
				continue;
			}

			// NN-04: never write a row belonging to a finalized month. The
			// mutation guard already blocks direct edits there; the allocator
			// double-checks because a live span may still overlap it.
			$start = $entry->getStartTime();
			if ($start !== null) {
				$entryMonthKey = $start->format('Y-m');
				$finalized = $monthFinality[$entryMonthKey]
					?? $this->monthClosureService->isMonthFinalized($userId, (int)$start->format('Y'), (int)$start->format('n'));
				if ($finalized) {
					$skippedSet[$entryMonthKey] = true;
					continue;
				}
			}

			$previousPaid = $entry->getPaidBreakSeconds();
			$entry->setPaidBreakSeconds($target);
			try {
				$this->timeEntryMapper->update($entry);
				$result['changed']++;
				// L1-06: same-day reallocation rewrites a sibling entry's
				// paid share without any user-visible mutation of that row —
				// leave a per-entry audit trail for payroll forensics.
				$this->auditShareChange($userId, $id, $entry, $previousPaid, $target);
			} catch (DbException $e) {
				$this->logger->error('PaidBreakAllocator: snapshot write failed', [
					'app' => 'arbeitszeitcheck',
					'userId' => $userId,
					'entryId' => $id,
					'exception' => $e,
				]);
				throw $e;
			}
		}

		$skipped = array_keys($skippedSet);
		sort($skipped);
		$result['skipped_finalized'] = $skipped;
		return $result;
	}

	/**
	 * Per-entry audit row for a reallocation write — the batch-level
	 * `paid_break_recompute` action only records totals, which cannot show
	 * which rows moved. Best-effort: audit failure never blocks the write.
	 */
	private function auditShareChange(string $userId, int $entryId, TimeEntry $entry, int $previousPaid, int $newPaid): void {
		if ($this->auditLogMapper === null) {
			return;
		}
		try {
			$this->auditLogMapper->logAction(
				'system',
				'paid_break_share_changed',
				'time_entry',
				$entryId,
				null,
				[
					'user_id' => $userId,
					'entry_id' => $entryId,
					'date' => $entry->getStartTime()?->format('Y-m-d'),
					'paid_break_seconds_before' => $previousPaid,
					'paid_break_seconds_after' => $newPaid,
				],
				'paid_break_allocator'
			);
		} catch (\Throwable $e) {
			$this->logger->warning('PaidBreakAllocator: share-change audit write failed', [
				'app' => 'arbeitszeitcheck',
				'entryId' => $entryId,
				'exception' => $e,
			]);
		}
	}

	/**
	 * Allocate one calendar day's paid pool across its entries.
	 *
	 * @param list<TimeEntry> $entries
	 * @param array<int,int> $shares accumulated entry-id ⇒ paid seconds
	 */
	private function allocateDay(array $entries, string $userId, int $dayStart, int $dayEnd, int $nowTs, array &$shares): void
	{
		$dayDate = \DateTime::createFromFormat('!U', (string)$dayStart);
		$policy = $this->breakPolicyResolver->paidBreakPolicyForUser($userId, $dayDate);
		if ($policy['count_as_work'] !== true) {
			return; // flag off ⇒ every entry keeps paid 0 for this day
		}
		$poolSeconds = $policy['cap_minutes'] === null
			? PHP_INT_MAX
			: $policy['cap_minutes'] * 60;
		if ($poolSeconds <= 0) {
			return;
		}

		// Collect per-entry merged break intervals for this day. The pool
		// mirrors TimeEntry::getBreakDurationHours() exactly: portions are
		// filtered by the entry's countable floor BEFORE merging, so a
		// sub-floor pause was never deducted from net and can never be paid
		// (EC-01 — it already is working time).
		// Spec §10 "Auflösungsalgorithmus (verbindlich)": day pool distributed
		// per entry ordered by (start_time, id) — each entry receives
		// min(its merged deductible seconds in D, remaining pool).
		$perEntry = [];
		foreach ($entries as $entry) {
			$id = $entry->getId();
			if ($id === null || !in_array($entry->getStatus(), self::PARTICIPATING_STATUSES, true)) {
				continue;
			}
			$floorSeconds = max(0, $entry->resolveCountableMinBreakMinutes()) * 60;
			$raw = $this->breakPolicyResolver->entryBreakIntervals($entry, $dayStart, $dayEnd, $nowTs);
			$filtered = [];
			foreach ($raw as $portion) {
				if ($floorSeconds > 0 && ($portion['end'] - $portion['start']) < $floorSeconds) {
					continue;
				}
				$filtered[] = [$portion['start'], $portion['end']];
			}
			$merged = $this->breakPolicyResolver->mergeIntervals($filtered);
			if ($merged === []) {
				continue;
			}
			$startTs = $entry->getStartTime()?->getTimestamp() ?? PHP_INT_MAX;
			$perEntry[] = [
				'id' => $id,
				'start' => $startTs,
				'seconds' => $this->breakPolicyResolver->intervalSeconds($merged),
			];
		}
		if ($perEntry === []) {
			return;
		}
		usort($perEntry, static fn (array $a, array $b): int => $a['start'] <=> $b['start'] ?: $a['id'] <=> $b['id']);

		$poolRest = $poolSeconds;
		foreach ($perEntry as $row) {
			if ($poolRest <= 0) {
				break;
			}
			$entryPaid = min($row['seconds'], $poolRest);
			$shares[$row['id']] = ($shares[$row['id']] ?? 0) + $entryPaid;
			$poolRest -= $entryPaid;
		}
	}

	/**
	 * Storage-TZ midnight timestamp for a given unix timestamp.
	 */
	private function storageMidnight(int $ts): \DateTimeImmutable
	{
		$dt = (new \DateTimeImmutable('@' . $ts))->setTimezone($this->timeZoneService->storageTimeZone());
		return $dt->setTime(0, 0, 0);
	}
}
