<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Support\StampClientRequestNormalizer;
use OCA\ArbeitszeitCheck\Support\StampOccurredAtParser;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Idempotent mobile clock/break replay for offline companion drains.
 *
 * Claim-before-mutate: reserve the clientRequestId row before calling
 * TimeTrackingService so concurrent retries cannot double-apply.
 */
class MobileStampReplayService
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
		private readonly TimeTrackingService $timeTrackingService,
		private readonly MobileStampIdempotencyMapper $idempotencyMapper,
		private readonly StampOccurredAtParser $occurredAtParser,
		private readonly ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @return array{success: bool, timeEntry: array<string, mixed>}
	 */
	public function clockIn(
		string $userId,
		?string $projectCheckProjectId,
		?string $description,
		?string $clientRequestIdRaw,
		?string $occurredAtRaw,
		?string $workLocation = null,
	): array {
		return $this->replay(
			$userId,
			$clientRequestIdRaw,
			$occurredAtRaw,
			fn (?\DateTimeInterface $at) => $this->timeTrackingService->clockIn(
				$userId,
				$projectCheckProjectId,
				$description,
				$at,
				$workLocation,
			),
			fn (?\DateTimeInterface $at) => self::fingerprint([
				'clock_in',
				$at?->getTimestamp() ?? 'now',
				$projectCheckProjectId ?? '',
				$description ?? '',
				$workLocation ?? '',
			]),
		);
	}

	/**
	 * @return array{success: bool, timeEntry: array<string, mixed>}
	 */
	public function clockOut(string $userId, ?string $clientRequestIdRaw, ?string $occurredAtRaw): array
	{
		return $this->replay(
			$userId,
			$clientRequestIdRaw,
			$occurredAtRaw,
			fn (?\DateTimeInterface $at) => $this->timeTrackingService->clockOut($userId, TimeEntry::ENDED_REASON_MANUAL_CLOCK_OUT, 'standard', $at),
			fn (?\DateTimeInterface $at) => self::fingerprint(['clock_out', $at?->getTimestamp() ?? 'now']),
		);
	}

	/**
	 * @return array{success: bool, timeEntry: array<string, mixed>}
	 */
	public function startBreak(string $userId, ?string $clientRequestIdRaw, ?string $occurredAtRaw): array
	{
		return $this->replay(
			$userId,
			$clientRequestIdRaw,
			$occurredAtRaw,
			fn (?\DateTimeInterface $at) => $this->timeTrackingService->startBreak($userId, $at),
			fn (?\DateTimeInterface $at) => self::fingerprint(['break_start', $at?->getTimestamp() ?? 'now']),
		);
	}

	/**
	 * @return array{success: bool, timeEntry: array<string, mixed>}
	 */
	public function endBreak(string $userId, ?string $clientRequestIdRaw, ?string $occurredAtRaw): array
	{
		return $this->replay(
			$userId,
			$clientRequestIdRaw,
			$occurredAtRaw,
			fn (?\DateTimeInterface $at) => $this->timeTrackingService->endBreak($userId, $at),
			fn (?\DateTimeInterface $at) => self::fingerprint(['break_end', $at?->getTimestamp() ?? 'now']),
		);
	}

	/**
	 * Canonical payload fingerprint: sha256 over the action + normalized inputs
	 * so an idempotent retry with a *different* payload is rejected instead of
	 * silently replaying the stale response.
	 *
	 * @param list<string|int> $parts
	 */
	private static function fingerprint(array $parts): string
	{
		return hash('sha256', implode('|', array_map(
			static fn ($p) => (string)$p,
			$parts,
		)));
	}

	/**
	 * @param callable(?\DateTimeInterface): TimeEntry $mutator
	 * @param callable(?\DateTimeInterface): string $fingerprintFn
	 * @return array{success: bool, timeEntry: array<string, mixed>}
	 */
	private function replay(
		string $userId,
		?string $clientRequestIdRaw,
		?string $occurredAtRaw,
		callable $mutator,
		callable $fingerprintFn,
	): array {
		$clientRequestId = StampClientRequestNormalizer::normalize($clientRequestIdRaw);
		$effectiveAt = $this->occurredAtParser->parseOptional($occurredAtRaw, $clientRequestId);
		$payloadHash = $fingerprintFn($effectiveAt);

		$claimId = null;
		if ($clientRequestId !== null) {
			$claim = $this->claimOrReplay($userId, $clientRequestId, $payloadHash);
			if ($claim['cached'] !== null) {
				return $claim['cached'];
			}
			$claimId = $claim['claimId'];
		}

		try {
			$timeEntry = $mutator($effectiveAt);
		} catch (\Throwable $e) {
			if ($claimId !== null) {
				$this->idempotencyMapper->deleteById($claimId);
			}
			throw $e;
		}

		$summary = $this->safeSummary($timeEntry, $userId);
		$payload = [
			'success' => true,
			'timeEntry' => $summary,
			'break_warning' => $this->breakWarning($timeEntry),
		];

		if ($claimId !== null) {
			$json = json_encode($payload, JSON_THROW_ON_ERROR);
			$this->idempotencyMapper->updateResponseJsonForId($claimId, $json);
		}

		return $payload;
	}

	/**
	 * Resolve an existing claim, or take it.
	 *
	 * Returns ['cached' => <stored response>] when the request was already
	 * applied, ['claimId' => <row id>] when this caller owns the claim and must
	 * run the mutator. Throws MISMATCH on same-id/different-payload, IN_FLIGHT
	 * when a live pending claim outlived the wait budget.
	 *
	 * @return array{cached: array{success: bool, timeEntry: array<string, mixed>}|null, claimId: ?int}
	 */
	private function claimOrReplay(string $userId, string $clientRequestId, string $payloadHash): array
	{
		for ($i = 0; $i < self::WAIT_ROUNDS; $i++) {
			$row = $this->idempotencyMapper->findByUserAndRequestId($userId, $clientRequestId);
			if ($row === null) {
				$claim = $this->idempotencyMapper->tryInsert($userId, $clientRequestId, self::PENDING_JSON, $this->timeFactory->getTime(), $payloadHash);
				if ($claim !== null) {
					return ['cached' => null, 'claimId' => (int)$claim->getId()];
				}
				// Lost a claim race — loop and re-read the winner's row.
				continue;
			}

			$this->assertPayloadMatches($row->getPayloadHash(), $payloadHash);

			$raw = $row->getResponseJson();
			if ($raw !== self::PENDING_JSON) {
				/** @var array{success: bool, timeEntry: array<string, mixed>} $decoded */
				$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
				return ['cached' => $decoded, 'claimId' => null];
			}

			if ($this->timeFactory->getTime() - $row->getCreatedAt() >= self::STALE_CLAIM_SECONDS) {
				// Abandoned claim (request died mid-flight): conditional delete —
				// whoever's delete lands first owns the reclaim, everyone else
				// keeps waiting on the winner's fresh claim.
				$this->idempotencyMapper->deleteStalePending(
					$userId,
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

	/**
	 * Rows written before the payload_hash column existed carry NULL — they
	 * cannot be verified and are accepted; anything else must match exactly.
	 */
	private function assertPayloadMatches(?string $storedHash, string $incomingHash): void
	{
		if ($storedHash !== null && !hash_equals($storedHash, $incomingHash)) {
			throw new StampReplayException('STAMP_CLIENT_REQUEST_MISMATCH');
		}
	}

	/**
	 * Organisational break-policy warning for the stored payload. Warnings are
	 * persisted into the idempotency row so replays stay byte-identical.
	 */
	private function breakWarning(TimeEntry $timeEntry): ?array
	{
		try {
			if ($timeEntry->getStatus() !== TimeEntry::STATUS_COMPLETED) {
				return null;
			}
			$compliance = \OCP\Server::get(ComplianceService::class);
			return $compliance->breakWarningForEntry($timeEntry);
		} catch (\Throwable) {
			return null;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function safeSummary(TimeEntry $timeEntry, string $userId): array
	{
		try {
			return $timeEntry->getSummary();
		} catch (\Throwable) {
			return [
				'id' => $timeEntry->getId(),
				'userId' => $userId,
				'status' => $timeEntry->getStatus(),
			];
		}
	}
}
