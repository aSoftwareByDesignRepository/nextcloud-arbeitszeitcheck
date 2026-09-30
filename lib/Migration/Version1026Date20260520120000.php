<?php

declare(strict_types=1);

/**
 * Rename overtime year-balance table to Oracle-safe identifier length.
 *
 * Background
 * ----------
 * `Version1025Date20260519120000` originally created
 * `at_user_overtime_year_balance`, which with the default `oc_` prefix exceeds
 * the 30-character identifier cap enforced by Nextcloud for Oracle-compatible
 * installs. That prevented enabling the app on some production databases.
 *
 * Fresh installs now get `at_user_ot_year_bal` from the corrected 1025 step.
 * This migration renames the legacy physical table on instances that already
 * applied 1025 before the fix, without copying or dropping user data.
 *
 * PostgreSQL keeps the old `…_id_seq` sequence name after `ALTER TABLE … RENAME`;
 * we rename the sequence when present so schema reflection stays consistent.
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
use RuntimeException;

class Version1026Date20260520120000 extends SimpleMigrationStep
{
	private const OLD_LOGICAL = 'at_user_overtime_year_balance';

	private const NEW_LOGICAL = 'at_user_ot_year_bal';

	public function __construct(
		private IDBConnection $db,
		private IConfig $config,
	) {
	}

	private function tablePrefix(): string
	{
		return (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void
	{
		$this->renameIfNeeded($output);
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES) {
			$this->renamePostgresSequenceIfNeeded($output);
		}
	}

	private function renameIfNeeded(IOutput $output): void
	{
		$oldExists = $this->db->tableExists(self::OLD_LOGICAL);
		$newExists = $this->db->tableExists(self::NEW_LOGICAL);

		if (!$oldExists && $newExists) {
			return;
		}
		if (!$oldExists && !$newExists) {
			return;
		}
		if ($oldExists && $newExists) {
			if ($this->isTableEmpty(self::NEW_LOGICAL)) {
				$this->dropEmptyTarget($output);
			} else {
				throw new RuntimeException(
					'ArbeitszeitCheck: refusing to rename '
					. self::OLD_LOGICAL . ' -> ' . self::NEW_LOGICAL
					. ' because the target table already exists and is not empty.'
				);
			}
		}

		if (!$this->db->tableExists(self::OLD_LOGICAL)) {
			return;
		}

		$prefix = $this->tablePrefix();
		$oldTable = $prefix . self::OLD_LOGICAL;
		$newTable = $prefix . self::NEW_LOGICAL;
		$this->assertSafeSqlIdentifier($oldTable);
		$this->assertSafeSqlIdentifier($newTable);

		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL) {
			$this->db->executeStatement(sprintf(
				'RENAME TABLE `%s` TO `%s`',
				$oldTable,
				$newTable
			));
		} else {
			$this->db->executeStatement(sprintf(
				'ALTER TABLE "%s" RENAME TO "%s"',
				$oldTable,
				$newTable
			));
		}
		$output->info('ArbeitszeitCheck: renamed table ' . self::OLD_LOGICAL . ' to ' . self::NEW_LOGICAL . '.');
	}

	private function dropEmptyTarget(IOutput $output): void
	{
		$prefix = $this->tablePrefix();
		$prefixed = $prefix . self::NEW_LOGICAL;
		$this->assertSafeSqlIdentifier($prefixed);
		if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL) {
			$this->db->executeStatement('DROP TABLE IF EXISTS `' . $prefixed . '`');
		} else {
			$this->db->executeStatement('DROP TABLE IF EXISTS "' . $prefixed . '"');
		}
		$output->info('ArbeitszeitCheck: dropped empty ' . self::NEW_LOGICAL . ' before rename from legacy name.');
	}

	private function renamePostgresSequenceIfNeeded(IOutput $output): void
	{
		$prefix = $this->tablePrefix();
		$oldSeq = $prefix . self::OLD_LOGICAL . '_id_seq';
		$newSeq = $prefix . self::NEW_LOGICAL . '_id_seq';
		$this->assertSafeSqlIdentifier($oldSeq);
		$this->assertSafeSqlIdentifier($newSeq);

		if (!$this->postgresSequenceExists($oldSeq)) {
			return;
		}
		if ($this->postgresSequenceExists($newSeq)) {
			return;
		}

		try {
			$this->db->executeStatement(sprintf(
				'ALTER SEQUENCE "%s" RENAME TO "%s"',
				$oldSeq,
				$newSeq
			));
			$output->info('ArbeitszeitCheck (PostgreSQL): renamed sequence ' . $oldSeq . ' to ' . $newSeq . '.');
		} catch (\Throwable $e) {
			// best-effort: installs predating the sequence skip this rename; the
			// warning is surfaced in occ output instead of aborting the upgrade.
			$output->warning('ArbeitszeitCheck (PostgreSQL): could not rename sequence: ' . $e->getMessage());
		}
	}

	private function postgresSequenceExists(string $sequenceName): bool
	{
		try {
			$rs = $this->db->executeQuery(
				'SELECT 1 FROM pg_class WHERE relkind = \'S\' AND relname = ?',
				[$sequenceName]
			);
			$found = $rs->fetchOne();
			$rs->closeCursor();
			return $found !== false;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function isTableEmpty(string $logicalTable): bool
	{
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->count('*', 'c'))
				->from($logicalTable);
			$rs = $qb->executeQuery();
			$row = $rs->fetch();
			$rs->closeCursor();
			return ((int)($row['c'] ?? 0)) === 0;
		} catch (\Throwable $e) {
			return false;
		}
	}

	private function assertSafeSqlIdentifier(string $name): void
	{
		if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
			throw new \InvalidArgumentException('Invalid SQL identifier: ' . $name);
		}
	}
}
