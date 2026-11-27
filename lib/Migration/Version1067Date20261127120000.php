<?php

declare(strict_types=1);

/**
 * Recurring holiday rules (customer request 2026-11-27, spec B-R1).
 *
 * at_holiday_rules is the single source of truth for annually repeating
 * company/custom holidays; instances are synthesized at read time in
 * HolidayService::getHolidaysForYearInternal() and never persisted.
 * at_holiday_rule_skips stores per-year exceptions ("in 2027 not").
 *
 * rule_key is a materialized natural key ('d:MM-DD' / 'e:±NN') so the
 * UNIQUE index deduplicates on every supported DB — NULL month/day vs
 * easter_offset columns cannot sit in the unique position because
 * NULL != NULL under MySQL.
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

class Version1067Date20261127120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('at_holiday_rules')) {
			$table = $schema->createTable('at_holiday_rules');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('anchor', Types::STRING, ['notnull' => true, 'length' => 8]);
			$table->addColumn('month', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('day', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('easter_offset', Types::SMALLINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('rule_key', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('name_msgid', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('start_year', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_hrules_pk');
			$table->addUniqueIndex(['state', 'rule_key', 'scope'], 'at_hrules_key_uq');
			$table->addIndex(['state', 'active'], 'at_hrules_state_act_idx');
		}

		if (!$schema->hasTable('at_holiday_rule_skips')) {
			$table = $schema->createTable('at_holiday_rule_skips');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'length' => 20]);
			$table->addColumn('rule_id', Types::BIGINT, ['notnull' => true, 'length' => 20]);
			$table->addColumn('year', Types::INTEGER, ['notnull' => true]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_hrskips_pk');
			$table->addUniqueIndex(['rule_id', 'year'], 'at_hrskips_rule_year_uq');
		}

		return $schema;
	}
}
