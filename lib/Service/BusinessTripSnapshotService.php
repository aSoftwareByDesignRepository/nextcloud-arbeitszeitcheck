<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculation;
use OCA\ArbeitszeitCheck\Db\BusinessTripSegment;
use OCA\ArbeitszeitCheck\Db\BusinessTripSnapshot;
use OCA\ArbeitszeitCheck\Db\BusinessTripSnapshotMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;

/**
 * Trip snapshots (spec §9.7).
 *
 * A snapshot freezes a hash chain over the trip header, every segment
 * fact and every per-day calculation row. The payload hash is the CAS
 * token for approval: a preview may be stale, and approval only commits
 * when the snapshot it was issued against still equals a fresh
 * recalculation under lock. Snapshot rows are immutable history — a new
 * calculation appends a new snapshot and moves `current_snapshot_id`.
 */
final class BusinessTripSnapshotService
{
	public function __construct(
		private readonly BusinessTripSnapshotMapper $snapshots,
	) {
	}

	/**
	 * Hash chain over all facts that may change the result. Any segment or
	 * rule change produces a different hash — that is the point.
	 *
	 * @param list<BusinessTripSegment> $segments
	 * @param list<array<string,mixed>> $days engine day results
	 * @param list<int> $linkedEntryIds
	 */
	public function payloadHash(BusinessTrip $trip, array $segments, array $days, array $linkedEntryIds): string
	{
		$segmentFacts = array_map(static fn (BusinessTripSegment $s): array => [
			'id' => (int)$s->getId(),
			'start' => $s->getStartsAtUtc()->format('Y-m-d\TH:i:s\Z'),
			'end' => $s->getEndsAtUtc()->format('Y-m-d\TH:i:s\Z'),
			'type' => $s->getSegmentType(),
			'mode' => $s->getTransportMode(),
		], $segments);
		$dayFacts = array_map(static fn (array $d): array => [
			'date' => $d['localDate'],
			'rule' => $d['ruleSetId'] . ':' . $d['ruleConfigHash'],
			'work' => $d['actualWorkMinutes'],
			'active' => $d['activeTravelMinutes'],
			'passive' => $d['passiveTravelMinutes'],
			'compliance' => $d['complianceMinutes'],
			'target' => $d['accountTargetMinutes'],
			'topUp' => $d['accountTopUpMinutes'],
			'hash' => $d['snapshotHash'],
		], $days);
		sort($linkedEntryIds);
		$payload = [
			'trip' => [
				// No revision here: the token binds to the fact set, not the
				// row generation — submit freezes before the CAS bump, so a
				// revision in the hash would make every served token stale by
				// construction and approvals could never commit.
				'id' => (int)$trip->getId(),
				'start' => $trip->getStartsAtUtc()->format('Y-m-d\TH:i:s\Z'),
				'end' => $trip->getEndsAtUtc()->format('Y-m-d\TH:i:s\Z'),
				'evalTz' => $trip->getEvaluationTimezone(),
			],
			'segments' => $segmentFacts,
			'days' => $dayFacts,
			'linkedEntries' => array_values($linkedEntryIds),
		];
		return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
	}

	/** Persist a snapshot row and return it. Caller holds the transaction. */
	public function freeze(BusinessTrip $trip, string $payloadHash, string $kind, string $actor): BusinessTripSnapshot
	{
		if (!in_array($kind, BusinessTripSnapshot::KINDS, true)) {
			throw new BusinessTripException('TRIP_SNAPSHOT_KIND_INVALID', 'Invalid snapshot kind.', 500);
		}
		if ($kind === BusinessTripSnapshot::KIND_PREVIEW) {
			// Previews are advisory: an identical payload must not grow the
			// table unboundedly (preview spam / UI polling). Submit and
			// approval freezes stay append-only — they are the audit trail.
			$latest = $this->snapshots->findLatestForTrip((int)$trip->getId(), $kind);
			if ($latest !== null && hash_equals((string)$latest->getPayloadHash(), $payloadHash)) {
				return $latest;
			}
		}
		$snapshot = new BusinessTripSnapshot();
		$snapshot->setTripId((int)$trip->getId());
		$snapshot->setTripRevision((int)$trip->getRevision());
		$snapshot->setPayloadHash($payloadHash);
		$snapshot->setKind($kind);
		$snapshot->setCreatedBy($actor);
		$snapshot->setCreatedAt(new \DateTime());
		return $this->snapshots->insert($snapshot);
	}

	/**
	 * Fail-closed CAS check (spec §9.7): a missing, unknown, or mismatched
	 * snapshot token is a 409 — never merge against current state.
	 */
	public function assertCurrent(BusinessTrip $trip, ?string $snapshotToken): BusinessTripSnapshot
	{
		if ($snapshotToken === null || trim($snapshotToken) === '') {
			throw new BusinessTripException('TRIP_SNAPSHOT_REQUIRED', 'A calculation snapshot token is required.', 409, ['snapshot' => 'required']);
		}
		$current = $this->current($trip);
		if ($current === null || !hash_equals((string)$current->getPayloadHash(), $snapshotToken)) {
			throw new BusinessTripException('TRIP_SNAPSHOT_STALE', 'The trip changed since the preview; refresh and retry.', 409, ['snapshot' => 'stale']);
		}
		return $current;
	}

	public function current(BusinessTrip $trip): ?BusinessTripSnapshot
	{
		$id = $trip->getCurrentSnapshotId();
		if ($id === null) {
			return null;
		}
		return $this->snapshots->findByIdOrNull((int)$id);
	}
}
