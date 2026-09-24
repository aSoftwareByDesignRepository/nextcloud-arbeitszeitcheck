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
		$this->backupService = \OCP\Server::get(UpgradeBackupService::class);
		$this->db = \OCP\Server::get(IDBConnection::class);
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
