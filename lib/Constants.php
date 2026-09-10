<?php

declare(strict_types=1);

/**
 * Application constants for arbeitszeitcheck
 *
 * Named constants for business rules, limits, and magic numbers.
 * Use these instead of hardcoded values for maintainability and clarity.
 *
 * @copyright Copyright (c) 2024-2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck;

final class Constants
{
	/**
	 * Number of days within which time entries can be edited (compliance / data integrity).
	 */
	public const EDIT_WINDOW_DAYS = 14;

	/**
	 * Default minute step for time pickers (manual entry + correction).
	 * Odd minutes remain enterable via free typing / preserved on edit.
	 */
	public const TIME_PICKER_MINUTE_STEP = 5;

	/**
	 * Default number of items per page for list endpoints (time entries, absences, violations, etc.).
	 */
	public const DEFAULT_LIST_LIMIT = 25;

	/**
	 * Maximum number of items per request (DoS protection).
	 */
	public const MAX_LIST_LIMIT = 500;

	/**
	 * Maximum NC accounts scanned when resolving admin employee list access filters.
	 */
	public const ADMIN_EMPLOYEE_FILTER_MAX_SCAN = 10000;

	/**
	 * Minimum characters required for admin user-picker search (GET ?picker=1).
	 * Prevents unbounded directory dumps on large instances.
	 */
	public const PICKER_MIN_SEARCH_LENGTH = 2;

	/**
	 * Maximum results returned per admin user-picker request.
	 */
	public const PICKER_MAX_RESULTS = 25;

	/**
	 * Default vacation days per year when no user setting exists (German standard).
	 */
	public const DEFAULT_VACATION_DAYS_PER_YEAR = 25;

	public const VACATION_MODE_MANUAL_FIXED = 'manual_fixed';
	public const VACATION_MODE_MODEL_BASED_SIMPLE = 'model_based_simple';
	public const VACATION_MODE_TARIFF_RULE_BASED = 'tariff_rule_based';
	public const VACATION_MODE_MANUAL_EXCEPTION = 'manual_exception';

	/**
	 * Sentinel L3 mode: "the user has an L3 row but it explicitly defers to
	 * the layered resolution chain (L2 → L1 → L0)". Used by the layered
	 * vacation entitlement engine. Stored verbatim in
	 * `at_user_vacation_policies.vacation_mode` when `inherit_lower_layers = 1`
	 * is impractical (e.g. when a tenant wants the inherit decision visible in
	 * the column instead of a separate flag) and accepted on read in both
	 * representations.
	 */
	public const VACATION_MODE_INHERIT = 'inherit';

	/**
	 * Layered vacation entitlement: feature flag (app config). When "1"
	 * (default), {@see \OCA\ArbeitszeitCheck\Service\VacationEntitlementEngine}
	 * consults L0/L1/L2 defaults before falling back to legacy resolution.
	 * Setting to "0" forces the legacy code path (per-user assignments only),
	 * which is the documented escape hatch for tenants who want to disable
	 * the feature post-rollout. See
	 * `pm/app-ideas/arbeitszeitcheck/vacation-entitlement-hierarchy.md`
	 * §Migration §REQ-DAT-07.
	 */
	public const CONFIG_LAYERED_ENTITLEMENTS_ENABLED = 'layered_entitlements_enabled';

	/**
	 * Algorithm version emitted in {@see VacationEntitlementEngine} traces.
	 * Increment when changing arithmetic or precedence so payroll auditors
	 * can replay a snapshot deterministically against the algorithm of the
	 * day. NEVER reuse a version number for a different algorithm.
	 */
	public const ENTITLEMENT_ALGORITHM_VERSION = 1;

	/**
	 * Audit-log entity types used by the layered entitlement admin flows.
	 */
	public const AUDIT_ENTITY_ORG_VACATION_DEFAULT = 'org_vacation_default';
	public const AUDIT_ENTITY_MODEL_VACATION_DEFAULT = 'model_vacation_default';
	public const AUDIT_ENTITY_TEAM_VACATION_POLICY = 'team_vacation_policy';

	public const TARIFF_RULE_SET_STATUS_DRAFT = 'draft';
	public const TARIFF_RULE_SET_STATUS_ACTIVE = 'active';
	public const TARIFF_RULE_SET_STATUS_RETIRED = 'retired';

	/** App config: month (1–12) when carryover from the previous year expires (default March). */
	public const CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH = 'vacation_carryover_expiry_month';

	/** App config: day of month for carryover expiry (default 31). */
	public const CONFIG_VACATION_CARRYOVER_EXPIRY_DAY = 'vacation_carryover_expiry_day';

	/**
	 * Optional max opening carryover days (empty = no cap). Tarifvertrag-specific; not legal advice.
	 */
	public const CONFIG_VACATION_CARRYOVER_MAX_DAYS = 'vacation_carryover_max_days';

	/** When "1", background job may write next year opening from unused carryover remainder (see docs). */
	public const CONFIG_VACATION_ROLLOVER_ENABLED = 'vacation_rollover_enabled';

	/**
	 * When "1" and rollover enabled, also roll unused annual entitlement (off by default; Tarifvertrag-specific).
	 */
	public const CONFIG_VACATION_ROLLOVER_INCLUDE_UNUSED_ANNUAL = 'vacation_rollover_include_unused_annual';

	/**
	 * Maximum duration in days for absence requests (validation).
	 */
	public const MAX_ABSENCE_DAYS = 365;

	/**
	 * Sick leave: maximum days in the past for start date (German law allows up to 3 days backdating; 7 is a safe buffer).
	 */
	public const SICK_LEAVE_MAX_PAST_DAYS = 7;

	/**
	 * Maximum date range in days for exports (audit, users, etc.).
	 */
	public const MAX_EXPORT_DATE_RANGE_DAYS = 365;

	/** Rolling calendar subscription: months of history included on each refresh. */
	public const SUBSCRIPTION_ROLLING_PAST_MONTHS = 3;

	/** Rolling calendar subscription: months ahead included on each refresh. */
	public const SUBSCRIPTION_ROLLING_FUTURE_MONTHS = 12;

	/**
	 * Hard cap for subscription rolling-window span (3 past + 12 future months can exceed export limit).
	 */
	public const MAX_SUBSCRIPTION_DATE_RANGE_DAYS = 460;

	/**
	 * Virtual team id for organization-wide calendar subscription scope (all app-access employees).
	 * Does not reference {@see at_teams}; stored in token rows and API payloads as teamId=0.
	 */
	public const OUTLOOK_ICAL_ORG_WIDE_TEAM_ID = 0;

	/**
	 * Batch size for chunked DB operations (recursive team walks, Oracle-safe IN lists).
	 * Must stay ≤ {@see \OCA\ArbeitszeitCheck\Support\QueryInChunker::MAX_EXPRESSIONS_PER_LIST}.
	 */
	public const BATCH_CHUNK_SIZE = 500;

	/** App config: when "1", employees may finalize months (revision-safe snapshot + lock). Default off. */
	public const CONFIG_MONTH_CLOSURE_ENABLED = 'month_closure_enabled';

	/** Kiosk feature gate — default off until admin enables. */
	public const CONFIG_KIOSK_ENABLED = 'kiosk_enabled';

	/** Per-install HMAC salt for RFID lookup hashes (never store raw UIDs). */
	public const CONFIG_KIOSK_RFID_SALT = 'kiosk_rfid_hmac_salt';

	/**
	 * Pairing codes expire after 10 minutes (security: stolen/printed codes).
	 * Play Store reviewers: generate a fresh code when the review starts.
	 */
	public const KIOSK_PAIRING_TTL_SECONDS = 600;

	public const KIOSK_SESSION_TTL_SECONDS = 60;

	public const KIOSK_ENROLLMENT_TTL_SECONDS = 300;

	public const KIOSK_MAX_FAILED_ATTEMPTS = 5;

	public const KIOSK_LOCKOUT_SECONDS = 300;

	/** User preference: employee may use foyer kiosk. */
	public const USER_PREF_KIOSK_ALLOWED = 'kiosk_allowed';

	/**
	 * IANA timezone name for calendar-day boundaries (paused “today”, daily totals, exports).
	 * @see Version1015Date20260415120000
	 */
	public const CONFIG_APP_TIMEZONE = 'app_timezone';

	/**
	 * App config: JSON array of user IDs that are allowed to administer this app.
	 * Empty means all Nextcloud admins are app-admins (backward compatible default).
	 */
	public const CONFIG_APP_ADMIN_USER_IDS = 'app_admin_user_ids';

	/**
	 * Portfolio access door (ACCESS-AND-DIRECTORY-PICKERS): '0' = Open, '1' = Restricted.
	 * Empty / unset ⇒ derive from whether group allowlists are non-empty (legacy).
	 */
	public const CONFIG_ACCESS_RESTRICTION_ENABLED = 'access_restriction_enabled';

	/** JSON array of UIDs allow-listed when Restricted is on. */
	public const CONFIG_ACCESS_ALLOWED_USER_IDS = 'access_allowed_user_ids';

	/**
	 * JSON array of GIDs allow-listed when Restricted is on.
	 * Synced to Nextcloud app restriction when Restricted; cleared from NC when Open.
	 */
	public const CONFIG_ACCESS_ALLOWED_GROUP_IDS = 'access_allowed_group_ids';

	/**
	 * Days after the last day of a calendar month until automatic finalization runs (daily job).
	 * "0" = no automatic finalization (employees must finalize manually, or admin reopens).
	 */
	public const CONFIG_MONTH_CLOSURE_GRACE_DAYS_AFTER_EOM = 'month_closure_grace_days_after_eom';

	/** App config: when "1", HR absence email notifications are enabled globally. */
	public const CONFIG_HR_NOTIFICATIONS_ENABLED = 'hr_notifications_enabled';
	/** App config: comma-separated HR recipient email list (legacy readable). */
	public const CONFIG_HR_NOTIFICATION_RECIPIENTS = 'hr_notification_recipients';
	/** App config: versioned JSON matrix of absence_type => event => bool. */
	public const CONFIG_HR_NOTIFICATION_MATRIX_V1 = 'hr_notification_matrix_v1';

	/** @var list<string> */
	public const HR_NOTIFICATION_EVENTS = [
		'request_created',
		'substitute_approved',
		'substitute_declined',
		'manager_approved',
		'manager_rejected',
		'employee_cancelled',
		'employee_shortened',
	];

	/** @var list<string> */
	public const ABSENCE_TYPES = [
		'vacation',
		'sick_leave',
		'personal_leave',
		'parental_leave',
		'special_leave',
		'unpaid_leave',
		'home_office',
		'business_trip',
	];

	/** App config: overtime/undertime traffic light enabled globally. */
	public const CONFIG_OVERTIME_TRAFFIC_LIGHT_ENABLED = 'overtime_traffic_light_enabled';
	/** App config: yellow overtime threshold in hours (positive). */
	public const CONFIG_OVERTIME_THRESHOLD_YELLOW_OVER = 'overtime_threshold_yellow_over';
	/** App config: red overtime threshold in hours (positive). */
	public const CONFIG_OVERTIME_THRESHOLD_RED_OVER = 'overtime_threshold_red_over';
	/** App config: yellow undertime threshold in hours (positive absolute value). */
	public const CONFIG_OVERTIME_THRESHOLD_YELLOW_UNDER = 'overtime_threshold_yellow_under';
	/** App config: red undertime threshold in hours (positive absolute value). */
	public const CONFIG_OVERTIME_THRESHOLD_RED_UNDER = 'overtime_threshold_red_under';
	/** App config: comma-separated overtime notification recipients. */
	public const CONFIG_OVERTIME_NOTIFICATION_RECIPIENTS = 'overtime_notification_recipients';
	/** App config: versioned JSON matrix of direction => level => bool. */
	public const CONFIG_OVERTIME_NOTIFICATION_MATRIX_V1 = 'overtime_notification_matrix_v1';

	/** @var list<string> */
	public const OVERTIME_DIRECTIONS = ['over', 'under'];
	/** @var list<string> */
	public const OVERTIME_LEVELS = ['yellow', 'red'];

	/** User setting key: ISO Y-m-d date from which overtime balance is tracked (null = Jan 1 legacy). */
	public const SETTING_OVERTIME_TRACKING_FROM = 'overtime_tracking_from';

	/**
	 * User setting key: ISO Y-m-d employment start date (Eintrittsdatum).
	 * When set, the annual vacation entitlement is prorated for the (first)
	 * calendar year the employee was hired in. Empty = no proration (legacy:
	 * full annual entitlement regardless of partial years).
	 */
	public const SETTING_EMPLOYMENT_START = 'employment_start';

	/**
	 * User setting key: ISO Y-m-d employment end date (Austrittsdatum).
	 * When set, the annual vacation entitlement is prorated for the (last)
	 * calendar year the employee leaves in. Empty = open-ended employment.
	 */
	public const SETTING_EMPLOYMENT_END = 'employment_end';

	/**
	 * App config: method used to prorate annual vacation entitlement for
	 * employees whose employment does not span the full calendar year
	 * (see {@see self::SETTING_EMPLOYMENT_START} / {@see self::SETTING_EMPLOYMENT_END}).
	 * One of {@see self::VACATION_PRORATION_METHOD_TWELFTHS} (default) or
	 * {@see self::VACATION_PRORATION_METHOD_DAILY}. Only takes effect for
	 * employees who actually have an employment start and/or end on file.
	 */
	public const CONFIG_VACATION_PRORATION_METHOD = 'vacation_proration_method';

	/**
	 * Vacation year window mode (BANSS Phase B). Default calendar keeps legacy
	 * Jan–Dec entitlement years. Anniversary uses employment_start ± N years.
	 */
	public const CONFIG_VACATION_YEAR_MODE = 'vacation_year_mode';

	public const VACATION_YEAR_MODE_CALENDAR = 'calendar';

	public const VACATION_YEAR_MODE_ANNIVERSARY = 'anniversary';

	public const DEFAULT_VACATION_YEAR_MODE = self::VACATION_YEAR_MODE_CALENDAR;

	/** Error / audit code when anniversary mode lacks employment_start. */
	public const VAC_YEAR_MISSING_START = 'VAC_YEAR_MISSING_START';

	/**
	 * Org vacation unit (BANSS Phase C / US-102). Default days = legacy behaviour.
	 * Hours = pure-hours entitlement & consumption after migration wizard (Q3=A).
	 */
	public const CONFIG_VACATION_UNIT = 'vacation_unit';

	public const VACATION_UNIT_DAYS = 'days';

	public const VACATION_UNIT_HOURS = 'hours';

	public const DEFAULT_VACATION_UNIT = self::VACATION_UNIT_DAYS;

	/**
	 * Hours-per-day factor used by the days↔hours migration wizard and as the
	 * last-resort debit fallback when an employee has no working-time model.
	 * Day-to-day bookings prefer weekday schedule nets / model daily hours.
	 * BANSS-style 38.5 h weeks: admins should set 7.7 (= 38.5/5) at migration.
	 */
	public const CONFIG_VACATION_HOURS_PER_DAY = 'vacation_hours_per_day';

	public const DEFAULT_VACATION_HOURS_PER_DAY = 8.0;

	/** BANSS Appendix A average: 38.5 h / 5 workdays — recommended migration factor. */
	public const BANSS_RECOMMENDED_VACATION_HOURS_PER_DAY = 7.7;

	/**
	 * Factor used at the last days↔hours unit flip. Reverse migration must use
	 * this (not a later preset-only hours_per_day tweak) so Bestandskunden
	 * amounts round-trip without silent drift.
	 */
	public const CONFIG_VACATION_LAST_CONVERT_FACTOR = 'vacation_last_convert_factor';

	/** Admin confirmed Employee apps are unit-aware (Q8 hard-gate). */
	public const CONFIG_VACATION_UNIT_CLIENT_CONFIRMED = 'vacation_unit_client_confirmed';

	/** ISO timestamp of last successful unit migration wizard run. */
	public const CONFIG_VACATION_UNIT_MIGRATED_AT = 'vacation_unit_migrated_at';

	/**
	 * In-flight days↔hours migration (JSON). IConfig is not in the DB
	 * transaction — if the process dies after commit but before the unit flip,
	 * {@see VacationUnitMigrationService::completePendingMigrationIfNeeded()}
	 * finishes the flip from the committed audit row (never leaves hour
	 * magnitudes labeled as days).
	 */
	public const CONFIG_VACATION_UNIT_MIGRATE_PENDING = 'vacation_unit_migrate_pending';

	public const VAC_UNIT_MIG_REQUIRED = 'VAC_UNIT_MIG_REQUIRED';

	public const VAC_UNIT_CLIENT_GATE = 'VAC_UNIT_CLIENT_GATE';

	/** Concurrent vacation mutation while days↔hours migration holds the lock / pending flag. */
	public const VAC_UNIT_MIGRATE_IN_PROGRESS = 'VAC_UNIT_MIGRATE_IN_PROGRESS';

	/** Hours-mode schema columns missing — run occ upgrade / app migrations first. */
	public const VAC_UNIT_SCHEMA_OUTDATED = 'VAC_UNIT_SCHEMA_OUTDATED';

	public const ABSENCE_HOURS_CLIENT_REQUIRED = 'ABSENCE_HOURS_CLIENT_REQUIRED';

	/**
	 * Days-mode half-day vacation (AZC-VAC-HALF-DAY-DAYS-MODE).
	 * day_fraction is request-only; never bind client `days`.
	 */
	public const VAC_HALF_DAY_RANGE_FORBIDDEN = 'VAC_HALF_DAY_RANGE_FORBIDDEN';

	public const VAC_HALF_DAY_NON_WORKING = 'VAC_HALF_DAY_NON_WORKING';

	public const VAC_HALF_DAY_INVALID = 'VAC_HALF_DAY_INVALID';

	public const VAC_HALF_DAY_INTEGRITY = 'VAC_HALF_DAY_INTEGRITY';

	/** Calendar→anniversary while users lack employment_start without ack. */
	public const VAC_YEAR_MISSING_HIRE_ACK_REQUIRED = 'VAC_YEAR_MISSING_HIRE_ACK_REQUIRED';

	/**
	 * Full-month proration (Zwölftelung) per German BUrlG §5: each calendar
	 * month touched by the employment relationship contributes 1/12 of the
	 * annual entitlement; the prorated result is rounded up to a full day when
	 * its fractional part is at least half a day (§5(2)), and never rounded
	 * down below the proportional minimum.
	 */
	public const VACATION_PRORATION_METHOD_TWELFTHS = 'twelfths';

	/**
	 * Exact daily proration: `annual_days × (covered_calendar_days / days_in_year)`,
	 * rounded to two decimal places. Use when month-granular Zwölftelung is
	 * not desired (e.g. company policy / collective agreement).
	 */
	public const VACATION_PRORATION_METHOD_DAILY = 'daily';

	/** Default proration method when the app config is unset. */
	public const DEFAULT_VACATION_PRORATION_METHOD = self::VACATION_PRORATION_METHOD_TWELFTHS;

	/**
	 * Algorithm version stamped into the proration trace so payroll auditors
	 * can replay a prorated entitlement deterministically. Increment when the
	 * proration arithmetic or month-counting rule changes.
	 */
	public const VACATION_PRORATION_ALGORITHM_VERSION = 1;

	/** User setting: when absent or truthy, employee may use clock in/out (stamping). Default enabled. */
	public const SETTING_CLOCK_STAMPING_ENABLED = 'clock_stamping_enabled';

	/** User setting: when absent or truthy, employee may create manual time entries. Default enabled. */
	public const SETTING_MANUAL_TIME_ENTRY_ENABLED = 'manual_time_entry_enabled';

	/** App config: organisation allows clock in/out (stamping). Default on. */
	public const CONFIG_CLOCK_STAMPING_ENABLED = 'clock_stamping_enabled';

	/** App config: organisation allows manual time entries. Default on. */
	public const CONFIG_MANUAL_TIME_ENTRY_ENABLED = 'manual_time_entry_enabled';

	/** App config: require manager approval for edits to completed time entries. Default off. */
	public const CONFIG_TIME_ENTRY_CHANGES_REQUIRE_APPROVAL = 'time_entry_changes_require_approval';

	/** App config: require manager approval for new manual time entries. Default off. */
	public const CONFIG_MANUAL_TIME_ENTRIES_REQUIRE_APPROVAL = 'manual_time_entries_require_approval';

	/** Sibling Nextcloud app id for ProjectCheck (instance-level install checks). */
	public const APP_ID_PROJECTCHECK = 'projectcheck';

	/**
	 * App config: when "1", employees may link ArbeitszeitCheck time to ProjectCheck
	 * projects (clock-in picker and manual entries). Requires the ProjectCheck app
	 * to be installed and enabled on this Nextcloud (not only for the current admin).
	 */
	public const CONFIG_PROJECTCHECK_INTEGRATION_ENABLED = 'projectcheck_integration_enabled';

	/** Default for {@see self::CONFIG_PROJECTCHECK_INTEGRATION_ENABLED}: opt-in (off). */
	public const CONFIG_PROJECTCHECK_INTEGRATION_DEFAULT = '0';

	/** Overtime balance algorithm version for audit replay. */
	public const OVERTIME_ALGORITHM_VERSION = 2;

	/** App config: when "1", overtime bank cap and month-end payout (Auszahlung) are active. */
	public const CONFIG_OVERTIME_BANK_ENABLED = 'overtime_bank_enabled';

	/**
	 * When "1", hour+% premium (Zuschlag) classification is enabled org-wide.
	 * Default off — orthogonal to Saldo / Auszahlung.
	 */
	public const CONFIG_PREMIUM_SURCHARGES_ENABLED = 'premium_surcharges_enabled';

	/** JSON PremiumPolicy document (hours_only). Empty = use AT starter when enabling. */
	public const CONFIG_PREMIUM_POLICY_JSON = 'premium_policy_json';

	/** Monotonic policy revision for audit / snapshots. */
	public const CONFIG_PREMIUM_POLICY_VERSION = 'premium_policy_version';

	/**
	 * JSON map of premium category id → DATEV Lohnart code (1–4 digits).
	 * Empty / missing ids are skipped — additive export only; never alters normal-hour lines.
	 */
	public const CONFIG_DATEV_LOHNART_PREMIUM_MAP = 'datev_lohnart_premium_map';

	/** DATEV Beraternummer (consultant number), up to 7 digits. */
	public const CONFIG_DATEV_BERATERNUMMER = 'datev_beraternummer';

	/** DATEV Mandantennummer (client number), up to 5 digits. */
	public const CONFIG_DATEV_MANDANTENNUMMER = 'datev_mandantennummer';

	/** DATEV Lohnart for normal working hours (default 1000). */
	public const CONFIG_DATEV_LOHNART_NORMAL = 'datev_lohnart_normal';

	/** DATEV Lohnart reserved for future Saldo/Auszahlung mapping (default 2000). */
	public const CONFIG_DATEV_LOHNART_UEBERSTUNDEN = 'datev_lohnart_ueberstunden';

	/** Per-user DATEV Personalnummer (IConfig user value key). */
	public const USER_DATEV_PERSONALNUMMER = 'datev_personalnummer';

	/** App config: maximum banked overtime hours (default 100). Hours above may be paid out. */
	public const CONFIG_OVERTIME_BANK_MAX_HOURS = 'overtime_bank_max_hours';

	/** App config: bank fill % at which the traffic light turns yellow (0–100). */
	public const CONFIG_OVERTIME_BANK_YELLOW_PERCENT = 'overtime_bank_yellow_percent';

	/** App config: bank fill % at which the traffic light turns red (0–100). */
	public const CONFIG_OVERTIME_BANK_RED_PERCENT = 'overtime_bank_red_percent';

	/** App config: when "1", employees receive an in-app notification after overtime payout. */
	public const CONFIG_OVERTIME_PAYOUT_NOTIFY_IN_APP = 'overtime_payout_notify_in_app';

	/** App config: when "1", employees receive an email after overtime payout (requires valid address). */
	public const CONFIG_OVERTIME_PAYOUT_NOTIFY_EMAIL = 'overtime_payout_notify_email';

	/**
	 * When "1", employees cannot finalize a month while overtime above the bank cap
	 * is still unpaid (payroll must record payout first). Off by default.
	 */
	public const CONFIG_OVERTIME_BLOCK_MONTH_CLOSURE_PENDING_PAYOUT = 'overtime_block_month_closure_pending_payout';

	/**
	 * G2 — org opt-in for DutyCheck rotation Soll in AZC overtime/vacation (default off).
	 */
	public const CONFIG_DUTY_ROTATION_SOLL_ENABLED = 'duty_rotation_soll_enabled';

	/** Default app-config value for {@see CONFIG_DUTY_ROTATION_SOLL_ENABLED}. */
	public const CONFIG_DUTY_ROTATION_SOLL_DEFAULT = '0';

	/**
	 * Compliance score weights (critical, warning, info).
	 */
	public const COMPLIANCE_SCORE_CRITICAL_WEIGHT = 25;
	public const COMPLIANCE_SCORE_WARNING_WEIGHT = 10;
	public const COMPLIANCE_SCORE_INFO_WEIGHT = 5;
	public const COMPLIANCE_SCORE_MAX_DEDUCTION = 100;


	private function __construct()
	{
	}
}
