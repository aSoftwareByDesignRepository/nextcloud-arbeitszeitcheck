<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Drops every table the arbeitszeitcheck app has ever created, migration rows, and app config.
 *
 * Nextcloud runs this step on disable ({@see \OC\App\AppManager::disableApp}) and again on
 * remove ({@see \OC\Installer::removeApp}, {@see \OCA\Settings\Controller\AppSettingsController::uninstallApp}).
 * Disable (including auto-disable during a server upgrade) always preserves data; only an
 * explicit app removal drops tables.
 *
 * Regenerate table list via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=arbeitszeitcheck
 *
 * Uses `DROP TABLE IF EXISTS` (not SchemaWrapper) so IDBConnection injection works on
 * all Nextcloud versions. MySQL temporarily disables FK checks so legacy FK chains
 * (e.g. project_files → projects) cannot block uninstall.
 */
namespace OCA\ArbeitszeitCheck\Repair;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

final class UninstallDropTables implements IRepairStep
{
	public const APP_ID = 'arbeitszeitcheck';

	/**
	 * Legacy counter from the two-pass implementation. Cleared on disable and by schema
	 * repair steps so upgrades never inherit a stale value.
	 */
	public const REPAIR_PASS_KEY = 'uninstall_repair_pass';

	/**
	 * Sorted list of every table this app has ever created across all migrations.
	 * Kept in sync by the DB-standards linter.
	 */
	public const TABLES = [
		'at_absence_calendar',
		'at_absences',
		'at_audit',
		'at_entitlement_snapshots',
		'at_entries',
		'at_holiday_suppress',
		'at_holidays',
		'at_kiosk_creds',
		'at_kiosk_enrollment',
		'at_kiosk_sessions',
		'at_kiosk_terminals',
		'at_model_vacation_defaults',
		'at_models',
		'at_month_closure',
		'at_month_closure_revision',
		'at_org_vacation_defaults',
		'at_mob_stamp_idem',
		'at_kiosk_stamp_idem',
		'at_ot_adj',
		'at_ot_payout',
		'at_settings',
		'at_tariff_rule_modules',
		'at_tariff_rule_sets',
		'at_team_managers',
		'at_team_members',
		'at_team_vacation_policies',
		'at_teams',
		'at_user_models',
		'at_user_ot_year_bal',
		'at_user_vacation_policies',
		'at_vacation_rollover_log',
		'at_vacation_year_balance',
		'at_violations',
		'azc_license_state',
		'azc_mobile_seat',
		'azc_outlook_ical_tokens',
		'azc_terminal_device',
	];

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
		private readonly IRootFolder $rootFolder,
	) {
	}

	public function getName(): string
	{
		return 'Drop arbeitszeitcheck tables and install metadata on uninstall';
	}

	public function run(IOutput $output): void
	{
		if (UninstallRepairFlow::isRemovalContext()) {
			$this->dropAllTablesAndMetadata($output);
			return;
		}

		// Disable path (manual or auto during server upgrade): idempotent — never drop.
		$this->config->deleteAppValue(self::APP_ID, self::REPAIR_PASS_KEY);
		$output->info(
			'arbeitszeitcheck: preserving data on disable. '
			. 'Tables, migration history, and settings are kept until the app is fully removed.'
		);
	}

	private function dropAllTablesAndMetadata(IOutput $output): void
	{
		$provider = $this->connection->getDatabaseProvider();
		$fkChecksDisabled = false;
		if ($provider === IDBConnection::PLATFORM_MYSQL) {
			$this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=0');
			$fkChecksDisabled = true;
		}

		$dropped = 0;
		foreach (self::TABLES as $table) {
			if ($this->dropLogicalTableIfExists($table)) {
				$dropped++;
			}
		}

		if ($fkChecksDisabled) {
			$this->connection->executeStatement('SET FOREIGN_KEY_CHECKS=1');
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->delete('migrations')
			->where($qb->expr()->eq('app', $qb->createNamedParameter(self::APP_ID)));
		$migrationsRemoved = $qb->executeStatement();

		$this->config->deleteAppValues(self::APP_ID);

		$this->purgeUpgradeBackupSnapshots($output);

		$output->info(sprintf(
			'arbeitszeitcheck: dropped %d of %d table(s); removed %d migration row(s), app config, and upgrade-backup snapshots.',
			$dropped,
			count(self::TABLES),
			$migrationsRemoved,
		));
	}

	private function dropLogicalTableIfExists(string $logicalTable): bool
	{
		if (!$this->connection->tableExists($logicalTable)) {
			return false;
		}

		$prefix = (string)$this->config->getSystemValue('dbtableprefix', 'oc_');
		$physical = $prefix . $logicalTable;
		$provider = $this->connection->getDatabaseProvider();

		if ($provider === IDBConnection::PLATFORM_MYSQL) {
			$this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS `%s`', $physical));
		} elseif ($provider === IDBConnection::PLATFORM_POSTGRES) {
			$this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS "%s" CASCADE', $physical));
		} elseif ($provider === IDBConnection::PLATFORM_ORACLE) {
			$this->connection->executeStatement(sprintf('DROP TABLE %s CASCADE CONSTRAINTS', $physical));
		} elseif ($provider === IDBConnection::PLATFORM_SQLITE) {
			$this->connection->executeStatement(sprintf('DROP TABLE IF EXISTS "%s"', $physical));
		}

		return true;
	}

	/**
	 * Pre-update JSON snapshots contain full table exports — remove on explicit app removal.
	 */
	private function purgeUpgradeBackupSnapshots(IOutput $output): void
	{
		$instanceId = (string)$this->config->getSystemValue('instanceid', '');
		if ($instanceId === '') {
			return;
		}

		$path = 'appdata_' . $instanceId . '/' . self::APP_ID . '/upgrade-backups';
		try {
			$node = $this->rootFolder->get($path);
		} catch (NotFoundException) {
			return;
		}

		if (!$node instanceof Folder) {
			return;
		}

		$node->delete();
		$output->info('arbeitszeitcheck: removed upgrade-backup snapshots from app data.');
	}
}
