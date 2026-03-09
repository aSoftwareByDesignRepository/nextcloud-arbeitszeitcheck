<?php

declare(strict_types=1);

/**
 * Migration to add approved_by_user_id for storing manager UID (Nextcloud user IDs are strings)
 *
 * The approved_by column is BIGINT - (int)"alice" = 0, losing approver identity.
 * This column stores the actual manager user ID as string for correct audit trail.
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

class Version1005Date20250307000000 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('at_entries')) {
			return null;
		}

		$table = $schema->getTable('at_entries');
		if ($table->hasColumn('approved_by_user_id')) {
			return null;
		}

		$table->addColumn('approved_by_user_id', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);
		return $schema;
	}
}
