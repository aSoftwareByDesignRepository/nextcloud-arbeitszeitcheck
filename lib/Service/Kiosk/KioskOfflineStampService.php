<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service\Kiosk;

use OCA\ArbeitszeitCheck\Db\KioskStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\KioskTerminal;
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

	public function __construct(
		private readonly KioskAuthService $authService,
		private readonly KioskActionService $actionService,
		private readonly TimeTrackingService $timeTrackingService,
		private readonly KioskStampIdempotencyMapper $idempotencyMapper,
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
		$cached = $this->readCompletedPayload($terminalId, $clientRequestId);
		if ($cached !== null) {
			return $cached;
		}
		$now = $this->timeFactory->getTime();
		if (!$this->idempotencyMapper->tryInsert($terminalId, $clientRequestId, self::PENDING_JSON, $now)) {
			$cached = $this->waitForCompletedPayload($terminalId, $clientRequestId);
			if ($cached !== null) {
				return $cached;
			}
			throw new StampReplayException('STAMP_CLIENT_REQUEST_IN_FLIGHT');
		}

		try {
			$userId = $this->authService->resolveUserIdFromRfid($terminal, $rfidUid);
			$this->authService->assertUserEligibleForAction($userId);

			$newStatus = match ($action) {
				'clock_in' => $this->applyClockIn($userId, $effectiveAt),
				'clock_out' => $this->applyClockOut($userId, $effectiveAt),
				'break_start' => $this->applyStartBreak($userId, $effectiveAt),
				'break_end' => $this->applyEndBreak($userId, $effectiveAt),
				default => throw new KioskException('KIOSK_ACTION_INVALID'),
			};

			$payload = [
				'success' => true,
				'data' => [
					'newStatus' => $newStatus,
					'message' => $this->actionService->actionMessageFor($action),
				],
			];
			$json = json_encode($payload, JSON_THROW_ON_ERROR);
			$this->idempotencyMapper->updateResponseJson($terminalId, $clientRequestId, $json);
			return $payload;
		} catch (\Throwable $e) {
			$this->idempotencyMapper->deleteByTerminalAndRequestId($terminalId, $clientRequestId);
			throw $e;
		}
	}

	/**
	 * @return array{success: bool, data: array{newStatus: string, message: string}}|null
	 */
	private function readCompletedPayload(string $terminalId, string $clientRequestId): ?array
	{
		$existing = $this->idempotencyMapper->findByTerminalAndRequestId($terminalId, $clientRequestId);
		if ($existing === null) {
			return null;
		}
		$raw = $existing->getResponseJson();
		if ($raw === self::PENDING_JSON) {
			return null;
		}
		/** @var array{success: bool, data: array{newStatus: string, message: string}} $decoded */
		$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		return $decoded;
	}

	/**
	 * @return array{success: bool, data: array{newStatus: string, message: string}}|null
	 */
	private function waitForCompletedPayload(string $terminalId, string $clientRequestId): ?array
	{
		for ($i = 0; $i < 8; $i++) {
			usleep(25_000);
			$cached = $this->readCompletedPayload($terminalId, $clientRequestId);
			if ($cached !== null) {
				return $cached;
			}
			if ($this->idempotencyMapper->findByTerminalAndRequestId($terminalId, $clientRequestId) === null) {
				return null;
			}
		}
		return $this->readCompletedPayload($terminalId, $clientRequestId);
	}

	private function applyClockIn(string $userId, \DateTimeInterface $at): string
	{
		$this->timeTrackingService->clockIn($userId, null, null, $at);
		return 'working';
	}

	private function applyClockOut(string $userId, \DateTimeInterface $at): string
	{
		$this->timeTrackingService->clockOut($userId, \OCA\ArbeitszeitCheck\Db\TimeEntry::ENDED_REASON_MANUAL_CLOCK_OUT, 'standard', $at);
		return 'off';
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
