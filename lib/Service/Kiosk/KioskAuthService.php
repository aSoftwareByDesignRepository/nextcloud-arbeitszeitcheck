<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service\Kiosk;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\KioskCred;
use OCA\ArbeitszeitCheck\Db\KioskCredMapper;
use OCA\ArbeitszeitCheck\Db\KioskEnrollmentMapper;
use OCA\ArbeitszeitCheck\Db\KioskSession;
use OCA\ArbeitszeitCheck\Db\KioskSessionMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Kiosk\KioskCrypto;
use OCA\ArbeitszeitCheck\Exception\TimeCaptureForbiddenException;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class KioskAuthService
{
	public function __construct(
		private readonly KioskCredentialService $credentialService,
		private readonly KioskCredMapper $credMapper,
		private readonly KioskEnrollmentMapper $enrollmentMapper,
		private readonly KioskSessionMapper $sessionMapper,
		private readonly KioskSettingsService $settingsService,
		private readonly PermissionService $permissionService,
		private readonly TimeCaptureMethodService $timeCaptureMethodService,
		private readonly TimeTrackingService $timeTrackingService,
		private readonly IUserManager $userManager,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly ITimeFactory $timeFactory,
		private readonly ILockingProvider $lockingProvider,
	) {
	}

	/**
	 * @return array{sessionToken: string, userId: string, displayName: string, status: string, workedSecondsToday: int, allowedActions: list<string>, sessionExpiresAt: string}
	 */
	public function identify(KioskTerminal $terminal, string $method, ?string $rfidUid, ?string $userId, ?string $pin): array
	{
		$now = $this->timeFactory->getDateTime();
		$this->sessionMapper->deleteExpiredForTerminal($terminal->getTerminalId(), $now);

		// Fast path before credential work — tablet can switch to enrollment immediately.
		if ($method === 'rfid') {
			$this->assertNoActiveEnrollment($terminal->getTerminalId(), $now);
		}

		$cred = match ($method) {
			'rfid' => $this->resolveRfidCredential($rfidUid ?? ''),
			'pin' => $this->resolvePinCredential($userId ?? '', $pin ?? ''),
			default => throw new KioskException('KIOSK_CREDENTIAL_UNKNOWN'),
		};

		$userIdResolved = $cred->getUserId();
		$this->assertUserEligible($userIdResolved);

		$this->credentialService->resetFailedAttempts($cred);
		$sessionToken = KioskCrypto::generateToken();
		$now = $this->timeFactory->getDateTime();
		$expires = (clone $now)->modify('+' . Constants::KIOSK_SESSION_TTL_SECONDS . ' seconds');

		$session = new KioskSession();
		$session->setTerminalId($terminal->getTerminalId());
		$session->setUserId($userIdResolved);
		$session->setTokenHash(KioskCrypto::hashSecret($sessionToken));
		$session->setExpiresAt($expires);
		$session->setCreatedAt($now);

		// Final enrollment gate under the terminal lock closes the TOCTOU with start():
		// identify must not create a clock session while Admin is assigning a badge here.
		if ($method === 'rfid') {
			$this->assertNoActiveEnrollment($terminal->getTerminalId(), $now);
			$termLock = KioskEnrollmentLockKeys::forTerminal($terminal->getTerminalId());
			try {
				$this->lockingProvider->acquireLock($termLock, ILockingProvider::LOCK_EXCLUSIVE, 'Kiosk identify enrollment gate');
			} catch (LockedException) {
				throw new KioskException('ENROLLMENT_ACTIVE');
			}
			try {
				$nowGate = $this->timeFactory->getDateTime();
				$this->assertNoActiveEnrollment($terminal->getTerminalId(), $nowGate);
				// Invalidate prior unused sessions under the same lock as insert.
				$this->sessionMapper->deleteUnusedForTerminal($terminal->getTerminalId());
				$this->sessionMapper->insert($session);
			} finally {
				$this->lockingProvider->releaseLock($termLock, ILockingProvider::LOCK_EXCLUSIVE);
			}
		} else {
			// Same terminal lock as RFID so concurrent PIN identifies cannot
			// deleteUnused each other's brand-new unused session.
			$termLock = KioskEnrollmentLockKeys::forTerminal($terminal->getTerminalId());
			try {
				$this->lockingProvider->acquireLock($termLock, ILockingProvider::LOCK_EXCLUSIVE, 'Kiosk identify PIN session gate');
			} catch (LockedException) {
				throw new KioskException('KIOSK_BUSY');
			}
			try {
				$this->sessionMapper->deleteUnusedForTerminal($terminal->getTerminalId());
				$this->sessionMapper->insert($session);
			} finally {
				$this->lockingProvider->releaseLock($termLock, ILockingProvider::LOCK_EXCLUSIVE);
			}
		}

		$user = $this->userManager->get($userIdResolved);
		$statusData = $this->timeTrackingService->getStatus($userIdResolved);
		$kioskStatus = $this->mapKioskStatus((string)($statusData['status'] ?? 'clocked_out'));
		$allowedActions = $this->mapAllowedActions($kioskStatus);
		$workedSeconds = (int)round(((float)($statusData['working_today_hours'] ?? 0)) * 3600);

		$this->auditLogMapper->logAction($userIdResolved, 'kiosk_identify_ok', 'kiosk_session', $session->getId(), null, [
			'method' => $method,
			'terminalId' => $terminal->getTerminalId(),
		], $userIdResolved);

		return [
			'sessionToken' => $sessionToken,
			'userId' => $userIdResolved,
			'displayName' => $user !== null ? $user->getDisplayName() : $userIdResolved,
			'status' => $kioskStatus,
			'workedSecondsToday' => $workedSeconds,
			'allowedActions' => $allowedActions,
			// Absolute wall expiry (ATOM) — tablet countdown must never invent past this.
			'sessionExpiresAt' => $expires->format(\DateTimeInterface::ATOM),
		];
	}

	private function assertNoActiveEnrollment(string $terminalId, \DateTimeInterface $now): void
	{
		if ($this->enrollmentMapper->findActiveByTerminalId($terminalId, $now) !== null) {
			throw new KioskException('ENROLLMENT_ACTIVE');
		}
	}

	public function validateSession(KioskTerminal $terminal, string $sessionToken): KioskSession
	{
		$now = $this->timeFactory->getDateTime();
		$terminalId = $terminal->getTerminalId();
		$session = $this->sessionMapper->findValidSession($terminalId, $sessionToken, $now);
		if ($session !== null) {
			return $session;
		}
		// Already claimed (stamp may have landed while the tablet timed out) — not "expired".
		if ($this->sessionMapper->findUsedSession($terminalId, $sessionToken, $now) !== null) {
			throw new KioskException('KIOSK_SESSION_USED');
		}
		throw new KioskException('KIOSK_SESSION_INVALID');
	}

	public function assertUserEligibleForAction(string $userId): void
	{
		$this->assertUserEligible($userId);
	}

	/**
	 * Resolve RFID to a user id without creating a one-shot kiosk session (offline stamp replay).
	 */
	public function resolveUserIdFromRfid(KioskTerminal $terminal, string $rfidUid): string
	{
		$now = $this->timeFactory->getDateTime();
		$this->assertNoActiveEnrollment($terminal->getTerminalId(), $now);
		$cred = $this->resolveRfidCredential($rfidUid);
		$userIdResolved = $cred->getUserId();
		$this->assertUserEligible($userIdResolved);
		$this->credentialService->resetFailedAttempts($cred);
		return $userIdResolved;
	}

	/** @return list<array{userId: string, displayName: string}> */
	public function listPinUsers(): array
	{
		$users = [];
		foreach ($this->credMapper->findAllWithPin() as $cred) {
			$userId = $cred->getUserId();
			if (!$this->settingsService->isUserKioskAllowed($userId)) {
				continue;
			}
			if (!$this->permissionService->isUserAllowedByAccessGroups($userId)) {
				continue;
			}
			$user = $this->userManager->get($userId);
			if ($user === null) {
				continue;
			}
			$users[] = [
				'userId' => $userId,
				'displayName' => $user->getDisplayName(),
			];
		}
		usort($users, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $users;
	}

	private function resolveRfidCredential(string $rfidUid): KioskCred
	{
		$cred = $this->credentialService->findCredByRfidUid($rfidUid);
		if ($cred === null) {
			$this->auditLogMapper->logAction('', 'kiosk_identify_failed', 'kiosk_cred', null, null, ['method' => 'rfid']);
			throw new KioskException('KIOSK_CREDENTIAL_UNKNOWN');
		}
		if ($this->credentialService->isLocked($cred)) {
			throw new KioskException('PIN_LOCKED');
		}
		return $cred;
	}

	private function resolvePinCredential(string $userId, string $pin): KioskCred
	{
		if ($userId === '' || $pin === '') {
			throw new KioskException('PIN_INVALID');
		}
		$cred = $this->credMapper->findByUserAndType($userId, 'pin');
		if ($cred === null) {
			$this->auditLogMapper->logAction($userId, 'kiosk_identify_failed', 'kiosk_cred', null, null, ['method' => 'pin']);
			throw new KioskException('KIOSK_CREDENTIAL_UNKNOWN');
		}
		if ($this->credentialService->isLocked($cred)) {
			throw new KioskException('PIN_LOCKED');
		}
		if (!$this->credentialService->verifyPin($cred, $pin)) {
			$this->credentialService->recordFailedAttempt($cred);
			$this->auditLogMapper->logAction($userId, 'kiosk_identify_failed', 'kiosk_cred', $cred->getId(), null, ['method' => 'pin']);
			throw new KioskException('PIN_INVALID');
		}
		return $cred;
	}

	private function assertUserEligible(string $userId): void
	{
		$this->credentialService->assertUserKioskAllowed($userId);
		if (!$this->permissionService->isUserAllowedByAccessGroups($userId)) {
			throw new KioskException('KIOSK_USER_NOT_ALLOWED');
		}
		try {
			$this->timeCaptureMethodService->assertClockStampingAllowed($userId);
		} catch (TimeCaptureForbiddenException) {
			// Must stay a KioskException — uncaught BusinessRuleException becomes an HTML
			// 500 and the tablet shows the useless "unknown" error.
			throw new KioskException('KIOSK_CLOCK_STAMPING_DISABLED');
		}
	}

	private function mapKioskStatus(string $rawStatus): string
	{
		return match ($rawStatus) {
			TimeEntry::STATUS_ACTIVE => 'working',
			TimeEntry::STATUS_BREAK => 'on_break',
			TimeEntry::STATUS_PAUSED => 'off',
			default => 'off',
		};
	}

	/** @return list<string> */
	private function mapAllowedActions(string $kioskStatus): array
	{
		return match ($kioskStatus) {
			'working' => ['clock_out', 'break_start'],
			'on_break' => ['break_end', 'clock_out'],
			default => ['clock_in'],
		};
	}
}
