<?php

declare(strict_types=1);

/**
 * Maps synced public-holiday CalDAV objects per user for Nextcloud Calendar integration.
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

class Version1012Date20260405120000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('at_holiday_calendar')) {
			return null;
		}

		$table = $schema->createTable('at_holiday_calendar');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('holiday_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('calendar_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('object_uri', Types::STRING, [
			'notnull' => true,
			'length' => 255,
		]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id'], 'at_holcal_pk');
		$table->addUniqueIndex(['user_id', 'holiday_id'], 'at_holcal_user_holiday_uq');
		$table->addIndex(['user_id'], 'at_holcal_user_idx');

		return $schema;
	}
}
