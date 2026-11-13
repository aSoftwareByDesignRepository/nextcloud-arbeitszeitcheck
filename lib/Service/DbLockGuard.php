<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Lock acquisition with stale-row self-heal.
 *
 * DBLockingProvider persists lock rows in oc_file_locks. A killed request
 * (SIGKILL, OOM, container stop) skips the releaseAll shutdown hook, so its
 * row survives — and acquireLock()'s CAS never consults ttl, meaning one
 * leaked row can block every acquirer for up to the provider TTL (~1h).
 * The platform's own CleanupFileLocks cron sweeps rows where ttl < now on
 * a 5-minute interval, i.e. "expired" is the platform's dead-holder
 * criterion. We apply the same criterion on contention: sweep expired rows,
 * retry once. A row whose ttl has not elapsed may belong to a live holder,
 * so it is never touched — genuine contention still surfaces LockedException
 * to the caller exactly as before.
 */
final class DbLockGuard
{
	/**
	 * @throws LockedException still contended after the stale sweep
	 */
	public static function acquire(ILockingProvider $provider, string $key, int $type, ?string $label = null): void
	{
		try {
			$provider->acquireLock($key, $type, $label);
			return;
		} catch (LockedException) {
			// maybe a dead holder — sweep + retry once below
		}
		self::sweepExpired($provider);
		$provider->acquireLock($key, $type, $label);
	}

	private static function sweepExpired(ILockingProvider $provider): void
	{
		try {
			// cleanExpiredLocks() exists on OC\Lock\DBLockingProvider only —
			// memcache providers have no persisted rows to sweep.
			if (method_exists($provider, 'cleanExpiredLocks')) {
				$provider->cleanExpiredLocks();
			}
		} catch (\Throwable) {
			// best-effort; the retry decides
		}
	}
}
