<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Migration\Version1046Date20260921120000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Schema\ITable;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use Test\TestCase;

/**
 * DB truth: Offline-Sync filter uses capture_source column; backfill promotes legacy JSON rows.
 */
class AuditCaptureSourceIntegrationTest extends TestCase
{
	private const USER = '__azc_audit_csrc__';

	private IDBConnection $db;
	private AuditLogMapper $mapper;

	protected function setUp(): void
	{
		parent::setUp();
		$this->db = \OC::$server->get(IDBConnection::class);
		$this->mapper = \OC::$server->get(AuditLogMapper::class);
		$this->assertTrue($this->db->tableExists('at_audit'), 'at_audit must exist');
		$this->assertTrue($this->hasCaptureSourceColumn(), 'Version1046 capture_source must be migrated');
		$this->cleanup();
	}

	protected function tearDown(): void
	{
		$this->cleanup();
		parent::tearDown();
	}

	public function testLogActionPersistsIndexedCaptureSource(): void
	{
		$offline = $this->mapper->logAction(
			self::USER,
			'clock_in',
			'time_entry',
			null,
			null,
			[
				'capture_source' => Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC,
				'client_occurred_at' => '2026-09-21T08:15:00+02:00',
			]
		);
		$live = $this->mapper->logAction(
			self::USER,
			'clock_in',
			'time_entry',
			null,
			null,
			['note' => 'live punch']
		);

		$this->assertSame(Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC, $offline->getCaptureSource());
		$this->assertNull($live->getCaptureSource());

		$start = new \DateTime('yesterday');
		$end = new \DateTime('tomorrow');
		$filtered = $this->mapper->searchByDateRange($start, $end, [
			'user_id' => self::USER,
			'offline_sync' => true,
		]);
		$ids = array_map(static fn ($row) => $row->getId(), $filtered);
		$this->assertContains($offline->getId(), $ids);
		$this->assertNotContains($live->getId(), $ids);
		$this->assertSame(1, $this->mapper->countByDateRange($start, $end, [
			'user_id' => self::USER,
			'offline_sync' => true,
		]));
	}

	public function testBackfillPromotesLegacyJsonOnlyRows(): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->insert('at_audit')
			->values([
				'user_id' => $qb->createNamedParameter(self::USER),
				'action' => $qb->createNamedParameter('clock_in'),
				'entity_type' => $qb->createNamedParameter('time_entry'),
				'new_values' => $qb->createNamedParameter(json_encode([
					'capture_source' => 'offline_sync',
					'client_occurred_at' => '2026-09-20T12:00:00+02:00',
				], JSON_UNESCAPED_SLASHES)),
				'created_at' => $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')),
			]);
		$qb->executeStatement();

		$id = $this->lastAuditIdForUser();
		$this->assertGreaterThan(0, $id);
		$this->assertNull($this->readCaptureSource($id), 'fixture must start with NULL capture_source');

		$migration = new Version1046Date20260921120000($this->db);
		$migration->postSchemaChange($this->createMock(IOutput::class), $this->schemaWithCaptureSource(...), []);
		$this->assertSame(Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC, $this->readCaptureSource($id));

		// Idempotent second pass
		$migration->postSchemaChange($this->createMock(IOutput::class), $this->schemaWithCaptureSource(...), []);
		$this->assertSame(Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC, $this->readCaptureSource($id));
	}

	private function hasCaptureSourceColumn(): bool
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('capture_source')
				->from('at_audit')
				->setMaxResults(1);
			$qb->executeQuery()->closeCursor();
			return true;
		} catch (\Throwable) {
			return false;
		}
	}

	private function schemaWithCaptureSource(): ISchemaWrapper
	{
		// NC35: ISchemaWrapper::getTable() returns OCP\DB\Schema\ITable (not Doctrine Table).
		$table = $this->createMock(ITable::class);
		$table->method('hasColumn')->with('capture_source')->willReturn(true);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('at_audit')->willReturn(true);
		$schema->method('getTable')->with('at_audit')->willReturn($table);

		return $schema;
	}

	private function lastAuditIdForUser(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from('at_audit')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)))
			->orderBy('id', 'DESC')
			->setMaxResults(1);

		return (int)$qb->executeQuery()->fetchOne();
	}

	private function readCaptureSource(int $id): ?string
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('capture_source')
			->from('at_audit')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$value = $qb->executeQuery()->fetchOne();
		if ($value === false || $value === null) {
			return null;
		}

		return (string)$value;
	}

	private function cleanup(): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('at_audit')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER)));
		$qb->executeStatement();
	}
}
