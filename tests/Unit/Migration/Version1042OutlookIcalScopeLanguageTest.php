<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use OCA\ArbeitszeitCheck\Migration\Version1042Date20260819220000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\ITable;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1042OutlookIcalScopeLanguageTest extends TestCase
{
	private const TEAM_SCOPE_UNIQUE = Version1042Date20260819220000::TEAM_SCOPE_UNIQUE;
	private const SCOPE_LANGUAGE_UNIQUE = Version1042Date20260819220000::SCOPE_LANGUAGE_UNIQUE;

	private function migration(?IDBConnection $db = null): Version1042Date20260819220000
	{
		return new Version1042Date20260819220000($db ?? $this->createMock(IDBConnection::class));
	}

	/**
	 * @param array<string,bool> $indexes
	 * @param array<string,bool> $columns
	 */
	private function schemaWithTable(array $indexes, array $columns, ITable $table): ISchemaWrapper
	{
		$table->method('hasIndex')
			->willReturnCallback(static fn (string $name): bool => $indexes[$name] ?? false);
		$table->method('hasColumn')
			->willReturnCallback(static fn (string $name): bool => $columns[$name] ?? false);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('azc_outlook_ical_tokens')->willReturn(true);
		$schema->method('getTable')->with('azc_outlook_ical_tokens')->willReturn($table);

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
		$db->method('tableExists')->with('azc_outlook_ical_tokens')->willReturn(false);
		$db->expects($this->never())->method('getQueryBuilder');

		$this->migration($db)->preSchemaChange(
			$this->createMock(IOutput::class),
			fn (): ISchemaWrapper => $this->createMock(ISchemaWrapper::class),
			[]
		);
	}

	public function testChangeSchemaAddsEncryptedColumnAndScopeLanguageUniqueIndex(): void
	{
		$table = $this->createMock(ITable::class);
		$table->expects($this->once())
			->method('addColumn')
			->with('token_encrypted');
		$table->expects($this->once())
			->method('dropIndex')
			->with(self::TEAM_SCOPE_UNIQUE);
		$table->expects($this->once())
			->method('addUniqueIndex')
			->with(['tenant_id', 'team_id', 'feed_language_code'], self::SCOPE_LANGUAGE_UNIQUE);

		$schema = $this->schemaWithTable(
			[self::TEAM_SCOPE_UNIQUE => true, self::SCOPE_LANGUAGE_UNIQUE => false],
			['token_encrypted' => false],
			$table,
		);
		$this->assertSame($schema, $this->runChangeSchema($schema));
	}

	public function testChangeSchemaIsNoOpWhenAlreadyMigrated(): void
	{
		$table = $this->createMock(ITable::class);
		$table->expects($this->never())->method('addColumn');
		$table->expects($this->never())->method('dropIndex');
		$table->expects($this->never())->method('addUniqueIndex');

		$schema = $this->schemaWithTable(
			[self::TEAM_SCOPE_UNIQUE => false, self::SCOPE_LANGUAGE_UNIQUE => true],
			['token_encrypted' => true],
			$table,
		);
		$this->assertNull($this->runChangeSchema($schema));
	}

	public function testChangeSchemaSkipsWhenTableMissing(): void
	{
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->with('azc_outlook_ical_tokens')->willReturn(false);
		$schema->expects($this->never())->method('getTable');

		$this->assertNull($this->runChangeSchema($schema));
	}
}
