<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\KioskEnrollment;
use OCA\ArbeitszeitCheck\Db\KioskEnrollmentMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Db\KioskTerminalMapper;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskCredentialService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskDbLockPurger;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskEnrollmentService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskErrorMessages;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** In-memory ICache so scan errors actually persist across service calls. */
final class ArrayCache implements ICache
{
	/** @var array<string,mixed> */
	public array $data = [];

	public function get($key) { return $this->data[$key] ?? null; }
	public function set($key, $value, $ttl = null): bool { $this->data[$key] = $value; return true; }
	public function hasKey($key): bool { return isset($this->data[$key]); }
	public function remove($key): bool { unset($this->data[$key]); return true; }
	public function clear($prefix = ''): bool { $this->data = []; return true; }
	public static function isAvailable(): bool { return true; }
}

final class KioskEnrollmentServiceDeepTest extends TestCase
{
	private KioskEnrollmentMapper&MockObject $enrollmentMapper;
	private KioskTerminalMapper&MockObject $terminalMapper;
	private KioskCredentialService&MockObject $credentialService;
	private IUserManager&MockObject $userManager;
	private AuditLogMapper&MockObject $auditLogMapper;
	private ITimeFactory&MockObject $timeFactory;
	private ILockingProvider&MockObject $lockingProvider;
	private ICacheFactory&MockObject $cacheFactory;
	private KioskErrorMessages $errorMessages;
	private KioskDbLockPurger&MockObject $lockPurger;
	private KioskEnrollmentService $service;
	private ArrayCache $cache;

	private ?KioskEnrollment $activeEnrollment = null;
	private ?KioskEnrollment $latestCompleted = null;
	private ?KioskTerminal $terminal = null;
	private ?IUser $user = null;
	private bool $claimOk = true;
	private int $cancelForTerminalCalls = 0;

	protected function setUp(): void
	{
		parent::setUp();
		$this->enrollmentMapper = $this->createMock(KioskEnrollmentMapper::class);
		$this->terminalMapper = $this->createMock(KioskTerminalMapper::class);
		$this->credentialService = $this->createMock(KioskCredentialService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->auditLogMapper = $this->createMock(AuditLogMapper::class);
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);
		$this->cacheFactory = $this->createMock(ICacheFactory::class);
		$l10n = $this->createMock(\OCP\IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $t, array $p = []) => empty($p) ? $t : vsprintf($t, $p));
		$this->errorMessages = new KioskErrorMessages($l10n);
		$this->lockPurger = $this->createMock(KioskDbLockPurger::class);
		$this->cache = new ArrayCache();
		$this->cacheFactory->method('createDistributed')->willReturn($this->cache);

		$this->timeFactory->method('getDateTime')->willReturn(new \DateTime('2026-02-10 12:00:00'));
		$this->userManager->method('get')->willReturnCallback(function () {
			return $this->user;
		});
		$this->enrollmentMapper->method('findActiveByTerminalId')->willReturnCallback(fn () => $this->activeEnrollment);
		$this->enrollmentMapper->method('findLatestCompletedByTerminalId')->willReturnCallback(fn () => $this->latestCompleted);
		$this->enrollmentMapper->method('claimComplete')->willReturnCallback(fn () => $this->claimOk);
		$this->enrollmentMapper->method('cancelForTerminal')->willReturnCallback(function () {
			$this->cancelForTerminalCalls++;
			$this->activeEnrollment = null;
			return 1;
		});
		$this->enrollmentMapper->method('insert')->willReturnCallback(static function (KioskEnrollment $e) {
			$e->setId(42);
			return $e;
		});
		$this->enrollmentMapper->method('update')->willReturnArgument(0);
		$this->terminalMapper->method('findByTerminalId')->willReturnCallback(fn () => $this->terminal);

