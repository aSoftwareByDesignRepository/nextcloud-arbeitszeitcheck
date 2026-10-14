<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service\Kiosk;

use OCA\ArbeitszeitCheck\Db\KioskStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Support\StampClientRequestNormalizer;
use OCA\ArbeitszeitCheck\Support\StampOccurredAtParser;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Idempotent RFID stamp for kiosk offline queue replay (no one-shot session).
 *
 * Claim-before-mutate on (terminal_id, client_request_id).
 */
class KioskOfflineStampService
{
	private const PENDING_JSON = '{"pending":true}';
	/**
	 * A PENDING claim older than this is treated as abandoned (the request died
	 * mid-flight) and may be reclaimed. Must stay far above any realistic
	 * request runtime so a slow-but-alive request is never double-applied.
	 */
	private const STALE_CLAIM_SECONDS = 300;
	private const WAIT_POLL_US = 25_000;
	private const WAIT_ROUNDS = 12;

	public function __construct(
		private readonly KioskAuthService $authService,
		private readonly KioskActionService $actionService,
		private readonly TimeTrackingService $timeTrackingService,
		private readonly KioskStampIdempotencyMapper $idempotencyMapper,
		private readonly KioskBusinessRuleMapper $businessRuleMapper,
		private readonly StampOccurredAtParser $occurredAtParser,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return array{success: bool, data: array{newStatus: string, message: string}}
	 */
	public function stampRfid(
		KioskTerminal $terminal,
		string $rfidUid,
		string $action,
		?string $clientRequestIdRaw,
		?string $occurredAtRaw,
	): array {
		$clientRequestId = StampClientRequestNormalizer::normalize($clientRequestIdRaw);
		if ($clientRequestId === null) {
			throw new StampReplayException('STAMP_CLIENT_REQUEST_ID_REQUIRED');
		}
		$effectiveAt = $this->occurredAtParser->parseOptional($occurredAtRaw, $clientRequestId);
		if ($effectiveAt === null) {
			throw new StampReplayException('STAMP_OCCURRED_AT_INVALID');
		}

		$terminalId = $terminal->getTerminalId();
		$payloadHash = hash('sha256', implode('|', [
			$action,
			(string)$effectiveAt->getTimestamp(),
			$rfidUid,
		]));
		$claim = $this->claimOrReplay($terminalId, $clientRequestId, $payloadHash);
		if ($claim['cached'] !== null) {
			return $claim['cached'];
		}
		$claimId = $claim['claimId'];

		try {
			$userId = $this->authService->resolveUserIdFromRfid($terminal, $rfidUid);
			$this->authService->assertUserEligibleForAction($userId);

			$completedEntry = null;
			$newStatus = match ($action) {
				'clock_in' => $this->applyClockIn($userId, $effectiveAt),
				'clock_out' => $this->applyClockOut($userId, $effectiveAt, $completedEntry),
				'break_start' => $this->applyStartBreak($userId, $effectiveAt),
				'break_end' => $this->applyEndBreak($userId, $effectiveAt),
				default => throw new KioskException('KIOSK_ACTION_INVALID'),
			};

			$payload = [
				'success' => true,
				'data' => [
					'newStatus' => $newStatus,
					'message' => $this->actionService->actionMessageFor($action),
					'break_warning' => $completedEntry !== null ? $this->breakWarning($completedEntry) : null,
				],
			];
			$json = json_encode($payload, JSON_THROW_ON_ERROR);
			$this->idempotencyMapper->updateResponseJsonForId($claimId, $json);
			return $payload;
		} catch (\Throwable $e) {
			$this->idempotencyMapper->deleteById($claimId);
			if ($e instanceof BusinessRuleException) {
				// Business rejections (rest period, already clocked in, …) must keep
				// their typed kiosk code — an INTERNAL_ERROR 500 would make terminals
				// and ops indistinguishable from real server faults.
				throw $this->businessRuleMapper->toKioskException($e);
			}
			throw $e;
		}
	}

	/**
	 * Resolve an existing claim, or take it. Same contract as the mobile
	 * replay: cached response on completed, claimId when this caller owns the
	 * claim; MISMATCH on same-id/different-payload; stale PENDING claims are
	 * reclaimed so a crashed request cannot wedge the key forever.
	 *
	 * @return array{cached: array{success: bool, data: array{newStatus: string, message: string}}|null, claimId: ?int}
	 */
	private function claimOrReplay(string $terminalId, string $clientRequestId, string $payloadHash): array
	{
		for ($i = 0; $i < self::WAIT_ROUNDS; $i++) {
			$row = $this->idempotencyMapper->findByTerminalAndRequestId($terminalId, $clientRequestId);
			if ($row === null) {
				$claim = $this->idempotencyMapper->tryInsert($terminalId, $clientRequestId, self::PENDING_JSON, $this->timeFactory->getTime(), $payloadHash);
				if ($claim !== null) {
					return ['cached' => null, 'claimId' => (int)$claim->getId()];
				}
				// Lost a claim race — loop and re-read the winner's row.
				continue;
			}

			$storedHash = $row->getPayloadHash();
			if ($storedHash !== null && !hash_equals($storedHash, $payloadHash)) {
				throw new StampReplayException('STAMP_CLIENT_REQUEST_MISMATCH');
			}

			$raw = $row->getResponseJson();
			if ($raw !== self::PENDING_JSON) {
				/** @var array{success: bool, data: array{newStatus: string, message: string}} $decoded */
				$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
				return ['cached' => $decoded, 'claimId' => null];
			}

			if ($this->timeFactory->getTime() - $row->getCreatedAt() >= self::STALE_CLAIM_SECONDS) {
				$this->idempotencyMapper->deleteStalePending(
					$terminalId,
					$clientRequestId,
					self::PENDING_JSON,
					$this->timeFactory->getTime() - self::STALE_CLAIM_SECONDS,
				);
				continue;
			}

			usleep(self::WAIT_POLL_US);
		}
		throw new StampReplayException('STAMP_CLIENT_REQUEST_IN_FLIGHT');
	}

	private function applyClockIn(string $userId, \DateTimeInterface $at): string
	{
		$this->timeTrackingService->clockIn($userId, null, null, $at);
		return 'working';
	}

	private function applyClockOut(string $userId, \DateTimeInterface $at, ?\OCA\ArbeitszeitCheck\Db\TimeEntry &$entry = null): string
	{
		$entry = $this->timeTrackingService->clockOut($userId, \OCA\ArbeitszeitCheck\Db\TimeEntry::ENDED_REASON_MANUAL_CLOCK_OUT, 'standard', $at);
		return 'off';
	}

	/**
	 * Organisational break-policy warning for a completed entry — warnings
	 * never block, and a failure to compute must not fail the stamp.
	 */
	private function breakWarning(\OCA\ArbeitszeitCheck\Db\TimeEntry $entry): ?array
	{
		try {
			if ($entry->getStatus() !== \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED) {
				return null;
			}
			$compliance = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\ComplianceService::class);
			return $compliance->breakWarningForEntry($entry);
		} catch (\Throwable) {
			return null;
		}
	}

	private function applyStartBreak(string $userId, \DateTimeInterface $at): string
	{
		$this->timeTrackingService->startBreak($userId, $at);
		return 'on_break';
	}

	private function applyEndBreak(string $userId, \DateTimeInterface $at): string
	{
		$this->timeTrackingService->endBreak($userId, $at);
		return 'working';
	}
}
