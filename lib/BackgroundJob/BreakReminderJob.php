<?php

declare(strict_types=1);

/**
 * Break reminder background job for the arbeitszeitcheck app
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
 * Break reminder job
 *
 * Checks for users who have worked 6+ hours without taking a break
 * Runs every 30 minutes during business hours
 */
class BreakReminderJob extends TimedJob
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

		// Run every 30 minutes
		$this->setInterval(30 * 60);
	}

	/**
	 * @inheritDoc
	 */
	protected function run($argument): void
	{
		$currentHour = (int)date('G');
		
		// Only run during business hours (6 AM to 10 PM)
		if ($currentHour < 6 || $currentHour >= 22) {
			return;
		}

		$this->logger->info('Starting break reminder check');

		try {
			$remindersSent = 0;

			// Check all users
			$this->userManager->callForAllUsers(function ($user) use (&$remindersSent) {
				$userId = $user->getUID();

				// Skip if user is disabled
				if (!$user->isEnabled()) {
					return;
				}

				// Check user settings - skip if break reminders disabled
				$breakRemindersEnabled = $this->userSettingsMapper->getBooleanSetting(
					$userId,
					'break_reminders_enabled',
					true // Default: enabled
				);
				
				if (!$breakRemindersEnabled) {
					return;
				}

				// Find active time entries
				$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
				
				if ($activeEntry === null) {
					return;
				}

				// Calculate hours worked today (including current active entry)
				$today = new \DateTime();
				$today->setTime(0, 0, 0);
				$tomorrow = clone $today;
				$tomorrow->modify('+1 day');

				$totalHoursToday = $this->timeEntryMapper->getTotalHoursByUserAndDateRange(
					$userId,
					$today,
					$tomorrow
				);

				// Add current active entry hours
				$startTime = $activeEntry->getStartTime();
				$now = new \DateTime();
				$currentSessionHours = ($now->getTimestamp() - $startTime->getTimestamp()) / 3600;
				
				// Subtract break time if on break
				if ($activeEntry->getBreakStartTime() !== null && $activeEntry->getBreakEndTime() === null) {
					$breakHours = ($now->getTimestamp() - $activeEntry->getBreakStartTime()->getTimestamp()) / 3600;
					$currentSessionHours -= $breakHours;
				} elseif ($activeEntry->getBreakStartTime() !== null && $activeEntry->getBreakEndTime() !== null) {
					$breakHours = ($activeEntry->getBreakEndTime()->getTimestamp() - $activeEntry->getBreakStartTime()->getTimestamp()) / 3600;
					$currentSessionHours -= $breakHours;
				}

				$totalHoursWorked = $totalHoursToday + $currentSessionHours;

				// Check if break is required
				$breakDuration = $activeEntry->getBreakDurationHours();
				$requiredBreak = 0;

				if ($totalHoursWorked >= 6 && $breakDuration < 0.5) {
					// 30 minutes break required after 6 hours
					$requiredBreak = 30;
				} elseif ($totalHoursWorked >= 9 && $breakDuration < 0.75) {
					// 45 minutes break required after 9 hours
					$requiredBreak = 45;
				}

				if ($requiredBreak > 0) {
					// Check if we already sent a reminder in the last hour for this entry
					// (to avoid spamming)
					$lastReminderTime = $this->config->getUserValue(
						$userId,
						'arbeitszeitcheck',
						'last_break_reminder_' . $activeEntry->getId(),
						0
					);

					$oneHourAgo = time() - 3600;
					
					if ($lastReminderTime < $oneHourAgo) {
						$this->notificationService->notifyBreakReminder($userId, [
							'id' => $activeEntry->getId(),
							'hours_worked' => round($totalHoursWorked, 2),
							'required_break_minutes' => $requiredBreak
						]);

						// Store reminder timestamp
						$this->config->setUserValue(
							$userId,
							'arbeitszeitcheck',
							'last_break_reminder_' . $activeEntry->getId(),
							(string)time()
						);

						$remindersSent++;

						$this->logger->info('Break reminder sent', [
							'user_id' => $userId,
							'entry_id' => $activeEntry->getId(),
							'hours_worked' => round($totalHoursWorked, 2),
							'required_break' => $requiredBreak
						]);
					}
				}
			});

			if ($remindersSent > 0) {
				$this->logger->info('Break reminder check completed', [
					'reminders_sent' => $remindersSent
				]);
			}
		} catch (\Exception $e) {
			$this->logger->error('Break reminder check failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
		}
	}
}
