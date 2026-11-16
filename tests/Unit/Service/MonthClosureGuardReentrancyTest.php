<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\MonthClosureGuard;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;

/**
 * The DB locking provider is not reentrant: a nested writer (e.g. the
 * paid-break recompute inside a month-closure-locked entry write) that
 * re-acquires an already-held key must share the outer hold instead of
 * deadlocking the request — otherwise the outer insert commits and the
 * inner acquire still throws, surfacing a bogus 500 over a persisted row.
 */
class MonthClosureGuardReentrancyTest extends TestCase
{
	public function testNestedAcquireOfSameKeyAcquiresOnceAndReleasesAtOutermost(): void
	{
		$service = $this->createMock(MonthClosureService::class);
		$service->expects($this->once())
			->method('acquireMonthClosureTierLocks')
			->with($this->equalTo(['k1']));
		$service->expects($this->once())
			->method('releaseMonthClosureTierLocks')
			->with($this->equalTo(['k1']));

		$guard = new MonthClosureGuard($service);
		$guard->acquireMutableRangeLockKeys(['k1']);
		$guard->acquireMutableRangeLockKeys(['k1']); // nested — must not re-acquire
		$guard->releaseMutableRangeLocks(['k1']);     // inner release — still held
		$guard->releaseMutableRangeLocks(['k1']);     // outermost — releases the DB lock
	}

	public function testPartialOverlapAcquiresOnlyMissingKeys(): void
	{
		$service = $this->createMock(MonthClosureService::class);
		$acquired = [];
		$released = [];
		$service->method('acquireMonthClosureTierLocks')
			->willReturnCallback(static function (array $keys) use (&$acquired): void {
				$acquired[] = $keys;
			});
		$service->method('releaseMonthClosureTierLocks')
			->willReturnCallback(static function (array $keys) use (&$released): void {
				$released[] = $keys;
			});

		$guard = new MonthClosureGuard($service);
		$guard->acquireMutableRangeLockKeys(['k1']);
		$guard->acquireMutableRangeLockKeys(['k1', 'k2']);
		$guard->releaseMutableRangeLocks(['k1', 'k2']); // k2 free, k1 still held by outer
		$guard->releaseMutableRangeLocks(['k1']);

		$this->assertSame([['k1'], ['k2']], $acquired);
		$this->assertSame([['k2'], ['k1']], $released);
	}

	public function testFailedAcquireLeavesNoRefcountAndCanBeRetried(): void
	{
		$service = $this->createMock(MonthClosureService::class);
		$calls = 0;
		$service->method('acquireMonthClosureTierLocks')
			->willReturnCallback(static function () use (&$calls): void {
				$calls++;
				if ($calls === 1) {
					throw new LockedException('k1');
				}
			});

		$guard = new MonthClosureGuard($service);
		try {
			$guard->acquireMutableRangeLockKeys(['k1']);
			self::fail('expected LockedException');
		} catch (LockedException) {
		}
		// The failed attempt must not have marked the key as held — a retry
		// has to reach the service again instead of being skipped.
		$guard->acquireMutableRangeLockKeys(['k1']);
		$this->assertSame(2, $calls);
	}

	public function testAcquireMutableRangeLocksSharesTheSameRefcount(): void
	{
		$service = $this->createMock(MonthClosureService::class);
		$service->method('mutableRangeLockKeys')->willReturn(['k1']);
		$service->expects($this->once())->method('acquireMonthClosureTierLocks');

		$guard = new MonthClosureGuard($service);
		$guard->acquireMutableRangeLockKeys(['k1']);
		$keys = $guard->acquireMutableRangeLocks('u1', new \DateTime('2026-11-01'), new \DateTime('2026-11-30'));
		$this->assertSame(['k1'], $keys);
	}
}
