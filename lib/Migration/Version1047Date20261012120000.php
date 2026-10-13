<?php

declare(strict_types=1);

/**
 * Adds nullable `user_id` to at_models so a model can be employee-scoped
 * ("personal" working time model for individual contract hours).
 *
 * Global models keep user_id = NULL; every existing row therefore stays a
 * normal organisation-wide model. Expand-only / idempotent.
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

class Version1047Date20261012120000 extends SimpleMigrationStep
{
	public const COLUMN = 'user_id';

	public const INDEX = 'at_models_uid_idx';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('at_models')) {
			return null;
		}

		$table = $schema->getTable('at_models');
		$changed = false;

		if (!$table->hasColumn(self::COLUMN)) {
			$table->addColumn(self::COLUMN, Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$changed = true;
		}

		if (!$table->hasIndex(self::INDEX)) {
			$table->addIndex([self::COLUMN], self::INDEX);
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
