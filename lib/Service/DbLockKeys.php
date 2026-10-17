<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

/**
 * Short exclusive-lock keys for Nextcloud's DB locking provider.
 *
 * oc_file_locks.key is VARCHAR(64). Longer keys truncate on insert so
 * releaseLock(fullKey) never matches — exclusive locks stick at -1 until TTL
 * and the next mutation fails with LockedException / “already running”.
 *
 * md5 here is only a stable identifier, not a secret. Keep every key ≤ 64 chars.
 */
final class DbLockKeys
{
	public const ORDER = [
		'catalog_schema',
		'policy_cohort',
		'user_month',
		'domain_aggregate',
		'linked_records',
		'domain_ledger',
		'month_closure',
	];

	public static function policyCatalog(string $domain): string
	{
		return self::scoped('pc', [$domain]);
	}

	public static function policyCohort(int $cohortId): string
	{
		return self::scoped('co', [$cohortId]);
	}

	public static function workforceUserMonth(string $userId, string $month): string
	{
		if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) !== 1) {
			throw new \InvalidArgumentException('Invalid lock month.');
		}
		return self::scoped('um', [$userId, $month]);
	}

	public static function domainAggregate(string $domain, int|string $id): string
	{
		return self::scoped('da', [$domain, $id]);
	}

	public static function linkedRecords(string $domain, int|string $id): string
	{
		return self::scoped('lr', [$domain, $id]);
	}

	public static function domainLedger(string $domain, int|string $id): string
	{
		return self::scoped('dl', [$domain, $id]);
	}

	public static function specialEntitlementType(int|string $typeId): string
	{
		return self::scoped('da', ['se_type', $typeId]);
	}

	public static function specialEntitlementPolicy(int|string $typeId): string
	{
		return self::scoped('da', ['se_policy', $typeId]);
	}

	public static function specialEntitlementPeriod(string $userId, int|string $typeId, string $periodStart): string
	{
		return self::scoped('um', [$userId, 'se_period', $typeId, $periodStart]);
	}

	public static function specialEntitlementAbsence(int|string $absenceId): string
	{
		return self::scoped('lr', ['se_absence', $absenceId]);
	}

	public static function timeTrackingUser(string $userId): string
	{
		return 'azc/tt/' . md5($userId);
	}

	public static function absenceUser(string $userId): string
	{
		return 'azc/ab/' . md5($userId);
	}

	public static function monthClosure(string $userId, int $year, int $month): string
	{
		return 'azc/mc/' . md5(sprintf('%s|%04d|%02d', $userId, $year, $month));
	}

	public static function entitlementSnapshot(string $userId, int $year, string $asOfYmd): string
	{
		return 'azc/es/' . md5($userId . '|' . $year . '|' . $asOfYmd);
	}

	/**
	 * Overtime Saldo ledger. Serializes read-balance → compute → insert across
	 * at_ot_adj adjustments and at_ot_payout payouts so the recorded
	 * balance_before/balance_after chain can never interleave into a lie.
	 */
	public static function overtimeBalance(string $userId): string
	{
		return 'azc/ot/' . md5($userId);
	}

	/**
	 * Admin employee-profile update. Serializes the findEditableByUser →
	 * update/insert sequence so two concurrent saves cannot insert duplicate
	 * overlapping working-time-model assignments (no unique index backstops it).
	 */
	public static function userProfile(string $userId): string
	{
		return 'azc/up/' . md5($userId);
	}

	/**
	 * Working-time-model catalog. Model delete takes LOCK_EXCLUSIVE;
	 * assignment writes take LOCK_SHARED so a delete can never interleave
	 * between the "no assignments" check and an in-flight assignment insert
	 * (at_user_models.working_time_model_id has no FK). Same shape as
	 * {@see absenceTypeCatalog()}.
	 */
	public static function workingTimeModelCatalog(): string
	{
		return 'azc/wtm/catalog';
	}

	public static function vacationUnitMigration(): string
	{
		return 'azc/vu/migrate';
	}

	/** Exclusive lock while saving / sealing premium policy (NN-06). */
	public static function premiumPolicy(): string
	{
		return 'azc/pp/policy';
	}

	/** Exclusive lock while flipping vacation year mode + refreshing open allocations (AC-101.4). */
	public static function vacationYearMode(): string
	{
		return 'azc/vy/mode';
	}

	/**
	 * Custom absence-type catalog. Admin writes (create/update/delete) take
	 * LOCK_EXCLUSIVE; absence create/update paths take LOCK_SHARED so a
	 * hard-deleted type can never be referenced by an absence that validated
	 * against the pre-delete catalog (count→delete TOCTOU).
	 */
	public static function absenceTypeCatalog(): string
	{
		return 'azc/at/catalog';
	}

	/** Serializes upgrade backup/restore against concurrent mutations. */
	public static function upgradeBackup(): string
	{
		return 'arbeitszeitcheck-upgrade-backup';
	}

	/** Kiosk terminal pairing gate (one pairing handshake at a time). */
	public static function kioskPairing(): string
	{
		return 'arbeitszeitcheck/kiosk_pair';
	}

	/** Mobile seat capacity gate (assign/release must not overbook). */
	public static function mobileSeatCapacity(): string
	{
		return 'arbeitszeitcheck/mobile_seat_capacity';
	}

	/** Terminal device slot capacity gate. */
	public static function terminalDeviceSlot(): string
	{
		return 'arbeitszeitcheck/terminal_device_slot';
	}

	/** Kiosk RFID salt bootstrap (single initial writer). */
	public static function kioskRfidSalt(): string
	{
		return 'arbeitszeitcheck/kiosk_rfid_salt';
	}

	/**
	 * Per-user work-location day override (user_month family in ORDER).
	 * Serializes read → CAS-check → insert/update against the
	 * `at_wl_day_user_uq` unique index so concurrent first-writes converge.
	 */
	public static function workLocationDay(string $userId, string $localDate): string
	{
		return self::scoped('um', [$userId, 'wlday', $localDate]);
	}

	/** Layered vacation defaults — organisation-level write gate. */
	public static function vacationLayeredOrg(): string
	{
		return 'arbeitszeitcheck/layered-vacation/org';
	}

	/** Layered vacation defaults — per working-time-model write gate. */
	public static function vacationLayeredModel(int $workingTimeModelId): string
	{
		return 'arbeitszeitcheck/layered-vacation/model/' . $workingTimeModelId;
	}

	/** Layered vacation defaults — per team write gate. */
	public static function vacationLayeredTeam(int $teamId): string
	{
		return 'arbeitszeitcheck/layered-vacation/team/' . $teamId;
	}

	/**
	 * Business-trip rule catalog (catalog_schema tier). All rule-set /
	 * assignment writes serialize here first — spec lock order:
	 * rule catalog → user-month → trip → linked time entries → ledger.
	 */
	public static function businessTripRuleCatalog(): string
	{
		return self::scoped('pc', ['bt_rule_catalog']);
	}

	/** Single business-trip row (domain_aggregate tier). */
	public static function businessTripTrip(int|string $tripId): string
	{
		return self::scoped('da', ['bt_trip', $tripId]);
	}

	/** Linked time-entry set of a trip (linked_records tier). */
	public static function businessTripLinkedEntries(int|string $tripId): string
	{
		return self::scoped('lr', ['bt_links', $tripId]);
	}

	/** Business-trip credit ledger (domain_ledger tier, last). */
	public static function businessTripLedger(string $userId, int|string $tripId): string
	{
		return self::scoped('dl', ['bt_ledger', $userId, $tripId]);
	}

	/** Per user-month trip calculation gate (user_month tier). */
	public static function businessTripUserMonth(string $userId, string $month): string
	{
		return self::scoped('um', [$userId, 'bt', $month]);
	}

	private static function scoped(string $scope, array $parts): string
	{
		foreach ($parts as $part) {
			if (trim((string)$part) === '') {
				throw new \InvalidArgumentException('Lock key parts must not be empty.');
			}
		}
		return 'azc/' . $scope . '/' . md5(implode("\0", array_map('strval', $parts)));
	}
}
