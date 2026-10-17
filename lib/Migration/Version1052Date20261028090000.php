<?php

declare(strict_types=1);

/**
 * Universal work-location model (Workforce Policy Suite, Phase 3).
 *
 * Expand-only:
 * - `at_work_location_def`    — server-authoritative catalog (built-ins seeded
 *                               by the post-migration repair step, not here).
 * - `at_work_location_policy` — effectively dated default policies per scope
 *                               (org / model / team / user).
 * - `at_work_location_day`    — per-user per-day overrides (one row per day).
 * - widens `at_entries.work_location` from 16 to 64 chars so catalog codes fit.
 *
 * Historical `NULL` on `at_entries.work_location` keeps meaning "not recorded";
 * nothing is backfilled. Overlap guards for policies are enforced by the
 * service layer under a lock — a date-range overlap cannot be expressed as a
 * portable unique index.
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

class Version1052Date20261028090000 extends SimpleMigrationStep
{
	public const TABLE_DEF = 'at_work_location_def';
	public const TABLE_POLICY = 'at_work_location_policy';
	public const TABLE_DAY = 'at_work_location_day';
	public const ENTRIES_COLUMN = 'work_location';
	public const ENTRIES_COLUMN_LENGTH = 64;

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable(self::TABLE_DEF)) {
			$table = $schema->createTable(self::TABLE_DEF);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('base_label', Types::STRING, ['notnull' => true, 'length' => 80]);
			$table->addColumn('label_i18n', Types::TEXT, ['notnull' => false]);
			$table->addColumn('reporting_category', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('color', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'neutral']);
			$table->addColumn('icon', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'map-pin']);
			$table->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$table->addColumn('built_in', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('version', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_wl_def_pk');
			$table->addUniqueIndex(['code'], 'at_wl_def_code_uq');
			$table->addIndex(['active'], 'at_wl_def_active_idx');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_POLICY)) {
			$table = $schema->createTable(self::TABLE_POLICY);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('scope_type', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('scope_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('location_code', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('priority', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('effective_from', Types::DATE, ['notnull' => true]);
			$table->addColumn('effective_to', Types::DATE, ['notnull' => false]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_wl_pol_pk');
			$table->addIndex(['scope_type', 'scope_id', 'effective_from'], 'at_wl_pol_scope_idx');
			$table->addIndex(['location_code'], 'at_wl_pol_code_idx');
			$changed = true;
		}

		if (!$schema->hasTable(self::TABLE_DAY)) {
			$table = $schema->createTable(self::TABLE_DAY);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('local_date', Types::DATE, ['notnull' => true]);
			$table->addColumn('location_code', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('source', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'web']);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_wl_day_pk');
			$table->addUniqueIndex(['user_id', 'local_date'], 'at_wl_day_user_uq');
			$changed = true;
		}

		if ($schema->hasTable('at_entries')) {
			$entries = $schema->getTable('at_entries');
			if ($entries->hasColumn(self::ENTRIES_COLUMN)) {
				$column = $entries->getColumn(self::ENTRIES_COLUMN);
				if ($column->getLength() < self::ENTRIES_COLUMN_LENGTH) {
					$column->setLength(self::ENTRIES_COLUMN_LENGTH);
					$changed = true;
				}
			}
		}

		return $changed ? $schema : null;
	}
}
