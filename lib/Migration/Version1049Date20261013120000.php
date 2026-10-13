<?php

declare(strict_types=1);

/**
 * Adds nullable `work_location` to at_entries (GH #43 — per-entry marking
 * of office / home office / external appointment).
 *
 * NULL means "not recorded" and is honest for every legacy row; all new
 * writes resolve a concrete value (explicit param → per-employee default
 * → 'office'). Expand-only / idempotent.
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

class Version1049Date20261013120000 extends SimpleMigrationStep
{
	public const COLUMN = 'work_location';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('at_entries')) {
			return null;
		}

		$table = $schema->getTable('at_entries');

		if ($table->hasColumn(self::COLUMN)) {
			return null;
		}

		$table->addColumn(self::COLUMN, Types::STRING, [
			'notnull' => false,
			'length' => 16,
			'default' => null,
		]);

		return $schema;
	}
}
