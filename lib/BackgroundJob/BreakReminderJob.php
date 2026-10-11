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
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Support\BreakPolicyResolver;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Break reminder job
 *
 * Checks for users who have reached a country-profile break tier without
 * taking the required break. Runs every 30 minutes during business hours.
 */
class BreakReminderJob extends TimedJob
{
	private TimeEntryMapper $timeEntryMapper;
	private UserSettingsMapper $userSettingsMapper;
	private NotificationService $notificationService;
	private IUserManager $userManager;
	private IConfig $config;
	private LoggerInterface $logger;
	private PermissionService $permissionService;
	private TimeTrackingService $timeTrackingService;
	private LaborLawProfileFactory $lawProfileFactory;
	private ?BreakPolicyResolver $breakPolicyResolver;

	public function __construct(
		ITimeFactory $timeFactory,
		TimeEntryMapper $timeEntryMapper,
		UserSettingsMapper $userSettingsMapper,
		NotificationService $notificationService,
		IUserManager $userManager,
		IConfig $config,
		LoggerInterface $logger,
		PermissionService $permissionService,
		TimeTrackingService $timeTrackingService,
		LaborLawProfileFactory $lawProfileFactory,
		?BreakPolicyResolver $breakPolicyResolver = null,
	) {
		parent::__construct($timeFactory);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->notificationService = $notificationService;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->logger = $logger;
		$this->permissionService = $permissionService;
		$this->timeTrackingService = $timeTrackingService;
		$this->lawProfileFactory = $lawProfileFactory;
		$this->breakPolicyResolver = $breakPolicyResolver;

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
				if (!$this->permissionService->isUserAllowedByAccessGroups($userId)) {
					return;
				}

				$currentBreakEntry = $this->timeEntryMapper->findOnBreakByUser($userId);
				$afterFallback = $this->timeTrackingService->enforceBreakAutoFallbackForUser($userId, $currentBreakEntry);
				if ($currentBreakEntry !== null && $afterFallback === null) {
					$this->logger->info('Break auto-fallback executed in background job', [
						'user_id' => $userId,
						'entry_id' => $currentBreakEntry->getId(),
					]);
					return;
				}

				$autoCompletedEntry = $this->timeTrackingService->enforceDailyMaximumForUser($userId);
				if ($autoCompletedEntry !== null) {
					$this->logger->info('Daily maximum auto-completion executed in background job', [
						'user_id' => $userId,
						'entry_id' => $autoCompletedEntry->getId(),
					]);
					return;
				}

				// Check user settings - skip if break reminders disabled
				$notificationsEnabled = $this->userSettingsMapper->getBooleanSetting(
					$userId,
					'notifications_enabled',
					true
				);
				if (!$notificationsEnabled) {
					return;
				}

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

				// Calendar-day hours (midnight-clipped), same rules as TimeTrackingService::getTodayHours().
				$totalHoursWorked = $this->timeTrackingService->getTodayHours($userId);

				// Effective required break = max(law, org policy, model rules)
				// via the single resolver funnel — reminders must reflect the
				// same obligation the compliance checks enforce.
				$requiredBreakMinutes = $this->effectiveRequiredBreakMinutes($userId, $totalHoursWorked);
				$takenBreakMinutes = (int)round($activeEntry->getBreakDurationHours() * 60);
				$requiredBreak = $requiredBreakMinutes > $takenBreakMinutes
					? $requiredBreakMinutes
					: 0;

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

	/**
	 * Effective required break minutes for a user at a given hours-worked
	 * level. Prefers the central BreakPolicyResolver (law ∪ org ∪ model);
	 * falls back to the country labor-law profile when the resolver is not
	 * available or fails — a reminder must never crash the whole job.
	 */
	private function effectiveRequiredBreakMinutes(string $userId, float $totalHoursWorked): int
	{
		$resolver = $this->breakPolicyResolver;
		if ($resolver === null) {
			try {
				$resolver = \OCP\Server::get(BreakPolicyResolver::class);
			} catch (\Throwable) {
				$resolver = null;
			}
		}
		if ($resolver !== null) {
			try {
				return $resolver->requiredBreakMinutes(
					(int)round($totalHoursWorked * 3600),
					$userId
				);
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Break policy resolver failed in reminder job, using law profile: ' . $e->getMessage(),
					['exception' => $e, 'user_id' => $userId]
				);
			}
		}
		return $this->lawProfileFactory->getProfile($userId)
			->requiredBreakMinutes($totalHoursWorked);
	}
}
