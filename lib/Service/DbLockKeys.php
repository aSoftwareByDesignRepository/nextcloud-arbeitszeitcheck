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
}
