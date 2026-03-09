<?php

declare(strict_types=1);

/**
 * Migration to add composite indexes for optimized query performance
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add composite indexes for common query patterns
 */
class Version1003Date20250103000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		$changed = false;

		// Add composite index for time entries: user_id + start_time (for date range queries)
		if ($schema->hasTable('at_entries')) {
			$table = $schema->getTable('at_entries');
			if (!$table->hasIndex('at_ent_user_start_idx')) {
				$table->addIndex(['user_id', 'start_time'], 'at_ent_user_start_idx');
				$changed = true;
			}
			// Add composite index for user_id + status (for active entry queries)
			if (!$table->hasIndex('at_ent_user_stat_idx')) {
				$table->addIndex(['user_id', 'status'], 'at_ent_user_stat_idx');
				$changed = true;
			}
		}

		// Add composite index for absences: user_id + status (for pending/approved queries)
		if ($schema->hasTable('at_absences')) {
			$table = $schema->getTable('at_absences');
			if (!$table->hasIndex('at_abs_user_stat_idx')) {
				$table->addIndex(['user_id', 'status'], 'at_abs_user_stat_idx');
				$changed = true;
			}
		}

		// Add composite index for violations: user_id + resolved (for unresolved violations queries)
		if ($schema->hasTable('at_violations')) {
			$table = $schema->getTable('at_violations');
			if (!$table->hasIndex('at_viol_user_res_idx')) {
				$table->addIndex(['user_id', 'resolved'], 'at_viol_user_res_idx');
				$changed = true;
			}
			// Add composite index for user_id + date (for date range queries)
			if (!$table->hasIndex('at_viol_user_date_idx')) {
				$table->addIndex(['user_id', 'date'], 'at_viol_user_date_idx');
				$changed = true;
			}
		}

		// Add composite index for audit log: user_id + created_at (for date range queries)
		if ($schema->hasTable('at_audit')) {
			$table = $schema->getTable('at_audit');
			if (!$table->hasIndex('at_aud_user_creat_idx')) {
				$table->addIndex(['user_id', 'created_at'], 'at_aud_user_creat_idx');
				$changed = true;
			}
			// Add composite index for entity_type + entity_id (for entity-specific queries)
			if (!$table->hasIndex('at_aud_entity_idx')) {
				$table->addIndex(['entity_type', 'entity_id'], 'at_aud_entity_idx');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
