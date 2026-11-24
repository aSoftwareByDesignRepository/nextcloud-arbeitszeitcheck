<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCP\IL10N;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Request-scoped, reentrant guard for the profile-write lock stack.
 *
 * Two writers touch the same locks in one request — the composite profile
 * update (AdminUserProfileUpdateService) and the assignment writer
 * (UserWorkingTimeAssignmentService) nested inside it. DBLockingProvider
 * locks are NOT reentrant at the provider level, so the refcount lives here
 * in a shared request-singleton. Nullable provider only for legacy manual
 * constructions in tests; the DI container always injects it.
 *
 * Lock ordering (documented, must hold on every path):
 *   userProfile (exclusive) → wtmCatalog (shared) → closure tier locks
 */
final class UserProfileLockGuard
{
	/** @var array<string, int> userId => held count */
	private array $profileLocks = [];

	private int $catalogSharedHeld = 0;

	public function __construct(
		private readonly ?ILockingProvider $lockingProvider,
		private readonly IL10N $l10n,
	) {
	}

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 * @throws \OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException 409 when contended
	 */
	public function runProfileExclusive(string $userId, callable $callback): mixed
	{
		if ($this->lockingProvider === null || isset($this->profileLocks[$userId])) {
			$this->profileLocks[$userId] = ($this->profileLocks[$userId] ?? 0) + 1;
			try {
				return $callback();
			} finally {
				if (--$this->profileLocks[$userId] === 0) {
					unset($this->profileLocks[$userId]);
				}
			}
		}
		$key = DbLockKeys::userProfile($userId);
		try {
			DbLockGuard::acquire($this->lockingProvider, $key, ILockingProvider::LOCK_EXCLUSIVE, 'Employee profile update for user ' . $userId);
		} catch (LockedException) {
			throw new \OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException(
				$this->l10n->t('This employee profile is being updated by another request. Please wait a moment and try again.'),
				409
			);
		}
		$this->profileLocks[$userId] = 1;
		try {
			return $callback();
		} finally {
			unset($this->profileLocks[$userId]);
			$this->lockingProvider->releaseLock($key, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * Shared working-time-model-catalog lock: assignment writes hold it so a
	 * global model delete (exclusive twin) can never interleave between the
	 * "no assignments" check and an in-flight assignment insert.
	 *
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 * @throws \OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException 409 when contended
	 */
	public function runCatalogShared(callable $callback): mixed
	{
		if ($this->lockingProvider === null || $this->catalogSharedHeld > 0) {
			$this->catalogSharedHeld++;
			try {
				return $callback();
			} finally {
				$this->catalogSharedHeld--;
			}
		}
		try {
			DbLockGuard::acquire(
				$this->lockingProvider,
				DbLockKeys::workingTimeModelCatalog(),
				ILockingProvider::LOCK_SHARED,
				'Working-time-model assignment write',
			);
		} catch (LockedException) {
			throw new \OCA\ArbeitszeitCheck\Exception\AdminUserProfileUpdateException(
				$this->l10n->t('The working time model catalog is being updated. Please wait a moment and try again.'),
				409
			);
		}
		$this->catalogSharedHeld = 1;
		try {
			return $callback();
		} finally {
			$this->catalogSharedHeld = 0;
			$this->lockingProvider->releaseLock(DbLockKeys::workingTimeModelCatalog(), ILockingProvider::LOCK_SHARED);
		}
	}
}
