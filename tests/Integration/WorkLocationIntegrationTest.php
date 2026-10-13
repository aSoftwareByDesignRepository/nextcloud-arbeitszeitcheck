<?php

declare(strict_types=1);

/**
 * Integration coverage for GH #43 (per-entry work location):
 *  - the at_entries.work_location column exists after migrations run
 *  - entity ↔ column round-trips through the real QBMapper + DB
 *  - UserWorkLocationService resolves through the real container and the
 *    explicit → stored default → office chain holds against real storage
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\UserWorkLocationService;
use OCA\ArbeitszeitCheck\Support\WorkLocation;
use Test\TestCase;

class WorkLocationIntegrationTest extends TestCase
{
	private const TEST_USER = '__azc_work_location_it__';

	private TimeEntryMapper $timeEntryMapper;
	private UserWorkLocationService $workLocationService;

	protected function setUp(): void
	{
		parent::setUp();
		if (!class_exists(\OC::class) || !isset(\OC::$server)) {
			$this->markTestSkipped('Nextcloud is not bootstrapped (set NEXTCLOUD_ROOT or run inside Docker).');
		}
		$this->timeEntryMapper = \OC::$server->get(TimeEntryMapper::class);
		$this->workLocationService = \OC::$server->get(UserWorkLocationService::class);
		$this->timeEntryMapper->deleteByUser(self::TEST_USER);
		$this->workLocationService->setDefault(self::TEST_USER, null, self::TEST_USER);
	}

	protected function tearDown(): void
	{
		$this->timeEntryMapper->deleteByUser(self::TEST_USER);
		$this->workLocationService->setDefault(self::TEST_USER, null, self::TEST_USER);
		parent::tearDown();
	}

	private function newEntry(?string $location): TimeEntry
	{
		$e = new TimeEntry();
		$e->setUserId(self::TEST_USER);
		$e->setStartTime(new \DateTime('2020-01-15T09:00:00+00:00'));
		$e->setEndTime(new \DateTime('2020-01-15T17:00:00+00:00'));
		$e->setStatus(TimeEntry::STATUS_COMPLETED);
		$e->setWorkLocation($location);
		$now = new \DateTime('2020-01-15T18:00:00+00:00');
		$e->setCreatedAt($now);
		$e->setUpdatedAt($now);
		return $e;
	}

	public function testWorkLocationColumnExists(): void
	{
		$db = \OC::$server->get(\OCP\IDBConnection::class);
		$this->assertTrue($db->tableExists('at_entries'), 'at_entries must exist');
		// createSchema() returns storage-level (prefixed) names — match by suffix.
		$schema = $db->createSchema();
		$table = null;
		foreach ($schema->getTableNames() as $name) {
			if (str_ends_with($name, 'at_entries')) {
				$table = $schema->getTable($name);
				break;
			}
		}
		$this->assertNotNull($table, 'at_entries table not found in schema');
		$this->assertTrue(
			$table->hasColumn('work_location'),
			'at_entries.work_location must exist (Version1049 migration)'
		);
		$this->assertFalse(
			$table->getColumn('work_location')->getNotnull(),
			'work_location must be nullable — NULL means "not recorded" for legacy rows'
		);
	}

	public function testInsertAndReadBackRoundTrip(): void
	{
		$saved = $this->timeEntryMapper->insert($this->newEntry(WorkLocation::HOME));
		$this->assertGreaterThan(0, $saved->getId());
		$this->assertSame(WorkLocation::HOME, $saved->getWorkLocation());

		$reloaded = $this->timeEntryMapper->find($saved->getId());
		$this->assertSame(WorkLocation::HOME, $reloaded->getWorkLocation());
	}

	public function testNullStaysNullForLegacySemantics(): void
	{
		$saved = $this->timeEntryMapper->insert($this->newEntry(null));
		$reloaded = $this->timeEntryMapper->find($saved->getId());
		$this->assertNull($reloaded->getWorkLocation(), 'NULL must survive — it means "not recorded", not office');
	}

	public function testUpdateClearsAndSetsWorkLocation(): void
	{
		$saved = $this->timeEntryMapper->insert($this->newEntry(WorkLocation::HOME));
		$saved->setWorkLocation(null);
		$this->timeEntryMapper->update($saved);
		$this->assertNull($this->timeEntryMapper->find($saved->getId())->getWorkLocation());

		$saved->setWorkLocation(WorkLocation::EXTERNAL);
		$this->timeEntryMapper->update($saved);
		$this->assertSame(WorkLocation::EXTERNAL, $this->timeEntryMapper->find($saved->getId())->getWorkLocation());
	}

	public function testUserWorkLocationServiceResolvesThroughContainer(): void
	{
		$this->assertInstanceOf(UserWorkLocationService::class, $this->workLocationService);
	}

	public function testDefaultPersistedAndResolvedForNewEntries(): void
	{
		$this->assertNull($this->workLocationService->getDefault(self::TEST_USER));
		$this->assertSame(WorkLocation::OFFICE, $this->workLocationService->resolveForNewEntry(self::TEST_USER, null));

		$this->workLocationService->setDefault(self::TEST_USER, 'home', self::TEST_USER);
		$this->assertSame('home', $this->workLocationService->getDefault(self::TEST_USER));
		$this->assertSame('home', $this->workLocationService->resolveForNewEntry(self::TEST_USER, null));
		$this->assertSame('external', $this->workLocationService->resolveForNewEntry(self::TEST_USER, 'external'));
	}
}
