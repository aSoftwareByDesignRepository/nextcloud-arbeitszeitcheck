<?php

declare(strict_types=1);

/**
 * Custom admin-defined absence types ("weitere betriebliche Fehlzeiten").
 *
 * - New table at_custom_absence stores administrator-defined absence types
 *   (soft-deleted via `active`, so historical absences keep a resolvable
 *   label/semantics forever).
 * - Widens at_absences.type VARCHAR(20) → VARCHAR(64): built-in codes are
 *   ≤14 chars but custom codes (`custom_<slug>`) legitimately exceed 20
 *   and would otherwise truncate silently.
 *
 * Expand-only / idempotent.
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

class Version1048Date20261015120000 extends SimpleMigrationStep
{
	public const TABLE = 'at_custom_absence';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable(self::TABLE)) {
			$table = $schema->createTable(self::TABLE);
			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 20,
			]);
			$table->addColumn('code', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('label', Types::STRING, [
				'notnull' => true,
				'length' => 191,
			]);
			$table->addColumn('label_i18n', Types::TEXT, [
				'notnull' => false,
			]);
			$table->addColumn('soll_credit', Types::BOOLEAN, [
				'notnull' => false,
				'default' => false,
			]);
			$table->addColumn('max_days', Types::INTEGER, [
				'notnull' => true,
				'default' => 365,
			]);
			$table->addColumn('color', Types::STRING, [
				'notnull' => true,
				'length' => 32,
				'default' => 'info',
			]);
			$table->addColumn('icon', Types::STRING, [
				'notnull' => true,
				'length' => 64,
				'default' => 'calendar',
			]);
			$table->addColumn('active', Types::BOOLEAN, [
				'notnull' => false,
				'default' => true,
			]);
			$table->addColumn('created_at', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('created_by', Types::STRING, [
				'notnull' => true,
				'length' => 64,
			]);
			$table->addColumn('updated_at', Types::DATETIME, [
				'notnull' => false,
			]);
			$table->addColumn('updated_by', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			$table->setPrimaryKey(['id'], 'at_cabs_pk');
			$table->addUniqueIndex(['code'], 'at_cabs_code_uk');
			$table->addIndex(['active'], 'at_cabs_active_idx');
			$changed = true;
		}

		// Widen at_absences.type so custom codes cannot truncate.
		if ($schema->hasTable('at_absences')) {
			$absences = $schema->getTable('at_absences');
			if ($absences->hasColumn('type') && $absences->getColumn('type')->getLength() < 64) {
				$absences->changeColumn('type', ['length' => 64]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
