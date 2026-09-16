<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
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
			),
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
		);
	}

	/**
	 * @param callable(?\DateTimeInterface): TimeEntry $mutator
	 * @return array{success: bool, timeEntry: array<string, mixed>}
	 */
	private function replay(
		string $userId,
		?string $clientRequestIdRaw,
		?string $occurredAtRaw,
		callable $mutator,
	): array {
		$clientRequestId = StampClientRequestNormalizer::normalize($clientRequestIdRaw);
		$effectiveAt = $this->occurredAtParser->parseOptional($occurredAtRaw, $clientRequestId);

		if ($clientRequestId !== null) {
			$cached = $this->readCompletedPayload($userId, $clientRequestId);
			if ($cached !== null) {
				return $cached;
			}
			$now = $this->timeFactory->getTime();
			if (!$this->idempotencyMapper->tryInsert($userId, $clientRequestId, self::PENDING_JSON, $now)) {
				$cached = $this->waitForCompletedPayload($userId, $clientRequestId);
				if ($cached !== null) {
					return $cached;
				}
				throw new StampReplayException('STAMP_CLIENT_REQUEST_IN_FLIGHT');
			}
		}

		try {
			$timeEntry = $mutator($effectiveAt);
		} catch (MonthFinalizedException $e) {
			if ($clientRequestId !== null) {
				$this->idempotencyMapper->deleteByUserAndRequestId($userId, $clientRequestId);
			}
			throw $e;
		} catch (\Throwable $e) {
			if ($clientRequestId !== null) {
				$this->idempotencyMapper->deleteByUserAndRequestId($userId, $clientRequestId);
			}
			throw $e;
		}

		$summary = $this->safeSummary($timeEntry, $userId);
		$payload = [
			'success' => true,
			'timeEntry' => $summary,
		];

		if ($clientRequestId !== null) {
			$json = json_encode($payload, JSON_THROW_ON_ERROR);
			$this->idempotencyMapper->updateResponseJson($userId, $clientRequestId, $json);
		}

		return $payload;
	}

	/**
	 * @return array{success: bool, timeEntry: array<string, mixed>}|null
	 */
	private function readCompletedPayload(string $userId, string $clientRequestId): ?array
	{
		$existing = $this->idempotencyMapper->findByUserAndRequestId($userId, $clientRequestId);
		if ($existing === null) {
			return null;
		}
		$raw = $existing->getResponseJson();
		if ($raw === self::PENDING_JSON) {
			return null;
		}
		/** @var array{success: bool, timeEntry: array<string, mixed>} $decoded */
		$decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
		return $decoded;
	}

	/**
	 * @return array{success: bool, timeEntry: array<string, mixed>}|null
	 */
	private function waitForCompletedPayload(string $userId, string $clientRequestId): ?array
	{
		for ($i = 0; $i < 8; $i++) {
			usleep(25_000);
			$cached = $this->readCompletedPayload($userId, $clientRequestId);
			if ($cached !== null) {
				return $cached;
			}
			$still = $this->idempotencyMapper->findByUserAndRequestId($userId, $clientRequestId);
			if ($still === null) {
				return null;
			}
		}
		return $this->readCompletedPayload($userId, $clientRequestId);
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
