<?php

declare(strict_types=1);

/**
 * Idempotency log for automatic vacation carryover rollover (from_year → to_year).
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

class Version1013Date20260407120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('at_vacation_rollover_log')) {
			$table = $schema->createTable('at_vacation_rollover_log');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('from_year', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('to_year', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('amount', Types::FLOAT, [
				'notnull' => true,
				'default' => 0,
			]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_vrl_pk');
			$table->addUniqueIndex(['user_id', 'from_year', 'to_year'], 'at_vrl_user_from_to_uq');
			$table->addIndex(['from_year'], 'at_vrl_from_year_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
