<?php

declare(strict_types=1);

/**
 * Split-service days: frozen per-entry work context on `at_entries`
 * (split-day-working-model spec §9.5).
 *
 * - `work_context_json` TEXT NULL — frozen split context and last accounting
 *   result. NULL = legacy path: every duration/summary computation stays
 *   byte-identical to the pre-feature algorithm. Written exclusively by the
 *   server-side accounting transaction; never accepted from clients.
 * - `excluded_work_seconds` INTEGER NOT NULL DEFAULT 0 — Σ excluded
 *   interruption seconds for SQL totals; must equal the frozen context.
 *
 * Additive and guarded (hasTable + hasColumn), so running the migration twice
 * is a no-op. Absolutely no UPDATE/DELETE/backfill of existing rows (NN-01):
 * legacy rows keep NULL/0 forever.
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

class Version1063Date20261125090000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('at_entries')) {
			return null;
		}

		$table = $schema->getTable('at_entries');
		$changed = false;

		if (!$table->hasColumn('work_context_json')) {
			$table->addColumn('work_context_json', Types::TEXT, [
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}

		if (!$table->hasColumn('excluded_work_seconds')) {
			$table->addColumn('excluded_work_seconds', Types::INTEGER, [
				'notnull' => true,
				'default' => 0,
			]);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
