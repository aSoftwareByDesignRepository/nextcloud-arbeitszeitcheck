<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Exception\UpgradeBackupException;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\IDBConnection;
use Test\TestCase;

final class UpgradeBackupIntegrationTest extends TestCase
{
	private UpgradeBackupService $backupService;
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->backupService = \OC::$server->get(UpgradeBackupService::class);
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	public function testCreateListAndRestoreRoundTrip(): void
	{
		if (!$this->db->tableExists('at_entries')) {
			self::markTestSkipped('ArbeitszeitCheck tables not present in this instance.');
		}

		$before = $this->countRows('at_entries');

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
		self::assertSame(0, $this->countRows('at_entries'));

		$this->backupService->restoreSnapshot($snapshotId, false);
		self::assertSame($before, $this->countRows('at_entries'));
	}

	public function testRestoreRejectsInvalidSnapshotId(): void
	{
		$this->expectException(UpgradeBackupException::class);
		$this->backupService->restoreSnapshot('../evil', false);
	}

	public function testRestoreSkipsGeneratedColumns(): void
	{
		if (!$this->db->tableExists('at_entries')) {
			self::markTestSkipped('ArbeitszeitCheck tables not present in this instance.');
		}

		// A live ('active') entry makes the STORED GENERATED live_user_id
		// non-NULL in the snapshot; restore must skip it or MariaDB throws 1906.
		$qb = $this->db->getQueryBuilder();
		$qb->insert('at_entries')
			->setValue('user_id', $qb->createNamedParameter('upgrade-backup-test-user'))
			->setValue('start_time', $qb->createNamedParameter('2026-10-13 08:00:00'))
			->setValue('status', $qb->createNamedParameter('active'))
			->setValue('created_at', $qb->createNamedParameter('2026-10-13 08:00:00'))
			->setValue('updated_at', $qb->createNamedParameter('2026-10-13 08:00:00'))
			->executeStatement();

		$before = $this->countRows('at_entries');
		$result = $this->backupService->createSnapshot('integration-test-generated-column');
		$snapshotId = $result['id'];

		$this->db->getQueryBuilder()
			->delete('at_entries')
			->executeStatement();
		self::assertSame(0, $this->countRows('at_entries'));

		$this->backupService->restoreSnapshot($snapshotId, false);
		self::assertSame($before, $this->countRows('at_entries'));

		$this->db->getQueryBuilder()
			->delete('at_entries')
			->where('user_id = :uid')
			->setParameter('uid', 'upgrade-backup-test-user')
			->executeStatement();
	}

	private function countRows(string $table): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($table);
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count;
	}
}
