<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\KioskEnrollment;
use OCA\ArbeitszeitCheck\Db\KioskEnrollmentMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminalMapper;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskCredentialService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskDbLockPurger;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskEnrollmentLockKeys;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskEnrollmentService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskErrorMessages;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class KioskEnrollmentServiceCancelTest extends TestCase
{
	/** @var KioskEnrollmentMapper&MockObject */
	private KioskEnrollmentMapper $enrollmentMapper;
	/** @var AuditLogMapper&MockObject */
	private AuditLogMapper $auditLogMapper;
	/** @var ILockingProvider&MockObject */
	private ILockingProvider $lockingProvider;
	/** @var ITimeFactory&MockObject */
	private ITimeFactory $timeFactory;
	/** @var ICache&MockObject */
	private ICache $cache;
	/** @var KioskDbLockPurger&MockObject */
	private KioskDbLockPurger $lockPurger;

	private KioskEnrollmentService $service;

	protected function setUp(): void
	{
		parent::setUp();
		$this->enrollmentMapper = $this->createMock(KioskEnrollmentMapper::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->cache = $this->createMock(ICache::class);
		$this->lockPurger = $this->createMock(KioskDbLockPurger::class);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->cache);

		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-07-20 12:00:00'));

		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $params = []): string => $s);

		$this->service = new KioskEnrollmentService(
			$this->enrollmentMapper,
			$this->createMock(KioskTerminalMapper::class),
			$this->createMock(KioskCredentialService::class),
			$this->createMock(IUserManager::class),
			$this->auditLogMapper,
			$this->timeFactory,
			$this->lockingProvider,
			$cacheFactory,
			new KioskErrorMessages($l10n),
			$this->lockPurger,
		);
	}

	public function testCancelRejectsEmptyTerminalId(): void
	{
		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_TERMINAL_NOT_FOUND');
		$this->service->cancel('  ', 'admin');
	}

	public function testCancelDeletesActiveEnrollmentUnderOrderedLocks(): void
	{
		$enrollment = new KioskEnrollment();
		$enrollment->setId(42);
		$enrollment->setTerminalId('term-1');
		$enrollment->setUserId('alice');

		$this->enrollmentMapper->expects($this->exactly(2))
			->method('findActiveByTerminalId')
			->willReturn($enrollment);
		$this->enrollmentMapper->expects($this->once())
			->method('cancelForTerminal')
			->with('term-1')
			->willReturn(1);
		$this->cache->expects($this->once())->method('remove');

		$acquired = [];
		$this->lockingProvider->expects($this->exactly(2))
			->method('acquireLock')
			->willReturnCallback(function (string $key) use (&$acquired): void {
				$acquired[] = $key;
			});
		$this->lockingProvider->expects($this->exactly(2))->method('releaseLock');
		$this->lockPurger->expects($this->never())->method('purgeEnrollmentLocks');

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with(
				'alice',
				'kiosk_enrollment_cancelled',
				'kiosk_enrollment',
				42,
				null,
				['terminalId' => 'term-1'],
				'admin',
			);

		$result = $this->service->cancel('term-1', 'admin');
		$this->assertSame('cancelled', $result['status']);
		$this->assertSame(42, $result['enrollmentId']);
		$this->assertArrayNotHasKey('forced', $result);
		$this->assertCount(2, $acquired);
		$this->assertSame(KioskEnrollmentLockKeys::forUser('alice'), $acquired[0]);
		$this->assertSame(KioskEnrollmentLockKeys::forTerminal('term-1'), $acquired[1]);
		$this->assertLessThanOrEqual(64, strlen($acquired[0]));
		$this->assertLessThanOrEqual(64, strlen($acquired[1]));
	}

	public function testCancelWhenIdleReturnsAlreadyIdleWithoutFalseAudit(): void
	{
		$this->enrollmentMapper->expects($this->exactly(2))
			->method('findActiveByTerminalId')
			->willReturn(null);
		$this->enrollmentMapper->expects($this->once())
			->method('cancelForTerminal')
			->with('term-1')
			->willReturn(0);
		$this->enrollmentMapper->expects($this->once())
			->method('findLatestCompletedByTerminalId')
			->willReturn(null);

		$this->lockingProvider->expects($this->once())->method('acquireLock');
		$this->lockingProvider->expects($this->once())->method('releaseLock');
		$this->auditLogMapper->expects($this->never())->method('logAction');
		$this->lockPurger->expects($this->never())->method('purgeEnrollmentLocks');

		$result = $this->service->cancel('term-1', 'admin');
		$this->assertSame(['status' => 'already_idle'], $result);
	}

	public function testCancelWhenRecentlyCompletedReturnsAlreadyCompleted(): void
	{
		$completed = new KioskEnrollment();
		$completed->setTerminalId('term-1');
		$completed->setUserId('alice');
		$completed->setCompletedAt(new \DateTime('2026-07-20 11:59:30'));

		$this->enrollmentMapper->method('findActiveByTerminalId')->willReturn(null);
		$this->enrollmentMapper->expects($this->once())->method('cancelForTerminal')->willReturn(0);
		$this->enrollmentMapper->expects($this->once())
			->method('findLatestCompletedByTerminalId')
			->willReturn($completed);

		$this->lockingProvider->method('acquireLock');
		$this->lockingProvider->method('releaseLock');
		$this->auditLogMapper->expects($this->never())->method('logAction');

		$result = $this->service->cancel('term-1', 'admin');
		$this->assertSame(['status' => 'already_completed'], $result);
	}

	public function testCancelForceAbortsWhenLocksStayBusy(): void
	{
		$enrollment = new KioskEnrollment();
		$enrollment->setId(7);
		$enrollment->setTerminalId('term-1');
		$enrollment->setUserId('alice');

		$this->enrollmentMapper->method('findActiveByTerminalId')->willReturn($enrollment);
		$this->enrollmentMapper->expects($this->once())
			->method('findIncompleteByTerminalId')
			->with('term-1')
			->willReturn($enrollment);
		$this->enrollmentMapper->expects($this->once())
			->method('cancelForTerminal')
			->with('term-1')
			->willReturn(1);

		// Exhaust retries on the user lock (8 outer attempts × 2 provider
		// calls each — DbLockGuard sweeps stale rows and retries once).
		$this->lockingProvider->expects($this->exactly(16))
			->method('acquireLock')
			->willThrowException(new LockedException('held'));

		$this->lockPurger->expects($this->once())
			->method('purgeEnrollmentLocks')
			->with('alice', 'term-1');
		$this->lockPurger->expects($this->once())
			->method('purgeCredentialLocks')
			->with('alice');
		$this->cache->expects($this->once())->method('remove');

		$this->auditLogMapper->expects($this->once())
			->method('logAction')
			->with(
				'alice',
				'kiosk_enrollment_cancelled',
				'kiosk_enrollment',
				7,
				null,
				['terminalId' => 'term-1', 'forced' => true],
				'admin',
			);

		$result = $this->service->cancel('term-1', 'admin');
		$this->assertSame('cancelled', $result['status']);
		$this->assertTrue($result['forced']);
		$this->assertSame(7, $result['enrollmentId']);
	}
}
