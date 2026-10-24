<?php

declare(strict_types=1);

/**
 * Paid-break snapshot column (TVöD §6 Abs. 1 S. 2 shift-work feature).
 *
 * `paid_break_seconds` stores the portion of the calendar-day break pool
 * that is counted as paid working time for this entry. It is written only
 * by the allocator inside the user mutation lock — never by clients —
 * so payroll surfaces read a stable snapshot instead of retro-recomputing
 * against the current model configuration.
 *
 * Idempotent (hasColumn guard), no app-class imports on the upgrade path.
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

class Version1059Date20261124090000 extends SimpleMigrationStep
{
	public const TABLE = 'at_entries';
	public const COLUMN = 'paid_break_seconds';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}
		$table = $schema->getTable(self::TABLE);
		if ($table->hasColumn(self::COLUMN)) {
			return null;
		}
		$table->addColumn(self::COLUMN, Types::INTEGER, [
			'notnull' => true,
			'default' => 0,
		]);
		return $schema;
	}
}
