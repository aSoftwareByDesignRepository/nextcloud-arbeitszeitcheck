<?php

declare(strict_types=1);

/**
 * Fix F16 schema/entity mismatch: `ExternalStamp::$kind` defaults to
 * 'stamp', and `Entity::setter()` no-ops when the assigned value equals the
 * property default — so every KIND_STAMP insert omitted the column and
 * strict-mode MariaDB rejected it (Field 'kind' doesn't have a default
 * value). The column default converges those inserts to the same value the
 * entity intended; 'segment' always differs from the property default and
 * is inserted explicitly.
 *
 * Idempotent.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1058Date20261121090000 extends SimpleMigrationStep
{
	public const TABLE = 'at_ext_stamp';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}
		$table = $schema->getTable(self::TABLE);
		if (!$table->hasColumn('kind')) {
			return null;
		}
		$column = $table->getColumn('kind');
		if ($column->getDefault() === 'stamp') {
			return null;
		}
		$column->setDefault('stamp');
		return $schema;
	}
}
