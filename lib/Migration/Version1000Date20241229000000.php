<?php

declare(strict_types=1);

/**
 * Initial database migration for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-generated migration step
 */
class Version1000Date20241229000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		// Time entries table (short name: at_entries)
		if (!$schema->hasTable('at_entries')) {
			$table = $schema->createTable('at_entries');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('start_time', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('end_time', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('break_start_time', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('break_end_time', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => false,
				'length' => 1000,
			]);
			$table->addColumn('project_check_project_id', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'active',
			]);
			$table->addColumn('is_manual_entry', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('justification', Types::TEXT, [
				'notnull' => false,
				'length' => 1000,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('approved_by', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
			]);
			$table->addColumn('approved_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->setPrimaryKey(['id'], 'at_entries_pk');
			$table->addIndex(['user_id'], 'at_entries_user_idx');
			$table->addIndex(['start_time'], 'at_entries_start_time_idx');
			$table->addIndex(['status'], 'at_entries_status_idx');
			$table->addIndex(['project_check_project_id'], 'at_entries_project_idx');
		}

		// Absences table (short name: at_absences)
		if (!$schema->hasTable('at_absences')) {
			$table = $schema->createTable('at_absences');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('type', Types::STRING, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('start_date', Types::DATE, [
				'notnull' => true,
			]);
			$table->addColumn('end_date', Types::DATE, [
				'notnull' => true,
			]);
			$table->addColumn('days', Types::FLOAT, [
				'notnull' => false,
				'precision' => 6,
				'scale' => 2,
			]);
			$table->addColumn('reason', Types::TEXT, [
				'notnull' => false,
				'length' => 1000,
			]);
			$table->addColumn('status', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'pending',
			]);
			$table->addColumn('approver_comment', Types::TEXT, [
				'notnull' => false,
				'length' => 1000,
			]);
			$table->addColumn('approved_by', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
			]);
			$table->addColumn('approved_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id'], 'at_absences_pk');
			$table->addIndex(['user_id'], 'at_absences_user_idx');
			$table->addIndex(['type'], 'at_absences_type_idx');
			$table->addIndex(['status'], 'at_absences_status_idx');
			$table->addIndex(['start_date', 'end_date'], 'at_absences_date_range_idx');
		}

		// Compliance violations table (short name: at_violations)
		if (!$schema->hasTable('at_violations')) {
			$table = $schema->createTable('at_violations');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('violation_type', Types::STRING, [
				'notnull' => true,
				'length' => 50,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => true,
				'length' => 1000,
			]);
			$table->addColumn('date', Types::DATE, [
				'notnull' => true,
			]);
			$table->addColumn('time_entry_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
			]);
			$table->addColumn('severity', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'warning',
			]);
			$table->addColumn('resolved', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('resolved_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('resolved_by', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id'], 'at_violations_pk');
			$table->addIndex(['user_id'], 'at_violations_user_idx');
			$table->addIndex(['violation_type'], 'at_violations_type_idx');
			$table->addIndex(['date'], 'at_violations_date_idx');
			$table->addIndex(['resolved'], 'at_violations_resolved_idx');
			$table->addIndex(['time_entry_id'], 'at_violations_entry_idx');
		}

		// Audit log table (short name: at_audit)
		if (!$schema->hasTable('at_audit')) {
			$table = $schema->createTable('at_audit');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('action', Types::STRING, [
				'notnull' => true,
				'length' => 50,
			]);
			$table->addColumn('entity_type', Types::STRING, [
				'notnull' => true,
				'length' => 50,
			]);
			$table->addColumn('entity_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
			]);
			$table->addColumn('old_values', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('new_values', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('ip_address', Types::STRING, [
				'notnull' => false,
				'length' => 45,
			]);
			$table->addColumn('user_agent', Types::TEXT, [
				'notnull' => false,
				'length' => 500,
			]);
			$table->addColumn('performed_by', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id'], 'at_audit_pk');
			$table->addIndex(['user_id'], 'at_audit_user_idx');
			$table->addIndex(['action'], 'at_audit_action_idx');
			$table->addIndex(['entity_type'], 'at_audit_entity_type_idx');
			$table->addIndex(['entity_id'], 'at_audit_entity_id_idx');
			$table->addIndex(['created_at'], 'at_audit_created_at_idx');
		}

		// User settings table (short name: at_settings)
		if (!$schema->hasTable('at_settings')) {
			$table = $schema->createTable('at_settings');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('setting_key', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('setting_value', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id'], 'at_settings_pk');
			$table->addIndex(['user_id'], 'at_settings_user_idx');
			$table->addIndex(['user_id', 'setting_key'], 'at_settings_user_key_idx');
			// Add unique constraint: each user can only have one setting per key
			$table->addUniqueIndex(['user_id', 'setting_key'], 'at_settings_user_key_unique');
		}

		// Working time models table (short name: at_models)
		if (!$schema->hasTable('at_models')) {
			$table = $schema->createTable('at_models');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('name', Types::STRING, [
				'notnull' => true,
				'length' => 100,
			]);
			$table->addColumn('description', Types::TEXT, [
				'notnull' => false,
				'length' => 500,
			]);
			$table->addColumn('type', Types::STRING, [
				'notnull' => true,
				'length' => 20,
				'default' => 'full_time',
			]);
			$table->addColumn('weekly_hours', Types::FLOAT, [
				'notnull' => true,
				'precision' => 5,
				'scale' => 2,
				'default' => 40.0,
			]);
			$table->addColumn('daily_hours', Types::FLOAT, [
				'notnull' => true,
				'precision' => 5,
				'scale' => 2,
				'default' => 8.0,
			]);
			$table->addColumn('break_rules', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('overtime_rules', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('is_default', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id'], 'at_models_pk');
			$table->addIndex(['type'], 'at_models_type_idx');
			$table->addIndex(['is_default'], 'at_models_default_idx');
		}

		// User working time models table (short name: at_user_models)
		if (!$schema->hasTable('at_user_models')) {
			$table = $schema->createTable('at_user_models');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('working_time_model_id', Types::BIGINT, [
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('vacation_days_per_year', Types::INTEGER, [
				'notnull' => true,
				'default' => 25,
			]);
			$table->addColumn('start_date', Types::DATE, [
				'notnull' => true,
			]);
			$table->addColumn('end_date', Types::DATE, [
				'notnull' => false,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->setPrimaryKey(['id'], 'at_user_models_pk');
			$table->addIndex(['user_id'], 'at_user_models_user_idx');
			$table->addIndex(['working_time_model_id'], 'at_user_models_model_idx');
			$table->addIndex(['user_id', 'start_date'], 'at_user_models_user_date_idx');
		}

		return $schema;
	}
}