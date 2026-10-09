<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service\Kiosk;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Db\KioskTerminalMapper;
use OCA\ArbeitszeitCheck\Kiosk\KioskCrypto;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCA\ArbeitszeitCheck\Service\TerminalDeviceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;

class KioskTerminalService
{
	private const PAIR_LOCK = 'arbeitszeitcheck/kiosk_pair';

	public function __construct(
		private readonly KioskTerminalMapper $terminalMapper,
		private readonly TerminalDeviceService $terminalDeviceService,
		private readonly LicenseService $licenseService,
		private readonly ITimeFactory $timeFactory,
		private readonly ILockingProvider $lockingProvider,
	) {
	}

	/**
	 * @return array{terminal: KioskTerminal, pairingCode: string, pairingExpiresAt: string}
	 */
	public function createPendingTerminal(string $label, string $createdBy): array
	{
		$this->expireStalePendingTerminals();
		if (!$this->licenseService->isTerminalPlanActive()) {
			throw new KioskException('TERMINAL_LICENSE_REQUIRED');
		}
		if (!$this->terminalDeviceService->hasCapacity()) {
			throw new KioskException('TERMINAL_DEVICE_LIMIT_REACHED');
		}

		$pairingCode = KioskCrypto::generatePairingCode();
		$terminalId = $this->generateUuid();
		$now = $this->timeFactory->getDateTime();
		$expires = (clone $now)->modify('+' . Constants::KIOSK_PAIRING_TTL_SECONDS . ' seconds');

		$device = $this->terminalDeviceService->reserveSlot($label);
		try {
			$this->terminalDeviceService->linkToKioskTerminal($device, $terminalId);

			$terminal = new KioskTerminal();
			$terminal->setTerminalId($terminalId);
			$terminal->setLabel($label);
			$terminal->setTokenHash(KioskCrypto::hashSecret(KioskCrypto::generateToken()));
			$terminal->setPairingCodeHash(KioskCrypto::hashSecret($pairingCode));
			$terminal->setPairingExpiresAt($expires);
			$terminal->setStatus('pending');
			$terminal->setCreatedBy($createdBy);
			$terminal->setCreatedAt($now);
			$this->terminalMapper->insert($terminal);
		} catch (\Throwable $e) {
			$this->terminalDeviceService->revokeDevice($device);
			throw $e;
		}

		return [
			'terminal' => $terminal,
			'pairingCode' => $pairingCode,
			'pairingExpiresAt' => $expires->format('c'),
		];
	}

