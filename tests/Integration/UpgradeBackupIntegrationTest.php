<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Exception\UpgradeBackupException;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCA\ArbeitszeitCheck\Tests\Support\UpgradeBackupStateGuard;
use OCP\IDBConnection;
use Test\TestCase;

final class UpgradeBackupIntegrationTest extends TestCase
{
	private UpgradeBackupService $backupService;
	private IDBConnection $db;
	private UpgradeBackupStateGuard $backupStateGuard;

	protected function setUp(): void
	{
		parent::setUp();
		// createSnapshot() writes a real snapshot under appdata and updates the
		// upgrade_backup_last_snapshot_id registry — record the baseline first so
		// the guard can sweep them in tearDown.
		$this->backupStateGuard = new UpgradeBackupStateGuard();
		$this->backupStateGuard->setUp();
		$this->backupService = \OCP\Server::get(UpgradeBackupService::class);
		$this->db = \OCP\Server::get(IDBConnection::class);
	}

	protected function tearDown(): void
	{
		$this->backupStateGuard->tearDown();
		parent::tearDown();
	}

	public function testCreateListAndRestoreRoundTrip(): void
	{
		if (!$this->db->tableExists('at_entries')) {
			self::markTestSkipped('ArbeitszeitCheck tables not present in this instance.');
		}

		// Concurrent writers (live cron jobs) can insert rows while this test
		// runs, so the invariant is "every snapshotted row returns", not an
		// exact table count.
		$idsBefore = $this->entryIds();

		$result = $this->backupService->createSnapshot('integration-test');
		$snapshotId = $result['id'];
		self::assertNotSame('', $snapshotId);
		self::assertTrue($result['manifest']['complete'] ?? false);
		self::assertNotEmpty($result['manifest']['tables'] ?? [], 'Snapshot must include table metadata when tables exist.');

		$snapshots = $this->backupService->listSnapshots();
		$ids = array_map(static fn (array $snapshot): string => (string)($snapshot['id'] ?? ''), $snapshots);
		self::assertContains($snapshotId, $ids, 'listSnapshots must find the snapshot just created');

		$this->db->getQueryBuilder()
			->delete('at_entries')
			->executeStatement();
		self::assertSame([], array_values(array_intersect($idsBefore, $this->entryIds())));

		$this->backupService->restoreSnapshot($snapshotId, false);
		$idsAfter = $this->entryIds();
		self::assertSame([], array_values(array_diff($idsBefore, $idsAfter)), 'restore must bring back every snapshotted row');
		self::assertGreaterThanOrEqual(count($idsBefore), count($idsAfter));
	}

	public function testRestoreSkipsGeneratedColumns(): void
	{
		if (!$this->db->tableExists('at_entries')) {
			self::markTestSkipped('ArbeitszeitCheck tables not present in this instance.');
		}

		// A live ('active') entry makes the STORED GENERATED live_user_id
		// non-NULL in the snapshot; restore must skip it or MariaDB throws 1906.
		// Pre-clean: a leftover active row for the fixture uid (restored from an
		// older snapshot, or a crashed prior run) would trip the live-uid unique
		// index on the insert below.
		$this->db->getQueryBuilder()
			->delete('at_entries')
			->where('user_id = :uid')
			->setParameter('uid', 'upgrade-backup-test-user')
			->executeStatement();

		$qb = $this->db->getQueryBuilder();
		$qb->insert('at_entries')
			->setValue('user_id', $qb->createNamedParameter('upgrade-backup-test-user'))
			->setValue('start_time', $qb->createNamedParameter('2026-10-13 08:00:00'))
			->setValue('status', $qb->createNamedParameter('active'))
			->setValue('created_at', $qb->createNamedParameter('2026-10-13 08:00:00'))
			->setValue('updated_at', $qb->createNamedParameter('2026-10-13 08:00:00'))
			->executeStatement();

		$idsBefore = $this->entryIds();
		$result = $this->backupService->createSnapshot('integration-test-generated-column');
		$snapshotId = $result['id'];

		$this->db->getQueryBuilder()
			->delete('at_entries')
			->executeStatement();
		self::assertSame([], array_values(array_intersect($idsBefore, $this->entryIds())));

		$this->backupService->restoreSnapshot($snapshotId, false);
		self::assertSame([], array_values(array_diff($idsBefore, $this->entryIds())), 'restore must bring back every snapshotted row');

		$this->db->getQueryBuilder()
			->delete('at_entries')
			->where('user_id = :uid')
			->setParameter('uid', 'upgrade-backup-test-user')
			->executeStatement();
	}

	public function testRestoreRejectsInvalidSnapshotId(): void
	{
		$this->expectException(UpgradeBackupException::class);
		$this->backupService->restoreSnapshot('../evil', false);
	}

	/** @return list<int> current primary keys in at_entries. */
	private function entryIds(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')->from('at_entries');
		$result = $qb->executeQuery();
		$ids = array_map('intval', $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $ids;
	}
}
