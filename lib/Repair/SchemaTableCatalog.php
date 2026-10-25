<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud DB-Standards (auto-generated)
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Tables that must exist after arbeitszeitcheck's current migrations ran —
 * every table ever created minus those intentionally dropped by later
 * migrations. Schema-repair (Ensure*Schema) and schema-readiness probes
 * use EXPECTED_TABLES; uninstall drop lists use UninstallDropTables::TABLES
 * (which deliberately keeps dropped legacy tables for complete cleanup).
 *
 * Regenerate via:
 *     php scripts/check-nextcloud-db-standards.php sync-uninstall --app=arbeitszeitcheck
 */
namespace OCA\ArbeitszeitCheck\Repair;

final class SchemaTableCatalog
{
	/** @var list<string> */
	public const EXPECTED_TABLES = [
		'at_absences',
		'at_audit',
		'at_btrip',
		'at_btrip_daycalc',
		'at_btrip_ledger',
		'at_btrip_link',
		'at_btrip_profile',
		'at_btrip_rule_asn',
		'at_btrip_ruleset',
		'at_btrip_seg',
		'at_btrip_snapshot',
		'at_custom_absence',
		'at_entitlement_snapshots',
		'at_entries',
		'at_ext_stamp',
		'at_holiday_suppress',
		'at_holidays',
		'at_kiosk_creds',
		'at_kiosk_enrollment',
		'at_kiosk_sessions',
		'at_kiosk_stamp_idem',
		'at_kiosk_terminals',
		'at_mob_stamp_idem',
		'at_model_vacation_defaults',
		'at_models',
		'at_month_closure',
		'at_month_closure_revision',
		'at_org_vacation_defaults',
		'at_ot_adj',
		'at_ot_payout',
		'at_policy_cohort',
		'at_policy_cohort_member',
		'at_se_ledger',
		'at_se_period',
		'at_se_policy',
		'at_se_reserv',
		'at_se_type',
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
		'at_work_location_day',
		'at_work_location_def',
		'at_work_location_policy',
		'azc_license_state',
		'azc_mobile_seat',
		'azc_outlook_ical_tokens',
		'azc_terminal_device',
	];

	/** @var list<string> tables dropped intentionally by later migrations */
	public const DROPPED_LEGACY_TABLES = [
		'at_absence_calendar',
	];
}
