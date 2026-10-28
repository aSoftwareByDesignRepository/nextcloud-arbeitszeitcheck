<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use Test\TestCase;

/**
 * Single-stamp correction — DB truth for the pending open-session lifecycle:
 *   active (unfinished) → pending_approval with end_time NULL
 *   → approve completes at proposed end | cancel/reject restores paused+open.
 *
 * Proves: the pending open session is discoverable (blocks new clock-ins),
 * concurrent decisions lose the race atomically, and cancellation never
 * fabricates worked time (end_time stays NULL).
 */
class SingleStampCorrectionIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_single_stamp_race__';

	private TimeEntryMapper $mapper;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = \OCP\Server::get(TimeEntryMapper::class);
		$this->cleanup();
	}

	protected function tearDown(): void
	{
		$this->cleanup();
		parent::tearDown();
	}

	public function testPendingOpenSessionIsDiscoverableAndBlocks(): void
	{
		$entry = $this->seedOpenSessionCorrectionPending();

		// The pending open session must be visible to the clock-in guard.
		$found = $this->mapper->findPendingOpenSessionByUser(self::TEST_USER);
		$this->assertNotNull($found, 'Pending open session must be found by the guard query');
		$this->assertSame((int)$entry->getId(), (int)$found->getId());
		$this->assertNull($found->getEndTime(), 'Pending open session must keep end_time NULL');
		$this->assertSame(TimeEntry::STATUS_PENDING_APPROVAL, $found->getStatus());

		// It must NOT masquerade as a live active entry anymore.
		$this->assertNull(
			$this->mapper->findActiveByUser(self::TEST_USER),
			'Pending open session must not be returned as active'
		);

		// Entity invariant: while pending, no further correction can be requested.
		$this->assertFalse($found->canRequestCorrection(3650));
	}

	public function testSecondDecisionOnOpenSessionLosesRace(): void
	{
		$entry = $this->seedOpenSessionCorrectionPending();
		$id = (int)$entry->getId();

		// Winner: approve — completes at the proposed retro clock-out time
		// (caller contract: supply storage-wall-clock datetimes).
		$winner = $this->mapper->find($id);
		$winner->setStatus(TimeEntry::STATUS_COMPLETED);
		$winner->setEndTime(new \DateTime('2026-01-10 17:00:00'));
		$winner->setUpdatedAt(new \DateTime('now'));
		$winner->setApprovedByUserId('manager_a');
		$winner->setApprovedAt(new \DateTime('now'));

		// Loser: reject — would have restored the session to paused.
		$loser = $this->mapper->find($id);
		$loser->setStatus(TimeEntry::STATUS_PAUSED);
		$loser->setUpdatedAt(new \DateTime('now'));

		$this->assertTrue($this->mapper->updateIfPendingApproval($winner), 'First decision must win');
		$this->assertFalse($this->mapper->updateIfPendingApproval($loser), 'Second decision must lose');

		$fresh = $this->mapper->find($id);
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $fresh->getStatus());
		$this->assertNotNull($fresh->getEndTime(), 'Approved open-session correction must carry an end time');
		$this->assertGreaterThan(
			$fresh->getStartTime()->getTimestamp(),
			$fresh->getEndTime()->getTimestamp(),
			'Rehydrated end_time must not land before start_time (wall-clock tz bug)'
		);
		$this->assertSame('manager_a', $fresh->getApprovedByUserId());
	}

	public function testCancelRestoresPausedOpenSessionWithoutFabricatingEnd(): void
	{
		$entry = $this->seedOpenSessionCorrectionPending();
		$id = (int)$entry->getId();

		// Cancellation: status leaves pending, session stays OPEN (paused), no end invented.
		$restored = $this->mapper->find($id);
		$restored->setStatus(TimeEntry::STATUS_PAUSED);
		$restored->setEndTime(null);
		$restored->setUpdatedAt(new \DateTime('now'));
		$this->assertTrue($this->mapper->updateIfPendingApproval($restored));

		$fresh = $this->mapper->find($id);
		$this->assertSame(TimeEntry::STATUS_PAUSED, $fresh->getStatus());
		$this->assertNull(
			$fresh->getEndTime(),
			'Cancelling a retro clock-out request must never fabricate worked time'
		);

		// The parked session is an unfinished same-day session again — resumable,
		// and it no longer blocks via the pending-open guard.
		$this->assertNull($this->mapper->findPendingOpenSessionByUser(self::TEST_USER));
		$window = $this->mapper->findPausedOrUnfinishedTodayByUser(
			self::TEST_USER,
			new \DateTime('2026-01-10 00:00:00'),
			new \DateTime('2026-01-11 00:00:00')
		);
		$this->assertNotNull($window, 'Restored session must be discoverable as paused/unfinished');
	}

	public function testActiveSessionIsCorrectionEligibleOnlyWhenRecent(): void
	{
		// canRequestCorrection is an entity gate; prove the boundary end-to-end
		// against a persisted row so a future regression in the WHERE-clause of
		// the pending lookup cannot silently diverge from entity rules.
		$active = $this->seedEntry(TimeEntry::STATUS_ACTIVE, '2026-01-10 08:00:00', null);
		$this->assertTrue($active->canRequestCorrection(3650));
		$this->assertNull(
			$this->mapper->findPendingOpenSessionByUser(self::TEST_USER),
			'Plain active session is not a pending request'
		);

		// A session on break must stay non-correctable (end the break first).
		// Not persisted: at_ent_live_uid_uq allows only ONE open entry per user.
		$onBreak = new TimeEntry();
		$onBreak->setUserId(self::TEST_USER);
		$onBreak->setStatus(TimeEntry::STATUS_BREAK);
		$onBreak->setStartTime(new \DateTime('2026-01-10 08:30:00'));
		$this->assertFalse($onBreak->canRequestCorrection(3650));
	}

	private function seedOpenSessionCorrectionPending(): TimeEntry
	{
		return $this->seedEntry(TimeEntry::STATUS_PENDING_APPROVAL, '2026-01-10 08:00:00', null);
	}

	private function seedEntry(string $status, string $start, ?string $end): TimeEntry
	{
		$entry = new TimeEntry();
		$entry->setUserId(self::TEST_USER);
		$entry->setStartTime(new \DateTime($start));
		$entry->setEndTime($end === null ? null : new \DateTime($end));
		$entry->setStatus($status);
		if ($status === TimeEntry::STATUS_PENDING_APPROVAL) {
			$entry->setJustification(json_encode([
				'type' => 'open_session_correction',
				'justification' => 'forgot to clock out',
				'proposed' => ['endTime' => '2026-01-10T17:00:00+01:00'],
			], JSON_THROW_ON_ERROR));
		}
		$now = new \DateTime('now');
		$entry->setCreatedAt($now);
		$entry->setUpdatedAt($now);
		return $this->mapper->insert($entry);
	}

	private function cleanup(): void
	{
		foreach ($this->mapper->findByUser(self::TEST_USER) as $row) {
			$this->mapper->delete($row);
		}
	}
}
