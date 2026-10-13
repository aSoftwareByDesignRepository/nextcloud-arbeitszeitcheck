<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use OCA\ArbeitszeitCheck\Migration\Version1049Date20261013120000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\ITable;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Contract: `work_location` on at_entries is expand-only / idempotent and
 * nullable — legacy rows stay NULL ("not recorded"), never rewritten (GH #43).
 */
class Version1049WorkLocationTest extends TestCase
{
	public function testColumnConstantStableAndPortable(): void
	{
		$this->assertSame('work_location', Version1049Date20261013120000::COLUMN);
		$this->assertLessThanOrEqual(30, strlen(Version1049Date20261013120000::COLUMN));
	}

	public function testChangeSchemaNoopsWhenTableMissing(): void
	{
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('at_entries')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$migration = new Version1049Date20261013120000();
		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			[]
		);
		$this->assertNull($result);
	}

	public function testChangeSchemaAddsNullableStringColumn(): void
	{
		$table = $this->createMock(ITable::class);
		$table->method('hasColumn')->with('work_location')->willReturn(false);
		$table->expects($this->once())
			->method('addColumn')
			->with(
				'work_location',
				Types::STRING,
				$this->callback(static function (array $options): bool {
					// Nullable + no default: legacy rows must stay NULL.
					return ($options['notnull'] ?? true) === false
						&& array_key_exists('default', $options)
						&& $options['default'] === null
						&& ($options['length'] ?? 0) <= 16;
				})
			);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('at_entries')->willReturn(true);
		$schema->method('getTable')->with('at_entries')->willReturn($table);

		$migration = new Version1049Date20261013120000();
		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			[]
		);
		$this->assertSame($schema, $result);
	}

	public function testChangeSchemaIdempotentWhenColumnPresent(): void
	{
		$table = $this->createMock(ITable::class);
		$table->method('hasColumn')->with('work_location')->willReturn(true);
		$table->expects($this->never())->method('addColumn');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('at_entries')->willReturn(true);
		$schema->method('getTable')->with('at_entries')->willReturn($table);

		$migration = new Version1049Date20261013120000();
		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			[]
		);
		$this->assertNull($result);
	}
}
