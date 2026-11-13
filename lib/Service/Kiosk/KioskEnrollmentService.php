<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service\Kiosk;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\KioskEnrollment;
use OCA\ArbeitszeitCheck\Db\KioskEnrollmentMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminalMapper;
use OCA\ArbeitszeitCheck\Service\DbLockGuard;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

class KioskEnrollmentService
{
	private const SCAN_ERROR_TTL = 300;

	/** Brief retries while a concurrent scan/identify finishes (~2s total). */
	private const LOCK_RETRY_ATTEMPTS = 8;

	public function __construct(
		private readonly KioskEnrollmentMapper $enrollmentMapper,
		private readonly KioskTerminalMapper $terminalMapper,
		private readonly KioskCredentialService $credentialService,
		private readonly IUserManager $userManager,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly ITimeFactory $timeFactory,
		private readonly ILockingProvider $lockingProvider,
		private readonly ICacheFactory $cacheFactory,
		private readonly KioskErrorMessages $errorMessages,
		private readonly KioskDbLockPurger $lockPurger,
	) {
	}

	/**
	 * @return array{enrollmentId: int, terminalId: string, userId: string, displayName: string, expiresAt: string}
	 */
	public function start(string $userId, string $terminalId, string $createdBy): array
	{
		$userId = trim($userId);
		$terminalId = trim($terminalId);
		if ($userId === '') {
			throw new KioskException('KIOSK_USER_NOT_ALLOWED');
		}
		if ($terminalId === '') {
			throw new KioskException('KIOSK_TERMINAL_NOT_FOUND');
		}

		$this->credentialService->assertUserKioskAllowed($userId);
		$terminal = $this->terminalMapper->findByTerminalId($terminalId);
		if ($terminal === null) {
			throw new KioskException('KIOSK_TERMINAL_NOT_FOUND');
		}
		if ($terminal->getStatus() !== 'active') {
			throw new KioskException('KIOSK_TERMINAL_NOT_ACTIVE');
		}
		$user = $this->userManager->get($userId);
		if ($user === null) {
			throw new KioskException('KIOSK_USER_NOT_ALLOWED');
		}

		try {
			return $this->startUnderLocks($userId, $terminalId, $createdBy, $user->getDisplayName());
		} catch (KioskException $e) {
			if ($e->getErrorCode() !== 'KIOSK_BUSY') {
				throw $e;
			}
			// Orphan exclusive locks (crash / pre-1.5.20 truncation) block start.
			// Purge known keys for this pair, then one more attempt.
			$this->lockPurger->purgeEnrollmentLocks($userId, $terminalId);
			return $this->startUnderLocks($userId, $terminalId, $createdBy, $user->getDisplayName());
		}
	}

