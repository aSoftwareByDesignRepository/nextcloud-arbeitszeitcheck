<?php

declare(strict_types=1);

/**
 * Create at_holidays table for per-state holiday definitions.
 *
 * This table stores statutory, company and custom holidays per German
 * state (Bundesland). It is used by HolidayCalendarService as the
 * single source of truth for holiday calculations.
 *
 * NOTE:
 * - Data migration from the legacy app config value "company_holidays"
 *   is handled in application code once this table exists, so this
 *   migration focuses on schema only.
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

class Version1007Date20260312000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('at_holidays')) {
			return $schema;
		}

		$table = $schema->createTable('at_holidays');

		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'length' => 20,
		]);

		// Two-letter German state code (e.g. BW, BY, NW)
		$table->addColumn('state', Types::STRING, [
			'notnull' => true,
			'length' => 8,
		]);

		$table->addColumn('date', Types::DATE, [
			'notnull' => true,
		]);

		$table->addColumn('name', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);

		// "full" or "half"
		$table->addColumn('kind', Types::STRING, [
			'notnull' => true,
			'length' => 16,
		]);

		// "statutory", "company", or "custom"
		$table->addColumn('scope', Types::STRING, [
			'notnull' => true,
			'length' => 16,
		]);

		// "generated" (seeded from base calendar) or "manual"
		$table->addColumn('source', Types::STRING, [
			'notnull' => false,
			'length' => 16,
		]);

		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->addColumn('updated_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);

		// Frequently used lookup indices
		$table->addIndex(['state', 'date'], 'at_holidays_state_date');
		$table->addIndex(['date'], 'at_holidays_date');

		return $schema;
	}
}