		$this->service = new KioskEnrollmentService(
			$this->enrollmentMapper,
			$this->terminalMapper,
			$this->credentialService,
			$this->userManager,
			$this->auditLogMapper,
			$this->timeFactory,
			$this->lockingProvider,
			$this->cacheFactory,
			$this->errorMessages,
			$this->lockPurger
		);
	}

	private function activeTerminal(): KioskTerminal
	{
		$t = new KioskTerminal();
		$t->setTerminalId('term-1');
		$t->setStatus('active');
		return $t;
	}

	private function enabledUser(string $uid = 'alice'): IUser&MockObject
	{
		$u = $this->createMock(IUser::class);
		$u->method('isEnabled')->willReturn(true);
		$u->method('getUID')->willReturn($uid);
		$u->method('getDisplayName')->willReturn('Alice A.');
		return $u;
	}

	private function pendingEnrollment(string $userId = 'alice'): KioskEnrollment
	{
		$e = new KioskEnrollment();
		$e->setId(42);
		$e->setTerminalId('term-1');
		$e->setUserId($userId);
		$e->setExpiresAt(new \DateTime('2026-02-10 12:05:00'));
		$e->setCreatedBy('admin');
		return $e;
	}

	// ---------------------------------------------------------------
	// start
	// ---------------------------------------------------------------

	public function testStartRejectsBlankIds(): void
	{
		$this->expectException(KioskException::class);
		$this->service->start('  ', 'term-1', 'admin');
	}

	public function testStartRejectsBlankTerminal(): void
	{
		$this->expectException(KioskException::class);
		$this->service->start('alice', ' ', 'admin');
	}

	public function testStartRejectsUnknownTerminal(): void
	{
		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_TERMINAL_NOT_FOUND');
		$this->service->start('alice', 'term-1', 'admin');
	}

	public function testStartRejectsInactiveTerminal(): void
	{
		$this->terminal = $this->activeTerminal();
		$this->terminal->setStatus('revoked');
		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_TERMINAL_NOT_ACTIVE');
		$this->service->start('alice', 'term-1', 'admin');
	}

	public function testStartRejectsMissingUser(): void
	{
		$this->terminal = $this->activeTerminal();
		$this->user = null;
		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_USER_NOT_ALLOWED');
		$this->service->start('alice', 'term-1', 'admin');
	}

	public function testStartCreatesEnrollmentUnderOrderedLocks(): void
	{
		$this->terminal = $this->activeTerminal();
		$this->user = $this->enabledUser();

		$acquired = [];
		$this->lockingProvider->method('acquireLock')->willReturnCallback(
			function ($key) use (&$acquired) { $acquired[] = $key; }
		);
		$released = [];
		$this->lockingProvider->method('releaseLock')->willReturnCallback(
			function ($key) use (&$released) { $released[] = $key; }
		);
		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'kiosk_enrollment_started', 'kiosk_enrollment', 42);

		$out = $this->service->start('alice', 'term-1', 'admin');
		$this->assertSame(42, $out['enrollmentId']);
		$this->assertSame('Alice A.', $out['displayName']);
		// user lock acquired before terminal lock (documented order)
		$this->assertSame(\OCA\ArbeitszeitCheck\Service\Kiosk\KioskEnrollmentLockKeys::forUser('alice'), $acquired[0]);
		$this->assertSame(\OCA\ArbeitszeitCheck\Service\Kiosk\KioskEnrollmentLockKeys::forTerminal('term-1'), $acquired[1]);
		$this->assertCount(2, $released);
	}

	public function testStartPurgesOrphanLocksAndRetriesOnBusy(): void
	{
		$this->terminal = $this->activeTerminal();
		$this->user = $this->enabledUser();

		$calls = 0;
		$this->lockingProvider->method('acquireLock')->willReturnCallback(
			function () use (&$calls) {
				$calls++;
				if ($calls === 1) {
					throw new KioskException('KIOSK_BUSY');
				}
			}
		);
		$this->lockPurger->expects($this->once())->method('purgeEnrollmentLocks')->with('alice', 'term-1');

		$out = $this->service->start('alice', 'term-1', 'admin');
		$this->assertSame(42, $out['enrollmentId']);
	}

	// ---------------------------------------------------------------
	// getStatus
	// ---------------------------------------------------------------

	public function testGetStatusPending(): void
	{
		$this->activeEnrollment = $this->pendingEnrollment();
		$this->user = $this->enabledUser();
		$out = $this->service->getStatus('term-1');
		$this->assertSame('pending', $out['status']);
		$this->assertSame('Alice A.', $out['displayName']);
		$this->assertSame('alice', $out['userId']);
	}

	public function testGetStatusCompletedWithinWindow(): void
	{
		$done = $this->pendingEnrollment();
		$done->setCompletedAt(new \DateTime('2026-02-10 11:59:00')); // 60s ago
		$this->latestCompleted = $done;
		$out = $this->service->getStatus('term-1');
		$this->assertSame('completed', $out['status']);
		$this->assertSame('alice', $out['userId']);
	}

	public function testGetStatusExpiredAfterWindow(): void
	{
		$done = $this->pendingEnrollment();
		$done->setCompletedAt(new \DateTime('2026-02-10 10:00:00')); // hours ago
		$this->latestCompleted = $done;
		$out = $this->service->getStatus('term-1');
		$this->assertSame('expired', $out['status']);
	}

	public function testGetStatusSurfacesRememberedScanError(): void
	{
		$this->activeEnrollment = $this->pendingEnrollment();
		$this->claimOk = false; // will throw ENROLLMENT_NOT_ACTIVE after claiming fails

		// completeScan failure records the error...
		try {
			$this->service->completeScan('term-1', 'A1B2', 'enroll-scan');
			$this->fail('expected KioskException');
		} catch (KioskException $e) {
			$this->assertSame('ENROLLMENT_NOT_ACTIVE', $e->getErrorCode());
		}
		// ...but claim-failure is thrown before assignRfid, so no scan error is stored;
		// store one explicitly and prove getStatus surfaces it.
		$this->cache->set('err_' . hash('sha256', 'term-1'), 'KIOSK_SCAN_FAILED');
		$out = $this->service->getStatus('term-1');
		$this->assertSame('pending', $out['status']);
		$this->assertSame('KIOSK_SCAN_FAILED', $out['lastError']);
		$this->assertNotEmpty($out['lastErrorMessage']);
	}

	// ---------------------------------------------------------------
	// completeScan
	// ---------------------------------------------------------------

	public function testCompleteScanThrowsWithoutActiveEnrollment(): void
	{
		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('ENROLLMENT_NOT_ACTIVE');
		$this->service->completeScan('term-1', 'A1B2');
	}

	public function testCompleteScanThrowsWhenClaimFails(): void
	{
		$this->activeEnrollment = $this->pendingEnrollment();
		$this->claimOk = false;
		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('ENROLLMENT_NOT_ACTIVE');
		$this->service->completeScan('term-1', 'A1B2');
	}

	public function testCompleteScanAssignsRfidAndAudits(): void
	{
		$this->activeEnrollment = $this->pendingEnrollment();
		$this->user = $this->enabledUser();
		$this->credentialService->method('assignRfid')->willReturn(['id' => 77]);

		$this->auditLogMapper->expects($this->once())->method('logAction')
			->with('alice', 'kiosk_credential_assigned', 'kiosk_cred', 77);

		$out = $this->service->completeScan('term-1', 'A1B2', 'enroll-scan');
		$this->assertSame('Alice A.', $out['displayName']);
	}

	public function testCompleteScanRollsBackClaimOnKioskError(): void
	{
		$this->activeEnrollment = $this->pendingEnrollment();
		$this->credentialService->method('assignRfid')
			->willThrowException(new KioskException('KIOSK_RFID_ALREADY_ASSIGNED'));

		$this->enrollmentMapper->expects($this->once())->method('update')
			->with($this->callback(static fn (KioskEnrollment $e): bool => $e->getCompletedAt() === null));

		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_RFID_ALREADY_ASSIGNED');
		try {
			$this->service->completeScan('term-1', 'A1B2');
		} finally {
			$this->assertSame(
				'KIOSK_RFID_ALREADY_ASSIGNED',
				$this->cache->get('err_' . hash('sha256', 'term-1'))
			);
		}
	}

	public function testCompleteScanMapsLockContentionToBusy(): void
	{
		$this->activeEnrollment = $this->pendingEnrollment();
		$this->credentialService->method('assignRfid')
			->willThrowException(new LockedException('lock'));

		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_BUSY');
		$this->service->completeScan('term-1', 'A1B2');
	}

	public function testCompleteScanMapsGenericFailureToScanFailed(): void
	{
		$this->activeEnrollment = $this->pendingEnrollment();
		$this->credentialService->method('assignRfid')
			->willThrowException(new \RuntimeException('db gone'));

		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('KIOSK_SCAN_FAILED');
		$this->service->completeScan('term-1', 'A1B2');
	}

	// ---------------------------------------------------------------
	// getConfigEnrollment
	// ---------------------------------------------------------------

	public function testGetConfigEnrollmentNullWhenInactive(): void
	{
		$this->assertNull($this->service->getConfigEnrollment('term-1'));
	}

	public function testGetConfigEnrollmentReturnsActiveSession(): void
	{
		$this->activeEnrollment = $this->pendingEnrollment();
		$this->user = $this->enabledUser();
		$out = $this->service->getConfigEnrollment('term-1');
		$this->assertTrue($out['active']);
		$this->assertSame('Alice A.', $out['displayName']);
		$this->assertSame('2026-02-10T12:05:00+00:00', $out['expiresAt']);
	}
}
