<?php

declare(strict_types=1);

/**
 * Pending cancel-request flags on `at_absences` (FA-2c).
 *
 * Three nullable columns model the owner→manager cancellation request for a
 * *running* approved absence. The row status deliberately stays `approved`
 * while a request is open so the FIFO allocation and overlap checks keep
 * counting it — the quota is only released when the request is decided.
 *
 * Idempotent (hasTable + hasColumn guards), no app-class imports on the
 * upgrade path. Columns are additive/nullable: a rollback only needs to
 * ignore them.
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

class Version1061Date20261109090000 extends SimpleMigrationStep
{
	public const TABLE = 'at_absences';
	public const COL_REQUESTED_AT = 'cancel_requested_at';
	public const COL_REQUESTED_BY = 'cancel_requested_by';
	public const COL_REASON = 'cancel_reason';

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable(self::TABLE)) {
			return null;
		}
		$table = $schema->getTable(self::TABLE);
		$changed = false;
		if (!$table->hasColumn(self::COL_REQUESTED_AT)) {
			$table->addColumn(self::COL_REQUESTED_AT, Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);
			$changed = true;
		}
		if (!$table->hasColumn(self::COL_REQUESTED_BY)) {
			$table->addColumn(self::COL_REQUESTED_BY, Types::STRING, [
				'notnull' => false,
				'length' => 64,
				'default' => null,
			]);
			$changed = true;
		}
		if (!$table->hasColumn(self::COL_REASON)) {
			$table->addColumn(self::COL_REASON, Types::STRING, [
				'notnull' => false,
				'length' => 500,
				'default' => null,
			]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
