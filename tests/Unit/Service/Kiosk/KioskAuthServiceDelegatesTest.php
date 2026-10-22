<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\KioskCred;
use OCA\ArbeitszeitCheck\Db\KioskCredMapper;
use OCA\ArbeitszeitCheck\Db\KioskEnrollmentMapper;
use OCA\ArbeitszeitCheck\Db\KioskSessionMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskAuthService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskCredentialService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskSettingsService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Delegate/resolver coverage: assertUserEligibleForAction,
 * resolveUserIdFromRfid, listPinUsers and the private credential
 * resolvers via identify().
 */
final class KioskAuthServiceDelegatesTest extends TestCase
{
	private KioskCredentialService&MockObject $credService;
	private KioskCredMapper&MockObject $credMapper;
	private KioskEnrollmentMapper&MockObject $enrollmentMapper;
	private KioskSessionMapper&MockObject $sessionMapper;
	private KioskSettingsService&MockObject $settingsService;
	private PermissionService&MockObject $permission;
	private TimeCaptureMethodService&MockObject $timeCapture;
	private IUserManager&MockObject $userManager;
	private AuditLogMapper&MockObject $audit;
	private KioskAuthService $service;

	private bool $accessAllowed = true;
	private bool $kioskAllowed = true;

	protected function setUp(): void
	{
		parent::setUp();
		$this->credService = $this->createMock(KioskCredentialService::class);
		$this->credMapper = $this->createMock(KioskCredMapper::class);
		$this->enrollmentMapper = $this->createMock(KioskEnrollmentMapper::class);
		$this->sessionMapper = $this->createMock(KioskSessionMapper::class);
		$this->settingsService = $this->createMock(KioskSettingsService::class);
		$this->permission = $this->createMock(PermissionService::class);
		$this->timeCapture = $this->createMock(TimeCaptureMethodService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->audit = $this->createMock(AuditLogMapper::class);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-20 12:00:00'));

		$this->permission->method('isUserAllowedByAccessGroups')->willReturnCallback(
			fn () => $this->accessAllowed
		);
		$this->settingsService->method('isUserKioskAllowed')->willReturnCallback(
			fn () => $this->kioskAllowed
		);
		$this->enrollmentMapper->method('findActiveByTerminalId')->willReturn(null);

		$this->service = new KioskAuthService(
			$this->credService,
			$this->credMapper,
			$this->enrollmentMapper,
			$this->sessionMapper,
			$this->settingsService,
			$this->permission,
			$this->timeCapture,
			$this->createMock(TimeTrackingService::class),
			$this->userManager,
			$this->audit,
			$time,
			$this->createMock(ILockingProvider::class),
		);
	}

	private function terminal(): KioskTerminal
	{
		$t = new KioskTerminal();
		$t->setTerminalId('term-1');
		return $t;
	}

	public function testAssertUserEligibleForActionHappyAndDenied(): void
	{
		$this->credService->expects($this->exactly(2))->method('assertUserKioskAllowed')->with('alice');
		$this->service->assertUserEligibleForAction('alice');
		$this->accessAllowed = false;
		try {
			$this->service->assertUserEligibleForAction('alice');
			$this->fail('expected KioskException');
		} catch (KioskException $e) {
			$this->assertSame('KIOSK_USER_NOT_ALLOWED', $e->getMessage());
		}
	}

	public function testAssertUserEligibleMapsClockStampingForbidden(): void
	{
		$this->timeCapture->method('assertClockStampingAllowed')
			->willThrowException(new TimeCaptureForbiddenException(
				'off', TimeCaptureForbiddenException::CODE_CLOCK_STAMPING_DISABLED
			));
		try {
			$this->service->assertUserEligibleForAction('alice');
			$this->fail('expected KioskException');
		} catch (KioskException $e) {
			$this->assertSame('KIOSK_CLOCK_STAMPING_DISABLED', $e->getMessage());
		}
	}

	public function testResolveUserIdFromRfidReturnsUidAndResetsAttempts(): void
	{
		$cred = new KioskCred();
		$cred->setUserId('alice');
		$this->credService->method('findCredByRfidUid')->with('rfid-1')->willReturn($cred);
		$this->credService->method('isLocked')->willReturn(false);
		$this->credService->expects($this->once())->method('resetFailedAttempts')->with($cred);

		$this->assertSame('alice', $this->service->resolveUserIdFromRfid($this->terminal(), 'rfid-1'));
	}

	public function testResolveUserIdFromRfidUnknownAndLocked(): void
	{
		$this->credService->method('findCredByRfidUid')->willReturn(null);
		$this->audit->expects($this->once())->method('logAction')
			->with('', 'kiosk_identify_failed');
		try {
			$this->service->resolveUserIdFromRfid($this->terminal(), 'rfid-x');
			$this->fail('expected KioskException');
		} catch (KioskException $e) {
			$this->assertSame('KIOSK_CREDENTIAL_UNKNOWN', $e->getMessage());
		}

		$locked = new KioskCred();
		$locked->setUserId('alice');
		$credService2 = $this->createMock(KioskCredentialService::class);
		$credService2->method('findCredByRfidUid')->willReturn($locked);
		$credService2->method('isLocked')->willReturn(true);
		$service = $this->rebuild($credService2);
		try {
			$service->resolveUserIdFromRfid($this->terminal(), 'rfid-y');
			$this->fail('expected KioskException');
		} catch (KioskException $e) {
			$this->assertSame('PIN_LOCKED', $e->getMessage());
		}
	}

	public function testListPinUsersFiltersAndSorts(): void
	{
		$c1 = new KioskCred();
		$c1->setUserId('zeta');
		$c2 = new KioskCred();
		$c2->setUserId('anna');
		$c3 = new KioskCred();
		$c3->setUserId('ghost'); // no user object -> skipped
		$this->credMapper->method('findAllWithPin')->willReturn([$c1, $c2, $c3]);
		$this->permission->method('isUserAllowedByAccessGroups')->willReturn(true);

		$this->userManager->method('get')->willReturnCallback(function (string $uid) {
			if ($uid === 'ghost') {
				return null;
			}
			$u = $this->createMock(IUser::class);
			$u->method('getDisplayName')->willReturn($uid === 'anna' ? 'Anna A' : 'Zeta Z');
			return $u;
		});

		$out = $this->service->listPinUsers();
		$this->assertSame(['anna', 'zeta'], array_column($out, 'userId'));
		$this->assertSame('Anna A', $out[0]['displayName']);
	}

	public function testIdentifyPinRejectsUnknownCredential(): void
	{
		$this->credMapper->method('findByUserAndType')->willReturn(null);
		$this->audit->expects($this->once())->method('logAction');
		try {
			$this->service->identify($this->terminal(), 'pin', null, 'alice', '1234');
			$this->fail('expected KioskException');
		} catch (KioskException $e) {
			$this->assertSame('KIOSK_CREDENTIAL_UNKNOWN', $e->getMessage());
		}
	}

	public function testIdentifyPinRejectsWrongPinAndRecordsAttempt(): void
	{
		$cred = new KioskCred();
		$cred->setId(3);
		$cred->setUserId('alice');
		$this->credMapper->method('findByUserAndType')->willReturn($cred);
		$this->credService->method('isLocked')->willReturn(false);
		$this->credService->method('verifyPin')->willReturn(false);
		$this->credService->expects($this->once())->method('recordFailedAttempt')->with($cred);

		try {
			$this->service->identify($this->terminal(), 'pin', null, 'alice', '9999');
			$this->fail('expected KioskException');
		} catch (KioskException $e) {
			$this->assertSame('PIN_INVALID', $e->getMessage());
		}
	}

	private function rebuild(KioskCredentialService $credService): KioskAuthService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-07-20 12:00:00'));
		return new KioskAuthService(
			$credService, $this->credMapper, $this->enrollmentMapper, $this->sessionMapper,
			$this->settingsService, $this->permission, $this->timeCapture,
			$this->createMock(TimeTrackingService::class), $this->userManager,
			$this->audit, $time, $this->createMock(ILockingProvider::class),
		);
	}
}
