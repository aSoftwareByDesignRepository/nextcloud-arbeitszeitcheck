<?php

declare(strict_types=1);

/**
 * MH-14: successor chain for working-time models.
 *
 * `superseded_by_model_id` BIGINT NULL on `at_models` — set when a
 * calc-relevant edit on an assigned model is materialized as a successor
 * copy. The old row stays byte-identical so historical assignments keep
 * resolving (sealed = Rechtsstand); the flag only filters it out of
 * assignment pickers (R-12) — never a deletion.
 *
 * Additive and guarded (hasTable + hasColumn); re-running is a no-op.
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

class Version1064Date20261124113000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('at_models')) {
			return null;
		}

		$table = $schema->getTable('at_models');
		if ($table->hasColumn('superseded_by_model_id')) {
			return null;
		}

		$table->addColumn('superseded_by_model_id', Types::BIGINT, [
			'notnull' => false,
			'default' => null,
		]);

		return $schema;
	}
}
