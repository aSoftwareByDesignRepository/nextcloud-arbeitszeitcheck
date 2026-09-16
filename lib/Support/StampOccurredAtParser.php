<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Exception\StampReplayException;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCP\IConfig;

final class StampOccurredAtParser
{
	/** @deprecated Use OfflineStampSkewPolicy::DEFAULT_PAST_HOURS / fromConfig() */
	public const MAX_PAST_SECONDS = 86400;
	public const MAX_FUTURE_SECONDS = OfflineStampSkewPolicy::MAX_FUTURE_SECONDS;

	public function __construct(
		private readonly TimeZoneService $timeZoneService,
		private readonly IConfig $config,
	) {
	}

	public function parseOptional(?string $occurredAtRaw, ?string $clientRequestId): ?\DateTimeInterface
	{
		if ($occurredAtRaw === null || trim($occurredAtRaw) === '') {
			return null;
		}
		if ($clientRequestId === null) {
			throw new StampReplayException('STAMP_CLIENT_REQUEST_ID_REQUIRED');
		}
		try {
			$storage = $this->timeZoneService->parseClientDateTime(trim($occurredAtRaw));
		} catch (\Throwable) {
			throw new StampReplayException('STAMP_OCCURRED_AT_INVALID');
		}
		$now = $this->timeZoneService->nowInStorage();
		$delta = $storage->getTimestamp() - $now->getTimestamp();
		$maxPast = OfflineStampSkewPolicy::pastSeconds(OfflineStampSkewPolicy::fromConfig($this->config));
		if ($delta < -$maxPast) {
			throw new StampReplayException('STAMP_OCCURRED_AT_OUT_OF_BOUNDS');
		}
		if ($delta > self::MAX_FUTURE_SECONDS) {
			throw new StampReplayException('STAMP_OCCURRED_AT_OUT_OF_BOUNDS');
		}
		return $storage;
	}
}
