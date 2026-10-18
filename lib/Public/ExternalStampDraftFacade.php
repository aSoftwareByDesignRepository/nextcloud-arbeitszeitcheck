<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Public;

use OCA\ArbeitszeitCheck\Service\ExternalStampService;
use Psr\Log\LoggerInterface;

/**
 * Suite flanges F16/F19b — pending-stamp write facade.
 *
 * Sibling apps propose time evidence (TourCheck first scan, CareCheck
 * work-log pull). Every proposal lands as a pending draft that only the
 * affected worker can confirm or dismiss — the facade never mints counted
 * time. Consumers probe `class_exists` + `FACADE_VERSION >= 1`, resolve via
 * `OCP\Server::get`, and must fail closed on absence.
 */
class ExternalStampDraftFacade
{
	public const FACADE_VERSION = 1;

	public function __construct(
		private readonly ExternalStampService $service,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * Propose a stamp (point-in-time, `$endTs === null`) or a worked segment.
	 * Idempotent on `$sourceRef` — replays return the stored draft.
	 *
	 * @return array{id:int, status:string, created:bool}|null null when the
	 *         proposal was invalid (unknown user, impossible timestamps,
	 *         disallowed source/ref) — treat as "not accepted", do not retry
	 *         with identical input.
	 */
	public function propose(string $uid, int $startTs, ?int $endTs, string $sourceApp, string $sourceRef): ?array
	{
		try {
			$result = $this->service->propose($uid, $startTs, $endTs, $sourceApp, $sourceRef);
			if ($result === null) {
				return null;
			}
			$stamp = $result['stamp'];
			return [
				'id' => (int)$stamp->getId(),
				'status' => $stamp->getStatus(),
				'created' => $result['created'],
			];
		} catch (\Throwable $e) {
			$this->logger->info('arbeitszeitcheck external-stamp facade failed', ['exception' => $e->getMessage()]);
			return null;
		}
	}
}
