<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTrip;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculation;
use OCA\ArbeitszeitCheck\Db\BusinessTripDayCalculationMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSegment;
use OCA\ArbeitszeitCheck\Db\BusinessTripSegmentMapper;
use OCA\ArbeitszeitCheck\Db\BusinessTripSnapshot;
use OCA\ArbeitszeitCheck\Db\BusinessTripTimeEntryLink;
use OCA\ArbeitszeitCheck\Db\BusinessTripTimeEntryLinkMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserBusinessTripProfileMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Policy\IdempotencyKey;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

/**
 * Business-trip lifecycle (spec §9.6): draft → submitted →
 * approved/rejected/cancelled.
 *
 * Lock order is fixed everywhere (spec §9.6): rule catalog → user-month →
 * trip → linked time entries → ledger. Every mutating path acquires the
 * tiers it touches in exactly that order — no lock upgrade inside a
 * transaction, so two concurrent lifecycle calls cannot deadlock.
 *
 * Invariants:
 * - `requestKey` is unique and server-namespaced (`bt-req:` + key);
 *   a replayed create returns the existing trip, never a duplicate.
 * - All status transitions are CAS on `revision`; missing or stale
 *   revision/snapshot tokens are 409, never a silent merge.
 * - Approval recalculates under lock and requires the client's snapshot
 *   token to equal the fresh payload hash — exactly one concurrent
 *   approval can commit.
 * - Background jobs never finalize trips automatically.
 * - Trips never create work minutes; approved trips may only post the
 *   separate, explicitly bounded top-up ledger entries.
 */
