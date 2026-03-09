<?php

declare(strict_types=1);

/**
 * Missing time entry alert background job for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Missing time entry alert job
 *
 * Checks for users who didn't record any time entry for the previous day
 * Runs daily at 9 AM
 */
class MissingTimeEntryJob extends TimedJob
{
	private TimeEntryMapper $timeEntryMapper;
	private UserSettingsMapper $userSettingsMapper;
	private NotificationService $notificationService;
	private IUserManager $userManager;
	private IConfig $config;
	private LoggerInterface $logger;

	public function __construct(
		ITimeFactory $timeFactory,
		TimeEntryMapper $timeEntryMapper,
		UserSettingsMapper $userSettingsMapper,
		NotificationService $notificationService,
		IUserManager $userManager,
		IConfig $config,
		LoggerInterface $logger
	) {
		parent::__construct($timeFactory);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->notificationService = $notificationService;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->logger = $logger;

		// Run daily at 9 AM
		$this->setInterval(24 * 60 * 60);
	}

	/**
	 * @inheritDoc
	 */
	protected function run($argument): void
	{
		$currentHour = (int)date('G');
		
		// Only run at 9 AM
		if ($currentHour !== 9) {
			return;
		}

		$this->logger->info('Starting missing time entry check');

		try {
			$yesterday = new \DateTime();
			$yesterday->modify('-1 day');
			$yesterday->setTime(0, 0, 0);
			$today = new \DateTime();
			$today->setTime(0, 0, 0);

			$alertsSent = 0;

			// Check all users
			$this->userManager->callForAllUsers(function ($user) use ($yesterday, $today, &$alertsSent) {
				$userId = $user->getUID();

				// Skip if user is disabled
				if (!$user->isEnabled()) {
					return;
				}

				// Check user settings - skip if notifications disabled
				$notificationsEnabled = $this->userSettingsMapper->getBooleanSetting(
					$userId,
					'notifications_enabled',
					true // Default: enabled
				);
				
				if (!$notificationsEnabled) {
					return;
				}

				// Check if user has any time entries for yesterday
				$entries = $this->timeEntryMapper->findByUserAndDateRange($userId, $yesterday, $today);

				if (empty($entries)) {
					// No time entries found for yesterday - send alert
					$this->notificationService->notifyMissingTimeEntry(
						$userId,
						$yesterday->format('Y-m-d')
					);

					$alertsSent++;

					$this->logger->info('Missing time entry alert sent', [
						'user_id' => $userId,
						'date' => $yesterday->format('Y-m-d')
					]);
				}
			});

			if ($alertsSent > 0) {
				$this->logger->info('Missing time entry check completed', [
					'alerts_sent' => $alertsSent,
					'check_date' => $yesterday->format('Y-m-d')
				]);
			}
		} catch (\Exception $e) {
			$this->logger->error('Missing time entry check failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
		}
	}
}
