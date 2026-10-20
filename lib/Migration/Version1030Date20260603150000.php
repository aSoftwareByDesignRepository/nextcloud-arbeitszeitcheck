<?php

declare(strict_types=1);

/**
 * Persist explicit ProjectCheck connection default (opt-in / off).
 *
 * Fresh installs and upgrades without a stored value get "0". Upgrades that
 * already linked time entries to ProjectCheck projects are migrated to "1" so
 * existing billing workflows keep working.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1030Date20260603150000 extends SimpleMigrationStep
{
	public function __construct(
		private readonly IConfig $config,
		private readonly IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		// Stored key/default inlined: migrations run during the in-request app
		// update while the previously installed Constants class may still be
		// loaded — referencing it can fatal with "Undefined constant" (GH #45).
		$key = 'projectcheck_integration_enabled';
		$existing = $this->config->getAppValue('arbeitszeitcheck', $key, '');
		if ($existing !== '') {
			return;
		}

		$value = $this->hasLinkedTimeEntries()
			? '1'
			: '0';

		$this->config->setAppValue('arbeitszeitcheck', $key, $value);
		$output->info(sprintf(
			'ArbeitszeitCheck: set ProjectCheck connection to %s (%s).',
			$value,
			$value === '1'
				? 'existing project links found'
				: 'opt-in default',
		));
	}

	private function hasLinkedTimeEntries(): bool
	{
		if (!$this->db->tableExists('at_entries')) {
			return false;
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'c'))
				->from('at_entries')
				->where($qb->expr()->isNotNull('project_check_project_id'))
				->andWhere($qb->expr()->neq(
					'project_check_project_id',
					$qb->createNamedParameter('', IQueryBuilder::PARAM_STR),
				))
				->setMaxResults(1);

			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();

			return ((int)($row['c'] ?? 0)) > 0;
		} catch (\Throwable $e) {
			return false;
		}
	}
}
