<?php

declare(strict_types=1);

/**
 * Missing time entry alert background job for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\BackgroundJob;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
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
	private AbsenceMapper $absenceMapper;
	private HolidayService $holidayService;
	private NotificationService $notificationService;
	private IUserManager $userManager;
	private IConfig $config;
	private LoggerInterface $logger;
	private ILockingProvider $locking;
	private PermissionService $permissionService;

	public function __construct(
		ITimeFactory $timeFactory,
		TimeEntryMapper $timeEntryMapper,
		AbsenceMapper $absenceMapper,
		HolidayService $holidayService,
		NotificationService $notificationService,
		IUserManager $userManager,
		IConfig $config,
		LoggerInterface $logger,
		PermissionService $permissionService,
		ILockingProvider $locking
	) {
		parent::__construct($timeFactory);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->absenceMapper = $absenceMapper;
		$this->holidayService = $holidayService;
		$this->notificationService = $notificationService;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->logger = $logger;
		$this->locking = $locking;
		$this->permissionService = $permissionService;

		// Run daily at 9 AM
		$this->setInterval(24 * 60 * 60);
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
				'missing time entry check',
			);
		} catch (LockedException) {
			$this->logger->warning(
				'ArbeitszeitCheck: skipping missing time entry check — upgrade backup/restore in progress.',
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
		
		// Only run at 9 AM
		if ($currentHour !== 9) {
			return;
		}

		$this->logger->info('Starting missing time entry check');

		try {
			$yesterday = (new \DateTime())->modify('-1 day')->setTime(0, 0, 0);
			$today = new \DateTime();
			$today->setTime(0, 0, 0);

			$alertsSent = 0;

			// Check all users
			$this->userManager->callForAllUsers(function ($user) use ($yesterday, $today, &$alertsSent) {
				$userId = $user->getUID();

				try {
					// Skip if user is disabled
					if (!$user->isEnabled()) {
						return;
					}
					if (!$this->permissionService->isUserAllowedByAccessGroups($userId)) {
						return;
					}
					if ($this->processUserForDate($userId, $yesterday, $today)) {
						$alertsSent++;
					}
				} catch (\Throwable $e) {
					$this->logger->warning('Missing time entry check failed for user', [
						'user_id' => $userId,
						'exception' => $e->getMessage(),
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

	private function processUserForDate(string $userId, \DateTimeInterface $yesterday, \DateTimeInterface $today): bool
	{
		if (!$this->notificationService->shouldSendMissingClockInReminder($userId)) {
			$this->logger->debug('Missing time entry reminder skipped by policy', ['user_id' => $userId]);
			return false;
		}

		// Skip reminders for non-working days to avoid false positives.
		if ($this->isNonWorkingDay($userId, $yesterday)) {
			$this->logger->debug('Missing time entry reminder skipped for non-working day', [
				'user_id' => $userId,
				'date' => $yesterday->format('Y-m-d'),
			]);
			return false;
		}

		// Check if user has any time entries for yesterday
		$entries = $this->timeEntryMapper->findByUserAndDateRange(
			$userId,
			\DateTime::createFromInterface($yesterday),
			\DateTime::createFromInterface($today)
		);
		if (!empty($entries)) {
			return false;
		}

		$dateKey = $yesterday->format('Y-m-d');
		$lastReminderDate = $this->config->getUserValue(
			$userId,
			'arbeitszeitcheck',
			'last_missing_clock_in_reminder_date',
			''
		);
		if ($lastReminderDate === $dateKey) {
			$this->logger->debug('Missing time entry reminder skipped due to dedupe', [
				'user_id' => $userId,
				'date' => $dateKey,
			]);
			return false;
		}

		// No time entries found for yesterday - send alert
		$this->notificationService->notifyMissingTimeEntry(
			$userId,
			$dateKey
		);
		$this->config->setUserValue(
			$userId,
			'arbeitszeitcheck',
			'last_missing_clock_in_reminder_date',
			$dateKey
		);

		$this->logger->info('Missing time entry alert sent', [
			'user_id' => $userId,
			'date' => $yesterday->format('Y-m-d')
		]);

		return true;
	}

	private function isNonWorkingDay(string $userId, \DateTimeInterface $date): bool
	{
		$weekday = (int)$date->format('N');
		if ($weekday >= 6) {
			return true;
		}

		if ($this->holidayService->isHolidayForUser($userId, (clone $date))) {
			return true;
		}

		$rangeStart = (new \DateTime($date->format('Y-m-d')))->setTime(0, 0, 0);
		$rangeEnd = (new \DateTime($date->format('Y-m-d')))->setTime(23, 59, 59);
		$absences = $this->absenceMapper->findByUserAndDateRange($userId, $rangeStart, $rangeEnd);
		foreach ($absences as $absence) {
			if ($absence->getStatus() === Absence::STATUS_APPROVED) {
				return true;
			}
		}

		return false;
	}
}
