<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Table;
use OCA\ArbeitszeitCheck\Migration\Version1046Date20260921120000;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Contract: Offline-Sync capture_source column is expand-only / idempotent.
 */
class Version1046AuditCaptureSourceTest extends TestCase
{
	public function testConstantsStable(): void
	{
		$this->assertSame('capture_source', Version1046Date20260921120000::COLUMN);
		$this->assertSame('at_audit_csrc_idx', Version1046Date20260921120000::INDEX);
		$this->assertLessThanOrEqual(30, strlen(Version1046Date20260921120000::INDEX));
	}

	public function testChangeSchemaNoopsWhenTableMissing(): void
	{
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('at_audit')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$migration = new Version1046Date20260921120000($this->createMock(IDBConnection::class));
		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			[]
		);
		$this->assertNull($result);
	}

	public function testChangeSchemaAddsColumnAndIndexWhenMissing(): void
	{
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->with('capture_source')->willReturn(false);
		$table->method('hasIndex')->with('at_audit_csrc_idx')->willReturn(false);
		$table->expects($this->once())->method('addColumn')->with('capture_source', $this->anything(), $this->anything());
		$table->expects($this->once())->method('addIndex')->with(['capture_source'], 'at_audit_csrc_idx');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('at_audit')->willReturn(true);
		$schema->method('getTable')->with('at_audit')->willReturn($table);

		$migration = new Version1046Date20260921120000($this->createMock(IDBConnection::class));
		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			[]
		);
		$this->assertSame($schema, $result);
	}

	public function testChangeSchemaIdempotentWhenPresent(): void
	{
		$table = $this->createMock(Table::class);
		$table->method('hasColumn')->with('capture_source')->willReturn(true);
		$table->method('hasIndex')->with('at_audit_csrc_idx')->willReturn(true);
		$table->expects($this->never())->method('addColumn');
		$table->expects($this->never())->method('addIndex');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('at_audit')->willReturn(true);
		$schema->method('getTable')->with('at_audit')->willReturn($table);

		$migration = new Version1046Date20260921120000($this->createMock(IDBConnection::class));
		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			[]
		);
		$this->assertNull($result);
	}

	public function testPostSchemaChangeNoopsWhenTableMissing(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('at_audit')->willReturn(false);
		$db->expects($this->never())->method('getQueryBuilder');

		$migration = new Version1046Date20260921120000($db);
		$migration->postSchemaChange(
			$this->createMock(IOutput::class),
			static fn () => null,
			[]
		);
	}
}
