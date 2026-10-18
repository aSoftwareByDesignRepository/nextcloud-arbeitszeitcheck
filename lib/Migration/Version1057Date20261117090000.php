<?php

declare(strict_types=1);

/**
 * Suite flanges F16/F19b — external work-time draft stamps.
 *
 * Sibling apps (TourCheck first-scan, CareCheck `check:work-log` pull)
 * propose time evidence that the *worker* explicitly confirms or dismisses.
 * Rows are never counted time on their own: only `confirm` promotes a row
 * into `at_entries` through the regular compliance path (BetrVG — no
 * covert stamping). `uq(source_app, source_ref)` makes producer retries
 * and pull-consumer replays idempotent.
 *
 * Expand-only and idempotent.
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

class Version1057Date20261117090000 extends SimpleMigrationStep
{
	public const TABLE = 'at_ext_stamp';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE)) {
			return null;
		}
		$table = $schema->createTable(self::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('start_ts', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('end_ts', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('source_app', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('source_ref', Types::STRING, ['notnull' => true, 'length' => 96]);
		$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
		$table->addColumn('promoted_entry_id', Types::BIGINT, ['notnull' => false, 'unsigned' => true]);
		$table->addColumn('decided_by', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('decided_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
		$table->setPrimaryKey(['id'], 'at_ext_stamp_pk');
		$table->addUniqueIndex(['source_app', 'source_ref'], 'at_ext_stamp_ref_uq');
		$table->addIndex(['user_id', 'status'], 'at_ext_stamp_user_idx');
		return $schema;
	}
}
