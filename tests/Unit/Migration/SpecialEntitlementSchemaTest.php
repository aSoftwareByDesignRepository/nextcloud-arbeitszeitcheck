<?php

declare(strict_types=1);

/**
 * Contract test for the Phase 4 special-entitlement schema.
 *
 * The balances must be append/expand-only, portable, uninstallable and
 * independent from the existing vacation `at_entitlement_snapshots` rows.
 * This test exercises the real Doctrine schema emitted by the migration,
 * not string matching.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCA\ArbeitszeitCheck\Migration\ArbeitszeitCheckTableCatalog;
use OCA\ArbeitszeitCheck\Migration\Version1053Date20261030090000;
use OCA\ArbeitszeitCheck\Repair\UninstallDropTables;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupCatalog;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MonthClosureForeignKeyTest.php';

final class SpecialEntitlementSchemaTest extends TestCase
{
	private const PREFIX = 'oc_';
	private const TABLES = [
		'at_se_type',
		'at_se_policy',
		'at_se_period',
		'at_se_reserv',
		'at_se_ledger',
	];

	public function testCreatesAllEntitlementTablesAndExtendsSnapshots(): void
	{
		$schema = $this->schemaWithLegacySnapshot();
		$wrapper = new PrefixedSchemaWrapperFake($schema, self::PREFIX);
		$migration = new Version1053Date20261030090000();

		$result = $migration->changeSchema($this->createMock(IOutput::class), static fn () => $wrapper, []);

		self::assertSame($wrapper, $result);
		foreach (self::TABLES as $logicalName) {
			self::assertLessThanOrEqual(30, strlen($logicalName), 'Logical table names must stay portable.');
			$table = $schema->getTable(self::PREFIX . $logicalName);
			$pk = $table->getPrimaryKey();
			self::assertNotNull($pk, $logicalName . ' must have an explicit primary key.');
			self::assertSame(['id'], array_map('strval', $pk->getUnquotedColumns()));
			self::assertLessThanOrEqual(30, strlen($pk->getName()), 'Primary-key identifier must stay portable.');
		}

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_se_type'), [
			'code', 'public_label', 'confidential_label', 'label_i18n', 'absence_type_code',
			'unit', 'period_type', 'allow_negative', 'employee_may_see_confidential_label',
			'active', 'version', 'created_by', 'updated_by', 'created_at', 'updated_at',
		]);
		self::assertTrue($this->hasUniqueIndex($schema->getTable(self::PREFIX . 'at_se_type'), ['code']));
		self::assertFalse($schema->getTable(self::PREFIX . 'at_se_type')->getColumn('allow_negative')->getNotnull());

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_se_policy'), [
			'entitlement_type_id', 'scope_type', 'scope_id', 'operation', 'amount',
			'tariff_rule_set_id', 'priority', 'effective_from', 'effective_to', 'reason',
			'version', 'created_by', 'updated_by', 'created_at', 'updated_at',
		]);
		self::assertTrue($this->hasIndexCovering($schema->getTable(self::PREFIX . 'at_se_policy'), [
			'entitlement_type_id', 'scope_type', 'scope_id',
		]));

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_se_period'), [
			'entitlement_type_id', 'user_id', 'period_start', 'period_end', 'computed_amount',
			'unit', 'computation_snapshot_id', 'status', 'revision',
		]);
		self::assertTrue($this->hasUniqueIndex($schema->getTable(self::PREFIX . 'at_se_period'), [
			'entitlement_type_id', 'user_id', 'period_start',
		]));

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_se_reserv'), [
			'period_id', 'absence_id', 'amount', 'status', 'request_key', 'revision', 'created_at', 'updated_at',
		]);
		self::assertTrue($this->hasUniqueIndex($schema->getTable(self::PREFIX . 'at_se_reserv'), ['absence_id']));
		self::assertTrue($this->hasUniqueIndex($schema->getTable(self::PREFIX . 'at_se_reserv'), ['request_key']));

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_se_ledger'), [
			'period_id', 'event_key', 'kind', 'amount', 'absence_id', 'reverses_ledger_id',
			'actor_uid', 'reason', 'created_at',
		]);
		self::assertTrue($this->hasUniqueIndex($schema->getTable(self::PREFIX . 'at_se_ledger'), ['event_key']));

		$snapshots = $schema->getTable(self::PREFIX . 'at_entitlement_snapshots');
		$this->assertColumns($snapshots, [
			'entitlement_type_id', 'algorithm_version', 'period_start', 'period_end', 'unit', 'effective_amount',
		]);
		self::assertTrue($this->hasIndexCovering($snapshots, ['entitlement_type_id']));
	}

	public function testMigrationIsIdempotentAndFreshInstallDoesNotRequireSnapshotTable(): void
	{
		$schema = $this->schemaWithLegacySnapshot();
		$wrapper = new PrefixedSchemaWrapperFake($schema, self::PREFIX);
		$migration = new Version1053Date20261030090000();
		$output = $this->createMock(IOutput::class);

		$migration->changeSchema($output, static fn () => $wrapper, []);
		self::assertNull($migration->changeSchema($output, static fn () => $wrapper, []));

		$freshSchema = new Schema();
		$freshWrapper = new PrefixedSchemaWrapperFake($freshSchema, self::PREFIX);
		$result = $migration->changeSchema($output, static fn () => $freshWrapper, []);
		self::assertSame($freshWrapper, $result);
		foreach (self::TABLES as $table) {
			self::assertTrue($freshSchema->hasTable(self::PREFIX . $table));
		}
	}

	public function testCatalogsKeepEntitlementDataInActiveBackupAndUninstallContracts(): void
	{
		foreach (self::TABLES as $table) {
			self::assertContains($table, ArbeitszeitCheckTableCatalog::TABLES);
			self::assertContains($table, UninstallDropTables::TABLES);
			self::assertContains($table, UpgradeBackupCatalog::BACKUP_TABLES);
			self::assertContains($table, UpgradeBackupCatalog::RESTORE_TABLE_ORDER);
		}
	}

	private function schemaWithLegacySnapshot(): Schema
	{
		$schema = new Schema();
		$legacy = $schema->createTable(self::PREFIX . 'at_entitlement_snapshots');
		$legacy->addColumn('id', 'bigint', ['autoincrement' => true, 'unsigned' => true, 'notnull' => true]);
		$legacy->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
		$legacy->addColumn('period_key', 'string', ['notnull' => true, 'length' => 32]);
		$legacy->addColumn('as_of_date', 'date', ['notnull' => false]);
		$legacy->setPrimaryKey(['id']);
		return $schema;
	}

	/** @param list<string> $columns */
	private function assertColumns(Table $table, array $columns): void
	{
		foreach ($columns as $column) {
			self::assertTrue($table->hasColumn($column), $table->getName() . ' is missing column ' . $column);
		}
	}

	/** @param list<string> $columns */
	private function hasIndexCovering(Table $table, array $columns): bool
	{
		foreach ($table->getIndexes() as $index) {
			$indexColumns = array_map('strval', $index->getUnquotedColumns());
			if (array_slice($indexColumns, 0, count($columns)) === $columns) {
				return true;
			}
		}
		return false;
	}

	/** @param list<string> $columns */
	private function hasUniqueIndex(Table $table, array $columns): bool
	{
		foreach ($table->getIndexes() as $index) {
			if (!$index->isUnique()) {
				continue;
			}
			$indexColumns = array_map('strval', $index->getUnquotedColumns());
			if ($indexColumns === $columns) {
				return true;
			}
		}
		return false;
	}
}
