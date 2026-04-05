<?php

declare(strict_types=1);

/**
 * Vacation carryover per user/year and calendar sync metadata for approved absences.
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

class Version1011Date20260405000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('at_vacation_year_balance')) {
			$table = $schema->createTable('at_vacation_year_balance');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('year', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('carryover_days', Types::FLOAT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_vacb_pk');
			$table->addUniqueIndex(['user_id', 'year'], 'at_vacb_user_year_uq');
			$table->addIndex(['user_id'], 'at_vacb_user_idx');
			$changed = true;
		}

		if (!$schema->hasTable('at_absence_calendar')) {
			$table = $schema->createTable('at_absence_calendar');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('absence_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('calendar_id', Types::BIGINT, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('object_uri', Types::STRING, [
				'notnull' => true,
				'length' => 255,
			]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_abscal_pk');
			$table->addUniqueIndex(['absence_id'], 'at_abscal_absence_uq');
			$table->addIndex(['user_id'], 'at_abscal_user_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
