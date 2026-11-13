<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\DbLockGuard;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

final class DbLockGuardTest extends TestCase
{
	/** Provider stub exposing the DB-provider sweep hook + scripted failures. */
	private function provider(array &$calls, int $failTimes, bool $withSweep): ILockingProvider
	{
		return new class($calls, $failTimes, $withSweep) implements ILockingProvider {
			public int $sweepCount = 0;
			private int $attempts = 0;
			public function __construct(private array &$calls, private int $failTimes, private bool $withSweep) {}
			public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
				$this->calls[] = "acquire:$path:$type";
				$this->attempts++;
				if ($this->attempts <= $this->failTimes) {
					throw new LockedException($path);
				}
			}
			public function changeLock(string $path, int $targetType): void {}
			public function releaseLock(string $path, int $type): void {}
			public function isLocked(string $path, int $type): bool { return false; }
			public function releaseAll(): void {}
			public function cleanExpiredLocks(): void { $this->sweepCount++; $this->calls[] = 'sweep'; }
		};
	}

	public function testCleanAcquireDoesNotSweep(): void
	{
		$calls = [];
		$p = $this->provider($calls, 0, true);
		DbLockGuard::acquire($p, 'azc/x', ILockingProvider::LOCK_EXCLUSIVE, 'lbl');
		$this->assertSame(['acquire:azc/x:' . ILockingProvider::LOCK_EXCLUSIVE], $calls);
	}

	public function testLockedThenSweepsAndRetriesOnce(): void
	{
		$calls = [];
		$p = $this->provider($calls, 1, true);
		DbLockGuard::acquire($p, 'azc/wtm/catalog', ILockingProvider::LOCK_EXCLUSIVE);
		$this->assertSame([
			'acquire:azc/wtm/catalog:' . ILockingProvider::LOCK_EXCLUSIVE,
			'sweep',
			'acquire:azc/wtm/catalog:' . ILockingProvider::LOCK_EXCLUSIVE,
		], $calls);
	}

	public function testPersistentContentionStillThrowsLockedException(): void
	{
		$calls = [];
		$p = $this->provider($calls, 99, true);
		$this->expectException(LockedException::class);
		try {
			DbLockGuard::acquire($p, 'azc/x', ILockingProvider::LOCK_SHARED);
		} finally {
			// two acquire attempts, one sweep between them — never a loop
			$this->assertSame(3, count($calls));
		}
	}

	public function testProviderWithoutSweepHookRetriesOnce(): void
	{
		$calls = [];
		$p = new class($calls) implements ILockingProvider {
			private int $attempts = 0;
			public function __construct(private array &$calls) {}
			public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
				$this->calls[] = 'acquire';
				$this->attempts++;
				if ($this->attempts === 1) {
					throw new LockedException($path);
				}
			}
			public function changeLock(string $path, int $targetType): void {}
			public function releaseLock(string $path, int $type): void {}
			public function isLocked(string $path, int $type): bool { return false; }
			public function releaseAll(): void {}
		};
		DbLockGuard::acquire($p, 'azc/x', ILockingProvider::LOCK_SHARED);
		$this->assertSame(['acquire', 'acquire'], $calls);
	}

	public function testNonLockedExceptionPropagatesWithoutRetry(): void
	{
		$p = new class implements ILockingProvider {
			public int $attempts = 0;
			public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
				$this->attempts++;
				throw new \RuntimeException('db down');
			}
			public function changeLock(string $path, int $targetType): void {}
			public function releaseLock(string $path, int $type): void {}
			public function isLocked(string $path, int $type): bool { return false; }
			public function releaseAll(): void {}
			public function cleanExpiredLocks(): void { throw new \LogicException('must not run'); }
		};
		try {
			DbLockGuard::acquire($p, 'azc/x', ILockingProvider::LOCK_EXCLUSIVE);
			$this->fail('Expected RuntimeException');
		} catch (\RuntimeException) {
			// caught outside expectException so the attempt count is asserted —
			// a catch-Throwable mutant would sweep + retry before propagating.
			$this->assertSame(1, $p->attempts);
		}
	}

	public function testSweepFailureStillRetriesAcquire(): void
	{
		$calls = [];
		$p = new class($calls) implements ILockingProvider {
			private int $attempts = 0;
			public function __construct(private array &$calls) {}
			public function acquireLock(string $path, int $type, ?string $readablePath = null): void {
				$this->calls[] = 'acquire';
				if (++$this->attempts === 1) {
					throw new LockedException($path);
				}
			}
			public function changeLock(string $path, int $targetType): void {}
			public function releaseLock(string $path, int $type): void {}
			public function isLocked(string $path, int $type): bool { return false; }
			public function releaseAll(): void {}
			public function cleanExpiredLocks(): void { throw new \Exception('table gone'); }
		};
		DbLockGuard::acquire($p, 'azc/x', ILockingProvider::LOCK_SHARED);
		$this->assertSame(['acquire', 'acquire'], $calls);
	}
}
