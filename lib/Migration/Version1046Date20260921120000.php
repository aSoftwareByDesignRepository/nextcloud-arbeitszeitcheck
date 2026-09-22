<?php

declare(strict_types=1);

/**
 * Indexed Offline-Sync capture source on audit rows (Kraft F4 scale follow-up).
 *
 * Adds nullable `capture_source` + short index so admin Offline-Sync filter can
 * use equality instead of LIKE on `new_values` JSON. Backfills existing rows
 * that already store capture_source=offline_sync in new_values (same needle as
 * the former LIKE filter). Expand-only / idempotent.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCA\ArbeitszeitCheck\Constants;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1046Date20260921120000 extends SimpleMigrationStep
{
	public const COLUMN = 'capture_source';

	public const INDEX = 'at_audit_csrc_idx';

	/** Same JSON needle the pre-1046 LIKE filter used (PHP json_encode, no spaces). */
	public const LEGACY_JSON_NEEDLE = '%"capture_source":"offline_sync"%';

	private const BACKFILL_CHUNK = 500;

	/** Soft wall-clock budget so web UI app upgrades do not stall in maintenance. */
	private const BACKFILL_MAX_SECONDS = 15;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('at_audit')) {
			return null;
		}

		$table = $schema->getTable('at_audit');
		$changed = false;

		if (!$table->hasColumn(self::COLUMN)) {
			$table->addColumn(self::COLUMN, Types::STRING, [
				'notnull' => false,
				'length' => 32,
				'default' => null,
			]);
			$changed = true;
		}

		if (!$table->hasIndex(self::INDEX)) {
			$table->addIndex([self::COLUMN], self::INDEX);
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		if (!$this->db->tableExists('at_audit')) {
			return;
		}

		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('at_audit') || !$schema->getTable('at_audit')->hasColumn(self::COLUMN)) {
			return;
		}

		$updated = 0;
		$started = microtime(true);
		$deferred = false;
		do {
			if ((microtime(true) - $started) >= self::BACKFILL_MAX_SECONDS) {
				$deferred = true;
				break;
			}
			$select = $this->db->getQueryBuilder();
			$select->select('id')
				->from('at_audit')
				->where($select->expr()->isNull(self::COLUMN))
				->andWhere($select->expr()->like(
					'new_values',
					$select->createNamedParameter(self::LEGACY_JSON_NEEDLE)
				))
				->setMaxResults(self::BACKFILL_CHUNK);

			$rows = $select->executeQuery()->fetchAll();
			$ids = [];
			foreach ($rows as $row) {
				$id = isset($row['id']) ? (int)$row['id'] : 0;
				if ($id > 0) {
					$ids[] = $id;
				}
			}
			if ($ids === []) {
				break;
			}

			$update = $this->db->getQueryBuilder();
			$update->update('at_audit')
				->set(self::COLUMN, $update->createNamedParameter(Constants::AUDIT_CAPTURE_SOURCE_OFFLINE_SYNC))
				->where($update->expr()->in(
					'id',
					$update->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)
				));
			$updated += $update->executeStatement();
		} while (\count($ids) === self::BACKFILL_CHUNK);

		if ($updated > 0) {
			$output->info(sprintf(
				'arbeitszeitcheck: backfilled capture_source on %d audit row(s).',
				$updated
			));
		}
		if ($deferred) {
			// Schema is already applied; remaining rows are finished by
			// BackfillAuditCaptureSourceJob (and Offline-Sync filter still works
			// for new writes that set the column directly).
			$output->info('arbeitszeitcheck: capture_source backfill deferred (time budget); background job continues.');
		}
	}
}