	/**
	 * @return array{enrollmentId: int, terminalId: string, userId: string, displayName: string, expiresAt: string}
	 */
	private function startUnderLocks(
		string $userId,
		string $terminalId,
		string $createdBy,
		string $displayName,
	): array {
		// Consistent lock order (user → terminal) avoids deadlocks with completeScan/cancel.
		$userLockKey = $this->userEnrollmentLockKey($userId);
		$terminalLockKey = $this->terminalEnrollmentLockKey($terminalId);
		$this->acquireExclusiveWithRetry($userLockKey, 'Kiosk enrollment start user');
		try {
			$this->acquireExclusiveWithRetry($terminalLockKey, 'Kiosk enrollment start terminal');
			try {
				$this->enrollmentMapper->cancelForTerminal($terminalId);
				$this->clearScanError($terminalId);
				$now = $this->timeFactory->getDateTime();
				$expires = (clone $now)->modify('+' . Constants::KIOSK_ENROLLMENT_TTL_SECONDS . ' seconds');

				$enrollment = new KioskEnrollment();
				$enrollment->setTerminalId($terminalId);
				$enrollment->setUserId($userId);
				$enrollment->setExpiresAt($expires);
				$enrollment->setCreatedBy($createdBy);
				$enrollment->setCreatedAt($now);
				$enrollment = $this->enrollmentMapper->insert($enrollment);

				$this->auditLogMapper->logAction($userId, 'kiosk_enrollment_started', 'kiosk_enrollment', $enrollment->getId(), null, [
					'terminalId' => $terminalId,
				], $createdBy);

				return [
					'enrollmentId' => $enrollment->getId(),
					'terminalId' => $terminalId,
					'userId' => $userId,
					'displayName' => $displayName,
					'expiresAt' => $expires->format('c'),
				];
			} finally {
				$this->lockingProvider->releaseLock($terminalLockKey, ILockingProvider::LOCK_EXCLUSIVE);
			}
		} finally {
			$this->lockingProvider->releaseLock($userLockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * @return array{
	 *   status: string,
	 *   userId?: string,
	 *   displayName?: string,
	 *   expiresAt?: string,
	 *   completedAt?: string,
	 *   lastError?: string,
	 *   lastErrorMessage?: string
	 * }
	 */
	public function getStatus(string $terminalId): array
	{
		$terminalId = trim($terminalId);
		$now = $this->timeFactory->getDateTime();
		$active = $this->enrollmentMapper->findActiveByTerminalId($terminalId, $now);
		if ($active !== null) {
			$user = $this->userManager->get($active->getUserId());
			$out = [
				'status' => 'pending',
				'userId' => $active->getUserId(),
				'displayName' => $user !== null ? $user->getDisplayName() : $active->getUserId(),
				'expiresAt' => $active->getExpiresAt()->format('c'),
			];
			return $this->withLastError($terminalId, $out);
		}

		$completed = $this->enrollmentMapper->findLatestCompletedByTerminalId($terminalId);
		if ($completed !== null && $completed->getCompletedAt() !== null) {
			$age = $now->getTimestamp() - $completed->getCompletedAt()->getTimestamp();
			// Keep “completed” visible for the full admin poll window (TTL + buffer).
			if ($age < Constants::KIOSK_ENROLLMENT_TTL_SECONDS + 60) {
				$this->clearScanError($terminalId);
				return [
					'status' => 'completed',
					'userId' => $completed->getUserId(),
					'completedAt' => $completed->getCompletedAt()->format('c'),
				];
			}
		}

		$out = ['status' => 'expired'];
		return $this->withLastError($terminalId, $out);
	}

	/**
	 * Abort a pending badge enrollment for a terminal.
	 *
	 * Prefer ordered locks (user → terminal) so cancel serializes with start/scan.
	 * If locks stay busy after retries (orphan exclusive rows), force-abort still
	 * clears the enrollment intent and purges the known lock keys — Admin must
	 * never be trapped by “cancel first” when cancel itself cannot take the mutex.
	 *
	 * @return array{status: 'cancelled'|'already_idle'|'already_completed', enrollmentId?: int, forced?: bool}
	 */
	public function cancel(string $terminalId, string $actorUserId): array
	{
		$terminalId = trim($terminalId);
		if ($terminalId === '') {
			throw new KioskException('KIOSK_TERMINAL_NOT_FOUND');
		}

		$now = $this->timeFactory->getDateTime();
		$preview = $this->enrollmentMapper->findActiveByTerminalId($terminalId, $now);
		$previewUserId = $preview?->getUserId();

		try {
			if ($preview !== null) {
				return $this->cancelUnderUserTerminalLocks(
					$preview->getUserId(),
					$terminalId,
					$actorUserId,
				);
			}

			// Serialize cleanup with start/complete/identify without guessing a user lock.
			$terminalLockKey = $this->terminalEnrollmentLockKey($terminalId);
			$this->acquireExclusiveWithRetry($terminalLockKey, 'Kiosk enrollment cancel terminal');
			$discoveredUserId = null;
			try {
				$now = $this->timeFactory->getDateTime();
				$active = $this->enrollmentMapper->findActiveByTerminalId($terminalId, $now);
				if ($active === null) {
					$this->enrollmentMapper->cancelForTerminal($terminalId);
					$this->clearScanError($terminalId);
					return $this->cancelOutcomeWhenIdle($terminalId);
				}
				$discoveredUserId = $active->getUserId();
			} finally {
				$this->lockingProvider->releaseLock($terminalLockKey, ILockingProvider::LOCK_EXCLUSIVE);
			}

			return $this->cancelUnderUserTerminalLocks($discoveredUserId, $terminalId, $actorUserId);
		} catch (KioskException $e) {
			if ($e->getErrorCode() !== 'KIOSK_BUSY') {
				throw $e;
			}
			return $this->forceAbortEnrollment($terminalId, $actorUserId, $previewUserId);
		}
	}

	/**
	 * Clear enrollment without requiring locks. Purges orphan lock rows so the
	 * next start/PIN/badge assign is not immediately blocked by the same stuck mutex.
	 *
	 * @return array{status: 'cancelled'|'already_idle'|'already_completed', enrollmentId?: int, forced: true}
	 */
	private function forceAbortEnrollment(
		string $terminalId,
		string $actorUserId,
		?string $knownUserId,
	): array {
		$incomplete = $this->enrollmentMapper->findIncompleteByTerminalId($terminalId);
		$userId = $knownUserId;
		if (($userId === null || $userId === '') && $incomplete !== null) {
			$userId = $incomplete->getUserId();
		}

		$this->lockPurger->purgeEnrollmentLocks($userId, $terminalId);
		if ($userId !== null && $userId !== '') {
			// PIN/RFID assign may also be stuck on the same employee.
			$this->lockPurger->purgeCredentialLocks($userId);
		}

		$deleted = $this->enrollmentMapper->cancelForTerminal($terminalId);
		$this->clearScanError($terminalId);

		if ($deleted > 0 && $incomplete !== null) {
			$enrollmentId = $incomplete->getId();
			$this->auditLogMapper->logAction(
				$incomplete->getUserId(),
				'kiosk_enrollment_cancelled',
				'kiosk_enrollment',
				$enrollmentId,
				null,
				[
					'terminalId' => $terminalId,
					'forced' => true,
				],
				$actorUserId,
			);
			$result = [
				'status' => 'cancelled',
				'forced' => true,
			];
			if ($enrollmentId !== null) {
				$result['enrollmentId'] = $enrollmentId;
			}
			return $result;
		}

		$idle = $this->cancelOutcomeWhenIdle($terminalId);
		$idle['forced'] = true;
		return $idle;
	}

	/**
	 * @return array{status: 'cancelled'|'already_idle'|'already_completed', enrollmentId?: int}
	 */
	private function cancelUnderUserTerminalLocks(
		string $userId,
		string $terminalId,
		string $actorUserId,
	): array {
		$userLockKey = $this->userEnrollmentLockKey($userId);
		$terminalLockKey = $this->terminalEnrollmentLockKey($terminalId);
		$this->acquireExclusiveWithRetry($userLockKey, 'Kiosk enrollment cancel user');
		try {
			$this->acquireExclusiveWithRetry($terminalLockKey, 'Kiosk enrollment cancel terminal');
			try {
				$now = $this->timeFactory->getDateTime();
				$active = $this->enrollmentMapper->findActiveByTerminalId($terminalId, $now);
				if ($active === null) {
					$this->enrollmentMapper->cancelForTerminal($terminalId);
					$this->clearScanError($terminalId);
					return $this->cancelOutcomeWhenIdle($terminalId);
				}

				$enrollmentId = $active->getId();
				$this->enrollmentMapper->cancelForTerminal($terminalId);
				$this->clearScanError($terminalId);
				$this->auditLogMapper->logAction(
					$active->getUserId(),
					'kiosk_enrollment_cancelled',
					'kiosk_enrollment',
					$enrollmentId,
					null,
					['terminalId' => $terminalId],
					$actorUserId,
				);

				$result = ['status' => 'cancelled'];
				if ($enrollmentId !== null) {
					$result['enrollmentId'] = $enrollmentId;
				}
				return $result;
			} finally {
				$this->lockingProvider->releaseLock($terminalLockKey, ILockingProvider::LOCK_EXCLUSIVE);
			}
		} finally {
			$this->lockingProvider->releaseLock($userLockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * @return array{status: 'already_idle'|'already_completed'}
	 */
	private function cancelOutcomeWhenIdle(string $terminalId): array
	{
		$now = $this->timeFactory->getDateTime();
		$completed = $this->enrollmentMapper->findLatestCompletedByTerminalId($terminalId);
		if ($completed !== null && $completed->getCompletedAt() !== null) {
			$age = $now->getTimestamp() - $completed->getCompletedAt()->getTimestamp();
			// Same visibility window as getStatus() — Admin may have raced the tablet save.
			if ($age < Constants::KIOSK_ENROLLMENT_TTL_SECONDS + 60) {
				return ['status' => 'already_completed'];
			}
		}
		return ['status' => 'already_idle'];
	}

	/**
	 * @return array{displayName: string, message: string}
	 */
	public function completeScan(string $terminalId, string $rfidUid, string $actorLabel = 'enroll-scan'): array
	{
		$terminalId = trim($terminalId);
		$now = $this->timeFactory->getDateTime();
		$enrollmentPreview = $this->enrollmentMapper->findActiveByTerminalId($terminalId, $now);
		if ($enrollmentPreview === null) {
			throw new KioskException('ENROLLMENT_NOT_ACTIVE');
		}

		$userLockKey = $this->userEnrollmentLockKey($enrollmentPreview->getUserId());
		$terminalLockKey = $this->terminalEnrollmentLockKey($terminalId);
		$this->acquireExclusiveWithRetry($userLockKey, 'Kiosk enrollment scan user');
		try {
			$this->acquireExclusiveWithRetry($terminalLockKey, 'Kiosk enrollment scan terminal');
			try {
				$now = $this->timeFactory->getDateTime();
				$enrollment = $this->enrollmentMapper->findActiveByTerminalId($terminalId, $now);
				if ($enrollment === null) {
					throw new KioskException('ENROLLMENT_NOT_ACTIVE');
				}

				$enrollmentId = $enrollment->getId();
				if ($enrollmentId === null) {
					throw new KioskException('ENROLLMENT_NOT_ACTIVE');
				}

				// Claim first so a second concurrent scan cannot assign a different card.
				if (!$this->enrollmentMapper->claimComplete($enrollmentId, $now, $now)) {
					throw new KioskException('ENROLLMENT_NOT_ACTIVE');
				}

				try {
					$result = $this->credentialService->assignRfid(
						$enrollment->getUserId(),
						$rfidUid,
						$enrollment->getCreatedBy(),
						null,
						$actorLabel,
						false, // enrollment logs the assign below — avoid duplicate audit rows
					);
				} catch (\Throwable $e) {
					// Roll back the claim so the admin can retry enrollment.
					$enrollment->setCompletedAt(null);
					$this->enrollmentMapper->update($enrollment);
					if ($e instanceof KioskException) {
						$this->rememberScanError($terminalId, $e->getErrorCode());
						throw $e;
					}
					if ($e instanceof LockedException) {
						$this->rememberScanError($terminalId, 'KIOSK_BUSY');
						throw new KioskException('KIOSK_BUSY');
					}
					$this->rememberScanError($terminalId, 'KIOSK_SCAN_FAILED');
					throw new KioskException('KIOSK_SCAN_FAILED');
				}

				$this->clearScanError($terminalId);
				$user = $this->userManager->get($enrollment->getUserId());
				$this->auditLogMapper->logAction($enrollment->getUserId(), 'kiosk_credential_assigned', 'kiosk_cred', $result['id'], null, [
					'type' => 'rfid',
					'method' => $actorLabel,
					'terminalId' => $terminalId,
				], $enrollment->getCreatedBy());

				return [
					'displayName' => $user !== null ? $user->getDisplayName() : $enrollment->getUserId(),
					// Client localizes success copy (tablet locale ≠ server Accept-Language).
					'message' => '',
				];
			} finally {
				$this->lockingProvider->releaseLock($terminalLockKey, ILockingProvider::LOCK_EXCLUSIVE);
			}
		} finally {
			$this->lockingProvider->releaseLock($userLockKey, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * @return array{active: bool, displayName?: string, expiresAt?: string}|null
	 */
	public function getConfigEnrollment(string $terminalId): ?array
	{
		$now = $this->timeFactory->getDateTime();
		$enrollment = $this->enrollmentMapper->findActiveByTerminalId($terminalId, $now);
		if ($enrollment === null) {
			return null;
		}
		$user = $this->userManager->get($enrollment->getUserId());
		return [
			'active' => true,
			'displayName' => $user !== null ? $user->getDisplayName() : $enrollment->getUserId(),
			'expiresAt' => $enrollment->getExpiresAt()->format('c'),
		];
	}

	/**
	 * Acquire an exclusive lock with short retries so brief completeScan/identify
	 * windows do not surface as hard failures. Orphan recovery is handled by callers
	 * via {@see KioskDbLockPurger} after retries are exhausted.
	 */
	private function acquireExclusiveWithRetry(string $lockKey, string $label, int $attempts = self::LOCK_RETRY_ATTEMPTS): void
	{
		$attempts = max(1, $attempts);
		$delayUs = 40000; // 40ms, then 80ms, 160ms… capped at 200ms
		for ($i = 0; $i < $attempts; $i++) {
			try {
				DbLockGuard::acquire($this->lockingProvider, $lockKey, ILockingProvider::LOCK_EXCLUSIVE, $label);
				return;
			} catch (LockedException) {
				if ($i + 1 >= $attempts) {
					break;
				}
				usleep($delayUs);
				$delayUs = min(200000, $delayUs * 2);
			}
		}
		throw new KioskException('KIOSK_BUSY');
	}

	/**
	 * @param array<string, mixed> $out
	 * @return array<string, mixed>
	 */
	private function withLastError(string $terminalId, array $out): array
	{
		$code = $this->peekScanError($terminalId);
		if ($code === null || $code === '') {
			return $out;
		}
		$out['lastError'] = $code;
		$out['lastErrorMessage'] = $this->errorMessages->message($code);
		return $out;
	}

	private function rememberScanError(string $terminalId, string $code): void
	{
		$this->cacheFactory->createDistributed('azc_kiosk_enroll')->set(
			$this->scanErrorKey($terminalId),
			$code,
			self::SCAN_ERROR_TTL,
		);
	}

	private function peekScanError(string $terminalId): ?string
	{
		$value = $this->cacheFactory->createDistributed('azc_kiosk_enroll')->get(
			$this->scanErrorKey($terminalId),
		);
		return is_string($value) && $value !== '' ? $value : null;
	}

	private function clearScanError(string $terminalId): void
	{
		$this->cacheFactory->createDistributed('azc_kiosk_enroll')->remove(
			$this->scanErrorKey($terminalId),
		);
	}

	private function scanErrorKey(string $terminalId): string
	{
		return 'err_' . hash('sha256', $terminalId);
	}

	private function userEnrollmentLockKey(string $userId): string
	{
		return KioskEnrollmentLockKeys::forUser($userId);
	}

	private function terminalEnrollmentLockKey(string $terminalId): string
	{
		return KioskEnrollmentLockKeys::forTerminal($terminalId);
	}
}
