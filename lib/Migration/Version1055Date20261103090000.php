<?php

declare(strict_types=1);

/**
 * Business-trip approval snapshots (Workforce Policy Suite, Phase 5).
 *
 * A snapshot freezes the hash chain over trip inputs, resolved rule sets
 * and every per-day calculation row. `at_btrip.current_snapshot_id` points
 * at the latest snapshot; approval compares it against the client's token
 * so only exactly one concurrent approval can commit.
 *
 * Expand-only.
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

class Version1055Date20261103090000 extends SimpleMigrationStep
{
	public const TABLE_SNAPSHOT = 'at_btrip_snapshot';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(self::TABLE_SNAPSHOT)) {
			$table = $schema->createTable(self::TABLE_SNAPSHOT);
			$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
			$table->addColumn('trip_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('trip_revision', Types::INTEGER, ['notnull' => true, 'unsigned' => true]);
			$table->addColumn('payload_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('kind', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'preview']);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id'], 'at_btrip_snap_pk');
			$table->addIndex(['trip_id', 'created_at'], 'at_btrip_snap_trip_idx');
			return $schema;
		}
		return null;
	}
}
