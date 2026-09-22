<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\ManagerPendingApprovalMailService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily digest of open manager approvals (when mode=digest).
 */
class ManagerPendingApprovalDigestJob extends TimedJob
{
	public function __construct(
		ITimeFactory $timeFactory,
		private ManagerPendingApprovalMailService $mailService,
		private LoggerInterface $logger,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		try {
			$sent = $this->mailService->sendDailyDigests();
			if ($sent > 0) {
				$this->logger->info('arbeitszeitcheck: manager pending digest sent to {n} manager(s)', [
					'app' => 'arbeitszeitcheck',
					'n' => $sent,
				]);
			}
		} catch (\Throwable $e) {
			$this->logger->warning('arbeitszeitcheck: manager pending digest failed: ' . $e->getMessage(), [
				'app' => 'arbeitszeitcheck',
				'exception' => $e,
			]);
		}
	}
}
