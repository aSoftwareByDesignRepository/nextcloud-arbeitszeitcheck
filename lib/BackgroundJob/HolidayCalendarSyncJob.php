<?php

declare(strict_types=1);

/**
 * Periodic sync of public holidays into Nextcloud Calendar for users who opted in.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Service\HolidayNcCalendarSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;

class HolidayCalendarSyncJob extends TimedJob
{
	public function __construct(
		ITimeFactory $timeFactory,
		private HolidayNcCalendarSyncService $holidayNcCalendarSyncService,
	) {
		parent::__construct($timeFactory);
		$this->setInterval(24 * 60 * 60);
	}

	protected function run($argument): void
	{
		$offset = 0;
		$batch = 100;
		while (true) {
			$n = $this->holidayNcCalendarSyncService->syncBatch($batch, $offset);
			if ($n === 0) {
				break;
			}
			$offset += $batch;
		}
	}
}
