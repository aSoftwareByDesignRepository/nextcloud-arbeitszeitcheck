<?php

declare(strict_types=1);

/**
 * ArbeitszeitCheck wiring for the shared transfer engine.
 *
 *  - requireTransferAdmin gates on the app's real door-admin role
 *    (PermissionService::isAdmin — system admin OR delegated app admin).
 *  - validateValue/normalizeValue replicate the SAME normalisation the
 *    settings writers apply (AdminController::updateAdminSettings /
 *    updateNotificationSettings and the rollout endpoints) — bool '1'/'0',
 *    range clamps, RegionRegistry country/region, DatevOrgCredentials,
 *    OfflineStampSkewPolicy, TimePickerMinuteStep, BreakPolicyResolver,
 *    PremiumPolicy, DatevPremiumLohnartMap, the notification
 *    recipient/matrix rules and VacationUnit/Proration/YearMode
 *    normalisers (validator parity, NN-08).
 *  - writeMirrored runs the access-door policy through
 *    PermissionService::saveAccessDoorPolicy in ONE atomic call — never
 *    raw setAppValue (NN-02, azc #46: the group allowlist is mirrored into
 *    the NC platform app restriction).
 *  - createSafetySnapshot delegates to UpgradeBackupService for the
 *    mandatory pre-replace snapshot (FS-2).
 */

