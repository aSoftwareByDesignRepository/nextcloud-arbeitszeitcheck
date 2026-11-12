<?php

declare(strict_types=1);

/**
 * Explicit day-fraction / half-day-slot persistence on `at_absences`
 * (AZC-HALF-DAY-SLOTS-BOUNDARIES).
 *
 * Until now a half day existed only as a *derived* fact (`days / day weight`),
 * so a retroactive schedule-weight change (new half company holiday etc.)
 * silently re-interpreted stored rows (EC-18). These four nullable columns
 * persist the entered boundary fractions and AM/PM slots as truth:
 *
 * - start_day_fraction / start_day_slot — fraction+slot of the FIRST day
 *   (single-day rows: the day itself)
 * - end_day_fraction / end_day_slot — fraction+slot of the LAST day
 *   (multi-day ranges only; NULL when start = end)
 *
 * NULL means "nothing special stored": full-day coverage on that boundary,
 * or a legacy row whose fraction is still derived once and then sealed by
 * the SealAbsenceDayFractions repair step. Interior days are always
 * full by construction — only boundaries can carry fractions.
 *
 * Idempotent (hasTable + hasColumn guards), additive nullable columns,
 * no app-class imports on the upgrade path. Rollback only needs to
 * ignore the columns.
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

class Version1062Date20261125090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('at_absences')) {
			return null;
		}
		$table = $schema->getTable('at_absences');
		$changed = false;

		foreach (['start_day_fraction', 'end_day_fraction'] as $col) {
			if (!$table->hasColumn($col)) {
				$table->addColumn($col, 'decimal', [
					'notnull' => false,
					'precision' => 3,
					'scale' => 2,
					'default' => null,
				]);
				$changed = true;
			}
		}
		foreach (['start_day_slot', 'end_day_slot'] as $col) {
			if (!$table->hasColumn($col)) {
				$table->addColumn($col, Types::STRING, [
					'notnull' => false,
					'length' => 4,
					'default' => null,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
