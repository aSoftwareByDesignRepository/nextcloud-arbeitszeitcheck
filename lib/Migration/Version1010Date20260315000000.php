<?php

declare(strict_types=1);

/**
 * Add compound indices on at_entries, at_violations, at_holidays, at_absences
 * for compliance/reporting queries. Idempotent: adds only indices that don't
 * already exist (e.g. from earlier migrations).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1010Date20260315000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		// at_entries: (user_id, status) for findActiveByUser, findOnBreakByUser
		if ($schema->hasTable('at_entries')) {
			$table = $schema->getTable('at_entries');
			if (!$table->hasIndex('at_ent_user_stat_idx')) {
				$table->addIndex(['user_id', 'status'], 'at_ent_user_stat_idx');
				$changed = true;
			}
			// at_entries: (user_id, start_time) for date-range compliance/reporting
			if (!$table->hasIndex('at_ent_user_start_idx')) {
				$table->addIndex(['user_id', 'start_time'], 'at_ent_user_start_idx');
				$changed = true;
			}
		}

		// at_violations: (user_id, resolved) for compliance dashboard
		if ($schema->hasTable('at_violations')) {
			$table = $schema->getTable('at_violations');
			if (!$table->hasIndex('at_viol_user_res_idx')) {
				$table->addIndex(['user_id', 'resolved'], 'at_viol_user_res_idx');
				$changed = true;
			}
		}

		// at_holidays: (state, date) for working-day calculation
		if ($schema->hasTable('at_holidays')) {
			$table = $schema->getTable('at_holidays');
			if (!$table->hasIndex('at_holidays_state_date')) {
				$table->addIndex(['state', 'date'], 'at_holidays_state_date');
				$changed = true;
			}
		}

		// at_absences: (substitute_user_id) for findSubstitutePendingForUser
		if ($schema->hasTable('at_absences')) {
			$table = $schema->getTable('at_absences');
			if (!$table->hasIndex('at_abs_subst_idx')) {
				$table->addIndex(['substitute_user_id'], 'at_abs_subst_idx');
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