namespace OCA\ArbeitszeitCheck\ConfigTransfer;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Exception\NotAppAdminException;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCA\ArbeitszeitCheck\Service\VacationProrationService;
use OCA\ArbeitszeitCheck\Service\VacationUnitService;
use OCA\ArbeitszeitCheck\Service\VacationYearWindowResolver;
use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCA\ArbeitszeitCheck\Support\DatevOrgCredentials;
use OCA\ArbeitszeitCheck\Support\DatevPremiumLohnartMap;
use OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy;
use OCA\ArbeitszeitCheck\Support\PremiumPolicy;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCA\ArbeitszeitCheck\Support\TimePickerMinuteStep;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	/** Access-door keys — class 'mirrored', written atomically. */
	private const ACCESS_KEYS = [
		Constants::CONFIG_ACCESS_RESTRICTION_ENABLED,
		Constants::CONFIG_ACCESS_ALLOWED_USER_IDS,
		Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS,
		Constants::CONFIG_APP_ADMIN_USER_IDS,
	];

	/**
	 * Every '1'/'0' flag the settings writers persist. Truthy rule mirrors
	 * AdminController::toBool + the explicit ('1'|'true') write conversion.
	 */
	private const BOOL_KEYS = [
		'auto_compliance_check',
		'realtime_compliance_check',
		'compliance_strict_mode',
		'enable_violation_notifications',
		'break_auto_fallback_enabled',
		'missing_clock_in_reminders_enabled',
		'export_midnight_split_enabled',
		'month_closure_enabled',
		'send_ical_approved_absences',
		'send_ical_to_substitute',
		'send_ical_to_managers',
		'send_email_substitution_request',
		'send_email_substitute_approved_to_employee',
		'send_email_substitute_approved_to_manager',
		'manager_pending_email_absences',
		'manager_pending_email_manual_entries',
		'manager_pending_email_corrections',
		'statutory_auto_reseed',
		'vacation_rollover_enabled',
		'vacation_rollover_include_unused_annual',
		'time_entry_changes_require_approval',
		'manual_time_entries_require_approval',
		'projectcheck_integration_enabled',
		'clock_stamping_enabled',
		'manual_time_entry_enabled',
		'kiosk_enabled',
		'hr_notifications_enabled',
		'overtime_traffic_light_enabled',
		'overtime_bank_enabled',
		'overtime_payout_notify_in_app',
		'overtime_payout_notify_email',
		'overtime_block_month_closure_pending_payout',
		'premium_surcharges_enabled',
		'layered_entitlements_enabled',
		'use_app_teams',
		'work_location_catalog_enabled',
		'special_entitlements_enabled',
		'business_trips_enabled',
		'business_trip_credit_enabled',
		'policy_cohorts_enabled',
		'legacy_home_absence_create_enabled',
		'legacy_trip_absence_create_enabled',
		'duty_rotation_soll_enabled',
		'paid_absence_planned_hours_credit',
		'leave_soll_effects',
		'vacation_unit_client_confirmed',
	];

	/** AdminController::MAX_NOTIFICATION_RECIPIENTS */
	private const MAX_NOTIFICATION_RECIPIENTS = 20;
	/** AdminController::MAX_NOTIFICATION_RECIPIENT_LENGTH */
	private const MAX_NOTIFICATION_RECIPIENT_LENGTH = 254;
	/** AdminController::MAX_NOTIFICATION_RECIPIENTS_RAW_LENGTH */
	private const MAX_NOTIFICATION_RECIPIENTS_RAW_LENGTH = 4000;

	public function __construct(
		private readonly PermissionService $permissions,
		private readonly UpgradeBackupService $backups,
		private readonly AuditLogMapper $auditLog,
		private readonly TransferManifest $manifest,
		private readonly ?BreakPolicyResolver $breakPolicyResolver,
		private readonly IConfig $config,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		if ($uid === '' || !$this->permissions->isAdmin($uid)) {
			// A non-TransferException — the controller collapses it into the
			// family's uniform NOT_FOUND payload (no existence oracle).
			throw new NotAppAdminException('Transfer requires an ArbeitszeitCheck administrator.');
		}
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->normalize($key, $value);
			return null;
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage() !== '' ? $e->getMessage() : 'INVALID_VALUE';
		} catch (\JsonException) {
			return 'INVALID_JSON';
		} catch (\Throwable) {
			return 'INVALID_VALUE';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			return $this->normalize($key, $value);
		} catch (\Throwable) {
			return $value; // validateValue already rejected bad input
		}
	}

	private function normalize(string $key, string $value): string
	{
		if (in_array($key, self::ACCESS_KEYS, true)) {
			return $this->normalizeAccessKey($key, $value);
		}
		if (in_array($key, self::BOOL_KEYS, true)) {
			return $this->toBoolString($value);
		}

		return match ($key) {
			// ── hours / retention (updateAdminSettings parity) ──
			'max_daily_hours', 'min_rest_period' => $this->rejectFloatRange($value, 1.0, 24.0),
			'default_working_hours' => (string)max(0.0, $this->toFloat($value)),
			'time_picker_minute_step' => (string)TimePickerMinuteStep::normalize((int)$value),
			'retention_period' => (string)max(1, min(10, (int)$value)),
			// ── regional ──
			'country' => $this->normalizeCountry($value),
			'german_state' => $this->normalizeRegion($value),
			'weekly_absolute_max_hours' => ((int)$value === 50) ? '50' : '45',
			'app_timezone' => $this->normalizeTimezone($value),
			// ── compliance ──
			'break_auto_fallback_minutes' => (string)max(15, min(720, (int)$value)),
			'break_auto_fallback_flex_window_start' => (string)max(0, min(23, (int)$value)),
			'break_auto_fallback_flex_window_end' => (string)max(1, min(24, (int)$value)),
			'require_substitute_types' => $this->normalizeSubstituteTypes($value),
			'break_policy_json' => $this->normalizeBreakPolicyJson($value),
			'break_policy_version' => $this->digitsOnly($value),
			// ── time recording ──
			'offline_stamp_max_past_hours' => $this->normalizeOfflineStampHours($value),
			// ── exports / DATEV ──
			'datev_beraternummer' => $this->normalizeDatevDigits($value, 7),
			'datev_mandantennummer' => $this->normalizeDatevDigits($value, 5),
			'datev_lohnart_normal' => $this->normalizeDatevLohnart($value, '1000'),
			'datev_lohnart_ueberstunden' => $this->normalizeDatevLohnart($value, '2000'),
			'datev_lohnart_premium_map' => $this->normalizeDatevPremiumMap($value),
			// ── month closure ──
			'month_closure_grace_days_after_eom' => (string)max(0, min(90, (int)$value)),
			// ── notification policy ──
			'manager_pending_email_mode' => $value === Constants::MANAGER_PENDING_EMAIL_MODE_DIGEST
				? Constants::MANAGER_PENDING_EMAIL_MODE_DIGEST
				: Constants::MANAGER_PENDING_EMAIL_MODE_IMMEDIATE,
			'hr_notification_recipients', 'overtime_notification_recipients'
				=> $this->normalizeRecipientList($value),
			'hr_notification_matrix_v1' => $this->normalizeHrMatrix($value),
			'overtime_notification_matrix_v1' => $this->normalizeOvertimeMatrix($value),
			// ── overtime / premiums ──
			'overtime_threshold_yellow_over', 'overtime_threshold_red_over',
			'overtime_threshold_yellow_under', 'overtime_threshold_red_under'
				=> $this->normalizeThreshold($value),
			'overtime_bank_max_hours' => $this->normalizeBankMaxHours($value),
			'overtime_bank_yellow_percent', 'overtime_bank_red_percent'
				=> (string)max(0, min(100, (int)$value)),
			'premium_policy_json' => $this->normalizePremiumPolicyJson($value),
			'premium_policy_version' => $this->digitsOnly($value),
			// ── vacation / entitlement ──
			'vacation_carryover_expiry_month' => (string)max(1, min(12, (int)$value)),
			'vacation_carryover_expiry_day' => (string)max(1, min(31, (int)$value)),
			'vacation_carryover_max_days' => $this->normalizeCarryoverMax($value),
			'vacation_proration_method' => VacationProrationService::normalizeMethod($value),
			'vacation_year_mode' => VacationYearWindowResolver::normalizeMode($value),
			'vacation_unit' => in_array($value, [Constants::VACATION_UNIT_DAYS, Constants::VACATION_UNIT_HOURS], true)
				? $value
				: throw new \InvalidArgumentException('INVALID_VACATION_UNIT'),
			'vacation_hours_per_day' => $this->rejectFloatRange($value, 0.25, 24.0),
			// ── misc JSON payloads ──
			'company_holidays' => $this->normalizeCompanyHolidays($value),
			default => strlen($value) <= 65536
				? $value
				: throw new \InvalidArgumentException('TOO_LONG'),
		};
	}

	// ── access door (mirrored) ─────────────────────────────────────────

	private function normalizeAccessKey(string $key, string $value): string
	{
		if ($key === Constants::CONFIG_ACCESS_RESTRICTION_ENABLED) {
			return $this->toBoolString($value);
		}
		return $this->normalizeIdListJson($value);
	}

	private function normalizeIdListJson(string $value): string
	{
		$list = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
		if (!is_array($list) || !array_is_list($list)) {
			throw new \InvalidArgumentException('INVALID_ID_LIST');
		}
		$clean = [];
		foreach ($list as $id) {
			$id = trim((string)$id);
			if ($id !== '' && !isset($clean[$id])) {
				$clean[$id] = true;
			}
		}
		return json_encode(array_keys($clean), JSON_THROW_ON_ERROR);
	}

	public function validateMirrored(array $keyValues): ?string
	{
		$decoded = $this->decodePolicy($keyValues);
		if ($decoded === null) {
			return 'INVALID_POLICY_JSON';
		}
		foreach ($decoded['appAdminUserIds'] as $uid) {
			if (!$this->userManager->userExists($uid)) {
				return 'INVALID_APP_ADMIN_USER';
			}
		}
		foreach ($decoded['allowedUserIds'] as $uid) {
			if (!$this->userManager->userExists($uid)) {
				return 'INVALID_ALLOWED_USER';
			}
		}
		foreach ($decoded['allowedGroupIds'] as $gid) {
			if (!$this->groupManager->groupExists($gid)) {
				return 'INVALID_ALLOWED_GROUP';
			}
		}
		return null;
	}

	public function writeMirrored(array $keyValues): void
	{
		$decoded = $this->decodePolicy($keyValues);
		if ($decoded === null) {
			throw new \InvalidArgumentException('INVALID_POLICY_JSON');
		}
		// One atomic policy write through the central permission writer
		// (NN-02 / azc #46): the four appconfig keys and the NC platform
		// app-restriction mirror move together — never raw setAppValue.
		$this->permissions->saveAccessDoorPolicy($decoded);
	}

	/**
	 * Overlay the transferred mirrored keys onto the current door policy,
	 * so a file carrying only a subset never blanks the rest (same overlay
	 * semantics saveAccessDoorPolicy applies).
	 *
	 * @param array<string,string> $kv
	 * @return array{restrictionEnabled:bool,allowedUserIds:list<string>,allowedGroupIds:list<string>,appAdminUserIds:list<string>}|null
	 */
	private function decodePolicy(array $kv): ?array
	{
		$current = $this->permissions->accessDoorPolicy();
		$restricted = $current['restrictionEnabled'];
		$users = $current['allowedUserIds'];
		$groups = $current['allowedGroupIds'];
		$admins = $current['appAdminUserIds'];
		if (isset($kv[Constants::CONFIG_ACCESS_RESTRICTION_ENABLED])) {
			$restricted = $this->toBoolString($kv[Constants::CONFIG_ACCESS_RESTRICTION_ENABLED]) === '1';
		}
		foreach ([
			Constants::CONFIG_ACCESS_ALLOWED_USER_IDS => 'users',
			Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS => 'groups',
			Constants::CONFIG_APP_ADMIN_USER_IDS => 'admins',
		] as $key => $field) {
			if (isset($kv[$key])) {
				$decoded = json_decode($kv[$key], true);
				if (!is_array($decoded)) {
					return null;
				}
				$$field = array_values(array_map('strval', $decoded));
			}
		}
		return [
			'restrictionEnabled' => $restricted,
			'allowedUserIds' => array_values($users),
			'allowedGroupIds' => array_values($groups),
			'appAdminUserIds' => array_values($admins),
		];
	}

	public function securityRelevantKeys(): array
	{
		return [
			Constants::CONFIG_ACCESS_RESTRICTION_ENABLED,
			Constants::CONFIG_ACCESS_ALLOWED_USER_IDS,
			Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS,
			Constants::CONFIG_APP_ADMIN_USER_IDS,
			'compliance_strict_mode',
			'time_entry_changes_require_approval',
			'manual_time_entries_require_approval',
			'retention_period',
		];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			// Fresh instance without data: no snapshot, but not an error.
			// A real failure on a non-empty install must not go unprotected.
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		$spec = $this->manifest->keySpec($key);
		return $spec !== null ? $spec['section'] : null;
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		$actorId = $actor === '' ? 'cli' : $actor;
		try {
			$this->auditLog->logAction(
				$actorId,
				'config_transfer_' . $action,
				'config_transfer',
				null,
				null,
				$details,
				$actorId,
			);
		} catch (\Throwable $e) {
			// audit must never fail the transfer itself
			$this->logger->warning('arbeitszeitcheck: config transfer audit event failed', [
				'app' => 'arbeitszeitcheck',
				'action' => $action,
				'exception' => $e,
			]);
		}
	}

	// ── value normalisers (settings-writer parity) ─────────────────────

	/** Same truthy rule as AdminController::toBool + the '1'/'true' write conversion. */
	private function toBoolString(mixed $value): string
	{
		return ($value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on')
			? '1' : '0';
	}

	private function toFloat(string $value): float
	{
		return (float)str_replace(',', '.', trim($value));
	}

	/** Range-validated float the settings writer rejects (not clamps). */
	private function rejectFloatRange(string $value, float $min, float $max): string
	{
		$v = $this->toFloat($value);
		if (!is_finite($v) || $v < $min || $v > $max) {
			throw new \InvalidArgumentException('VALIDATION_RANGE');
		}
		return (string)$v;
	}

	private function normalizeCountry(string $value): string
	{
		$v = strtoupper(trim($value));
		if (!RegionRegistry::isSupportedCountry($v)) {
			throw new \InvalidArgumentException('INVALID_COUNTRY');
		}
		return $v;
	}

	private function normalizeRegion(string $value): string
	{
		$v = strtoupper(trim($value));
		if (!RegionRegistry::isValidRegion($v)) {
			throw new \InvalidArgumentException('INVALID_REGION');
		}
		// The writer validates the region against the configured country —
		// same rule against the target instance's country.
		$country = strtoupper(trim((string)$this->config->getAppValue('arbeitszeitcheck', 'country', '')));
		if (RegionRegistry::isSupportedCountry($country) && RegionRegistry::countryOf($v) !== $country) {
			throw new \InvalidArgumentException('REGION_COUNTRY_MISMATCH');
		}
		return $v;
	}

	private function normalizeTimezone(string $value): string
	{
		$v = trim($value);
		if ($v === '') {
			return '';
		}
		try {
			new \DateTimeZone($v);
		} catch (\Throwable) {
			throw new \InvalidArgumentException('INVALID_TIMEZONE');
		}
		return $v;
	}

	private function normalizeOfflineStampHours(string $value): string
	{
		$hours = (int)$value;
		if (!OfflineStampSkewPolicy::isAllowedPastHours($hours)) {
			throw new \InvalidArgumentException('VALIDATION_RANGE');
		}
		return (string)OfflineStampSkewPolicy::normalizePastHours($hours);
	}

	private function normalizeSubstituteTypes(string $value): string
	{
		$decoded = json_decode($value, true);
		if (!is_array($decoded)) {
			throw new \InvalidArgumentException('INVALID_JSON_LIST');
		}
		$valid = AbsenceTypeCatalog::all();
		$out = array_values(array_unique(array_filter(
			$decoded,
			static fn (mixed $t): bool => in_array((string)$t, $valid, true),
		)));
		return json_encode($out, JSON_THROW_ON_ERROR);
	}

	private function normalizeBreakPolicyJson(string $value): string
	{
		if (trim($value) === '') {
			return '';
		}
		$decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($decoded)) {
			throw new \InvalidArgumentException('INVALID_POLICY_JSON');
		}
		$resolver = $this->breakPolicyResolver;
		if ($resolver === null) {
			// Resolver not wired — JSON shape is the only check we can run.
			return json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
		}
		$validated = $resolver->validatePolicy($decoded);
		if (($validated['errors'] ?? []) !== []) {
			throw new \InvalidArgumentException('INVALID_POLICY');
		}
		return json_encode($validated['normalized'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
	}

	private function normalizePremiumPolicyJson(string $value): string
	{
		if (trim($value) === '') {
			return '';
		}
		$decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($decoded)) {
			throw new \InvalidArgumentException('INVALID_POLICY_JSON');
		}
		if (PremiumPolicy::validate($decoded) !== []) {
			throw new \InvalidArgumentException('INVALID_POLICY');
		}
		return json_encode(PremiumPolicy::fromValidated($decoded)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * DATEV org fields — digits only, max length (DatevOrgCredentials
	 * semantics). Pair completeness is a two-key write-time rule the engine
	 * cannot apply per-key; each field validates its own format.
	 */
	private function normalizeDatevDigits(string $value, int $maxLen): string
	{
		$digits = DatevOrgCredentials::normalizeDigits($value);
		if ($digits !== '' && !preg_match('/^\d{1,' . $maxLen . '}$/', $digits)) {
			throw new \InvalidArgumentException('INVALID_DATEV_NUMBER');
		}
		return $digits;
	}

	private function normalizeDatevLohnart(string $value, string $default): string
	{
		$digits = DatevOrgCredentials::normalizeDigits($value);
		if ($digits === '') {
			return $default;
		}
		if (DatevOrgCredentials::validateLohnart($digits, false) !== []) {
			throw new \InvalidArgumentException('INVALID_LOHNART');
		}
		return $digits;
	}

	private function normalizeDatevPremiumMap(string $value): string
	{
		if (trim($value) === '') {
			return '';
		}
		$decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
		if (DatevPremiumLohnartMap::validate($decoded) !== []) {
			throw new \InvalidArgumentException('INVALID_LOHNART_MAP');
		}
		return DatevPremiumLohnartMap::toJson(DatevPremiumLohnartMap::normalize($decoded));
	}

	private function normalizeRecipientList(string $value): string
	{
		$raw = trim($value);
		if ($raw === '') {
			return '';
		}
		if (mb_strlen($raw) > self::MAX_NOTIFICATION_RECIPIENTS_RAW_LENGTH) {
			throw new \InvalidArgumentException('RECIPIENTS_TOO_LONG');
		}
		$unique = [];
		foreach (explode(',', $raw) as $entry) {
			$email = strtolower(trim($entry));
			if ($email === '') {
				continue;
			}
			if (mb_strlen($email) > self::MAX_NOTIFICATION_RECIPIENT_LENGTH
				|| !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				// Same fail-closed rule as the writer: any invalid address
				// rejects the whole list (never silently drops one).
				throw new \InvalidArgumentException('INVALID_RECIPIENT_EMAIL');
			}
			$unique[$email] = true;
		}
		$recipients = array_keys($unique);
		if (count($recipients) > self::MAX_NOTIFICATION_RECIPIENTS) {
			throw new \InvalidArgumentException('TOO_MANY_RECIPIENTS');
		}
		return implode(',', $recipients);
	}

	private function normalizeHrMatrix(string $value): string
	{
		$input = json_decode($value, true);
		if (!is_array($input)) {
			$input = [];
		}
		$matrix = [];
		foreach (Constants::ABSENCE_TYPES as $absenceType) {
			$matrix[$absenceType] = [];
			$typeInput = (isset($input[$absenceType]) && is_array($input[$absenceType])) ? $input[$absenceType] : [];
			foreach (Constants::HR_NOTIFICATION_EVENTS as $eventKey) {
				$matrix[$absenceType][$eventKey] = $this->toBool($typeInput[$eventKey] ?? false);
			}
		}
		return json_encode($matrix, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
	}

	private function normalizeOvertimeMatrix(string $value): string
	{
		$input = json_decode($value, true);
		if (!is_array($input)) {
			$input = [];
		}
		$matrix = [];
		foreach (Constants::OVERTIME_DIRECTIONS as $direction) {
			$matrix[$direction] = [];
			$typeInput = (isset($input[$direction]) && is_array($input[$direction])) ? $input[$direction] : [];
			foreach (Constants::OVERTIME_LEVELS as $level) {
				$matrix[$direction][$level] = $this->toBool($typeInput[$level] ?? false);
			}
		}
		return json_encode($matrix, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
	}

	private function toBool(mixed $value): bool
	{
		return $this->toBoolString($value) === '1';
	}

	/** OvertimeTrafficLightService::sanitizeFloat parity (clamp 0..500). */
	private function normalizeThreshold(string $value): string
	{
		$v = $this->toFloat($value);
		if (!is_finite($v)) {
			$v = 0.0;
		}
		return (string)max(0.0, min(500.0, $v));
	}

	private function normalizeBankMaxHours(string $value): string
	{
		$v = $this->toFloat($value);
		if (!is_finite($v)) {
			$v = 100.0;
		}
		return (string)max(1.0, min(500.0, round($v, 2)));
	}

	private function normalizeCarryoverMax(string $value): string
	{
		$s = trim($value);
		if ($s === '') {
			return '';
		}
		$v = $this->toFloat($s);
		$hoursMode = (new VacationUnitService($this->config))->isHoursMode();
		$ceiling = $hoursMode ? 4000.0 : 366.0;
		if (!is_finite($v) || $v < 0 || $v > $ceiling) {
			throw new \InvalidArgumentException('VALIDATION_RANGE');
		}
		return (string)$v;
	}

	private function normalizeCompanyHolidays(string $value): string
	{
		if (trim($value) === '') {
			return '';
		}
		$items = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
		if (!is_array($items)) {
			throw new \InvalidArgumentException('INVALID_HOLIDAY_LIST');
		}
		foreach ($items as $item) {
			if (!is_array($item)) {
				throw new \InvalidArgumentException('INVALID_HOLIDAY_ITEM');
			}
			$date = trim((string)($item['date'] ?? ''));
			$name = trim((string)($item['name'] ?? ''));
			if ($date === '' || $name === '') {
				throw new \InvalidArgumentException('INVALID_HOLIDAY_ITEM');
			}
			try {
				new \DateTime($date);
			} catch (\Throwable) {
				throw new \InvalidArgumentException('INVALID_HOLIDAY_DATE');
			}
		}
		return json_encode(array_values($items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
	}

	private function digitsOnly(string $value): string
	{
		$v = trim($value);
		if ($v === '' || !preg_match('/^\d+$/', $v)) {
			throw new \InvalidArgumentException('INVALID_VERSION');
		}
		return $v;
	}
}
