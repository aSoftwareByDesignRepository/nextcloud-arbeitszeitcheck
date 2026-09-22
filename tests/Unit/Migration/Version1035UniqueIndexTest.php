<?php

declare(strict_types=1);

/**
 * Schema-step tests for {@see \OCA\ArbeitszeitCheck\Migration\Version1035Date20260724130000}
 * (B-1 consolidation: canonical unique index on at_holidays, removal of the
 * redundant pre-release duplicate index).
 *
 * The dedupe pass against real data is covered by
 * tests/Integration/HolidayMigrationsIntegrationTest.php.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use OCA\ArbeitszeitCheck\Migration\Version1035Date20260724130000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\ITable;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1035UniqueIndexTest extends TestCase
{
	private const CANONICAL = Version1035Date20260724130000::UNIQUE_INDEX;
	private const REDUNDANT = Version1035Date20260724130000::REDUNDANT_INDEX;

	private function migration(?IDBConnection $db = null): Version1035Date20260724130000
	{
		return new Version1035Date20260724130000($db ?? $this->createMock(IDBConnection::class));
	}

	/**
	 * @param array<string,bool> $indexes name => exists
	 */
	private function schemaWithIndexes(array $indexes, ITable $table): ISchemaWrapper
	{
		$table->method('hasIndex')
			->willReturnCallback(static fn (string $name): bool => $indexes[$name] ?? false);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('at_holidays')->willReturn(true);
		$schema->method('getTable')->with('at_holidays')->willReturn($table);

		return $schema;
	}

	private function runChangeSchema(ISchemaWrapper $schema): ?ISchemaWrapper
	{
		return $this->migration()->changeSchema(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => $schema,
			[]
		);
	}

	public function testPreSchemaChangeSkipsWhenTableMissing(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->with('at_holidays')->willReturn(false);
		$db->expects($this->never())->method('getQueryBuilder');

		$this->migration($db)->preSchemaChange(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class),
			[]
		);
	}

	/**
	 * Fresh/restored databases without any unique index get the canonical one.
	 */
	public function testChangeSchemaAddsCanonicalIndexWhenMissing(): void
	{
		$table = $this->createMock(ITable::class);
		$table->expects($this->once())
			->method('addUniqueIndex')
			->with(['state', 'date', 'scope'], self::CANONICAL);
		$table->expects($this->never())->method('dropIndex');

		$schema = $this->schemaWithIndexes([self::CANONICAL => false, self::REDUNDANT => false], $table);
		$this->assertSame($schema, $this->runChangeSchema($schema));
	}

	/**
	 * Pre-release 1.6.0 databases carry the redundant duplicate index — it
	 * must be dropped while the canonical index is kept.
	 */
	public function testChangeSchemaDropsRedundantPreReleaseIndex(): void
	{
		$table = $this->createMock(ITable::class);
		$table->expects($this->never())->method('addUniqueIndex');
		$table->expects($this->once())->method('dropIndex')->with(self::REDUNDANT);

		$schema = $this->schemaWithIndexes([self::CANONICAL => true, self::REDUNDANT => true], $table);
		$this->assertSame($schema, $this->runChangeSchema($schema));
	}

	/**
	 * Databases upgraded via 1008 only (all production installs): nothing to do.
	 */
	public function testChangeSchemaIsANoOpWhenAlreadyCanonical(): void
	{
		$table = $this->createMock(ITable::class);
		$table->expects($this->never())->method('addUniqueIndex');
		$table->expects($this->never())->method('dropIndex');

		$schema = $this->schemaWithIndexes([self::CANONICAL => true, self::REDUNDANT => false], $table);
		$this->assertNull($this->runChangeSchema($schema));
	}

	/**
	 * Degenerate case: only the redundant index exists — the canonical index
	 * is added before the redundant one is dropped, so uniqueness is never lost.
	 */
	public function testChangeSchemaReplacesRedundantWithCanonical(): void
	{
		$table = $this->createMock(ITable::class);
		$table->expects($this->once())
			->method('addUniqueIndex')
			->with(['state', 'date', 'scope'], self::CANONICAL);
		$table->expects($this->once())->method('dropIndex')->with(self::REDUNDANT);

		$schema = $this->schemaWithIndexes([self::CANONICAL => false, self::REDUNDANT => true], $table);
		$this->assertSame($schema, $this->runChangeSchema($schema));
	}

	public function testChangeSchemaSkipsWhenTableMissing(): void
	{
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('at_holidays')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertNull($this->runChangeSchema($schema));
	}
}
