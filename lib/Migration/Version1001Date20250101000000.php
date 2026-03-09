<?php

declare(strict_types=1);

/**
 * Migration to fix BOOLEAN columns: make them nullable to comply with Nextcloud's Oracle constraints
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
 * Migration to fix BOOLEAN NOT NULL columns
 */
class Version1001Date20250101000000 extends SimpleMigrationStep {

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

		// Fix is_manual_entry in at_entries table
		if ($schema->hasTable('at_entries')) {
			$table = $schema->getTable('at_entries');
			if ($table->hasColumn('is_manual_entry')) {
				$column = $table->getColumn('is_manual_entry');
				if ($column->getNotnull()) {
					$column->setNotnull(false);
					$changed = true;
				}
			}
		}

		// Fix resolved in at_violations table
		if ($schema->hasTable('at_violations')) {
			$table = $schema->getTable('at_violations');
			if ($table->hasColumn('resolved')) {
				$column = $table->getColumn('resolved');
				if ($column->getNotnull()) {
					$column->setNotnull(false);
					$changed = true;
				}
			}
		}

		// Fix is_default in at_models table
		if ($schema->hasTable('at_models')) {
			$table = $schema->getTable('at_models');
			if ($table->hasColumn('is_default')) {
				$column = $table->getColumn('is_default');
				if ($column->getNotnull()) {
					$column->setNotnull(false);
					$changed = true;
				}
			}
		}

		return $changed ? $schema : null;
	}
}
