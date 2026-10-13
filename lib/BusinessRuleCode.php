<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck;

/**
 * Stable machine reason codes for {@see Exception\BusinessRuleException}.
 *
 * Locale-independent — kiosk and web clients map these to their own copy.
 */
final class BusinessRuleCode
{
	public const ALREADY_CLOCKED_IN = 'already_clocked_in';
	/** Clock-in blocked while a paused/open session has a pending correction. */
	public const OPEN_SESSION_CORRECTION_PENDING = 'open_session_correction_pending';
	public const ON_BREAK_END_FIRST = 'on_break_end_first';
	public const NOT_CLOCKED_IN = 'not_clocked_in';
	public const BREAK_ALREADY_STARTED = 'break_already_started';
	public const NOT_ON_BREAK = 'not_on_break';
	public const DAILY_HOURS_LIMIT = 'daily_hours_limit';
	public const REST_PERIOD_REQUIRED = 'rest_period_required';
	public const PROJECT_NOT_ALLOWED = 'project_not_allowed';
	public const PROJECT_ID_TOO_LONG = 'project_id_too_long';
	public const MONTH_FINALIZED = 'month_finalized';
	public const LOCKED = 'locked';
	/** Request carried a work_location value outside the canonical allowlist. */
	public const INVALID_WORK_LOCATION = 'invalid_work_location';
}
