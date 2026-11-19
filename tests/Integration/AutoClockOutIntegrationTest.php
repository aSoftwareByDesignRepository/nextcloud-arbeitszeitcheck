<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\BusinessRuleCode;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * DB truth: the background auto clock-out write path persists the planned
 * end, the automatic ended-reason and the audit capture source, stays
 * idempotent and refuses to close a different entry than the scanned one.
 */
class AutoClockOutIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_auto_clock_out__';

	private TimeTrackingService $timeTracking;

	private TimeEntryMapper $timeEntryMapper;

	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->timeTracking = \OCP\Server::get(TimeTrackingService::class);
		$this->timeEntryMapper = \OCP\Server::get(TimeEntryMapper::class);
		$this->db = \OCP\Server::get(IDBConnection::class);
		$this->cleanup();
	}

	protected function tearDown(): void
	{
		$this->cleanup();
		parent::tearDown();
	}

	public function testAutoClockOutPersistsPlannedEndReasonAndAuditSource(): void
	{
		$entry = $this->insertOpenEntry('active');
		$start = $entry->getStartTime();
		$this->assertNotNull($start);
		$plannedEnd = (clone $start)->modify('+8 hours');
		$this->assertLessThan(time(), $plannedEnd->getTimestamp(), 'fixture must be fully in the past');

		$updated = $this->timeTracking->autoClockOutAtPlannedEnd(
			self::TEST_USER,
			(int)$entry->getId(),
			$plannedEnd,
		);

		$this->assertSame(TimeEntry::STATUS_COMPLETED, $updated->getStatus());
		$this->assertSame(TimeEntry::ENDED_REASON_AUTO_PLANNED_END, $updated->getEndedReason());
		$this->assertNotNull($updated->getEndTime());
		$this->assertSame(
			$plannedEnd->format('Y-m-d H:i:s'),
			$updated->getEndTime()->format('Y-m-d H:i:s'),
			'End time must be the resolved planned end, never the wall clock',
		);

		$this->assertSame(
			Constants::AUDIT_CAPTURE_SOURCE_AUTO_CLOCK_OUT,
			$this->readAuditCaptureSource((int)$entry->getId()),
			'The clock_out audit row must carry the auto_clock_out capture source',
		);
	}

	public function testAutoClockOutIsIdempotentForCompletedEntry(): void
	{
		$entry = $this->insertOpenEntry('active');
		$start = $entry->getStartTime();
		$this->assertNotNull($start);
		$plannedEnd = (clone $start)->modify('+8 hours');

		$this->timeTracking->autoClockOutAtPlannedEnd(self::TEST_USER, (int)$entry->getId(), $plannedEnd);

		try {
			$this->timeTracking->autoClockOutAtPlannedEnd(self::TEST_USER, (int)$entry->getId(), $plannedEnd);
			$this->fail('Second run on the already completed entry must be rejected');
		} catch (BusinessRuleException $e) {
			$this->assertSame(BusinessRuleCode::NOT_CLOCKED_IN, $e->getReasonCode());
		}

		$reloaded = $this->timeEntryMapper->find((int)$entry->getId());
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $reloaded->getStatus());
		$this->assertSame(
			$plannedEnd->format('Y-m-d H:i:s'),
			$reloaded->getEndTime()?->format('Y-m-d H:i:s'),
			'Second run must not move the persisted end time',
		);
	}

	public function testAutoClockOutRejectsMismatchedEntryIdWithoutTouchingRow(): void
	{
		$entry = $this->insertOpenEntry('active');
		$start = $entry->getStartTime();
		$this->assertNotNull($start);

		try {
			$this->timeTracking->autoClockOutAtPlannedEnd(
				self::TEST_USER,
				(int)$entry->getId() + 999999,
				(clone $start)->modify('+8 hours'),
			);
			$this->fail('A scanned-entry id mismatch must be rejected as already handled');
		} catch (BusinessRuleException $e) {
			$this->assertSame(BusinessRuleCode::NOT_CLOCKED_IN, $e->getReasonCode());
		}

		$reloaded = $this->timeEntryMapper->find((int)$entry->getId());
		$this->assertSame(TimeEntry::STATUS_ACTIVE, $reloaded->getStatus());
		$this->assertNull($reloaded->getEndTime());
		$this->assertNull($reloaded->getEndedReason());
	}

	public function testAutoClockOutVetoesOverlappingEntryUnderLock(): void
	{
		// A second entry that lands between the job's pre-flight overlap
		// scan and the locked write must veto the closure — the unit suite
		// mocks findOverlapping, this proves the real mapper wiring.
		$entry = $this->insertOpenEntry('active');
		$start = $entry->getStartTime();
		$this->assertNotNull($start);
		$plannedEnd = (clone $start)->modify('+8 hours');

		$qb = $this->db->getQueryBuilder();
		$qb->insert('at_entries')
			->values([
				'user_id' => $qb->createNamedParameter(self::TEST_USER),
				'start_time' => $qb->createNamedParameter(
					(new \DateTime('-5 hours'))->format('Y-m-d H:i:s')
				),
				'end_time' => $qb->createNamedParameter(
					(new \DateTime('-4 hours'))->format('Y-m-d H:i:s')
				),
				'status' => $qb->createNamedParameter('completed'),
				'created_at' => $qb->createNamedParameter(
					(new \DateTime('-5 hours'))->format('Y-m-d H:i:s')
				),
				'updated_at' => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();

		try {
			$this->timeTracking->autoClockOutAtPlannedEnd(
				self::TEST_USER,
				(int)$entry->getId(),
				$plannedEnd,
			);
			$this->fail('An entry overlapping the planned window must veto the write');
		} catch (BusinessRuleException $e) {
			$this->assertSame(BusinessRuleCode::OVERLAP_CONFLICT, $e->getReasonCode());
		}

		$reloaded = $this->timeEntryMapper->find((int)$entry->getId());
		$this->assertSame(TimeEntry::STATUS_ACTIVE, $reloaded->getStatus());
		$this->assertNull($reloaded->getEndTime());
	}

	private function insertOpenEntry(string $status): TimeEntry
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('at_entries')
			->values([
				'user_id' => $qb->createNamedParameter(self::TEST_USER),
				'start_time' => $qb->createNamedParameter(
					(new \DateTime('-10 hours'))->format('Y-m-d H:i:s')
				),
				'status' => $qb->createNamedParameter($status),
				'created_at' => $qb->createNamedParameter(
					(new \DateTime('-10 hours'))->format('Y-m-d H:i:s')
				),
				'updated_at' => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();

		return $this->timeEntryMapper->find($this->db->lastInsertId('at_entries'));
	}

	private function readAuditCaptureSource(int $entryId): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('capture_source')
			->from('at_audit')
			->where($qb->expr()->eq('entity_type', $qb->createNamedParameter('time_entry')))
			->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entryId)))
			->andWhere($qb->expr()->eq('action', $qb->createNamedParameter('clock_out')))
			->orderBy('id', 'DESC')
			->setMaxResults(1);
		$value = $qb->executeQuery()->fetchOne();
		if ($value === false || $value === null) {
			return null;
		}

		return (string)$value;
	}

	private function cleanup(): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('at_entries')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::TEST_USER)));
		$qb->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->delete('at_audit')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::TEST_USER)));
		$qb->executeStatement();
	}
}
