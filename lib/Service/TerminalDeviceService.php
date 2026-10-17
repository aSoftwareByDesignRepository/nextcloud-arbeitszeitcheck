<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\TerminalDevice;
use OCA\ArbeitszeitCheck\Db\TerminalDeviceMapper;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;

/**
 * Commercial terminal device slots (links to kiosk terminals in Track C).
 */
class TerminalDeviceService
{

	public function __construct(
		private readonly TerminalDeviceMapper $terminalDeviceMapper,
		private readonly LicenseService $licenseService,
		private readonly IDBConnection $db,
		private readonly ILockingProvider $lockingProvider,
	) {
	}

	public function getActiveCount(): int
	{
		return $this->terminalDeviceMapper->countActive();
	}

	public function getDeviceLimit(): int
	{
		return $this->licenseService->getTerminalDeviceLimit();
	}

	public function hasCapacity(): bool
	{
		return $this->getActiveCount() < $this->getDeviceLimit();
	}

	/**
	 * Reserve a commercial device slot with an exclusive capacity lock so two
	 * concurrent creates cannot both observe a free seat under READ COMMITTED.
	 */
	public function reserveSlot(string $label): TerminalDevice
	{
		$this->lockingProvider->acquireLock(DbLockKeys::terminalDeviceSlot(), ILockingProvider::LOCK_EXCLUSIVE, 'Terminal device capacity');
		try {
			$this->db->beginTransaction();
			try {
				if ($this->getActiveCount() >= $this->getDeviceLimit()) {
					throw new KioskException('TERMINAL_DEVICE_LIMIT_REACHED');
				}

				$device = new TerminalDevice();
				$device->setLabel($label);
				$device->setRegisteredAt(new \DateTime());
				$device->setRevoked(0);
				$device = $this->terminalDeviceMapper->insert($device);
				$this->db->commit();
				return $device;
			} catch (\Throwable $e) {
				$this->db->rollBack();
				throw $e;
			}
		} finally {
			$this->lockingProvider->releaseLock(DbLockKeys::terminalDeviceSlot(), ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function linkToKioskTerminal(TerminalDevice $device, string $kioskTerminalId): TerminalDevice
	{
		$device->setKioskTerminalId($kioskTerminalId);
		return $this->terminalDeviceMapper->update($device);
	}

	public function findByKioskTerminalId(string $kioskTerminalId): ?TerminalDevice
	{
		return $this->terminalDeviceMapper->findByKioskTerminalId($kioskTerminalId);
	}

	public function revokeByKioskTerminalId(string $kioskTerminalId): void
	{
		$device = $this->terminalDeviceMapper->findByKioskTerminalId($kioskTerminalId);
		if ($device === null) {
			return;
		}
		$this->revokeDevice($device);
	}

	/** @return TerminalDevice[] */
	public function findAllActiveDevices(): array
	{
		return $this->terminalDeviceMapper->findAllActive();
	}

	public function revokeDevice(TerminalDevice $device): void
	{
		$device->setRevoked(1);
		$this->terminalDeviceMapper->update($device);
	}
}