	/**
	 * @return array{terminalId: string, terminalToken: string, label: string}
	 */
	public function pair(string $pairingCode, string $label): array
	{
		$this->expireStalePendingTerminals();
		if (!$this->licenseService->isTerminalPlanActive()) {
			throw new KioskException('TERMINAL_LICENSE_REQUIRED');
		}

		$this->lockingProvider->acquireLock(self::PAIR_LOCK, ILockingProvider::LOCK_EXCLUSIVE, 'Kiosk terminal pairing');
		try {
			$now = $this->timeFactory->getDateTime();
			$pending = $this->findPendingByPairingCode($pairingCode, $now);
			if ($pending === null) {
				throw new KioskException('PAIRING_CODE_INVALID');
			}

			$terminalId = $pending->getTerminalId();
			$existingDevice = $this->terminalDeviceService->findByKioskTerminalId($terminalId);
			$newlyReserved = null;
			if ($existingDevice === null) {
				$newlyReserved = $this->terminalDeviceService->reserveSlot($pending->getLabel());
			}

			$terminalToken = KioskCrypto::generateToken();
			$claimed = $this->terminalMapper->claimPendingAsActive(
				$terminalId,
				KioskCrypto::hashSecret($terminalToken),
				$now,
				$label !== '' ? $label : null,
			);
			if ($claimed !== 1) {
				if ($newlyReserved !== null) {
					$this->terminalDeviceService->revokeDevice($newlyReserved);
				}
				throw new KioskException('PAIRING_CODE_INVALID');
			}

			try {
				if ($newlyReserved !== null) {
					$this->terminalDeviceService->linkToKioskTerminal($newlyReserved, $terminalId);
				}
			} catch (\Throwable $e) {
				if ($newlyReserved !== null) {
					$this->terminalDeviceService->revokeDevice($newlyReserved);
				}
				// Pairing code was consumed; revoke the terminal so capacity is not stuck.
				$this->revoke($terminalId);
				throw $e;
			}

			$fresh = $this->terminalMapper->findByTerminalId($terminalId);

			return [
				'terminalId' => $terminalId,
				'terminalToken' => $terminalToken,
				'label' => $fresh !== null ? $fresh->getLabel() : $pending->getLabel(),
			];
		} finally {
			$this->lockingProvider->releaseLock(self::PAIR_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	public function validateTerminalToken(string $terminalId, string $token): ?KioskTerminal
	{
		if ($terminalId === '' || $token === '') {
			return null;
		}
		$terminal = $this->terminalMapper->findByTerminalId($terminalId);
		if ($terminal === null || $terminal->getStatus() !== 'active') {
			return null;
		}
		if ($terminal->getTokenHash() === '' || !KioskCrypto::verifySecret($token, $terminal->getTokenHash())) {
			return null;
		}
		return $terminal;
	}

	public function recordHeartbeat(KioskTerminal $terminal): void
	{
		$terminal->setLastSeenAt($this->timeFactory->getDateTime());
		$this->terminalMapper->update($terminal);
	}

	/** Existence check for callers that must distinguish missing terminals. */
	public function findByTerminalId(string $terminalId): ?KioskTerminal
	{
		return $this->terminalMapper->findByTerminalId($terminalId);
	}

	public function revoke(string $terminalId): void
	{
		$terminal = $this->terminalMapper->findByTerminalId($terminalId);
		if ($terminal === null) {
			return;
		}
		$terminal->setStatus('revoked');
		$terminal->setTokenHash(KioskCrypto::hashSecret(KioskCrypto::generateToken()));
		$terminal->setPairingCodeHash(null);
		$terminal->setPairingExpiresAt(null);
		$this->terminalMapper->update($terminal);
		$this->terminalDeviceService->revokeByKioskTerminalId($terminalId);
	}

	/** @return KioskTerminal[] */
	public function listTerminals(): array
	{
		$this->expireStalePendingTerminals();
		return array_merge(
			$this->terminalMapper->findAllActive(),
			$this->terminalMapper->findPendingPairing(),
		);
	}

	public function expireStalePendingTerminals(): int
	{
		$now = $this->timeFactory->getDateTime();
		$expired = 0;
		foreach ($this->terminalMapper->findPendingPairing() as $terminal) {
			$expiresAt = $terminal->getPairingExpiresAt();
			if ($expiresAt === null || $expiresAt < $now) {
				$this->expirePendingTerminal($terminal);
				$expired++;
			}
		}
		return $expired;
	}

	/** Revoke newest active terminals when the license device limit shrinks. */
	public function trimActiveToDeviceLimit(int $limit): int
	{
		$limit = max(0, $limit);
		$devices = $this->terminalDeviceService->findAllActiveDevices();
		if (count($devices) <= $limit) {
			return 0;
		}
		$toRevoke = array_slice($devices, $limit);
		$revoked = 0;
		foreach ($toRevoke as $device) {
			$kioskTerminalId = $device->getKioskTerminalId();
			if ($kioskTerminalId !== null && $kioskTerminalId !== '') {
				$this->revoke($kioskTerminalId);
			} else {
				$this->terminalDeviceService->revokeDevice($device);
			}
			$revoked++;
		}
		return $revoked;
	}

	public function revokeAllActiveAndPending(): int
	{
		$revoked = 0;
		foreach ($this->terminalMapper->findAllActive() as $terminal) {
			$this->revoke($terminal->getTerminalId());
			$revoked++;
		}
		foreach ($this->terminalMapper->findPendingPairing() as $terminal) {
			$this->expirePendingTerminal($terminal);
			$revoked++;
		}
		foreach ($this->terminalDeviceService->findAllActiveDevices() as $device) {
			$this->terminalDeviceService->revokeDevice($device);
		}
		return $revoked;
	}

	private function expirePendingTerminal(KioskTerminal $terminal): void
	{
		$this->terminalDeviceService->revokeByKioskTerminalId($terminal->getTerminalId());
		$terminal->setStatus('revoked');
		$terminal->setPairingCodeHash(null);
		$terminal->setPairingExpiresAt(null);
		$terminal->setTokenHash(KioskCrypto::hashSecret(KioskCrypto::generateToken()));
		$this->terminalMapper->update($terminal);
	}

	private function findPendingByPairingCode(string $code, \DateTimeInterface $now): ?KioskTerminal
	{
		$normalized = strtoupper(trim($code));
		foreach ($this->terminalMapper->findPendingPairing() as $terminal) {
			$expires = $terminal->getPairingExpiresAt();
			if ($expires === null || $expires < $now) {
				continue;
			}
			$hash = $terminal->getPairingCodeHash();
			if ($hash !== null && KioskCrypto::verifySecret($normalized, $hash)) {
				return $terminal;
			}
		}
		return null;
	}

	private function generateUuid(): string
	{
		$data = random_bytes(16);
		$data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
		$data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
	}
}
