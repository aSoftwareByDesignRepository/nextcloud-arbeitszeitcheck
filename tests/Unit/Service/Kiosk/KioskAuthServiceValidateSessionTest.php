<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\KioskCredMapper;
use OCA\ArbeitszeitCheck\Db\KioskEnrollmentMapper;
use OCA\ArbeitszeitCheck\Db\KioskSession;
use OCA\ArbeitszeitCheck\Db\KioskSessionMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskAuthService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskCredentialService;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskSettingsService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

/**
 * Used sessions must surface as KIOSK_SESSION_USED (not INVALID) so a foyer
 * tablet that timed out after claim-before-mutate can treat the retry as success.
 */
class KioskAuthServiceValidateSessionTest extends TestCase
{
	private function makeService(KioskSessionMapper $sessionMapper): KioskAuthService
	{
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new \DateTime('2026-08-31 12:00:00'));

		return new KioskAuthService(
			$this->createMock(KioskCredentialService::class),
			$this->createMock(KioskCredMapper::class),
			$this->createMock(KioskEnrollmentMapper::class),
			$sessionMapper,
			$this->createMock(KioskSettingsService::class),
			$this->createMock(PermissionService::class),
			$this->createMock(TimeCaptureMethodService::class),
			$this->createMock(TimeTrackingService::class),
			$this->createMock(IUserManager::class),
			$this->createMock(AuditLogMapper::class),
			$time,
			$this->createMock(ILockingProvider::class),
		);
	}

	public function testValidateSessionReturnsUnusedSession(): void
	{
		$session = new KioskSession();
		$session->setId(1);
		$session->setUserId('alice');

		$sessionMapper = $this->createMock(KioskSessionMapper::class);
		$sessionMapper->expects($this->once())
			->method('findValidSession')
			->with('term-1', 'tok', $this->isInstanceOf(\DateTimeInterface::class))
			->willReturn($session);
		$sessionMapper->expects($this->never())->method('findUsedSession');

		$terminal = new KioskTerminal();
		$terminal->setTerminalId('term-1');

		$out = $this->makeService($sessionMapper)->validateSession($terminal, 'tok');
		$this->assertSame($session, $out);
	}

	public function testValidateSessionThrowsUsedWhenClaimAlreadyConsumed(): void
	{
		$used = new KioskSession();
		$used->setId(2);
		$used->setUserId('alice');

		$sessionMapper = $this->createMock(KioskSessionMapper::class);
		$sessionMapper->method('findValidSession')->willReturn(null);
		$sessionMapper->expects($this->once())
			->method('findUsedSession')
			->with('term-1', 'tok', $this->isInstanceOf(\DateTimeInterface::class))
			->willReturn($used);

		$terminal = new KioskTerminal();
		$terminal->setTerminalId('term-1');

		try {
			$this->makeService($sessionMapper)->validateSession($terminal, 'tok');
			$this->fail('Expected KioskException');
		} catch (KioskException $e) {
			$this->assertSame('KIOSK_SESSION_USED', $e->getErrorCode());
		}
	}

	public function testValidateSessionThrowsInvalidWhenUnknownOrExpired(): void
	{
		$sessionMapper = $this->createMock(KioskSessionMapper::class);
		$sessionMapper->method('findValidSession')->willReturn(null);
		$sessionMapper->method('findUsedSession')->willReturn(null);

		$terminal = new KioskTerminal();
		$terminal->setTerminalId('term-1');

		try {
			$this->makeService($sessionMapper)->validateSession($terminal, 'bogus');
			$this->fail('Expected KioskException');
		} catch (KioskException $e) {
			$this->assertSame('KIOSK_SESSION_INVALID', $e->getErrorCode());
		}
	}
}
