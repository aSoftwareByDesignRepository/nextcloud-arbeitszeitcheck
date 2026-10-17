<?php

declare(strict_types=1);

/**
 * Generic special-entitlement balances (Workforce Policy Suite, Phase 4).
 *
 * Expand-only. Entitlements are balances over absence-linked entitlements;
 * no table here stores worked minutes and no migration changes historical
 * time-entry or absence semantics.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1053Date20261030090000 extends SimpleMigrationStep
{
	public const TABLE_TYPE = 'at_se_type';
	public const TABLE_POLICY = 'at_se_policy';
	public const TABLE_PERIOD = 'at_se_period';
	public const TABLE_RESERVATION = 'at_se_reserv';
	public const TABLE_LEDGER = 'at_se_ledger';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable(self::TABLE_TYPE)) {
			$table = $schema->createTable(self::TABLE_TYPE);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('public_label', Types::STRING, ['notnull' => true, 'length' => 80]);
			$table->addColumn('confidential_label', Types::STRING, ['notnull' => false, 'length' => 80]);
			$table->addColumn('label_i18n', Types::TEXT, ['notnull' => false]);
			$table->addColumn('absence_type_code', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('unit', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('period_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('allow_negative', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('employee_may_see_confidential_label', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('version', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_se_type_pk');
			$table->addUniqueIndex(['code'], 'at_se_type_code_uq');
			$table->addUniqueIndex(['absence_type_code'], 'at_se_type_abs_uq');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_POLICY)) {
			$table = $schema->createTable(self::TABLE_POLICY);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('entitlement_type_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('scope_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('scope_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('operation', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('amount', 'decimal', ['notnull' => false, 'precision' => 10, 'scale' => 2]);
			$table->addColumn('tariff_rule_set_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('priority', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('effective_from', Types::DATE, ['notnull' => true]);
			$table->addColumn('effective_to', Types::DATE, ['notnull' => false]);
			$table->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 500]);
			$table->addColumn('version', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_se_policy_pk');
			$table->addIndex(['entitlement_type_id', 'scope_type', 'scope_id'], 'at_se_policy_scope_idx');
			$table->addIndex(['effective_from', 'effective_to'], 'at_se_policy_dates_idx');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_PERIOD)) {
			$table = $schema->createTable(self::TABLE_PERIOD);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('entitlement_type_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('period_start', Types::DATE, ['notnull' => true]);
			$table->addColumn('period_end', Types::DATE, ['notnull' => true]);
			$table->addColumn('computed_amount', 'decimal', ['notnull' => true, 'precision' => 10, 'scale' => 2, 'default' => '0']);
			$table->addColumn('unit', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('computation_snapshot_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'open']);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->setPrimaryKey(['id'], 'at_se_period_pk');
			$table->addUniqueIndex(['entitlement_type_id', 'user_id', 'period_start'], 'at_se_period_uq');
			$table->addIndex(['user_id', 'status'], 'at_se_period_user_idx');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_RESERVATION)) {
			$table = $schema->createTable(self::TABLE_RESERVATION);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('period_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('absence_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('amount', 'decimal', ['notnull' => true, 'precision' => 10, 'scale' => 2]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'active']);
			$table->addColumn('request_key', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_se_reserv_pk');
			$table->addUniqueIndex(['absence_id'], 'at_se_reserv_absence_uq');
			$table->addUniqueIndex(['request_key'], 'at_se_reserv_key_uq');
			$table->addIndex(['period_id', 'status'], 'at_se_reserv_period_idx');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_LEDGER)) {
			$table = $schema->createTable(self::TABLE_LEDGER);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('period_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('event_key', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('amount', 'decimal', ['notnull' => true, 'precision' => 10, 'scale' => 2]);
			$table->addColumn('absence_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('reverses_ledger_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 500]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_se_ledger_pk');
			$table->addUniqueIndex(['event_key'], 'at_se_ledger_key_uq');
			$table->addIndex(['period_id'], 'at_se_ledger_period_idx');
			$table->addIndex(['absence_id'], 'at_se_ledger_absence_idx');
			$changed = true;
		}

		if ($schema->hasTable('at_entitlement_snapshots')) {
			$table = $schema->getTable('at_entitlement_snapshots');
			foreach ([
				'entitlement_type_id' => [Types::BIGINT, ['notnull' => false, 'unsigned' => true]],
				'algorithm_version' => [Types::STRING, ['notnull' => false, 'length' => 16]],
				'period_start' => [Types::DATE, ['notnull' => false]],
				'period_end' => [Types::DATE, ['notnull' => false]],
				'unit' => [Types::STRING, ['notnull' => false, 'length' => 8]],
				'effective_amount' => ['decimal', ['notnull' => false, 'precision' => 10, 'scale' => 2]],
			] as $column => [$type, $optionsForColumn]) {
				if (!$table->hasColumn($column)) {
					$table->addColumn($column, $type, $optionsForColumn);
					$changed = true;
				}
			}
			if (!$table->hasIndex('at_ents_type_idx')) {
				$table->addIndex(['entitlement_type_id'], 'at_ents_type_idx');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
