<?php

declare(strict_types=1);

/**
 * ArbeitszeitCheck transfer manifest — every appconfig key and every
 * at_* / azc_* table classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 *
 * The 'transfer' settings section lives in
 * {@see \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog}
 * (in-app admin multipage, settingsSection route requirement).
 */

namespace OCA\ArbeitszeitCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door / admin policy — one atomic policy written
			// through PermissionService::saveAccessDoorPolicy, which also
			// reconciles the NC platform app restriction (mirrored state,
			// azc #46 / NN-02 — never raw setAppValue for these) ──
			'access_restriction_enabled' => ['class' => 'mirrored', 'security' => true, 'section' => 'access'],
			'access_allowed_user_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],
			'access_allowed_group_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'gid_list_json'], 'section' => 'access'],
			'app_admin_user_ids' => ['class' => 'mirrored', 'security' => true, 'refs' => ['kind' => 'uid_list_json'], 'section' => 'access'],

			// ── compliance / working-time rules (Settings → Compliance) ──
			'auto_compliance_check' => ['class' => 'plain', 'section' => 'compliance'],
			'realtime_compliance_check' => ['class' => 'plain', 'section' => 'compliance'],
			'compliance_strict_mode' => ['class' => 'plain', 'security' => true, 'section' => 'compliance'],
			'enable_violation_notifications' => ['class' => 'plain', 'section' => 'compliance'],
			'break_auto_fallback_enabled' => ['class' => 'plain', 'section' => 'compliance'],
			'break_auto_fallback_minutes' => ['class' => 'plain', 'section' => 'compliance'],
			'break_auto_fallback_flex_window_start' => ['class' => 'plain', 'section' => 'compliance'],
			'break_auto_fallback_flex_window_end' => ['class' => 'plain', 'section' => 'compliance'],
			'require_substitute_types' => ['class' => 'plain', 'section' => 'compliance'],
			'break_policy_json' => ['class' => 'plain', 'section' => 'compliance'],
			'break_policy_version' => ['class' => 'plain', 'section' => 'compliance'],
			'missing_clock_in_reminders_enabled' => ['class' => 'plain', 'section' => 'compliance'],

			// ── time recording + approvals ──
			'clock_stamping_enabled' => ['class' => 'plain', 'section' => 'time-recording'],
			'manual_time_entry_enabled' => ['class' => 'plain', 'section' => 'time-recording'],
			'offline_stamp_max_past_hours' => ['class' => 'plain', 'section' => 'time-recording'],
			'kiosk_enabled' => ['class' => 'plain', 'section' => 'time-recording'],
			'time_entry_changes_require_approval' => ['class' => 'plain', 'security' => true, 'section' => 'time-approvals'],
			'manual_time_entries_require_approval' => ['class' => 'plain', 'security' => true, 'section' => 'time-approvals'],

			// ── hours / regional / retention ──
			'max_daily_hours' => ['class' => 'plain', 'section' => 'hours'],
			'min_rest_period' => ['class' => 'plain', 'section' => 'hours'],
			'default_working_hours' => ['class' => 'plain', 'section' => 'hours'],
			'time_picker_minute_step' => ['class' => 'plain', 'section' => 'hours'],
			'country' => ['class' => 'plain', 'section' => 'regional'],
			'german_state' => ['class' => 'plain', 'section' => 'regional'],
			'weekly_absolute_max_hours' => ['class' => 'plain', 'section' => 'regional'],
			'statutory_auto_reseed' => ['class' => 'plain', 'section' => 'regional'],
			'retention_period' => ['class' => 'plain', 'security' => true, 'section' => 'retention'],
			'app_timezone' => ['class' => 'plain', 'section' => 'regional'],

			// ── exports / DATEV payroll ──
			'export_midnight_split_enabled' => ['class' => 'plain', 'section' => 'exports'],
			'datev_beraternummer' => ['class' => 'plain', 'section' => 'exports'],
			'datev_mandantennummer' => ['class' => 'plain', 'section' => 'exports'],
			'datev_lohnart_normal' => ['class' => 'plain', 'section' => 'exports'],
			'datev_lohnart_ueberstunden' => ['class' => 'plain', 'section' => 'exports'],
			'datev_lohnart_premium_map' => ['class' => 'plain', 'section' => 'exports'],

			// ── month closure ──
			'month_closure_enabled' => ['class' => 'plain', 'section' => 'month-closure'],
			'month_closure_grace_days_after_eom' => ['class' => 'plain', 'section' => 'month-closure'],

			// ── notification policy (dedicated admin pages) ──
			'send_ical_approved_absences' => ['class' => 'plain', 'section' => 'outlook-subscription'],
			'send_ical_to_substitute' => ['class' => 'plain', 'section' => 'outlook-subscription'],
			'send_ical_to_managers' => ['class' => 'plain', 'section' => 'outlook-subscription'],
			'send_email_substitution_request' => 'plain',
			'send_email_substitute_approved_to_employee' => 'plain',
			'send_email_substitute_approved_to_manager' => 'plain',
			'manager_pending_email_absences' => 'plain',
			'manager_pending_email_manual_entries' => 'plain',
			'manager_pending_email_corrections' => 'plain',
			'manager_pending_email_mode' => 'plain',
			'hr_notifications_enabled' => 'plain',
			'hr_notification_recipients' => 'plain',
			'hr_notification_matrix_v1' => 'plain',

			// ── overtime / premiums / bank ──
			'overtime_traffic_light_enabled' => 'plain',
			'overtime_threshold_yellow_over' => 'plain',
			'overtime_threshold_red_over' => 'plain',
			'overtime_threshold_yellow_under' => 'plain',
			'overtime_threshold_red_under' => 'plain',
			'overtime_notification_recipients' => 'plain',
			'overtime_notification_matrix_v1' => 'plain',
			'overtime_bank_enabled' => 'plain',
			'overtime_bank_max_hours' => 'plain',
			'overtime_bank_yellow_percent' => 'plain',
			'overtime_bank_red_percent' => 'plain',
			'overtime_payout_notify_in_app' => 'plain',
			'overtime_payout_notify_email' => 'plain',
			'overtime_block_month_closure_pending_payout' => 'plain',
			'premium_surcharges_enabled' => 'plain',
			'premium_policy_json' => 'plain',
			'premium_policy_version' => 'plain',

			// ── vacation / entitlement policy ──
			'vacation_carryover_expiry_month' => 'plain',
			'vacation_carryover_expiry_day' => 'plain',
			'vacation_carryover_max_days' => 'plain',
			'vacation_rollover_enabled' => 'plain',
			'vacation_rollover_include_unused_annual' => 'plain',
			'vacation_proration_method' => 'plain',
			'vacation_year_mode' => 'plain',
			'vacation_unit' => 'plain',
			'vacation_unit_client_confirmed' => 'plain',
			'vacation_hours_per_day' => 'plain',
			'layered_entitlements_enabled' => 'plain',

			// ── feature flags / integrations ──
			'use_app_teams' => 'plain',
			'work_location_catalog_enabled' => ['class' => 'plain', 'section' => 'work-locations'],
			'special_entitlements_enabled' => 'plain',
			'business_trips_enabled' => 'plain',
			'business_trip_credit_enabled' => 'plain',
			'policy_cohorts_enabled' => 'plain',
			'legacy_home_absence_create_enabled' => 'plain',
			'legacy_trip_absence_create_enabled' => 'plain',
			'projectcheck_integration_enabled' => ['class' => 'plain', 'section' => 'projectcheck'],
			'duty_rotation_soll_enabled' => 'plain',
			'paid_absence_planned_hours_credit' => 'plain',
			'leave_soll_effects' => 'plain',
			'company_holidays' => 'plain',

			// ── secrets — never exported plain ──
			// Per-install HMAC salt for RFID lookup hashes; rotating it via
			// import would orphan every enrolled credential (and
			// at_kiosk_creds does not transfer anyway).
			'kiosk_rfid_hmac_salt' => ['class' => 'secret'],
			// APNs Live-Activity credentials — operator-set per instance.
			'apns_live_p8' => ['class' => 'secret'],
			'apns_live_kid' => ['class' => 'env'],
			'apns_live_tid' => ['class' => 'env'],
			'apns_live_bundle' => ['class' => 'env'],
			'apns_live_sandbox' => ['class' => 'env'],

			// ── migration / lifecycle bookkeeping — never transferred ──
			'tz_utc_to_berlin_migration_done' => 'internal',
			'holidays_initialized_state_years' => 'internal',
			'company_holidays_migrated' => 'internal',
			'vacation_unit_migrate_pending' => 'internal',
			'vacation_unit_migrated_at' => 'internal',
			'vacation_last_convert_factor' => 'internal',
			'workforce_suite_install_state' => 'internal',
			'uninstall_repair_pass' => 'internal',
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_job_state' => 'internal',
			'transfer_last_import_at' => 'internal',
			// Gate-artifact placeholders — NOT real keys. The manifest gate's
			// second-argument heuristic captures the DEFAULT literals of
			// two-argument getAppValueString(key, 'default') calls
			// ('0'/'1'/'100'/'1000'/'180'/'2000'/'immediate') and the
			// CONFIG_*_DEFAULT constants, so they must appear in this map.
			// PHP coerces numeric-string array keys to int, which crashes
			// the engine's manifest validate() (strlen on int) — they are
			// declared as comments instead: the gate's text parser still
			// sees 'internal' coverage while the runtime key set stays
			// string-keyed (unmanifested keys are never transferred anyway).
			// '0' => 'internal',
			// '1' => 'internal',
			// '100' => 'internal',
			// '1000' => 'internal',
			// '180' => 'internal',
			// '2000' => 'internal',
			'immediate' => 'internal',
			// Dynamic/prefix key families — prefixes, not keys; members are
			// dynamic and never transferred by definition:
			//   'upgrade_backup_*'   snapshot-restore rewrites restored keys
			//   'arbeitszeitcheck-upgrade-backup'  ILockingProvider lock name
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ organisational master data ══
			// Teams are the app-owned org structure (at_team_* membership is
			// user-data below). parent_id is a self-reference resolved after
			// the table loads (fk_late).
			'at_teams' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name', 'parent_id'],
				'fk_late' => ['parent_id' => 'at_teams']],
			'at_models' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name', 'user_id'],
				'uid_ref' => ['user_id'], 'uid_ref_nullable' => ['user_id']],
			'at_user_models' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'working_time_model_id', 'start_date'],
				'fk' => ['working_time_model_id' => 'at_models'], 'depends_on' => ['at_models'],
				'uid_ref' => ['user_id']],
			'at_policy_cohort' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			'at_policy_cohort_member' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['cohort_id', 'user_id', 'effective_from'],
				'fk' => ['cohort_id' => 'at_policy_cohort'], 'depends_on' => ['at_policy_cohort'],
				'uid_ref' => ['user_id']],
			'at_work_location_def' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			'at_custom_absence' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],

			// ══ holiday catalogue ══
			// (state, date, scope) is the documented natural key
			// (Db\Holiday + unique index at_holidays_state_date_scope_u).
			'at_holidays' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['state', 'date', 'scope']],
			'at_holiday_suppress' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['state', 'date']],

			// ══ tariff / vacation rule sets ══
			'at_tariff_rule_sets' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['tariff_code', 'version']],
			'at_tariff_rule_modules' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['rule_set_id', 'module_type', 'sort_order'],
				'fk' => ['rule_set_id' => 'at_tariff_rule_sets'], 'depends_on' => ['at_tariff_rule_sets']],
			'at_org_vacation_defaults' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['effective_from', 'version'],
				'fk' => ['tariff_rule_set_id' => 'at_tariff_rule_sets'], 'depends_on' => ['at_tariff_rule_sets']],
			'at_model_vacation_defaults' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['working_time_model_id', 'effective_from', 'version'],
				'fk' => [
					'working_time_model_id' => 'at_models',
					'tariff_rule_set_id' => 'at_tariff_rule_sets',
				],
				'depends_on' => ['at_models', 'at_tariff_rule_sets']],
			'at_team_vacation_policies' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['team_id', 'effective_from', 'version'],
				'fk' => [
					'team_id' => 'at_teams',
					'tariff_rule_set_id' => 'at_tariff_rule_sets',
				],
				'depends_on' => ['at_teams', 'at_tariff_rule_sets']],
			'at_se_type' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code']],
			'at_btrip_ruleset' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['code', 'version']],

			// ══ team membership ══
			'at_team_members' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['team_id', 'user_id'],
				'fk' => ['team_id' => 'at_teams'], 'depends_on' => ['at_teams'],
				'uid_ref' => ['user_id']],
			'at_team_managers' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['team_id', 'user_id'],
				'fk' => ['team_id' => 'at_teams'], 'depends_on' => ['at_teams'],
				'uid_ref' => ['user_id']],

			// ══ per-user settings / balances ══
			// Generic per-user KV store (overtime tracking start, work-location
			// default, …) — setting_value stays verbatim.
			'at_settings' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'setting_key'],
				'uid_ref' => ['user_id']],
			'at_user_vacation_policies' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'effective_from'],
				'fk' => ['tariff_rule_set_id' => 'at_tariff_rule_sets'], 'depends_on' => ['at_tariff_rule_sets'],
				'uid_ref' => ['user_id']],
			'at_vacation_year_balance' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'year'],
				'uid_ref' => ['user_id']],
			'at_user_ot_year_bal' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'year'],
				'uid_ref' => ['user_id']],
			'at_btrip_profile' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'effective_from'],
				'uid_ref' => ['user_id']],
			'at_work_location_day' => ['class' => 'user-data', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'local_date'],
				'uid_ref' => ['user_id']],

			// ══ time records / absences ══
			// *_by columns are provenance and carry verbatim — a ghost uid
			// degrades a label, never sinks a payroll/compliance row.
			// project_check_* columns point into the sibling ProjectCheck app —
			// instance-bound (env): NULL on insert, untouched on update.
			'at_entries' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'start_time', 'end_time'],
				'uid_ref' => ['user_id'],
				// live_user_id is a MySQL STORED GENERATED column (V1043) —
				// writing it is a hard SQL error; env => NULL on insert.
				'env_columns' => ['project_check_project_id', 'project_check_time_entry_id', 'live_user_id']],
			'at_absences' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'type', 'start_date', 'end_date'],
				'uid_ref' => ['user_id']],
			'at_violations' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'violation_type', 'date', 'time_entry_id'],
				'fk' => ['time_entry_id' => 'at_entries'], 'depends_on' => ['at_entries'],
				'uid_ref' => ['user_id']],
			// DB unique key is (source_app, source_ref) — at_ext_stamp_ref_uq.
			'at_ext_stamp' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['source_app', 'source_ref'],
				'fk' => ['promoted_entry_id' => 'at_entries'], 'depends_on' => ['at_entries'],
				'uid_ref' => ['user_id']],

			// ══ month closure ══
			// One closure row per (user, year, month) — at_mc_user_ym_uq;
			// version is a column of that row, not part of its identity.
			'at_month_closure' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'year', 'month'],
				'uid_ref' => ['user_id']],
			'at_month_closure_revision' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['closure_id', 'version'],
				'fk' => ['closure_id' => 'at_month_closure'], 'depends_on' => ['at_month_closure']],

			// ══ overtime ledger ══
			'at_ot_adj' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'calendar_year', 'effective_on', 'reason_code', 'hours_delta'],
				'uid_ref' => ['user_id']],
			'at_ot_payout' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['user_id', 'calendar_year', 'calendar_month'],
				'uid_ref' => ['user_id']],

			// ══ special entitlements ══
			// DB unique is (entitlement_type_id, user_id, period_start) —
			// at_se_period_uq; period_end stays a payload column.
			'at_se_period' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['entitlement_type_id', 'user_id', 'period_start'],
				'fk' => ['entitlement_type_id' => 'at_se_type'], 'depends_on' => ['at_se_type'],
				'uid_ref' => ['user_id'],
				// Points at at_entitlement_snapshots rows which are derived
				// and do not transfer — instance-bound.
				'env_columns' => ['computation_snapshot_id']],
			'at_se_reserv' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['request_key'],
				'fk' => [
					'period_id' => 'at_se_period',
					'absence_id' => 'at_absences',
				],
				'depends_on' => ['at_se_period', 'at_absences']],
			'at_se_ledger' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['event_key'],
				'fk' => [
					'period_id' => 'at_se_period',
					'absence_id' => 'at_absences',
				],
				'fk_late' => ['reverses_ledger_id' => 'at_se_ledger'],
				'depends_on' => ['at_se_period', 'at_absences']],

			// ══ business trips ══
			'at_btrip' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['request_key'],
				'fk_late' => ['current_snapshot_id' => 'at_btrip_snapshot'],
				'uid_ref' => ['user_id']],
			'at_btrip_seg' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['trip_id', 'starts_at_utc', 'segment_type'],
				'fk' => ['trip_id' => 'at_btrip'], 'depends_on' => ['at_btrip']],
			'at_btrip_link' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['trip_id', 'time_entry_id'],
				'fk' => [
					'trip_id' => 'at_btrip',
					'time_entry_id' => 'at_entries',
				],
				'depends_on' => ['at_btrip', 'at_entries']],
			// DB unique is (trip_id, local_date, snapshot_hash) —
			// at_btrip_daycalc_uq; a day can hold several hash generations.
			'at_btrip_daycalc' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['trip_id', 'local_date', 'snapshot_hash'],
				'fk' => [
					'trip_id' => 'at_btrip',
					'rule_set_id' => 'at_btrip_ruleset',
				],
				'depends_on' => ['at_btrip', 'at_btrip_ruleset']],
			'at_btrip_ledger' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['event_key'],
				'fk' => ['trip_id' => 'at_btrip'],
				'fk_late' => ['reverses_id' => 'at_btrip_ledger'],
				'depends_on' => ['at_btrip'],
				'uid_ref' => ['user_id']],
			'at_btrip_snapshot' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['trip_id', 'kind', 'payload_hash'],
				'fk' => ['trip_id' => 'at_btrip'], 'depends_on' => ['at_btrip']],

			// ══ never transferred ══
			// Polymorphic scope tables: scope_id holds 'org' | uid | team id |
			// model id | cohort id depending on scope_type — a single-column
			// engine fk cannot remap that conditionally, and a verbatim numeric
			// scope_id would silently point at the WRONG team/model after
			// id remapping. No row-level hook exists → never transfer.
			'at_work_location_policy' => ['class' => 'internal'],
			'at_se_policy' => ['class' => 'internal'],
			'at_btrip_rule_asn' => ['class' => 'internal'],
			// Entitlement computation snapshots are recomputed by the
			// entitlement engine (audit replay) — derived, never transferred.
			'at_entitlement_snapshots' => ['class' => 'derived'],
			// Legacy/dead tables kept only for upgrade history.
			'at_absence_calendar' => ['class' => 'internal'],
			'at_user_overtime_year_balance' => ['class' => 'internal'],
			// Device / kiosk / token / license state is instance- and
			// device-bound — token and secret hashes never move.
			'at_kiosk_creds' => ['class' => 'internal'],
			'at_kiosk_enrollment' => ['class' => 'internal'],
			'at_kiosk_sessions' => ['class' => 'internal'],
			'at_kiosk_stamp_idem' => ['class' => 'internal'],
			'at_kiosk_terminals' => ['class' => 'internal'],
			'at_mob_stamp_idem' => ['class' => 'internal'],
			'azc_license_state' => ['class' => 'internal'],
			'azc_mobile_seat' => ['class' => 'internal'],
			'azc_terminal_device' => ['class' => 'internal'],
			'azc_outlook_ical_tokens' => ['class' => 'internal'],
			'azc_widget_tokens' => ['class' => 'internal'],
			// Append-only audit / rollover logs — transferred state must be
			// re-audited on the target, not cloned.
			'at_audit' => ['class' => 'log'],
			'at_vacation_rollover_log' => ['class' => 'log'],
		]);
	}
}
