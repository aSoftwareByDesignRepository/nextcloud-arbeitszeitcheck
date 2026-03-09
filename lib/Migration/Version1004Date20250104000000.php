<?php

declare(strict_types=1);

/**
 * Migration to add breaks JSON field for multiple breaks support
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migration to add breaks JSON field for storing multiple breaks
 */
class Version1004Date20250104000000 extends SimpleMigrationStep {

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

		// Add breaks JSON field to time entries table
		if ($schema->hasTable('at_entries')) {
			$table = $schema->getTable('at_entries');
			if (!$table->hasColumn('breaks')) {
				$table->addColumn('breaks', Types::TEXT, [
					'notnull' => false,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
