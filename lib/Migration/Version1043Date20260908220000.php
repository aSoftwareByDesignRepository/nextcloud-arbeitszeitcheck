<?php

declare(strict_types=1);

/**
 * DB-level single open session per user (Zeus concurrency → DB truth).
 *
 * MySQL/MariaDB: STORED generated `live_user_id` + UNIQUE (`at_ent_live_uid_uq`)
 * so only one row with status in (active, break) can exist per user_id.
 * PostgreSQL: partial unique index on user_id WHERE status IN ('active','break').
 *
 * Entity dual-reads `live_user_id` as read-only {@see \OCA\ArbeitszeitCheck\Db\TimeEntry::$liveUserId}.
 *
 * Idempotent: skips when the constraint already exists (expand-only / legacy-safe).
 */

namespace OCA\ArbeitszeitCheck\Migration;

use Closure;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1043Date20260908220000 extends SimpleMigrationStep
{
	public const LIVE_USER_UNIQUE = 'at_ent_live_uid_uq';

	public const PG_PARTIAL_UNIQUE = 'at_ent_open_uid_uq';

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	private function physicalTable(string $logical): string
	{
		return $this->config->getSystemValueString('dbtableprefix', 'oc_') . $logical;
	}

	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		if (!$this->db->tableExists('at_entries')) {
			return;
		}

		// Keep newest open row per user; close older duplicates before UNIQUE.
		$qb = $this->db->getQueryBuilder();
		$qb->select('user_id')
			->selectAlias($qb->func()->count('*'), 'c')
			->from('at_entries')
			->where($qb->expr()->in(
				'status',
				$qb->createNamedParameter(
					[TimeEntry::STATUS_ACTIVE, TimeEntry::STATUS_BREAK],
					IQueryBuilder::PARAM_STR_ARRAY
				)
			))
			->groupBy('user_id')
			->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));

		$dupUsers = [];
		$cursor = $qb->executeQuery();
		while (($row = $cursor->fetch()) !== false) {
			$dupUsers[] = (string)$row['user_id'];
		}
		$cursor->closeCursor();

		$closed = 0;
		foreach ($dupUsers as $userId) {
			$keepQb = $this->db->getQueryBuilder();
			$keepQb->select('id')
				->from('at_entries')
				->where($keepQb->expr()->eq('user_id', $keepQb->createNamedParameter($userId)))
				->andWhere($keepQb->expr()->in(
					'status',
					$keepQb->createNamedParameter(
						[TimeEntry::STATUS_ACTIVE, TimeEntry::STATUS_BREAK],
						IQueryBuilder::PARAM_STR_ARRAY
					)
				))
				->orderBy('start_time', 'DESC')
				->setMaxResults(1);
			$keepId = (int)$keepQb->executeQuery()->fetchOne();
			if ($keepId <= 0) {
				continue;
			}

			$upd = $this->db->getQueryBuilder();
			$upd->update('at_entries')
				->set('status', $upd->createNamedParameter(TimeEntry::STATUS_PAUSED))
				->set('ended_reason', $upd->createNamedParameter(TimeEntry::ENDED_REASON_STALE_PAUSED_REPAIR))
				->where($upd->expr()->eq('user_id', $upd->createNamedParameter($userId)))
				->andWhere($upd->expr()->in(
					'status',
					$upd->createNamedParameter(
						[TimeEntry::STATUS_ACTIVE, TimeEntry::STATUS_BREAK],
						IQueryBuilder::PARAM_STR_ARRAY
					)
				))
				->andWhere($upd->expr()->neq('id', $upd->createNamedParameter($keepId, IQueryBuilder::PARAM_INT)));
			$closed += $upd->executeStatement();
		}

		if ($closed > 0) {
			$output->info(sprintf(
				'arbeitszeitcheck: paused %d duplicate open time-entry row(s) before live-session uniqueness.',
				$closed
			));
		}
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		// Platform-specific DDL lives in postSchemaChange (generated / partial indexes).
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		if (!$this->db->tableExists('at_entries')) {
			return;
		}

		$provider = $this->db->getDatabaseProvider();
		if ($provider === IDBConnection::PLATFORM_MYSQL || $provider === 'mysql') {
			$this->ensureMysqlLiveUserUnique($output);
			return;
		}
		if ($provider === IDBConnection::PLATFORM_POSTGRES || $provider === 'postgresql' || $provider === 'pgsql') {
			$this->ensurePostgresPartialUnique($output);
		}
	}

	private function ensureMysqlLiveUserUnique(IOutput $output): void
	{
		$table = $this->physicalTable('at_entries');
		$safe = str_replace('`', '``', $table);

		$cols = $this->db->executeQuery('SHOW COLUMNS FROM `' . $safe . '` LIKE \'live_user_id\'')->fetchAll();
		if ($cols === []) {
			$this->db->executeStatement(
				'ALTER TABLE `' . $safe . '` '
				. 'ADD COLUMN `live_user_id` varchar(64) '
				. 'GENERATED ALWAYS AS (CASE WHEN `status` IN (\'active\',\'break\') THEN `user_id` ELSE NULL END) STORED'
			);
			$output->info('arbeitszeitcheck: added generated live_user_id on at_entries');
		}

		$idx = $this->db->executeQuery(
			'SHOW INDEX FROM `' . $safe . '` WHERE Key_name = ?',
			[self::LIVE_USER_UNIQUE]
		)->fetchAll();
		if ($idx === []) {
			$this->db->executeStatement(
				'ALTER TABLE `' . $safe . '` '
				. 'ADD UNIQUE KEY `' . self::LIVE_USER_UNIQUE . '` (`live_user_id`)'
			);
			$output->info('arbeitszeitcheck: added unique ' . self::LIVE_USER_UNIQUE);
		}
	}

	private function ensurePostgresPartialUnique(IOutput $output): void
	{
		$table = $this->physicalTable('at_entries');
		$idx = self::PG_PARTIAL_UNIQUE;

		$exists = $this->db->executeQuery(
			'SELECT 1 FROM pg_indexes WHERE schemaname = ANY (current_schemas(false)) AND indexname = ?',
			[$idx]
		)->fetchOne();
		if ($exists) {
			return;
		}

		// Physical table name is from trusted config prefix + fixed logical name.
		$this->db->executeStatement(
			'CREATE UNIQUE INDEX ' . $idx . ' ON ' . $table
			. ' (user_id) WHERE status IN (\'active\', \'break\')'
		);
		$output->info('arbeitszeitcheck: added partial unique ' . $idx);
	}
}