final class BusinessTripService
{
	/** @var array<string,list<string>> allowed state transitions */
	private const TRANSITIONS = [
		BusinessTrip::STATUS_DRAFT => [BusinessTrip::STATUS_SUBMITTED, BusinessTrip::STATUS_CANCELLED],
		BusinessTrip::STATUS_SUBMITTED => [BusinessTrip::STATUS_APPROVED, BusinessTrip::STATUS_REJECTED, BusinessTrip::STATUS_CANCELLED],
		BusinessTrip::STATUS_APPROVED => [BusinessTrip::STATUS_CANCELLED],
		BusinessTrip::STATUS_REJECTED => [],
		BusinessTrip::STATUS_CANCELLED => [],
	];

	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILockingProvider $lockingProvider,
		private readonly WorkforceSuiteConfig $config,
		private readonly BusinessTripMapper $trips,
		private readonly BusinessTripSegmentMapper $segments,
		private readonly BusinessTripTimeEntryLinkMapper $links,
		private readonly BusinessTripDayCalculationMapper $dayCalcs,
		private readonly TimeEntryMapper $timeEntries,
		private readonly UserBusinessTripProfileMapper $profiles,
		private readonly BusinessTripTimelineValidator $timelineValidator,
		private readonly BusinessTripRuleResolver $ruleResolver,
		private readonly BusinessTripCalculationEngine $engine,
		private readonly BusinessTripSnapshotService $snapshotService,
		private readonly BusinessTripCreditLedger $ledger,
		private readonly ?MonthClosureService $monthClosures = null,
		private readonly ?\OCA\ArbeitszeitCheck\Db\AuditLogMapper $audit = null,
	) {
	}

	/**
	 * Create a draft. Idempotent on requestKey — a client retry after a lost
	 * response returns the same trip instead of a duplicate.
	 */
	public function createDraft(
		string $userId,
		\DateTimeInterface $startsAtUtc,
		\DateTimeInterface $endsAtUtc,
		string $originTimezone,
		string $destinationTimezone,
		string $evaluationTimezone,
		?string $purpose,
		string $requestKey,
		string $actor,
	): BusinessTrip {
		$this->assertEnabled();
		$this->assertTimezone($originTimezone, 'originTimezone');
		$this->assertTimezone($destinationTimezone, 'destinationTimezone');
		$this->assertTimezone($evaluationTimezone, 'evaluationTimezone');
		if ($endsAtUtc <= $startsAtUtc) {
			throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'The trip must end after it starts.', 400, ['endsAt' => 'before_start']);
		}
		if ($purpose !== null && strlen($purpose) > 200) {
			throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'Purpose is too long.', 400, ['purpose' => 'too_long']);
		}
		// Server-namespaced so keys can never collide across users.
		$key = IdempotencyKey::parse($requestKey);
		$namespaced = 'bt-req:' . md5($userId) . ':' . $key->value;
		if (strlen($namespaced) > 128) {
			$namespaced = 'bt-req:' . hash('sha256', $userId . ':' . $key->value);
		}

		if ($existing = $this->trips->findByRequestKey($namespaced)) {
			return $existing;
		}
		// Overlap check + insert must serialize against every other writer of
		// this user's trip windows — the per-month user locks do that (any two
		// overlapping windows share at least one month). The closure locks keep
		// a month-finalize from landing between check and write.
		$locks = array_merge(
			$this->userMonthLockKeys($userId, $startsAtUtc, $endsAtUtc, $evaluationTimezone),
			$this->closureLockKeys($userId, $startsAtUtc, $endsAtUtc),
		);
		$this->acquireAllExclusive($locks, 'Business trip create');
		try {
			$this->assertMonthsMutable($userId, $startsAtUtc, $endsAtUtc);
			if ($this->trips->findOverlapping($userId, $startsAtUtc, $endsAtUtc) !== []) {
				throw new BusinessTripException('TRIP_OVERLAP', 'The trip overlaps an existing business trip.', 409, ['window' => 'overlap']);
			}
			$trip = new BusinessTrip();
			$trip->setUserId($userId);
			$trip->setStartsAtUtc(new \DateTime($startsAtUtc->format('Y-m-d H:i:s')));
			$trip->setEndsAtUtc(new \DateTime($endsAtUtc->format('Y-m-d H:i:s')));
			$trip->setOriginTimezone($originTimezone);
			$trip->setDestinationTimezone($destinationTimezone);
			$trip->setEvaluationTimezone($evaluationTimezone);
			$trip->setPurpose($purpose);
			$trip->setStatus(BusinessTrip::STATUS_DRAFT);
			$trip->setRequestKey($namespaced);
			$trip->setRevision(1);
			$now = new \DateTime();
			$trip->setCreatedBy($actor);
			$trip->setUpdatedBy($actor);
			$trip->setCreatedAt($now);
			$trip->setUpdatedAt($now);
			try {
				return $this->trips->insert($trip);
			} catch (\OCP\DB\Exception $e) {
				// Unique request_key race — the concurrent create won; return it.
				$winner = $this->trips->findByRequestKey($namespaced);
				if ($winner !== null) {
					return $winner;
				}
				throw $e;
			}
		} finally {
			$this->releaseAllExclusive($locks);
		}
	}

	/**
	 * Draft-only content update, CAS on revision.
	 *
	 * Lock order: user-months (new window) → trip → linked entries →
	 * month-closure. The entity handed in is pre-lock state — everything is
	 * re-validated under the locks against the fresh row so a window change
	 * can never orphan segments/links or race a concurrent overlap check.
	 */
	public function updateDraft(BusinessTrip $trip, int $expectedRevision, array $fields, string $actor): BusinessTrip
	{
		$this->assertEnabled();
		$this->assertStatus($trip, BusinessTrip::STATUS_DRAFT);
		// Candidate window/tz must be known before lock acquisition.
		$start = isset($fields['startsAtUtc']) ? $this->asDateTime($fields['startsAtUtc'], 'startsAtUtc') : $trip->getStartsAtUtc();
		$end = isset($fields['endsAtUtc']) ? $this->asDateTime($fields['endsAtUtc'], 'endsAtUtc') : $trip->getEndsAtUtc();
		$evalTz = isset($fields['evaluationTimezone']) ? (string)$fields['evaluationTimezone'] : (string)$trip->getEvaluationTimezone();
		$this->assertTimezone($evalTz, 'evaluationTimezone');
		if ($end <= $start) {
			throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'The trip must end after it starts.', 400, ['endsAt' => 'before_start']);
		}
		if (array_key_exists('purpose', $fields) && $fields['purpose'] !== null && strlen((string)$fields['purpose']) > 200) {
			throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'Purpose is too long.', 400, ['purpose' => 'too_long']);
		}

		$locks = array_merge(
			$this->userMonthLockKeys($trip->getUserId(), $start, $end, $evalTz),
			[
				DbLockKeys::businessTripTrip((int)$trip->getId()),
				DbLockKeys::businessTripLinkedEntries((int)$trip->getId()),
			],
			$this->closureLockKeys($trip->getUserId(), $start, $end),
		);
		$this->acquireAllExclusive($locks, 'Business trip update');
		try {
			// Re-read under lock: the entity handed in may be stale.
			$trip = $this->trips->find((int)$trip->getId());
			if ($expectedRevision !== (int)$trip->getRevision()) {
				throw $this->staleRevision();
			}
			$this->assertStatus($trip, BusinessTrip::STATUS_DRAFT);
			if (isset($fields['startsAtUtc'])) {
				$trip->setStartsAtUtc($start);
			}
			if (isset($fields['endsAtUtc'])) {
				$trip->setEndsAtUtc($end);
			}
			foreach (['originTimezone', 'destinationTimezone', 'evaluationTimezone'] as $tzField) {
				if (isset($fields[$tzField])) {
					$this->assertTimezone((string)$fields[$tzField], $tzField);
					$setter = 'set' . ucfirst($tzField);
					$trip->{$setter}((string)$fields[$tzField]);
				}
			}
			if (array_key_exists('purpose', $fields)) {
				$trip->setPurpose($fields['purpose']);
			}
			$this->assertMonthsMutable($trip->getUserId(), $start, $end);
			if ($this->trips->findOverlapping($trip->getUserId(), $start, $end, (int)$trip->getId()) !== []) {
				throw new BusinessTripException('TRIP_OVERLAP', 'The trip overlaps an existing business trip.', 409, ['window' => 'overlap']);
			}
			// A window shrink must not orphan recorded facts.
			foreach ($this->segments->findForTrip((int)$trip->getId()) as $seg) {
				if ($seg->getStartsAtUtc() < $start || $seg->getEndsAtUtc() > $end) {
					throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'The new window would cut a travel block — remove it first.', 400, ['segments' => 'outside_window']);
				}
			}
			foreach ($this->links->findTimeEntryIdsForTrip((int)$trip->getId()) as $entryId) {
				try {
					$entry = $this->timeEntries->find($entryId);
				} catch (DoesNotExistException) {
					continue;
				}
				if ($entry->getStartTime() === null || $entry->getStartTime() < $start || ($entry->getEndTime() !== null && $entry->getEndTime() > $end)) {
					throw new BusinessTripException('TRIP_LINK_OUTSIDE_WINDOW', 'The new window would cut a linked working time — unlink it first.', 400, ['timeEntryId' => 'outside_window']);
				}
			}
			$trip->setUpdatedBy($actor);
			$trip->setUpdatedAt(new \DateTime());
			if (!$this->trips->updateContentIfRevision($trip)) {
				throw $this->staleRevision();
			}
			return $trip;
		} finally {
			$this->releaseAllExclusive($locks);
		}
	}

	/**
	 * Atomically replace all segments of a draft (revalidation inside the
	 * trip lock; unique-fact invariant enforced by the timeline validator).
	 *
	 * @param list<array{startsAtUtc:string|\DateTimeInterface,endsAtUtc:string|\DateTimeInterface,segmentType:string,transportMode:?string}> $rows
	 */
	public function replaceSegments(BusinessTrip $trip, int $expectedRevision, array $rows, string $actor): BusinessTrip
	{
		$this->assertEnabled();
		$this->assertStatus($trip, BusinessTrip::STATUS_DRAFT);
		$lock = DbLockKeys::businessTripTrip((int)$trip->getId());
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Business trip');
		try {
			// Re-read under lock: the entity handed in may be stale.
			$trip = $this->trips->find((int)$trip->getId());
			if ($expectedRevision !== (int)$trip->getRevision()) {
				throw $this->staleRevision();
			}
			$this->assertStatus($trip, BusinessTrip::STATUS_DRAFT);

			$normalized = [];
			foreach ($rows as $row) {
				$normalized[] = [
					'startsAtUtc' => $this->asDateTime($row['startsAtUtc'] ?? null, 'segments'),
					'endsAtUtc' => $this->asDateTime($row['endsAtUtc'] ?? null, 'segments'),
					'segmentType' => (string)($row['segmentType'] ?? ''),
					'transportMode' => isset($row['transportMode']) ? (string)$row['transportMode'] : null,
				];
			}
			$this->timelineValidator->validate($normalized, $trip->getStartsAtUtc(), $trip->getEndsAtUtc());

			$this->db->beginTransaction();
			try {
				$this->segments->deleteForTrip((int)$trip->getId());
				$now = new \DateTime();
				foreach ($normalized as $row) {
					$seg = new BusinessTripSegment();
					$seg->setTripId((int)$trip->getId());
					$seg->setStartsAtUtc($row['startsAtUtc']);
					$seg->setEndsAtUtc($row['endsAtUtc']);
					$seg->setSegmentType($row['segmentType']);
					$seg->setTransportMode($row['transportMode']);
					$seg->setSource($actor === $trip->getUserId() ? BusinessTripSegment::SOURCE_EMPLOYEE : BusinessTripSegment::SOURCE_MANAGER);
					$seg->setRevision(1);
					$seg->setCreatedAt($now);
					$seg->setUpdatedAt($now);
					$this->segments->insert($seg);
				}
				// Any content change invalidates prior snapshots implicitly
				// (payload hash covers segment facts + revision).
				$trip->setUpdatedBy($actor);
				$trip->setUpdatedAt($now);
				if (!$this->trips->updateContentIfRevision($trip)) {
					throw $this->staleRevision();
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return $trip;
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Link an existing TimeEntry (must belong to the trip owner). */
	public function linkTimeEntry(BusinessTrip $trip, int $timeEntryId, string $actor): BusinessTripTimeEntryLink
	{
		$this->assertEnabled();
		$this->assertStatus($trip, BusinessTrip::STATUS_DRAFT);
		$linkLock = DbLockKeys::businessTripLinkedEntries((int)$trip->getId());
		$this->lockingProvider->acquireLock($linkLock, ILockingProvider::LOCK_EXCLUSIVE, 'Trip time-entry links');
		try {
			// Re-read under lock — the trip may have transitioned meanwhile.
			$trip = $this->trips->find((int)$trip->getId());
			$this->assertStatus($trip, BusinessTrip::STATUS_DRAFT);
			try {
				$entry = $this->timeEntries->find($timeEntryId);
			} catch (DoesNotExistException) {
				throw new BusinessTripException('TIME_ENTRY_NOT_FOUND', 'Time entry not found.', 404);
			}
			// Uniform failure for foreign entries — never leak existence.
			if ($entry->getUserId() !== $trip->getUserId()) {
				throw new BusinessTripException('TIME_ENTRY_NOT_FOUND', 'Time entry not found.', 404);
			}
			// Entries must lie inside the trip window.
			if ($entry->getStartTime() === null || $entry->getStartTime() < $trip->getStartsAtUtc()
				|| ($entry->getEndTime() !== null && $entry->getEndTime() > $trip->getEndsAtUtc())) {
				throw new BusinessTripException('TRIP_LINK_OUTSIDE_WINDOW', 'The time entry lies outside the trip window.', 400, ['timeEntryId' => 'outside_window']);
			}
			// One entry links to at most one trip ever.
			if ($this->links->findByTimeEntryId($timeEntryId) !== null) {
				throw new BusinessTripException('TRIP_LINK_TAKEN', 'The time entry is already linked to a business trip.', 409, ['timeEntryId' => 'already_linked']);
			}
			$link = new BusinessTripTimeEntryLink();
			$link->setTripId((int)$trip->getId());
			$link->setTimeEntryId($timeEntryId);
			$link->setCreatedAt(new \DateTime());
			try {
				return $this->links->insert($link);
			} catch (\OCP\DB\Exception $e) {
				if ($this->links->findByTimeEntryId($timeEntryId) !== null) {
					throw new BusinessTripException('TRIP_LINK_TAKEN', 'The time entry is already linked to a business trip.', 409, ['timeEntryId' => 'already_linked']);
				}
				throw $e;
			}
		} finally {
			$this->lockingProvider->releaseLock($linkLock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** Unlink a time entry — draft only, uniform not-found for foreign links. */
	public function unlinkTimeEntry(BusinessTrip $trip, int $timeEntryId, string $actor): void
	{
		$this->assertEnabled();
		$this->assertStatus($trip, BusinessTrip::STATUS_DRAFT);
		$linkLock = DbLockKeys::businessTripLinkedEntries((int)$trip->getId());
		$this->lockingProvider->acquireLock($linkLock, ILockingProvider::LOCK_EXCLUSIVE, 'Trip time-entry links');
		try {
			$trip = $this->trips->find((int)$trip->getId());
			$this->assertStatus($trip, BusinessTrip::STATUS_DRAFT);
			$link = $this->links->findByTimeEntryId($timeEntryId);
			if ($link === null || (int)$link->getTripId() !== (int)$trip->getId()) {
				throw new BusinessTripException('TIME_ENTRY_NOT_FOUND', 'Time entry not found.', 404);
			}
			$this->links->delete($link);
		} finally {
			$this->lockingProvider->releaseLock($linkLock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Read-only preview (spec §9.7): recalculates and freezes a preview
	 * snapshot whose payload hash is the approval CAS token. May be stale —
	 * approval always recalculates under lock.
	 *
	 * @return array{days:list<array<string,mixed>>,warnings:list<string>,snapshotToken:string}
	 */
	public function preview(BusinessTrip $trip, string $actor): array
	{
		$this->assertEnabled();
		$lock = DbLockKeys::businessTripTrip((int)$trip->getId());
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_SHARED, 'Business trip');
		try {
			$segments = $this->segments->findForTrip((int)$trip->getId());
			$entries = $this->linkedEntries($trip);
			$result = $this->engine->calculate(
				$trip, $segments, $entries,
				fn (string $localDate) => $this->ruleResolver->resolveForDay($trip->getUserId(), new \DateTimeImmutable($localDate)),
				$this->normalCommuteMinutes($trip),
			);
			$token = $this->snapshotService->payloadHash($trip, $segments, $result['days'], $this->links->findTimeEntryIdsForTrip((int)$trip->getId()));
			$this->db->beginTransaction();
			try {
				$snapshot = $this->snapshotService->freeze($trip, $token, BusinessTripSnapshot::KIND_PREVIEW, $actor);
				// Advisory pointer only — must not bump revision (a preview is
				// not a mutation; bumping would invalidate the client's CAS).
				$this->trips->setSnapshotPointer((int)$trip->getId(), (int)$snapshot->getId(), (int)$trip->getRevision());
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			return ['days' => $result['days'], 'warnings' => $result['warnings'], 'snapshotToken' => $token];
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_SHARED);
		}
	}

	/** draft → submitted. Recalculates and persists day rows + snapshot. */
	public function submit(BusinessTrip $trip, int $expectedRevision, string $actor): BusinessTrip
	{
		return $this->transition($trip, $expectedRevision, BusinessTrip::STATUS_SUBMITTED, $actor);
	}

	/**
	 * submitted → approved. Requires the preview snapshot token: the trip
	 * is recalculated under the full lock order and the fresh payload hash
	 * must equal the token. Exactly one concurrent approval commits —
	 * the CAS revision write is the tie-breaker.
	 */
	public function approve(BusinessTrip $trip, int $expectedRevision, string $snapshotToken, string $actor): BusinessTrip
	{
		return $this->transition($trip, $expectedRevision, BusinessTrip::STATUS_APPROVED, $actor, $snapshotToken);
	}

	public function reject(BusinessTrip $trip, int $expectedRevision, string $actor, ?string $reason = null): BusinessTrip
	{
		return $this->transition($trip, $expectedRevision, BusinessTrip::STATUS_REJECTED, $actor, null, $reason);
	}

	public function cancel(BusinessTrip $trip, int $expectedRevision, string $actor, ?string $reason = null): BusinessTrip
	{
		return $this->transition($trip, $expectedRevision, BusinessTrip::STATUS_CANCELLED, $actor, null, $reason);
	}

	/** Drafts may be hard-deleted; any later state is append-only history. */
	public function delete(BusinessTrip $trip, int $expectedRevision, string $actor): void
	{
		$this->assertEnabled();
		$this->assertStatus($trip, BusinessTrip::STATUS_DRAFT);
		if ($expectedRevision !== (int)$trip->getRevision()) {
			throw $this->staleRevision();
		}
		$lock = DbLockKeys::businessTripTrip((int)$trip->getId());
		$this->lockingProvider->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE, 'Business trip');
		try {
			// Re-read under lock — the CAS predicate must bind to fresh state.
			$trip = $this->trips->find((int)$trip->getId());
			if ($expectedRevision !== (int)$trip->getRevision()) {
				throw $this->staleRevision();
			}
			$this->assertStatus($trip, BusinessTrip::STATUS_DRAFT);
			$this->db->beginTransaction();
			try {
				$this->links->deleteForTrip((int)$trip->getId());
				$this->segments->deleteForTrip((int)$trip->getId());
				$this->dayCalcs->deleteForTrip((int)$trip->getId());
				$this->trips->delete($trip);
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->lockingProvider->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/** @return list<BusinessTrip> */
	public function listForUser(string $userId, int $limit = 100, int $offset = 0): array
	{
		return $this->trips->findForUser($userId, $limit, $offset);
	}

	/**
	 * Read-only detail: segments, linked entry ids, the persisted day
	 * calculations, and the current snapshot token. The token is advisory
	 * — approval always recalculates under lock, so serving a stale token
	 * degrades to a 409, never a wrong decision.
	 *
	 * @return array{segments:list<array<string,mixed>>,linkedTimeEntryIds:list<int>,days:list<array<string,mixed>>,snapshotToken:?string}
	 */
	public function detail(BusinessTrip $trip): array
	{
		$this->assertEnabled();
		$tripId = (int)$trip->getId();
		$segments = array_map(
			static fn (BusinessTripSegment $s) => [
				'id' => (int)$s->getId(),
				'startsAtUtc' => $s->getStartsAtUtc()?->format('c'),
				'endsAtUtc' => $s->getEndsAtUtc()?->format('c'),
				'segmentType' => (string)$s->getSegmentType(),
				'transportMode' => $s->getTransportMode(),
				'source' => (string)$s->getSource(),
			],
			$this->segments->findForTrip($tripId),
		);
		$days = array_map(
			static fn (BusinessTripDayCalculation $d) => [
				'localDate' => $d->getLocalDate()?->format('Y-m-d'),
				'ruleSetId' => $d->getRuleSetId() !== null ? (int)$d->getRuleSetId() : null,
				'presenceMinutes' => (int)$d->getPresenceMinutes(),
				'actualWorkMinutes' => (int)$d->getActualWorkMinutes(),
				'activeTravelMinutes' => (int)$d->getActiveTravelMinutes(),
				'passiveTravelMinutes' => (int)$d->getPassiveTravelMinutes(),
				'complianceMinutes' => (int)$d->getComplianceMinutes(),
				'accountTargetMinutes' => (int)$d->getAccountTargetMinutes(),
				'accountTopUpMinutes' => (int)$d->getAccountTopUpMinutes(),
				'exportMinutes' => (int)$d->getExportMinutes(),
			],
			$this->dayCalcs->findForTrip($tripId),
		);
		$snapshot = $this->snapshotService->current($trip);
		$token = $snapshot !== null ? (string)$snapshot->getPayloadHash() : null;
		return [
			'segments' => $segments,
			'linkedTimeEntryIds' => array_map('intval', $this->links->findTimeEntryIdsForTrip($tripId)),
			'days' => $days,
			'snapshotToken' => $token,
		];
	}

	public function findForUserOrFail(string $userId, int $tripId): BusinessTrip
	{
		try {
			$trip = $this->trips->find($tripId);
		} catch (DoesNotExistException) {
			throw new BusinessTripException('TRIP_NOT_FOUND', 'Not found.', 404);
		}
		// Uniform 404 for foreign trips — no existence oracle.
		if ($trip->getUserId() !== $userId) {
			throw new BusinessTripException('TRIP_NOT_FOUND', 'Not found.', 404);
		}
		return $trip;
	}

	// ------------------------------------------------------------------

	private function transition(
		BusinessTrip $trip,
		int $expectedRevision,
		string $target,
		string $actor,
		?string $snapshotToken = null,
		?string $reason = null,
	): BusinessTrip {
		$this->assertEnabled();
		if (!in_array($target, self::TRANSITIONS[$trip->getStatus()] ?? [], true)) {
			throw new BusinessTripException('TRIP_STATE_INVALID', 'This status change is not allowed.', 409, ['status' => 'invalid_transition']);
		}
		// Four-eyes: nobody may decide on their own trip. Employee withdrawal
		// goes through cancel(), never a self-decision.
		if ($actor === (string)$trip->getUserId() && in_array($target, [BusinessTrip::STATUS_APPROVED, BusinessTrip::STATUS_REJECTED], true)) {
			throw new BusinessTripException('TRIP_SELF_DECISION', 'You cannot decide on your own business trip.', 403, ['actor' => 'self']);
		}

		// Spec lock order: rule catalog → user-month → trip → linked time
		// entries → ledger → month closure. Locks acquired once, outermost
		// first; months come from the entity handed in and are re-verified
		// against the fresh row under lock below.
		$lockedMonths = $this->tripMonths($trip);
		$locks = [
			DbLockKeys::businessTripRuleCatalog(),
		];
		foreach ($lockedMonths as $month) {
			$locks[] = DbLockKeys::businessTripUserMonth($trip->getUserId(), $month);
		}
		$locks[] = DbLockKeys::businessTripTrip((int)$trip->getId());
		$locks[] = DbLockKeys::businessTripLinkedEntries((int)$trip->getId());
		$locks[] = DbLockKeys::businessTripLedger($trip->getUserId(), (int)$trip->getId());
		foreach ($this->closureLockKeys($trip->getUserId(), $trip->getStartsAtUtc(), $trip->getEndsAtUtc()) as $key) {
			$locks[] = $key;
		}
		$this->acquireAllExclusive($locks, 'Business trip transition');
		try {
			$trip = $this->trips->find((int)$trip->getId());
			if ($expectedRevision !== (int)$trip->getRevision()) {
				throw $this->staleRevision();
			}
			if (!in_array($target, self::TRANSITIONS[$trip->getStatus()] ?? [], true)) {
				throw new BusinessTripException('TRIP_STATE_INVALID', 'This status change is not allowed.', 409, ['status' => 'invalid_transition']);
			}
			// The window may have moved between lock acquisition and re-read;
			// the held user-month locks must still cover the real window.
			if ($this->tripMonths($trip) !== $lockedMonths) {
				throw $this->staleRevision();
			}
			// A decision writes calculation/ledger artifacts — a finalized
			// month must reject them (spec: closed-month guard).
			if (in_array($target, [BusinessTrip::STATUS_SUBMITTED, BusinessTrip::STATUS_APPROVED], true)
				|| $trip->getStatus() === BusinessTrip::STATUS_APPROVED) {
				$this->assertMonthsMutable($trip->getUserId(), $trip->getStartsAtUtc(), $trip->getEndsAtUtc());
			}
			// Overlap is re-checked under the user-month locks so a concurrent
			// create/update can never slip an overlapping trip past a decision.
			if (in_array($target, [BusinessTrip::STATUS_SUBMITTED, BusinessTrip::STATUS_APPROVED], true)
				&& $this->trips->findOverlapping($trip->getUserId(), $trip->getStartsAtUtc(), $trip->getEndsAtUtc(), (int)$trip->getId()) !== []) {
				throw new BusinessTripException('TRIP_OVERLAP', 'The trip overlaps an existing business trip.', 409, ['window' => 'overlap']);
			}

			$this->db->beginTransaction();
			try {
				$result = null;
				if (in_array($target, [BusinessTrip::STATUS_SUBMITTED, BusinessTrip::STATUS_APPROVED], true)) {
					// Recalculate facts under lock — preview output is never trusted.
					$segments = $this->segments->findForTrip((int)$trip->getId());
					$entries = $this->linkedEntries($trip);
					$result = $this->engine->calculate(
						$trip, $segments, $entries,
						fn (string $localDate) => $this->ruleResolver->resolveForDay($trip->getUserId(), new \DateTimeImmutable($localDate)),
						$this->normalCommuteMinutes($trip),
					);
					$freshToken = $this->snapshotService->payloadHash($trip, $segments, $result['days'], $this->links->findTimeEntryIdsForTrip((int)$trip->getId()));

					if ($target === BusinessTrip::STATUS_APPROVED) {
						// CAS on the snapshot: the approver decides on the
						// exact fact set they reviewed — nothing else.
						if ($snapshotToken === null || !hash_equals($freshToken, $snapshotToken)) {
							throw new BusinessTripException('TRIP_SNAPSHOT_STALE', 'The trip changed since the preview; refresh and retry.', 409, ['snapshot' => 'stale']);
						}
						$snapshot = $this->snapshotService->freeze($trip, $freshToken, BusinessTripSnapshot::KIND_APPROVED, $actor);
						$trip->setCurrentSnapshotId((int)$snapshot->getId());
					} else {
						$snapshot = $this->snapshotService->freeze($trip, $freshToken, BusinessTripSnapshot::KIND_SUBMITTED, $actor);
						$trip->setCurrentSnapshotId((int)$snapshot->getId());
					}

					// Append a fresh immutable day-calculation generation.
					$this->dayCalcs->deleteForTrip((int)$trip->getId());
					foreach ($result['days'] as $day) {
						$this->dayCalcs->insert($this->engine->toEntity($trip, $day));
					}
				}

				$trip->setStatus($target);
				$trip->setUpdatedBy($actor);
				$trip->setUpdatedAt(new \DateTime());
				if (!$this->trips->updateIfRevision($trip)) {
					throw $this->staleRevision();
				}

				if ($target === BusinessTrip::STATUS_APPROVED && $result !== null) {
					// The credit flag is an independent gate: approval still
					// computes + stores day calcs, but only posts the separate
					// top-up contribution when credits are enabled.
					if ($this->config->isEnabled('business_trip_credit_enabled')) {
						$this->ledger->postTopUpsUnderLock($trip, $result['days'], $actor);
					}
				}
				if ($target === BusinessTrip::STATUS_CANCELLED) {
					$this->ledger->reverseAllUnderLock($trip, $actor, $reason ?? 'cancelled');
				}
				$this->db->commit();
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
			// Audit is deliberately post-commit: a logging failure must never
			// roll back a committed transition (the ledger is the audit of
			// record for credits; this log is operational).
			$this->audit?->logAction(
				$trip->getUserId(),
				'business_trip_' . $target,
				'business_trip',
				(int)$trip->getId(),
				null,
				['status' => $target, 'reason' => $reason],
				$actor,
			);
			return $trip;
		} finally {
			$this->releaseAllExclusive($locks);
		}
	}

	/** @return list<TimeEntry> */
	private function linkedEntries(BusinessTrip $trip): array
	{
		$entries = [];
		foreach ($this->links->findTimeEntryIdsForTrip((int)$trip->getId()) as $entryId) {
			try {
				$entry = $this->timeEntries->find($entryId);
				if ($entry->getUserId() === $trip->getUserId()) {
					$entries[] = $entry;
				}
			} catch (DoesNotExistException) {
				// Entry deleted after linking — it simply contributes nothing.
			}
		}
		return $entries;
	}

	private function normalCommuteMinutes(BusinessTrip $trip): ?int
	{
		$profile = $this->profiles->findEffectiveForUser(
			$trip->getUserId(),
			$trip->getStartsAtUtc()->format('Y-m-d'),
		);
		return $profile?->getNormalCommuteMinutes();
	}

	/**
	 * Sorted list of `Y-m` months the window touches in the evaluation
	 * timezone — the user-month lock set.
	 *
	 * @return list<string>
	 */
	private function tripMonths(BusinessTrip $trip): array
	{
		return $this->windowMonths($trip->getStartsAtUtc(), $trip->getEndsAtUtc(), (string)$trip->getEvaluationTimezone());
	}

	/**
	 * Sorted `Y-m` months a window covers in the given timezone. Locking ALL
	 * touched months keeps overlap checks race-free: two overlapping windows
	 * always share at least one month bucket.
	 *
	 * @return list<string>
	 */
	private function windowMonths(\DateTimeInterface $start, \DateTimeInterface $end, string $timezone): array
	{
		$tz = new \DateTimeZone($timezone);
		$startDay = \DateTimeImmutable::createFromInterface($start)->setTimezone($tz)->setTime(0, 0);
		$endDay = \DateTimeImmutable::createFromInterface($end)->setTimezone($tz)->setTime(0, 0);
		$months = [];
		for ($c = $startDay->modify('first day of this month'); $c <= $endDay; $c = $c->modify('+1 month')) {
			$months[] = $c->format('Y-m');
		}
		sort($months); // deterministic acquisition order across callers
		return array_values(array_unique($months));
	}

	/**
	 * User-month lock keys for every month a UTC window covers in `tz` —
	 * the user_month tier of the spec lock order.
	 *
	 * @return list<string>
	 */
	private function userMonthLockKeys(string $userId, \DateTimeInterface $start, \DateTimeInterface $end, string $evaluationTimezone): array
	{
		return array_map(
			static fn (string $m): string => DbLockKeys::businessTripUserMonth($userId, $m),
			$this->windowMonths($start, $end, $evaluationTimezone),
		);
	}

	/**
	 * Month-closure lock keys (month_closure tier — always LAST in the
	 * order) for every finalized-month candidate of a UTC window. The
	 * month-closure domain slices by UTC calendar month.
	 *
	 * @return list<string>
	 */
	private function closureLockKeys(string $userId, \DateTimeInterface $start, \DateTimeInterface $end): array
	{
		if ($this->monthClosures === null) {
			return [];
		}
		$keys = [];
		foreach ($this->monthClosures->monthsOverlappingRange(
			new \DateTime($start->format('Y-m-d H:i:s')),
			new \DateTime($end->format('Y-m-d H:i:s')),
		) as [$y, $m]) {
			$keys[] = DbLockKeys::monthClosure($userId, $y, $m);
		}
		sort($keys);
		return $keys;
	}

	/**
	 * Acquire exclusive locks in the given order; a partial acquisition
	 * failure releases what was taken instead of leaking locks until TTL.
	 *
	 * @param list<string> $keys
	 */
	private function acquireAllExclusive(array $keys, string $note): void
	{
		$held = [];
		try {
			foreach ($keys as $key) {
				$this->lockingProvider->acquireLock($key, ILockingProvider::LOCK_EXCLUSIVE, $note);
				$held[] = $key;
			}
		} catch (\Throwable $e) {
			foreach (array_reverse($held) as $key) {
				try {
					$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
				} catch (\Throwable) {
					// Best effort — the lock TTL is the final safety net.
				}
			}
			throw $e;
		}
	}

	/** @param list<string> $keys */
	private function releaseAllExclusive(array $keys): void
	{
		foreach (array_reverse($keys) as $key) {
			$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Closed-month guard: a finalized month must reject trip mutations that
	 * produce calculation or ledger artifacts. Callers hold the matching
	 * month-closure locks so a finalize cannot land between check and write.
	 */
	private function assertMonthsMutable(string $userId, \DateTimeInterface $start, \DateTimeInterface $end): void
	{
		if ($this->monthClosures === null) {
			return;
		}
		try {
			$this->monthClosures->assertDateRangeMutable(
				$userId,
				new \DateTime($start->format('Y-m-d H:i:s')),
				new \DateTime($end->format('Y-m-d H:i:s')),
			);
		} catch (MonthFinalizedException) {
			throw new BusinessTripException('MONTH_FINALIZED', 'The month is already finalized for this period.', 409, ['month' => 'finalized']);
		}
	}

	private function assertEnabled(): void
	{
		// Fail-closed: upgraded installations keep every new domain dark
		// until an admin explicitly opts in.
		if (!$this->config->isEnabled('business_trips_enabled')) {
			throw new BusinessTripException('FEATURE_DISABLED', 'Business trips are not enabled.', 404);
		}
	}

	private function assertStatus(BusinessTrip $trip, string $expected): void
	{
		if ($trip->getStatus() !== $expected) {
			throw new BusinessTripException('TRIP_STATE_INVALID', 'The trip is not in the required state.', 409, ['status' => 'invalid_state']);
		}
	}

	private function staleRevision(): BusinessTripException
	{
		return new BusinessTripException('TRIP_REVISION_STALE', 'The trip changed; reload and retry.', 409, ['revision' => 'stale']);
	}

	private function assertTimezone(string $tz, string $field): void
	{
		if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
			throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'Unknown timezone.', 400, [$field => 'invalid']);
		}
	}

	private function asDateTime(mixed $value, string $field): \DateTime
	{
		if ($value instanceof \DateTimeInterface) {
			return new \DateTime($value->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
		}
		if (is_string($value)) {
			$parsed = \DateTime::createFromFormat(\DateTime::ATOM, $value)
				?: \DateTime::createFromFormat('Y-m-d\TH:i:s', $value, new \DateTimeZone('UTC'));
			if ($parsed !== false) {
				return $parsed;
			}
		}
		throw new BusinessTripException('TRIP_VALIDATION_ERROR', 'Invalid timestamp.', 400, [$field => 'invalid']);
	}
}
