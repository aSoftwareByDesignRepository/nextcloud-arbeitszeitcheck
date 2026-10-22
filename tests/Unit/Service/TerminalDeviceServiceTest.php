<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\TerminalDevice;
use OCA\ArbeitszeitCheck\Db\TerminalDeviceMapper;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\TerminalDeviceService;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Covers TerminalDeviceService::reserveSlot — the capacity-guarded create path
 * (exclusive lock + transaction + limit check + rollback on failure).
 */
final class TerminalDeviceServiceTest extends TestCase
{
	private TerminalDeviceMapper&MockObject $mapper;
	private LicenseService&MockObject $licenseService;
	private IDBConnection&MockObject $db;
	private ILockingProvider&MockObject $lockingProvider;
	private TerminalDeviceService $service;

	private int $activeCount = 0;
	private int $deviceLimit = 10;

	protected function setUp(): void
	{
		parent::setUp();
		$this->mapper = $this->createMock(TerminalDeviceMapper::class);
		$this->licenseService = $this->createMock(LicenseService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->lockingProvider = $this->createMock(ILockingProvider::class);

		$this->mapper->method('countActive')->willReturnCallback(fn () => $this->activeCount);
		$this->licenseService->method('getTerminalDeviceLimit')->willReturnCallback(fn () => $this->deviceLimit);

		$this->service = new TerminalDeviceService(
			$this->mapper,
			$this->licenseService,
			$this->db,
			$this->lockingProvider
		);
	}

	public function testReserveSlotCreatesDeviceUnderLockAndTransaction(): void
	{
		$this->lockingProvider->expects($this->once())->method('acquireLock')
			->with('arbeitszeitcheck/terminal_device_slot', ILockingProvider::LOCK_EXCLUSIVE);
		$this->lockingProvider->expects($this->once())->method('releaseLock')
			->with('arbeitszeitcheck/terminal_device_slot', ILockingProvider::LOCK_EXCLUSIVE);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->db->expects($this->never())->method('rollBack');
		$this->mapper->expects($this->once())->method('insert')
			->with($this->callback(static fn (TerminalDevice $d): bool => $d->getLabel() === 'Lobby tablet' && $d->getRevoked() === 0))
			->willReturnArgument(0);

		$device = $this->service->reserveSlot('Lobby tablet');
		$this->assertSame('Lobby tablet', $device->getLabel());
	}

	public function testReserveSlotRejectsWhenLimitReached(): void
	{
		$this->activeCount = 10;
		$this->deviceLimit = 10;

		$this->mapper->expects($this->never())->method('insert');
		$this->db->expects($this->once())->method('rollBack');
		$this->lockingProvider->expects($this->once())->method('releaseLock');

		$this->expectException(KioskException::class);
		$this->expectExceptionMessage('TERMINAL_DEVICE_LIMIT_REACHED');
		$this->service->reserveSlot('Lobby tablet');
	}

	public function testReserveSlotRollsBackOnInsertFailure(): void
	{
		$this->mapper->method('insert')->willThrowException(new \RuntimeException('deadlock'));
		$this->db->expects($this->once())->method('rollBack');
		$this->lockingProvider->expects($this->once())->method('releaseLock');

		$this->expectException(\RuntimeException::class);
		$this->service->reserveSlot('Lobby tablet');
	}
}
