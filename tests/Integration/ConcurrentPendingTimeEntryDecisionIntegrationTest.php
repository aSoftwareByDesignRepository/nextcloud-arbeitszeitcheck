<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use Test\TestCase;

/**
 * Atlas Lens 1 — DB truth: two status-guarded pending decisions cannot both apply.
 */
class ConcurrentPendingTimeEntryDecisionIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_atlas_pending_race__';

	private TimeEntryMapper $mapper;

	private ?int $entryId = null;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = \OC::$server->get(TimeEntryMapper::class);
		$this->cleanup();
	}

	protected function tearDown(): void
	{
		$this->cleanup();
		parent::tearDown();
	}

	public function testSecondUpdateIfPendingApprovalLosesRace(): void
	{
		$entry = $this->seedPendingEntry();
		$id = (int)$entry->getId();

		$winner = $this->mapper->find($id);
		$winner->setStatus(TimeEntry::STATUS_COMPLETED);
		$winner->setUpdatedAt(new \DateTime('now'));
		$winner->setApprovedByUserId('manager_a');
		$winner->setApprovedAt(new \DateTime('now'));

		$loser = $this->mapper->find($id);
		$loser->setStatus(TimeEntry::STATUS_REJECTED);
		$loser->setUpdatedAt(new \DateTime('now'));

		$this->assertTrue($this->mapper->updateIfPendingApproval($winner), 'First decision must win');
		$this->assertFalse($this->mapper->updateIfPendingApproval($loser), 'Second decision must lose');

		$fresh = $this->mapper->find($id);
		$this->assertSame(TimeEntry::STATUS_COMPLETED, $fresh->getStatus());
		$this->assertSame('manager_a', $fresh->getApprovedByUserId());
	}

	private function seedPendingEntry(): TimeEntry
	{
		$entry = new TimeEntry();
		$entry->setUserId(self::TEST_USER);
		$entry->setStartTime(new \DateTime('2026-01-10 10:00:00'));
		$entry->setEndTime(new \DateTime('2026-01-10 11:00:00'));
		$entry->setStatus(TimeEntry::STATUS_PENDING_APPROVAL);
		$entry->setIsManualEntry(true);
		$entry->setJustification(json_encode([
			'type' => 'manual_create',
			'justification' => 'atlas concurrent db proof',
			'proposed' => [],
		], JSON_THROW_ON_ERROR));
		$now = new \DateTime('now');
		$entry->setCreatedAt($now);
		$entry->setUpdatedAt($now);
		$inserted = $this->mapper->insert($entry);
		$this->entryId = (int)$inserted->getId();
		return $inserted;
	}

	private function cleanup(): void
	{
		$db = \OC::$server->get(\OCP\IDBConnection::class);
		if ($this->entryId !== null) {
			$qb = $db->getQueryBuilder();
			$qb->delete('at_entries')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($this->entryId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
				->executeStatement();
			$this->entryId = null;
		}
		if ($db->tableExists('at_entries')) {
			$qb = $db->getQueryBuilder();
			$qb->delete('at_entries')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::TEST_USER)))
				->executeStatement();
		}
	}
}
