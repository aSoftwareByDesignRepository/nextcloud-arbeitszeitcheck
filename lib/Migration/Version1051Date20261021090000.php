<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1051Date20261021090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('at_policy_cohort')) {
			$table = $schema->createTable('at_policy_cohort');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('code', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('public_label', Types::STRING, ['notnull' => true, 'length' => 255]);
			$table->addColumn('confidential_label', Types::STRING, ['notnull' => false, 'length' => 255]);
			$table->addColumn('active', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->setPrimaryKey(['id'], 'at_pc_pk');
			$table->addUniqueIndex(['code'], 'at_pc_code_uq');
			$table->addIndex(['active'], 'at_pc_active_idx');
			$changed = true;
		}

		if (!$schema->hasTable('at_policy_cohort_member')) {
			$table = $schema->createTable('at_policy_cohort_member');
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('cohort_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('effective_from', Types::DATE, ['notnull' => true]);
			$table->addColumn('effective_to', Types::DATE, ['notnull' => false]);
			$table->addColumn('revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true, 'default' => 1]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->setPrimaryKey(['id'], 'at_pcm_pk');
			$table->addUniqueIndex(['cohort_id', 'user_id', 'effective_from'], 'at_pcm_period_uq');
			$table->addIndex(['user_id', 'effective_from', 'effective_to'], 'at_pcm_user_dates_idx');
			$table->addForeignKeyConstraint($schema->getTable('at_policy_cohort'), ['cohort_id'], ['id'], ['onDelete' => 'CASCADE'], 'at_pcm_cohort_fk');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
