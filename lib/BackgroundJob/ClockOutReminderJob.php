<?php

declare(strict_types=1);

/**
 * Clock-out reminder background job for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Clock-out reminder job
 *
 * Checks for users who are still clocked in and sends reminders
 * Runs every hour during business hours
 */
class ClockOutReminderJob extends TimedJob
{
	private TimeEntryMapper $timeEntryMapper;
	private UserSettingsMapper $userSettingsMapper;
	private NotificationService $notificationService;
	private IUserManager $userManager;
	private IConfig $config;
	private LoggerInterface $logger;
	private ILockingProvider $locking;
	private PermissionService $permissionService;
	private TimeZoneService $timeZoneService;

	public function __construct(
		ITimeFactory $timeFactory,
		TimeEntryMapper $timeEntryMapper,
		UserSettingsMapper $userSettingsMapper,
		NotificationService $notificationService,
		IUserManager $userManager,
		IConfig $config,
		LoggerInterface $logger,
		PermissionService $permissionService,
		TimeZoneService $timeZoneService,
		ILockingProvider $locking
	) {
		parent::__construct($timeFactory);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->notificationService = $notificationService;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->logger = $logger;
		$this->locking = $locking;
		$this->permissionService = $permissionService;
		$this->timeZoneService = $timeZoneService;

		// Run every hour
		$this->setInterval(60 * 60);
	}

	/**
	 * @inheritDoc
	 */
	protected function run($argument): void
	{
		// Shared lock on the upgrade-backup key: a restore holds it
		// exclusively (UpgradeBackupService::runExclusive) — app-table
		// scans/writes must not interleave with a mid-restore
		// truncate+reinsert. A skip is not a failure: TimedJobs re-run
		// on their interval.
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'clock-out reminder check',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping clock-out reminder check — upgrade backup/restore in progress.',
				['app' => 'arbeitszeitcheck'],
			);
			return;
		}
		try {
			$this->doRun($argument);
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}

	private function doRun($argument): void
	{
		$currentHour = (int)date('G');
		
		// Only run during business hours (6 AM to 10 PM)
		if ($currentHour < 6 || $currentHour >= 22) {
			return;
		}

		$this->logger->info('Starting clock-out reminder check');

		try {
			$remindersSent = 0;

			// Check all users
			$this->userManager->callForAllUsers(function ($user) use (&$remindersSent) {
				$userId = $user->getUID();

				if (!$user->isEnabled()) {
					return;
				}
				if (!$this->permissionService->isUserAllowedByAccessGroups($userId)) {
					return;
				}

				$notificationsEnabled = $this->userSettingsMapper->getBooleanSetting(
					$userId,
					'notifications_enabled',
					true
				);
				if (!$notificationsEnabled) {
					return;
				}

				// Find active time entries
				$activeEntry = $this->timeEntryMapper->findActiveByUser($userId);
				
				if ($activeEntry === null) {
					return;
				}

				// Check if entry is older than 8 hours (likely forgot to clock out)
				$startTime = $activeEntry->getStartTime();
				$now = new \DateTime();
				$hoursWorked = ($now->getTimestamp() - $startTime->getTimestamp()) / 3600;

				// Send reminder if worked more than 8 hours
				if ($hoursWorked >= 8) {
					$lastReminderKey = 'last_clock_out_reminder_' . $activeEntry->getId();
					$lastReminderTime = (int)$this->config->getUserValue(
						$userId,
						'arbeitszeitcheck',
						$lastReminderKey,
						'0'
					);

					if ($lastReminderTime < (time() - 3600)) {
						// Format the reminder clock in the affected user's display TZ so
						// the notification matches their dashboard, not container UTC.
						$this->notificationService->notifyClockOutReminder($userId, [
							'id' => $activeEntry->getId(),
							'start_time' => $this->timeZoneService->formatForDisplay($startTime, 'H:i', $userId),
							'hours_worked' => round($hoursWorked, 2)
						]);
						$this->config->setUserValue(
							$userId,
							'arbeitszeitcheck',
							$lastReminderKey,
							(string)time()
						);
						
						$remindersSent++;
						
						$this->logger->info('Clock-out reminder sent', [
							'user_id' => $userId,
							'entry_id' => $activeEntry->getId(),
							'hours_worked' => round($hoursWorked, 2)
						]);
					}
				}
			});

			if ($remindersSent > 0) {
				$this->logger->info('Clock-out reminder check completed', [
					'reminders_sent' => $remindersSent
				]);
			}
		} catch (\Exception $e) {
			$this->logger->error('Clock-out reminder check failed', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
		}
	}
}
