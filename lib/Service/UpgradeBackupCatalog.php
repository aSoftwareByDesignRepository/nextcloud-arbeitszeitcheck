<?php

declare(strict_types=1);

/**
 * Tables and app-data paths included in pre-update upgrade backups.
 *
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Regenerate via:
 *     php scripts/sync-upgrade-backup.php --app=arbeitszeitcheck
 */
namespace OCA\ArbeitszeitCheck\Service;

final class UpgradeBackupCatalog
{
	public const APP_ID = 'arbeitszeitcheck';

	public const FORMAT_VERSION = 1;

	public const APPDATA_ROOT = 'upgrade-backups';

	/** @var list<string> App-data folder names (under appdata_<instance>/arbeitszeitcheck/) to include in snapshots. */
	public const APPDATA_FOLDERS = [

	];

	public const CONFIG_MAX_SNAPSHOTS = 'upgrade_backup_max_snapshots';

	public const CONFIG_LAST_SNAPSHOT_ID = 'upgrade_backup_last_snapshot_id';

	public const DEFAULT_MAX_SNAPSHOTS = 5;

	public const MAX_SNAPSHOTS_LIMIT = 20;

	/** @var list<string> */
	public const BACKUP_TABLES = [
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
		'at_kiosk_stamp_idem',
		'at_kiosk_terminals',
		'at_model_vacation_defaults',
		'at_models',
		'at_mob_stamp_idem',
		'at_month_closure',
		'at_month_closure_revision',
		'at_org_vacation_defaults',
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

	/** @var list<string> */
	public const RESTORE_TABLE_ORDER = [
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
		'at_kiosk_stamp_idem',
		'at_kiosk_terminals',
		'at_model_vacation_defaults',
		'at_models',
		'at_mob_stamp_idem',
		'at_month_closure',
		'at_month_closure_revision',
		'at_org_vacation_defaults',
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

	public static function isBackupTable(string $table): bool
	{
		return in_array($table, self::BACKUP_TABLES, true);
	}

	public static function clampMaxSnapshots(int $requested): int
	{
		return max(1, min(self::MAX_SNAPSHOTS_LIMIT, $requested));
	}

	/**
	 * @return list<string>
	 */
	public static function existingBackupTables(callable $tableExists): array
	{
		$existing = [];
		foreach (self::BACKUP_TABLES as $table) {
			if ($tableExists($table)) {
				$existing[] = $table;
			}
		}

		return $existing;
	}

	/**
	 * @return list<string>
	 */
	public static function sortedRestoreTables(array $presentTables): array
	{
		$present = array_fill_keys($presentTables, true);
		$ordered = [];
		foreach (self::RESTORE_TABLE_ORDER as $table) {
			if (isset($present[$table])) {
				$ordered[] = $table;
			}
		}

		foreach ($presentTables as $table) {
			if (!in_array($table, $ordered, true)) {
				$ordered[] = $table;
			}
		}

		return $ordered;
	}
}
