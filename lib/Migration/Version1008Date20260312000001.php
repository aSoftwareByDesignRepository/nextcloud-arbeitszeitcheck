<?php

declare(strict_types=1);

/**
 * Add unique constraint on (state, date, scope) to at_holidays to prevent
 * duplicate holiday rows (e.g. from concurrent statutory seeding).
 *
 * Before adding the index, we remove existing duplicates, keeping the row
 * with the smallest id per (state, date, scope).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1008Date20260312000001 extends SimpleMigrationStep
{
	public function __construct(
		private IDBConnection $db,
		private IConfig $config
	) {
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		// Remove duplicate rows keeping only the one with the smallest id per
		// (state, date, scope) before the unique index is added.
		// Uses the Doctrine DBAL QueryBuilder to stay fully portable across
		// MySQL/MariaDB, PostgreSQL, and SQLite.
		try {
			$qb = $this->db->getQueryBuilder();

			// Fetch all rows, ordered so duplicates within a group are predictable
			$qb->select('id', 'state', 'date', 'scope')
				->from('at_holidays')
				->orderBy('state', 'ASC')
				->addOrderBy('date', 'ASC')
				->addOrderBy('scope', 'ASC')
				->addOrderBy('id', 'ASC');

			$rows = $qb->executeQuery()->fetchAllAssociative();
		} catch (\Throwable $e) {
			$msg = (string)$e->getMessage();
			// Table does not exist on a fresh install — nothing to deduplicate
			if (str_contains($msg, "doesn't exist")
				|| str_contains($msg, 'does not exist')
				|| str_contains($msg, 'no such table')
				|| str_contains($msg, 'undefined table')
			) {
				return;
			}
			throw $e;
		}

		// Identify the IDs to delete in PHP — keeps the row with the smallest id
		// per group and marks all others as duplicates.
		$seen    = [];
		$toDelete = [];
		foreach ($rows as $row) {
			$key = $row['state'] . '|' . $row['date'] . '|' . $row['scope'];
			if (isset($seen[$key])) {
				$toDelete[] = (int)$row['id'];
			} else {
				$seen[$key] = true;
			}
		}

		// Delete in batches of 100 to avoid excessively long IN-clauses
		foreach (array_chunk($toDelete, 100) as $batch) {
			$delQb = $this->db->getQueryBuilder();
			$delQb->delete('at_holidays')
				->where($delQb->expr()->in('id', $delQb->createNamedParameter($batch, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
			$delQb->executeStatement();
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('at_holidays')) {
			return null;
		}

		$table = $schema->getTable('at_holidays');
		// Max 30 chars for Oracle; "at_holidays_state_date_scope_uniq" = 31
		$indexName = 'at_holidays_state_date_scope_u';
		if ($table->hasIndex($indexName)) {
			return null;
		}

		$table->addUniqueIndex(['state', 'date', 'scope'], $indexName);

		return $schema;
	}
}
