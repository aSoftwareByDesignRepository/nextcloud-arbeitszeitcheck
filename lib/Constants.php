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
	 * Default number of items per page for list endpoints (time entries, absences, violations, etc.).
	 */
	public const DEFAULT_LIST_LIMIT = 25;

	/**
	 * Maximum number of items per request (DoS protection).
	 */
	public const MAX_LIST_LIMIT = 500;

	/**
	 * Default vacation days per year when no user setting exists (German standard).
	 */
	public const DEFAULT_VACATION_DAYS_PER_YEAR = 25;

	/** App config: month (1–12) when carryover from the previous year expires (default March). */
	public const CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH = 'vacation_carryover_expiry_month';

	/** App config: day of month for carryover expiry (default 31). */
	public const CONFIG_VACATION_CARRYOVER_EXPIRY_DAY = 'vacation_carryover_expiry_day';

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

	/**
	 * Batch size for chunked DB operations (e.g. recursive team queries).
	 */
	public const BATCH_CHUNK_SIZE = 500;

	/**
	 * Compliance score weights (critical, warning, info).
	 */
	public const COMPLIANCE_SCORE_CRITICAL_WEIGHT = 25;
	public const COMPLIANCE_SCORE_WARNING_WEIGHT = 10;
	public const COMPLIANCE_SCORE_INFO_WEIGHT = 5;
	public const COMPLIANCE_SCORE_MAX_DEDUCTION = 100;

	/**
	 * CalDAV calendar URI for app-managed absence events (share this calendar for team visibility).
	 */
	public const CALENDAR_URI_ABSENCES = 'arbeitszeitcheck-absences';

	/**
	 * CalDAV calendar URI for app-managed public-holiday events (per-user state).
	 */
	public const CALENDAR_URI_HOLIDAYS = 'arbeitszeitcheck-holidays';

	/** App config: sync approved absences into Nextcloud Calendar (CalDAV). */
	public const CONFIG_CALENDAR_SYNC_ABSENCES_ENABLED = 'calendar_sync_absences_enabled';

	/** App config: allow syncing public holidays into users' Nextcloud calendars. */
	public const CONFIG_CALENDAR_SYNC_HOLIDAYS_ENABLED = 'calendar_sync_holidays_enabled';

	/** User setting: sync Feiertage into dedicated Nextcloud calendar. */
	public const USER_SETTING_CALENDAR_SYNC_HOLIDAYS = 'nc_calendar_sync_holidays';

	private function __construct()
	{
	}
}
