<?php

declare(strict_types=1);

/**
 * Business-trip domain (Workforce Policy Suite, Phase 5).
 *
 * Expand-only. Trips record travel facts, declarative rulesets, deterministic
 * per-day calculations, and an immutable top-up ledger. No table here mutates
 * worked minutes: actual work stays exclusively in TimeEntry; business trips
 * may only create separate, explicitly bounded top-up contributions.
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

class Version1054Date20261102090000 extends SimpleMigrationStep
{
	public const TABLE_TRIP = 'at_btrip';
	public const TABLE_SEGMENT = 'at_btrip_seg';
	public const TABLE_LINK = 'at_btrip_link';
	public const TABLE_RULESET = 'at_btrip_ruleset';
	public const TABLE_ASSIGNMENT = 'at_btrip_rule_asn';
	public const TABLE_PROFILE = 'at_btrip_profile';
	public const TABLE_DAYCALC = 'at_btrip_daycalc';
	public const TABLE_LEDGER = 'at_btrip_ledger';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable(self::TABLE_TRIP)) {
			$table = $schema->createTable(self::TABLE_TRIP);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('starts_at_utc', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('ends_at_utc', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('origin_timezone', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('destination_timezone', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('evaluation_timezone', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('purpose', Types::STRING, ['notnull' => false, 'length' => 200]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 10, 'default' => 'draft']);
			$table->addColumn('request_key', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('current_snapshot_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_btrip_pk');
			$table->addUniqueIndex(['request_key'], 'at_btrip_reqkey_uq');
			$table->addIndex(['user_id', 'status'], 'at_btrip_user_idx');
			$table->addIndex(['starts_at_utc'], 'at_btrip_start_idx');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_SEGMENT)) {
			$table = $schema->createTable(self::TABLE_SEGMENT);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('trip_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('starts_at_utc', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('ends_at_utc', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('segment_type', Types::STRING, ['notnull' => true, 'length' => 20]);
			$table->addColumn('transport_mode', Types::STRING, ['notnull' => false, 'length' => 20]);
			$table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 20, 'default' => 'employee']);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_btrip_seg_pk');
			$table->addIndex(['trip_id', 'starts_at_utc'], 'at_btrip_seg_trip_idx');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_LINK)) {
			$table = $schema->createTable(self::TABLE_LINK);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('trip_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('time_entry_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_btrip_link_pk');
			$table->addUniqueIndex(['time_entry_id'], 'at_btrip_link_te_uq');
			$table->addUniqueIndex(['trip_id', 'time_entry_id'], 'at_btrip_link_uq');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_RULESET)) {
			$table = $schema->createTable(self::TABLE_RULESET);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('version', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 120]);
			$table->addColumn('jurisdiction', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'custom']);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'draft']);
			$table->addColumn('valid_from', Types::DATE, ['notnull' => false]);
			$table->addColumn('valid_to', Types::DATE, ['notnull' => false]);
			$table->addColumn('config_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('config_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('activated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('activated_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_btrip_ruleset_pk');
			$table->addUniqueIndex(['code', 'version'], 'at_btrip_ruleset_uq');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_ASSIGNMENT)) {
			$table = $schema->createTable(self::TABLE_ASSIGNMENT);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('rule_set_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('scope_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('scope_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('priority', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('effective_from', Types::DATE, ['notnull' => true]);
			$table->addColumn('effective_to', Types::DATE, ['notnull' => false]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_btrip_rule_asn_pk');
			$table->addIndex(['scope_type', 'scope_id'], 'at_btrip_asn_scope_idx');
			$table->addIndex(['effective_from', 'effective_to'], 'at_btrip_asn_dates_idx');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_PROFILE)) {
			$table = $schema->createTable(self::TABLE_PROFILE);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('normal_commute_minutes', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('effective_from', Types::DATE, ['notnull' => true]);
			$table->addColumn('effective_to', Types::DATE, ['notnull' => false]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_btrip_profile_pk');
			$table->addIndex(['user_id', 'effective_from'], 'at_btrip_prof_user_idx');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_DAYCALC)) {
			$table = $schema->createTable(self::TABLE_DAYCALC);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('trip_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('local_date', Types::DATE, ['notnull' => true]);
			$table->addColumn('rule_set_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('rule_config_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('presence_minutes', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('actual_work_minutes', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('active_travel_minutes', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('passive_travel_minutes', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('compliance_minutes', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('account_target_minutes', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('account_top_up_minutes', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('export_minutes', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 0]);
			$table->addColumn('result_json', Types::TEXT, ['notnull' => true]);
			$table->addColumn('snapshot_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->setPrimaryKey(['id'], 'at_btrip_daycalc_pk');
			$table->addUniqueIndex(['trip_id', 'local_date', 'snapshot_hash'], 'at_btrip_daycalc_uq');
			$table->addIndex(['trip_id'], 'at_btrip_daycalc_trip_idx');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_LEDGER)) {
			$table = $schema->createTable(self::TABLE_LEDGER);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('trip_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('local_date', Types::DATE, ['notnull' => true]);
			$table->addColumn('event_key', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 10]);
			$table->addColumn('minutes', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('reverses_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
			$table->addColumn('snapshot_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 500]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_btrip_ledger_pk');
			$table->addUniqueIndex(['event_key'], 'at_btrip_ledger_key_uq');
			$table->addIndex(['user_id', 'local_date'], 'at_btrip_ledger_user_idx');
			$table->addIndex(['trip_id'], 'at_btrip_ledger_trip_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
