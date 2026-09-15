<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Migration;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Repair\UninstallDropTables;

/**
 * Active schema tables required for a healthy install (excludes legacy tables dropped by later migrations).
 *
 * {@see UninstallDropTables::TABLES} still lists every table ever created for complete uninstall cleanup.
 */
final class ArbeitszeitCheckTableCatalog
{
	public const APP_ID = Application::APP_ID;

	/** Tables removed intentionally by {@see Version1012Date20260406120000} — must not block upgrades. */
	private const LEGACY_DROPPED_TABLES = [
		'at_absence_calendar',
	];

	/** @var list<string> */
	public const TABLES = [
		'azc_license_state',
		'azc_mobile_seat',
		'azc_terminal_device',
		'azc_outlook_ical_tokens',
		'at_kiosk_creds',
		'at_kiosk_enrollment',
		'at_kiosk_sessions',
		'at_kiosk_terminals',
		'at_absences',
		'at_audit',
		'at_entitlement_snapshots',
		'at_entries',
		'at_holiday_suppress',
		'at_holidays',
		'at_model_vacation_defaults',
		'at_models',
		'at_month_closure',
		'at_month_closure_revision',
		'at_org_vacation_defaults',
		'at_ot_payout',
		'at_ot_adj',
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
	];

	public static function isLegacyDroppedTable(string $table): bool
	{
		return in_array($table, self::LEGACY_DROPPED_TABLES, true);
	}
}
