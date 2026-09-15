<?php

declare(strict_types=1);

/**
 * Audited overtime balance adjustments (Nullung / manual payout booking).
 * Does not alter time entries — hour-ledger only.
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

class Version1044Date20260914060000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		// strlen(oc_) + strlen(at_ot_adj) = 3 + 9 = 12 <= 30 (Oracle-safe).
		if (!$schema->hasTable('at_ot_adj')) {
			$table = $schema->createTable('at_ot_adj');
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('calendar_year', Types::INTEGER, [
				'notnull' => true,
				'unsigned' => true,
			]);
			$table->addColumn('effective_on', Types::DATE, [
				'notnull' => true,
			]);
			// Signed: positive credits balance (forgive undertime); negative debits (payout / null plus).
			$table->addColumn('hours_delta', Types::FLOAT, [
				'notnull' => true,
			]);
			$table->addColumn('reason_code', Types::STRING, [
				'notnull' => true,
				'length' => 32,
			]);
			$table->addColumn('note', Types::STRING, [
				'notnull' => false,
				'length' => 500,
				'default' => null,
			]);
			$table->addColumn('balance_before', Types::FLOAT, [
				'notnull' => true,
			]);
			$table->addColumn('balance_after', Types::FLOAT, [
				'notnull' => true,
			]);
			$table->addColumn('processed_by', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_otadj_pk');
			$table->addIndex(['user_id', 'calendar_year'], 'at_otadj_user_y_idx');
			$table->addIndex(['user_id', 'effective_on'], 'at_otadj_user_on_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
