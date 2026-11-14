<?php

declare(strict_types=1);

/**
 * Vacation mutations must refuse while days↔hours migration is pending / locked,
 * and while vacation year mode is flipping (lock order: year shared → migrate shared).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\DbLockKeys;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\VacationAllocationService;
use OCP\IDBConnection;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class AbsenceVacationUnitMigrateIdleGateTest extends TestCase
{
	private function service(IConfig $config, ILockingProvider $locking): AbsenceService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn ($s) => $s);

		return new AbsenceService(
			$this->createMock(AbsenceMapper::class),
			$this->createMock(AuditLogMapper::class),
			$this->createMock(UserSettingsMapper::class),
			$this->createMock(TeamResolverService::class),
			$this->createMock(UserWorkingTimeModelMapper::class),
			$config,
			$this->createMock(IDBConnection::class),
			$locking,
			$this->createMock(IUserManager::class),
			$l10n,
			$this->createMock(NotificationService::class),
			null,
			$this->createMock(HolidayService::class),
			$this->createMock(VacationYearBalanceMapper::class),
			$this->createMock(VacationAllocationService::class),
			null,
			$this->createMock(MonthClosureService::class),
		);
	}

	private function invokeAssert(AbsenceService $svc): void
	{
		$m = new ReflectionMethod(AbsenceService::class, 'assertVacationUnitMigrationIdle');
		$m->invoke($svc);
	}

	private function invokeReleaseShared(AbsenceService $svc): void
	{
		$m = new ReflectionMethod(AbsenceService::class, 'releaseVacationUnitMigrationSharedLock');
		$m->invoke($svc);
		$m2 = new ReflectionMethod(AbsenceService::class, 'releaseVacationYearModeSharedLock');
		$m2->invoke($svc);
	}

	public function testPendingFlagBlocksVacationMutations(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') {
				if ($key === Constants::CONFIG_VACATION_UNIT_MIGRATE_PENDING) {
					return '{"target":"hours"}';
				}
				return $default;
			}
		);
		$locking = $this->createMock(ILockingProvider::class);
		// Year shared is acquired first; migrate never reached when pending is set.
		$locking->expects($this->once())
			->method('acquireLock')
			->with(DbLockKeys::vacationYearMode(), ILockingProvider::LOCK_SHARED, $this->anything());
		$locking->expects($this->once())
			->method('releaseLock')
			->with(DbLockKeys::vacationYearMode(), ILockingProvider::LOCK_SHARED);

		$svc = $this->service($config, $locking);
		try {
			$this->invokeAssert($svc);
			$this->fail('Expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::VAC_UNIT_MIGRATE_IN_PROGRESS, $e->getReasonCode());
		}
	}

	public function testYearModeExclusiveBlocksVacationMutations(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->exactly(2)) // DbLockGuard retries once after stale-sweep
			->method('acquireLock')
			->with(DbLockKeys::vacationYearMode(), ILockingProvider::LOCK_SHARED, $this->anything())
			->willThrowException(new LockedException(DbLockKeys::vacationYearMode()));

		$svc = $this->service($config, $locking);
		try {
			$this->invokeAssert($svc);
			$this->fail('Expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame('VAC_YEAR_MODE_BUSY', $e->getReasonCode());
		}
	}

	public function testHeldMigrateLockBlocksVacationMutations(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$locking = $this->createMock(ILockingProvider::class);
		// 1 × vacationYearMode success + 2 × vacationUnitMigration attempts
		// (DbLockGuard sweeps stale rows and retries once before propagating).
		$locking->expects($this->exactly(3))
			->method('acquireLock')
			->willReturnCallback(static function (string $key, int $type, string $label = '') {
				unset($type, $label);
				if ($key === DbLockKeys::vacationYearMode()) {
					return;
				}
				if ($key === DbLockKeys::vacationUnitMigration()) {
					throw new LockedException(DbLockKeys::vacationUnitMigration());
				}
			});
		$locking->expects($this->once())
			->method('releaseLock')
			->with(DbLockKeys::vacationYearMode(), ILockingProvider::LOCK_SHARED);

		$svc = $this->service($config, $locking);
		try {
			$this->invokeAssert($svc);
			$this->fail('Expected BusinessRuleException');
		} catch (BusinessRuleException $e) {
			$this->assertSame(Constants::VAC_UNIT_MIGRATE_IN_PROGRESS, $e->getReasonCode());
		}
	}

	public function testIdleAcquiresSharedAndHoldsUntilRelease(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$locking = $this->createMock(ILockingProvider::class);
		$acquired = [];
		$locking->expects($this->exactly(2))
			->method('acquireLock')
			->willReturnCallback(static function (string $key, int $type) use (&$acquired): void {
				unset($type);
				$acquired[] = $key;
			});
		$released = [];
		$locking->expects($this->exactly(2))
			->method('releaseLock')
			->willReturnCallback(static function (string $key, int $type) use (&$released): void {
				unset($type);
				$released[] = $key;
			});

		$svc = $this->service($config, $locking);
		$this->invokeAssert($svc);
		$this->assertSame(
			[DbLockKeys::vacationYearMode(), DbLockKeys::vacationUnitMigration()],
			$acquired
		);
		$prop = new ReflectionProperty(AbsenceService::class, 'heldVacationUnitMigrateSharedLock');
		$this->assertSame(DbLockKeys::vacationUnitMigration(), $prop->getValue($svc));
		$yearProp = new ReflectionProperty(AbsenceService::class, 'heldVacationYearModeSharedLock');
		$this->assertSame(DbLockKeys::vacationYearMode(), $yearProp->getValue($svc));
		$this->invokeReleaseShared($svc);
		$this->assertNull($prop->getValue($svc));
		$this->assertNull($yearProp->getValue($svc));
		$this->assertSame(
			[DbLockKeys::vacationUnitMigration(), DbLockKeys::vacationYearMode()],
			$released
		);
	}

	public function testAssertIsIdempotentWhileHoldingShared(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$locking = $this->createMock(ILockingProvider::class);
		// First assert: year + migrate. Second assert: both already held → no further acquire.
		$locking->expects($this->exactly(2))
			->method('acquireLock');

		$svc = $this->service($config, $locking);
		$this->invokeAssert($svc);
		$this->invokeAssert($svc);
		$this->invokeReleaseShared($svc);
	}
}
