<?php

declare(strict_types=1);

/**
 * Regression guard for the install-blocking month-closure FK bug.
 *
 * This test reproduces the schema build performed by
 * {@see \OCA\ArbeitszeitCheck\Migration\Version1014Date20260409120000} and
 * verifies that the `at_mcr_closure_fk` foreign key on
 * `oc_at_month_closure_revision.closure_id` references the **prefixed**
 * `oc_at_month_closure` table, not the raw `at_month_closure` string.
 *
 * The bug it guards against produced this user-visible install crash on
 * PostgreSQL clusters:
 *
 *   SQLSTATE[42P01]: Undefined table: 7 ERROR: relation
 *   "at_month_closure" does not exist
 *
 *   ALTER TABLE oc_at_month_closure_revision
 *     ADD CONSTRAINT at_mcr_closure_fk FOREIGN KEY (closure_id)
 *     REFERENCES at_month_closure (id) ON DELETE CASCADE
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table as DBALTable;
use OCA\ArbeitszeitCheck\Migration\Version1014Date20260409120000;
use OC\DB\Schema\Table as NcTable;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Schema\ITable;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class MonthClosureForeignKeyTest extends TestCase
{
	private const PREFIX = 'oc_';

	public function testRevisionForeignKeyReferencesPrefixedClosureTable(): void
	{
		$schema = new Schema();
		$wrapper = new PrefixedSchemaWrapperFake($schema, self::PREFIX);
		$output = $this->createMock(IOutput::class);

		$migration = new Version1014Date20260409120000();
		$result = $migration->changeSchema($output, fn () => $wrapper, []);

		self::assertNotNull($result, 'Migration should return a schema on a fresh install.');

		$revision = $schema->getTable(self::PREFIX . 'at_month_closure_revision');
		$fks = $revision->getForeignKeys();

		self::assertArrayHasKey('at_mcr_closure_fk', $this->indexByName($fks), 'FK at_mcr_closure_fk must be present.');

		$fk = $this->indexByName($fks)['at_mcr_closure_fk'];

		self::assertSame(
			self::PREFIX . 'at_month_closure',
			$fk->getForeignTableName(),
			'FK must reference the *prefixed* closure table or PostgreSQL installs will crash.'
		);
		self::assertSame(['closure_id'], array_map('strval', $fk->getLocalColumns()));
		self::assertSame(['id'], array_map('strval', $fk->getForeignColumns()));
		self::assertSame('CASCADE', strtoupper((string)$fk->getOption('onDelete')));
	}

	public function testReRunningTheMigrationIsIdempotent(): void
	{
		$schema = new Schema();
		$wrapper = new PrefixedSchemaWrapperFake($schema, self::PREFIX);
		$output = $this->createMock(IOutput::class);
		$migration = new Version1014Date20260409120000();

		// First run: tables get created.
		$migration->changeSchema($output, fn () => $wrapper, []);

		// Second run: tables already exist; the migration must return null
		// and must not throw.
		$second = $migration->changeSchema($output, fn () => $wrapper, []);
		self::assertNull($second, 'Migration must be a no-op once both tables exist.');
	}

	/**
	 * @param iterable<\Doctrine\DBAL\Schema\ForeignKeyConstraint> $fks
	 * @return array<string, \Doctrine\DBAL\Schema\ForeignKeyConstraint>
	 */
	private function indexByName(iterable $fks): array
	{
		$out = [];
		foreach ($fks as $fk) {
			$out[strtolower($fk->getName())] = $fk;
		}
		return $out;
	}
}

/**
 * Minimal, in-memory ISchemaWrapper backed by a real Doctrine Schema.
 *
 * Mirrors `\OC\DB\SchemaWrapper`'s contract:
 *  - `createTable($name)` / `getTable($name)` / `hasTable($name)` all transparently
 *    prepend the configured Nextcloud table prefix, exactly as the production
 *    wrapper does, so migrations under test see identical name resolution.
 *
 * Only the methods used by ArbeitszeitCheck migrations are implemented; the
 * remaining `ISchemaWrapper` methods intentionally throw because exercising
 * them would mean the test has drifted from its narrow scope.
 */
final class PrefixedSchemaWrapperFake implements ISchemaWrapper
{
	public function __construct(
		private readonly Schema $schema,
		private readonly string $prefix,
	) {
	}

	public function getTable($tableName): ITable
	{
		return new NcTable($this->schema->getTable($this->prefix . $tableName));
	}

	public function hasTable($tableName): bool
	{
		return $this->schema->hasTable($this->prefix . $tableName);
	}

	public function createTable($tableName): ITable
	{
		return new NcTable($this->schema->createTable($this->prefix . $tableName));
	}

	public function dropTable($tableName): self
	{
		$this->schema->dropTable($this->prefix . $tableName);
		return $this;
	}

	public function getTables(): array
	{
		return array_values(array_map(
			static fn (DBALTable $table): ITable => new NcTable($table),
			$this->schema->getTables(),
		));
	}

	public function getTableNames(): array
	{
		return $this->schema->getTableNames();
	}

	public function getTableNamesWithoutPrefix(): array
	{
		$prefixLen = strlen($this->prefix);
		return array_map(function (string $name): string {
			return str_starts_with($name, $this->prefix)
				? substr($name, strlen($this->prefix))
				: $name;
		}, $this->schema->getTableNames());
	}

	public function getDatabasePlatform(): AbstractPlatform
	{
		throw new \LogicException('getDatabasePlatform() is not exercised by this test fake.');
	}

	/**
	 * Forward-compat: Nextcloud 33's ISchemaWrapper exposes this helper. It is
	 * not exercised by any ArbeitszeitCheck migration; declaring it here keeps
	 * the fake conformant with both the current and upcoming interface
	 * versions.
	 */
	public function dropAutoincrementColumn(string $table, string $column): void
	{
		throw new \LogicException('dropAutoincrementColumn() is not exercised by this test fake.');
	}
}
