<?php

declare(strict_types=1);

/**
 * Optional workforce features (SPEC optional-absence-corrections-hourly-leave-year-end):
 *
 * - at_absences.duration_mode / duration_minutes — persisted hour mode for
 *   built-in unpaid_leave / overtime_comp requests. NULL keeps legacy
 *   day-mode semantics; never inferred (NN-04).
 * - at_audit.operation_key / request_digest — atomic operation receipts
 *   for newly enabled correction/hour-mode commands (§15.9).
 * - at_year_reset_run / at_year_reset_item / at_year_reset_ref — manual +
 *   annual year-end reset orchestration (§9.5).
 * - at_workforce_delivery — durable post-commit notification intent (FM-09).
 * - at_balance_rev / at_balance_epoch — monotonic per-user/year financial
 *   revisions plus a shared epoch for global inputs (§9.6/§13.4).
 *
 * Additive and guarded everywhere; re-running is a no-op.
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

class Version1065Date20261126090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('at_absences')) {
			$table = $schema->getTable('at_absences');
			if (!$table->hasColumn('duration_mode')) {
				$table->addColumn('duration_mode', Types::STRING, [
					'notnull' => false,
					'length' => 8,
					'default' => null,
				]);
			}
			if (!$table->hasColumn('duration_minutes')) {
				$table->addColumn('duration_minutes', Types::INTEGER, [
					'notnull' => false,
					'default' => null,
				]);
			}
		}

		if ($schema->hasTable('at_audit')) {
			$table = $schema->getTable('at_audit');
			if (!$table->hasColumn('operation_key')) {
				$table->addColumn('operation_key', Types::STRING, [
					'notnull' => false,
					'length' => 64,
					'default' => null,
				]);
			}
			if (!$table->hasColumn('request_digest')) {
				$table->addColumn('request_digest', Types::STRING, [
					'notnull' => false,
					'length' => 64,
					'default' => null,
				]);
			}
			if (!$table->hasIndex('at_audit_op_uq')) {
				$table->addUniqueIndex(['operation_key'], 'at_audit_op_uq');
			}
		}

		if (!$schema->hasTable('at_year_reset_run')) {
			$table = $schema->createTable('at_year_reset_run');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('operation_key', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('closing_year', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('origin', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('policy_revision', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('policy_snapshot', Types::TEXT, ['notnull' => false]);
			$table->addColumn('selection_snapshot', Types::TEXT, ['notnull' => false]);
			$table->addColumn('actor_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 500]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 24]);
			$table->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 1]);
			$table->addColumn('preview_key_hash', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('preview_expires_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('selection_cursor', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('selection_complete_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_yr_run_pk');
			$table->addUniqueIndex(['operation_key'], 'at_yr_run_op_uq');
			$table->addUniqueIndex(['preview_key_hash'], 'at_yr_run_pv_uq');
			$table->addIndex(['state'], 'at_yr_run_state_idx');
			$table->addIndex(['origin', 'closing_year'], 'at_yr_run_org_yr_idx');
		}

		if (!$schema->hasTable('at_year_reset_item')) {
			$table = $schema->createTable('at_year_reset_item');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('last_execution_run_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('closing_year', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 24]);
			$table->addColumn('source_revision', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('before_centi_hours', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('delta_centi_hours', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('adjustment_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('error_code', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('lease_token', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('lease_until', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 1]);
			$table->addColumn('completed_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('review_state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'clear']);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_yr_item_pk');
			// Target/year identity across all run origins — at most one
			// compensated reset per employee per closing year (NN-05).
			$table->addUniqueIndex(['user_id', 'closing_year'], 'at_yr_item_uq');
			$table->addIndex(['last_execution_run_id'], 'at_yr_item_run_idx');
			$table->addIndex(['state'], 'at_yr_item_state_idx');
			$table->addIndex(['lease_until'], 'at_yr_item_lease_idx');
		}

		if (!$schema->hasTable('at_year_reset_ref')) {
			$table = $schema->createTable('at_year_reset_ref');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('run_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('item_id', Types::BIGINT, ['notnull' => false]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('preview_source_revision', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('preview_result', Types::TEXT, ['notnull' => false]);
			$table->addColumn('outcome', Types::STRING, ['notnull' => true, 'length' => 24, 'default' => 'pending']);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_yr_ref_pk');
			$table->addUniqueIndex(['run_id', 'user_id'], 'at_yr_ref_uq');
			$table->addIndex(['item_id'], 'at_yr_ref_item_idx');
		}

		if (!$schema->hasTable('at_workforce_delivery')) {
			$table = $schema->createTable('at_workforce_delivery');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('audit_event_id', Types::BIGINT, ['notnull' => true]);
			$table->addColumn('recipient_uid', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('channel', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('next_attempt_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('lease_token', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('lease_until', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_wfd_pk');
			$table->addUniqueIndex(['audit_event_id', 'recipient_uid', 'channel'], 'at_wfd_uq');
			$table->addIndex(['state', 'next_attempt_at'], 'at_wfd_due_idx');
		}

		if (!$schema->hasTable('at_balance_rev')) {
			$table = $schema->createTable('at_balance_rev');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('calendar_year', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 1]);
			$table->setPrimaryKey(['id'], 'at_balrev_pk');
			$table->addUniqueIndex(['user_id', 'calendar_year'], 'at_balrev_uq');
		}

		// Shared epoch for global financial inputs (policy/catalog/holiday
		// changes that affect every user) — avoids an unbounded per-user
		// fan-out transaction (§13.4).
		if (!$schema->hasTable('at_balance_epoch')) {
			$table = $schema->createTable('at_balance_epoch');
			$table->addColumn('domain', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('revision', Types::BIGINT, ['notnull' => true, 'default' => 1]);
			$table->setPrimaryKey(['domain'], 'at_balep_pk');
		}

		return $schema;
	}
}
