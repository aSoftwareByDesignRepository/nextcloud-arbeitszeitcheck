<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\KioskCred;
use OCA\ArbeitszeitCheck\Db\KioskCredMapper;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskCredentialService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskDbLockPurger;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskSettingsService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Lock recovery must retry live contention away before it ever purges —
 * purging a *live* lock would break per-user serialization (two concurrent
 * PIN generates / RFID assigns racing unique(user_id, type)).
 */
class KioskCredentialServiceLockRecoveryTest extends TestCase
{
	/** @var KioskCredMapper&MockObject */
	private KioskCredMapper $credMapper;
	/** @var KioskSettingsService&MockObject */
	private KioskSettingsService $settingsService;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $lockingProvider;
	/** @var KioskDbLockPurger&MockObject */
	private KioskDbLockPurger $lockPurger;

	private KioskCredentialService $service;

	protected function setUp(): void
	{
		$this->credMapper = $this->createMock(KioskCredMapper::class);
		$this->settingsService = $this->createMock(KioskSettingsService::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->lockPurger = $this->createMock(KioskDbLockPurger::class);

		$this->settingsService->method('isUserKioskAllowed')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$timeFactory = $this->createMock(ITimeFactory::class);
		$timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-07-20 12:00:00'));

		$this->service = new KioskCredentialService(
			$this->credMapper,
			$this->settingsService,
			$userManager,
			$this->createMock(AuditLogMapper::class),
			$timeFactory,
			$this->lockingProvider,
			$this->lockPurger,
		);
	}

	public function testGeneratePinRetriesLiveContentionWithoutPurging(): void
	{
		// Live holder frees the lock before retries are exhausted → no purge.
		$calls = 0;
		$this->lockingProvider->method('acquireLock')
			->willReturnCallback(function () use (&$calls): void {
				$calls++;
				if ($calls < 3) {
					throw new LockedException('busy');
				}
			});
		$this->lockPurger->expects($this->never())->method('purgeCredentialLocks');

		$this->credMapper->method('findByUserAndType')->willReturn(null);
		$this->credMapper->method('insert')->willReturnCallback(static function (KioskCred $cred): KioskCred {
			$cred->setId(11);
			return $cred;
		});

		$result = $this->service->generatePin('alice', 'admin');
		$this->assertSame(11, $result['id']);
		$this->assertMatchesRegularExpression('/^\d{6}$/', $result['pin']);
		$this->assertSame(3, $calls);
	}

	public function testGeneratePinPurgesOrphanLocksOnlyAfterRetriesExhausted(): void
	{
		// Orphan lock: 8 retry attempts fail, purge once, final attempt succeeds.
		// DbLockGuard retries once per acquire call → each of the service's
		// 8 outer retries costs 2 provider attempts; purge fires after all
		// outer retries are exhausted, then the recovery acquire succeeds.
		$calls = 0;
		$this->lockingProvider->method('acquireLock')
			->willReturnCallback(function () use (&$calls): void {
				$calls++;
				if ($calls <= 16) {
					throw new LockedException('orphan');
				}
			});
		$this->lockPurger->expects($this->once())
			->method('purgeCredentialLocks')
			->with('alice');

		$this->credMapper->method('findByUserAndType')->willReturn(null);
		$this->credMapper->method('insert')->willReturnCallback(static function (KioskCred $cred): KioskCred {
			$cred->setId(12);
			return $cred;
		});

		$result = $this->service->generatePin('alice', 'admin');
		$this->assertSame(12, $result['id']);
		$this->assertSame(17, $calls);
	}

	public function testGeneratePinSurfacesBusyWhenLockStaysHeldEvenAfterPurge(): void
	{
		$this->lockingProvider->method('acquireLock')
			->willThrowException(new LockedException('still busy'));
		$this->lockPurger->expects($this->once())->method('purgeCredentialLocks');
		$this->credMapper->expects($this->never())->method('insert');

		try {
			$this->service->generatePin('alice', 'admin');
			$this->fail('Expected KioskException');
		} catch (KioskException $e) {
			$this->assertSame('KIOSK_BUSY', $e->getErrorCode());
		}
	}

	public function testGeneratePinMapsUniqueConstraintRaceToBusyNotFiveHundred(): void
	{
		$this->credMapper->method('findByUserAndType')->willReturn(null);
		$violation = $this->createMock(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
		$this->credMapper->method('insert')->willThrowException($violation);
		$this->lockingProvider->expects($this->once())
			->method('releaseLock');

		try {
			$this->service->generatePin('alice', 'admin');
			$this->fail('Expected KioskException');
		} catch (KioskException $e) {
			$this->assertSame('KIOSK_BUSY', $e->getErrorCode());
		}
	}
}
