<?php

declare(strict_types=1);

/**
 * Migration to add substitute_user_id field to absences table
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
 * Migration to add substitute_user_id to at_absences table
 */
class Version1002Date20250102000000 extends SimpleMigrationStep {

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('at_absences')) {
			$table = $schema->getTable('at_absences');
			if (!$table->hasColumn('substitute_user_id')) {
				$table->addColumn('substitute_user_id', Types::STRING, [
					'notnull' => false,
					'length' => 64,
				]);
				$table->addIndex(['substitute_user_id'], 'at_abs_subst_idx');
				return $schema;
			}
		}

		return null;
	}
}
