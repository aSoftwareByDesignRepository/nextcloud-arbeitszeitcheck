<?php

declare(strict_types=1);

/**
 * Contract test for the Phase 5 business-trip schema.
 *
 * Trips are append/expand-only, portable, uninstallable and never store
 * worked minutes — actual work stays exclusively in TimeEntry; trips only
 * produce separate, explicitly bounded top-up contributions.
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use OCA\ArbeitszeitCheck\Migration\ArbeitszeitCheckTableCatalog;
use OCA\ArbeitszeitCheck\Migration\Version1054Date20261102090000;
use OCA\ArbeitszeitCheck\Migration\Version1055Date20261103090000;
use OCA\ArbeitszeitCheck\Migration\Version1056Date20261105090000;
use OCA\ArbeitszeitCheck\Repair\UninstallDropTables;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupCatalog;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/MonthClosureForeignKeyTest.php';

final class BusinessTripSchemaTest extends TestCase
{
	private const PREFIX = 'oc_';
	private const TABLES = [
		'at_btrip',
		'at_btrip_seg',
		'at_btrip_snapshot',
		'at_btrip_link',
		'at_btrip_ruleset',
		'at_btrip_rule_asn',
		'at_btrip_profile',
		'at_btrip_daycalc',
		'at_btrip_ledger',
	];

	public function testCreatesAllBusinessTripTables(): void
	{
		$schema = new Schema();
		$wrapper = new PrefixedSchemaWrapperFake($schema, self::PREFIX);
		$migration = new Version1054Date20261102090000();
		$snapshotMigration = new Version1055Date20261103090000();
		$provenanceMigration = new Version1056Date20261105090000();

		$result = $migration->changeSchema($this->createMock(IOutput::class), static fn () => $wrapper, []);
		$snapshotMigration->changeSchema($this->createMock(IOutput::class), static fn () => $wrapper, []);
		$provenanceMigration->changeSchema($this->createMock(IOutput::class), static fn () => $wrapper, []);

		self::assertSame($wrapper, $result);
		foreach (self::TABLES as $logicalName) {
			self::assertLessThanOrEqual(30, strlen($logicalName), 'Logical table names must stay portable.');
			$table = $schema->getTable(self::PREFIX . $logicalName);
			$pk = $table->getPrimaryKey();
			self::assertNotNull($pk, $logicalName . ' must have an explicit primary key.');
			self::assertSame(['id'], array_map('strval', $pk->getUnquotedColumns()));
			self::assertLessThanOrEqual(30, strlen($pk->getName()), 'Primary-key identifier must stay portable.');
		}

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_btrip'), [
			'user_id', 'starts_at_utc', 'ends_at_utc', 'origin_timezone', 'destination_timezone',
			'evaluation_timezone', 'purpose', 'status', 'request_key', 'revision',
			'current_snapshot_id', 'created_by', 'updated_by', 'created_at', 'updated_at',
		]);
		self::assertTrue($this->hasUniqueIndex($schema->getTable(self::PREFIX . 'at_btrip'), ['request_key']));

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_btrip_seg'), [
			'trip_id', 'starts_at_utc', 'ends_at_utc', 'segment_type', 'transport_mode',
			'source', 'revision', 'created_at', 'updated_at',
		]);

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_btrip_link'), [
			'trip_id', 'time_entry_id', 'created_at',
		]);
		self::assertTrue($this->hasUniqueIndex($schema->getTable(self::PREFIX . 'at_btrip_link'), ['time_entry_id']));

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_btrip_ruleset'), [
			'code', 'version', 'name', 'jurisdiction', 'status', 'valid_from', 'valid_to',
			'config_json', 'config_hash', 'created_by', 'activated_by', 'activated_at', 'created_at',
			// §10.5 provenance + legal review gate (migration 1056)
			'source_title', 'source_reference', 'classifier_schema_version',
			'reviewed_at', 'reviewed_by',
		]);
		self::assertTrue($this->hasUniqueIndex($schema->getTable(self::PREFIX . 'at_btrip_ruleset'), ['code', 'version']));

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_btrip_rule_asn'), [
			'rule_set_id', 'scope_type', 'scope_id', 'priority', 'effective_from', 'effective_to',
			'revision', 'created_by', 'updated_by', 'created_at', 'updated_at',
		]);

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_btrip_profile'), [
			'user_id', 'normal_commute_minutes', 'effective_from', 'effective_to',
			'revision', 'created_by', 'updated_by', 'created_at', 'updated_at',
		]);

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_btrip_daycalc'), [
			'trip_id', 'local_date', 'rule_set_id', 'rule_config_hash', 'presence_minutes',
			'actual_work_minutes', 'active_travel_minutes', 'passive_travel_minutes',
			'compliance_minutes', 'account_target_minutes', 'account_top_up_minutes',
			'export_minutes', 'result_json', 'snapshot_hash',
		]);
		self::assertTrue($this->hasUniqueIndex($schema->getTable(self::PREFIX . 'at_btrip_daycalc'), [
			'trip_id', 'local_date', 'snapshot_hash',
		]));

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_btrip_ledger'), [
			'user_id', 'trip_id', 'local_date', 'event_key', 'kind', 'minutes',
			'reverses_id', 'snapshot_hash', 'actor_uid', 'reason', 'created_at',
		]);
		self::assertTrue($this->hasUniqueIndex($schema->getTable(self::PREFIX . 'at_btrip_ledger'), ['event_key']));

		$this->assertColumns($schema->getTable(self::PREFIX . 'at_btrip_snapshot'), [
			'trip_id', 'trip_revision', 'payload_hash', 'kind', 'created_by', 'created_at',
		]);
	}

	public function testMigrationIsIdempotent(): void
	{
		$schema = new Schema();
		$wrapper = new PrefixedSchemaWrapperFake($schema, self::PREFIX);
		$migration = new Version1054Date20261102090000();
		$output = $this->createMock(IOutput::class);

		$migration->changeSchema($output, static fn () => $wrapper, []);
		self::assertNull($migration->changeSchema($output, static fn () => $wrapper, []));

		$snapshotMigration = new Version1055Date20261103090000();
		$snapshotMigration->changeSchema($output, static fn () => $wrapper, []);
		self::assertNull($snapshotMigration->changeSchema($output, static fn () => $wrapper, []));
	}

	public function testCatalogsKeepTripDataInActiveBackupAndUninstallContracts(): void
	{
		foreach (self::TABLES as $table) {
			self::assertContains($table, ArbeitszeitCheckTableCatalog::TABLES);
			self::assertContains($table, UninstallDropTables::TABLES);
			self::assertContains($table, UpgradeBackupCatalog::BACKUP_TABLES);
			self::assertContains($table, UpgradeBackupCatalog::RESTORE_TABLE_ORDER);
		}
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
