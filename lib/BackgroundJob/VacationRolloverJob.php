<?php

declare(strict_types=1);

/**
 * Daily job: apply vacation carryover rollover when enabled (see VacationRolloverService).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Service\VacationRolloverService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

class VacationRolloverJob extends TimedJob
{
	public function __construct(
		ITimeFactory $timeFactory,
		private VacationRolloverService $vacationRolloverService,
		private LoggerInterface $logger,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		try {
			$stats = $this->vacationRolloverService->runForAllUsers(null, false, false, false);
			$this->logger->info('Vacation rollover job finished', [
				'app' => 'arbeitszeitcheck',
				'applied' => $stats['applied'],
				'skipped' => $stats['skipped'],
				'errors' => $stats['errors'],
			]);
		} catch (\Throwable $e) {
			$this->logger->error('Vacation rollover job failed', [
				'app' => 'arbeitszeitcheck',
				'exception' => $e,
			]);
		}
	}
}
