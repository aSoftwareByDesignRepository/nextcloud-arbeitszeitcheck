<?php

declare(strict_types=1);

/**
 * Change at_violations.resolved_by from INT to VARCHAR(64) so that the actual
 * Nextcloud user ID can be stored instead of an irreversible CRC32 hash.
 *
 * Existing INT values are NULL-ed out because they are CRC32 hashes and the
 * original user ID cannot be recovered from them. The audit log (at_audit) has
 * already been recording the full resolver user ID in the new_values JSON since
 * the feature was introduced, so forensic traceability is preserved there.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1009Date20260314000000 extends SimpleMigrationStep
{
	public function __construct(
		private IDBConnection $db,
		private IConfig $config
	) {
	}

	/**
	 * Null out existing CRC32 hashes before the column type changes.
	 * The audit log retains the full resolver user ID for any previously resolved
	 * violations, so this data loss is acceptable and documented.
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->update('at_violations')
				->set('resolved_by', $qb->createNamedParameter(null, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_NULL))
				->where($qb->expr()->isNotNull('resolved_by'));
			$qb->executeStatement();
		} catch (\Throwable $e) {
			$msg = (string)$e->getMessage();
			// Table might not exist on a fresh install; safe to ignore.
			if (str_contains($msg, "doesn't exist")
				|| str_contains($msg, 'does not exist')
				|| str_contains($msg, 'no such table')
				|| str_contains($msg, 'undefined table')
				|| str_contains($msg, 'relation ')
			) {
				return;
			}
			throw $e;
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('at_violations')) {
			return null;
		}

		$table = $schema->getTable('at_violations');

		if (!$table->hasColumn('resolved_by')) {
			return null;
		}

		$column = $table->getColumn('resolved_by');

		// Already the correct type on a fresh install or re-run — nothing to do.
		if ($column->getType()->getName() === Types::STRING) {
			return null;
		}

		$column->setType(\Doctrine\DBAL\Types\Type::getType('string'));
		$column->setLength(64);
		$column->setNotnull(false);
		$column->setDefault(null);

		return $schema;
	}
}
