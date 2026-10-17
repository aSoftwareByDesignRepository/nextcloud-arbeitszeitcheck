<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerEntry;
use OCA\ArbeitszeitCheck\Db\BusinessTripCreditLedgerMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCP\Lock\ILockingProvider;

/**
 * Immutable business-trip top-up ledger (spec §9.8).
 *
 * Entries are append-only: a wrong posting is undone by a reversal or
 * correction row, never by update/delete. `event_key` is globally unique
 * and server-namespaced (`bt:<tripId>:<localDate>:<seq>`), so replays are
 * idempotent — the unique index is the final backstop and a duplicate
 * post returns the existing entry instead of a second credit.
 *
 * Top-ups are a separate bounded contribution: they never mutate
 * TimeEntry minutes or the general time account.
 */
final class BusinessTripCreditLedger
{
	public function __construct(
		private readonly ILockingProvider $lockingProvider,
		private readonly BusinessTripCreditLedgerMapper $entries,
	) {
	}

	/**
	 * Post the calculated per-day top-ups for a trip. Idempotent: event keys
	 * are deterministic per (trip, revision, day), so a retried approval
	 * posts nothing twice. Caller must hold the trip + user-month locks and
	 * the transaction; this method acquires the ledger tier (last in the
	 * spec lock order).
	 *
	 * @param list<array<string,mixed>> $days engine day results
	 * @return list<BusinessTripCreditLedgerEntry> entries that exist afterwards
	 */
	public function postTopUps(BusinessTrip $trip, array $days, string $actor): array
	{
		$lock = DbLockKeys::businessTripLedger($trip->getUserId(), (int)$trip->getId());
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Business-trip credit ledger');
		try {
			return $this->postTopUpsUnderLock($trip, $days, $actor);
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Same as {@see postTopUps()} for callers that already hold the ledger
	 * lock inside the spec lock order (ILockingProvider is not reentrant).
	 *
	 * @param list<array<string,mixed>> $days
	 * @return list<BusinessTripCreditLedgerEntry>
	 */
	public function postTopUpsUnderLock(BusinessTrip $trip, array $days, string $actor): array
	{
		$posted = [];
		foreach ($days as $seq => $day) {
			$minutes = (int)$day['accountTopUpMinutes'];
			if ($minutes <= 0) {
				continue;
			}
			$posted[] = $this->append(
				$trip,
				(string)$day['localDate'],
				sprintf('bt:%d:%d:%s:%d', (int)$trip->getId(), (int)$trip->getRevision(), $day['localDate'], $seq),
				BusinessTripCreditLedgerEntry::KIND_TOP_UP,
				$minutes,
				$actor,
				(string)($day['snapshotHash'] ?? ''),
			);
		}
		return $posted;
	}

	/**
	 * Reverse every still-positive top-up of a trip (cancel/reject after
	 * approval). Reversals are new rows; the originals stay immutable.
	 * Idempotent via deterministic reversal event keys.
	 *
	 * @return list<BusinessTripCreditLedgerEntry>
	 */
	public function reverseAll(BusinessTrip $trip, string $actor, string $reason): array
	{
		$lock = DbLockKeys::businessTripLedger($trip->getUserId(), (int)$trip->getId());
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Business-trip credit ledger');
		try {
			return $this->reverseAllUnderLock($trip, $actor, $reason);
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Caller already holds the ledger lock (inside the spec lock order). */
	public function reverseAllUnderLock(BusinessTrip $trip, string $actor, string $reason): array
	{
		$existing = $this->entries->findForTrip((int)$trip->getId());
		$reversedIds = [];
		foreach ($existing as $row) {
			if ($row->getKind() === BusinessTripCreditLedgerEntry::KIND_REVERSAL && $row->getReversesId() !== null) {
				$reversedIds[(int)$row->getReversesId()] = true;
			}
		}
		$reversed = [];
		foreach ($existing as $row) {
			if ($row->getKind() !== BusinessTripCreditLedgerEntry::KIND_TOP_UP
				|| isset($reversedIds[(int)$row->getId()])
				|| (int)$row->getMinutes() <= 0) {
				continue;
			}
			$reversed[] = $this->append(
				$trip,
				$row->getLocalDate()?->format('Y-m-d') ?? '',
				sprintf('bt:%d:rev:%d', (int)$trip->getId(), (int)$row->getId()),
				BusinessTripCreditLedgerEntry::KIND_REVERSAL,
				-((int)$row->getMinutes()),
				$actor,
				(string)($row->getSnapshotHash() ?? ''),
				(int)$row->getId(),
				$reason,
			);
		}
		return $reversed;
	}

	public function balanceForUserDate(string $userId, string $localDate): int
	{
		return $this->entries->balanceForUserDate($userId, $localDate);
	}

	private function append(
		BusinessTrip $trip,
		string $localDate,
		string $eventKey,
		string $kind,
		int $minutes,
		string $actor,
		string $snapshotHash = '',
		?int $reversesId = null,
		?string $reason = null,
	): BusinessTripCreditLedgerEntry {
		if ($eventKey === '' || strlen($eventKey) > 128) {
			throw new BusinessTripException('TRIP_EVENT_KEY_INVALID', 'Invalid ledger event key.', 500);
		}
		if (!in_array($kind, BusinessTripCreditLedgerEntry::KINDS, true)) {
			throw new BusinessTripException('TRIP_EVENT_KIND_INVALID', 'Invalid ledger entry kind.', 500);
		}
		$existing = $this->entries->findByEventKey($eventKey);
		if ($existing !== null) {
			return $existing; // replay — idempotent
		}
		$entry = new BusinessTripCreditLedgerEntry();
		$entry->setUserId((string)$trip->getUserId());
		$entry->setTripId((int)$trip->getId());
		$entry->setLocalDate(new \DateTime($localDate));
		$entry->setEventKey($eventKey);
		$entry->setKind($kind);
		$entry->setMinutes($minutes);
		$entry->setReversesId($reversesId);
		$entry->setSnapshotHash($snapshotHash !== '' ? $snapshotHash : null);
		$entry->setActorUid($actor);
		$entry->setReason($reason);
		$entry->setCreatedAt(new \DateTime());
		try {
			return $this->entries->insert($entry);
		} catch (\OCP\DB\Exception $e) {
			// Unique-key race: another worker committed first — return theirs.
			$winner = $this->entries->findByEventKey($eventKey);
			if ($winner !== null) {
				return $winner;
			}
			throw $e;
		}
	}
}
