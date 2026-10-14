<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Db\KioskStampIdempotencyMapper;
use OCA\ArbeitszeitCheck\Db\MobileStampIdempotencyMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Reaps offline stamp idempotency rows past the replay window.
 *
 * Rows exist so retried/replayed client requests resolve to one server
 * mutation. Once a row is older than any plausible drain lag (queue skew is
 * capped at 72 h), it only grows the table — delete after 7 days.
 */
class StampIdempotencyCleanupJob extends TimedJob
{
	private const RETENTION_SECONDS = 7 * 24 * 3600;

	public function __construct(
		ITimeFactory $timeFactory,
		private readonly MobileStampIdempotencyMapper $mobileMapper,
		private readonly KioskStampIdempotencyMapper $kioskMapper,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(24 * 3600);
	}

	protected function run($argument): void
	{
		$cutoff = $this->time->getTime() - self::RETENTION_SECONDS;
		$mobile = $this->mobileMapper->deleteOlderThan($cutoff);
		$kiosk = $this->kioskMapper->deleteOlderThan($cutoff);
		if ($mobile + $kiosk > 0) {
			$this->logger->info('Stamp idempotency cleanup: removed expired rows', [
				'mobile' => $mobile,
				'kiosk' => $kiosk,
			]);
		}
	}
}
