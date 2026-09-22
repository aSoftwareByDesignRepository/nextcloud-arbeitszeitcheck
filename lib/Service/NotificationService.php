<?php

declare(strict_types=1);

/**
 * Notification service for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Util\AbsenceWorkingDaysResolver;
use OCA\ArbeitszeitCheck\Constants;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;

/**
 * NotificationService for sending notifications to users
 */
class NotificationService
{
	public const CONFIG_MISSING_CLOCK_IN_REMINDERS_ENABLED = 'missing_clock_in_reminders_enabled';
	public const USER_SETTING_MISSING_CLOCK_IN_REMINDERS_ENABLED = 'missing_clock_in_reminders_enabled';

	private INotificationManager $notificationManager;
	private IL10N $l10n;
	private UserSettingsMapper $userSettingsMapper;
	private IUserManager $userManager;
	private IConfig $config;
	private AbsenceWorkingDaysResolver $workingDaysResolver;
	private TimeCaptureMethodService $timeCaptureMethodService;
	private ?TeamResolverService $teamResolver;
	private ?ManagerPendingApprovalMailService $managerPendingMail;

	public function __construct(
		INotificationManager $notificationManager,
		IL10N $l10n,
		UserSettingsMapper $userSettingsMapper,
		IUserManager $userManager,
		IConfig $config,
		AbsenceWorkingDaysResolver $workingDaysResolver,
		TimeCaptureMethodService $timeCaptureMethodService,
		?TeamResolverService $teamResolver = null,
		?ManagerPendingApprovalMailService $managerPendingMail = null,
	) {
		$this->notificationManager = $notificationManager;
		$this->l10n = $l10n;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->userManager = $userManager;
		$this->config = $config;
		$this->workingDaysResolver = $workingDaysResolver;
		$this->timeCaptureMethodService = $timeCaptureMethodService;
		$this->teamResolver = $teamResolver;
		$this->managerPendingMail = $managerPendingMail;
	}

	/**
	 * Send a compliance violation notification to a user
	 *
	 * @param string $userId User ID to notify
	 * @param array $violationData Violation data
	 * @return void
	 */
	public function notifyComplianceViolation(string $userId, array $violationData): void
	{
		if ($this->config->getAppValue('arbeitszeitcheck', 'enable_violation_notifications', '1') !== '1') {
			return;
		}

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('compliance_violation', (string)($violationData['id'] ?? ''))
			->setSubject('compliance_violation', [
				'violation_type' => $violationData['type'] ?? 'unknown',
				'violation_id' => $violationData['id'] ?? null,
				'severity' => $violationData['severity'] ?? 'warning'
			])
			->setMessage('compliance_violation', [
				'message' => $violationData['message'] ?? $this->l10n->t('A compliance violation has been detected'),
				'date' => $violationData['date'] ?? date('Y-m-d'),
				'type' => $violationData['type'] ?? 'unknown'
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Send a substitution request notification to the substitute
	 *
	 * @param string $substituteUserId User ID of the substitute
	 * @param string $employeeUserId User ID of the employee requesting absence
	 * @param array $absenceData Absence data
	 * @return void
	 */
	public function notifySubstitutionRequest(string $substituteUserId, string $employeeUserId, array $absenceData): void
	{
		$employee = $this->userManager->get($employeeUserId);
		$employeeName = $employee ? $employee->getDisplayName() : $employeeUserId;

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($substituteUserId)
			->setDateTime(new \DateTime())
			->setObject('absence_substitution', (string)($absenceData['id'] ?? ''))
			->setSubject('substitution_request', [
				'absence_id' => $absenceData['id'] ?? null,
				'employee_user_id' => $employeeUserId,
				'employee_display_name' => $employeeName,
				'start_date' => $absenceData['start_date'] ?? null,
				'end_date' => $absenceData['end_date'] ?? null,
				'type' => $absenceData['type'] ?? 'vacation',
				'days' => $absenceData['days'] ?? 0
			])
			->setMessage('substitution_request', [
				'employee_display_name' => $employeeName,
				'start_date' => $absenceData['start_date'] ?? null,
				'end_date' => $absenceData['end_date'] ?? null,
				'days' => $absenceData['days'] ?? 0
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Notify employee that substitute approved the absence
	 *
	 * @param string $employeeUserId User ID of the employee
	 * @param string $substituteUserId User ID of the substitute
	 * @param array $absenceData Absence data
	 * @return void
	 */
	public function notifySubstituteApproved(string $employeeUserId, string $substituteUserId, array $absenceData): void
	{
		$substitute = $this->userManager->get($substituteUserId);
		$substituteName = $substitute ? $substitute->getDisplayName() : $substituteUserId;

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($employeeUserId)
			->setDateTime(new \DateTime())
			->setObject('absence', (string)($absenceData['id'] ?? ''))
			->setSubject('substitute_approved', [
				'absence_id' => $absenceData['id'] ?? null,
				'substitute_user_id' => $substituteUserId,
				'substitute_display_name' => $substituteName,
				'start_date' => $absenceData['start_date'] ?? null,
				'end_date' => $absenceData['end_date'] ?? null
			])
			->setMessage('substitute_approved', [
				'substitute_display_name' => $substituteName,
				'start_date' => $absenceData['start_date'] ?? null,
				'end_date' => $absenceData['end_date'] ?? null
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Notify employee that substitute declined the absence
	 *
	 * @param string $employeeUserId User ID of the employee
	 * @param string $substituteUserId User ID of the substitute
	 * @param array $absenceData Absence data
	 * @param string|null $comment Decline comment
	 * @return void
	 */
	public function notifySubstituteDeclined(string $employeeUserId, string $substituteUserId, array $absenceData, ?string $comment = null): void
	{
		$substitute = $this->userManager->get($substituteUserId);
		$substituteName = $substitute ? $substitute->getDisplayName() : $substituteUserId;

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($employeeUserId)
			->setDateTime(new \DateTime())
			->setObject('absence', (string)($absenceData['id'] ?? ''))
			->setSubject('substitute_declined', [
				'absence_id' => $absenceData['id'] ?? null,
				'substitute_user_id' => $substituteUserId,
				'substitute_display_name' => $substituteName,
				'start_date' => $absenceData['start_date'] ?? null,
				'end_date' => $absenceData['end_date'] ?? null,
				'reason' => $comment
			])
			->setMessage('substitute_declined', [
				'substitute_display_name' => $substituteName,
				'reason' => $comment ?? $this->l10n->t('No reason provided')
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Send an absence approval notification
	 *
	 * @param string $userId User ID to notify
	 * @param array $absenceData Absence data
	 * @return void
	 */
	public function notifyAbsenceApproved(string $userId, array $absenceData): void
	{
		$days = $this->workingDaysResolver->resolveFromNotificationParameters($absenceData);
		$type = (string)($absenceData['type'] ?? 'vacation');
		$absenceId = $absenceData['id'] ?? $absenceData['absence_id'] ?? null;
		$startDate = $absenceData['start_date'] ?? null;
		$endDate = $absenceData['end_date'] ?? null;

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('absence', (string)($absenceId ?? ''))
			->setSubject('absence_approved', [
				'absence_id' => $absenceId,
				'type' => $type,
				'start_date' => $startDate,
				'end_date' => $endDate,
				'days' => $days,
			])
			->setMessage('absence_approved', [
				'type' => $type,
				'days' => $days,
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Send an absence rejection notification
	 *
	 * @param string $userId User ID to notify
	 * @param array $absenceData Absence data
	 * @param string|null $reason Rejection reason
	 * @return void
	 */
	public function notifyAbsenceRejected(string $userId, array $absenceData, ?string $reason = null): void
	{
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('absence', (string)($absenceData['id'] ?? ''))
			->setSubject('absence_rejected', [
				'absence_id' => $absenceData['id'] ?? null,
				'start_date' => $absenceData['start_date'] ?? null,
				'end_date' => $absenceData['end_date'] ?? null,
				'reason' => $reason
			])
			->setMessage('absence_rejected', [
				'type' => $absenceData['type'] ?? 'vacation',
				'reason' => $reason ?? $this->l10n->t('No reason provided')
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Send a reminder to clock out
	 *
	 * @param string $userId User ID to notify
	 * @param array $timeEntryData Time entry data
	 * @return void
	 */
	public function notifyClockOutReminder(string $userId, array $timeEntryData): void
	{
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('time_entry', (string)($timeEntryData['id'] ?? ''))
			->setSubject('reminder_clock_out', [
				'entry_id' => $timeEntryData['id'] ?? null,
				'start_time' => $timeEntryData['start_time'] ?? null,
				'hours_worked' => $timeEntryData['hours_worked'] ?? 0
			])
			->setMessage('reminder_clock_out', [
				'start_time' => $timeEntryData['start_time'] ?? null,
				'hours_worked' => $timeEntryData['hours_worked'] ?? 0
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Send a break reminder notification
	 *
	 * @param string $userId User ID to notify
	 * @param array $timeEntryData Time entry data
	 * @return void
	 */
	public function notifyBreakReminder(string $userId, array $timeEntryData): void
	{
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('time_entry', (string)($timeEntryData['id'] ?? ''))
			->setSubject('reminder_break', [
				'entry_id' => $timeEntryData['id'] ?? null,
				'hours_worked' => $timeEntryData['hours_worked'] ?? 0,
				'required_break' => $timeEntryData['required_break_minutes'] ?? 30
			])
			->setMessage('reminder_break', [
				'hours_worked' => $timeEntryData['hours_worked'] ?? 0,
				'required_break' => $timeEntryData['required_break_minutes'] ?? 30
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Send a notification about missing time entry
	 *
	 * @param string $userId User ID to notify
	 * @param string $date Date with missing entry
	 * @return void
	 */
	public function notifyMissingTimeEntry(string $userId, string $date): void
	{
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('time_entry', 'missing_' . $date)
			->setSubject('missing_time_entry', [
				'date' => $date
			])
			->setMessage('missing_time_entry', [
				'date' => $date,
				'message' => $this->l10n->t('No time entry recorded for %s', [$date])
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Evaluate if missing clock-in reminders are enabled for a user.
	 */
	public function shouldSendMissingClockInReminder(string $userId): bool
	{
		$user = $this->userManager->get($userId);
		if ($user === null || !$user->isEnabled()) {
			return false;
		}

		if (!$this->timeCaptureMethodService->isClockStampingEnabled($userId)) {
			return false;
		}

		$globalEnabled = $this->config->getAppValue(
			'arbeitszeitcheck',
			self::CONFIG_MISSING_CLOCK_IN_REMINDERS_ENABLED,
			'1'
		) === '1';

		if (!$globalEnabled) {
			return false;
		}

		return $this->userSettingsMapper->getBooleanSetting(
			$userId,
			self::USER_SETTING_MISSING_CLOCK_IN_REMINDERS_ENABLED,
			true
		);
	}

	/**
	 * Send a notification about overtime warning
	 *
	 * @param string $userId User ID to notify
	 * @param array $overtimeData Overtime data
	 * @return void
	 */
	public function notifyOvertimeWarning(string $userId, array $overtimeData): void
	{
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('overtime', (string)($overtimeData['period'] ?? ''))
			->setSubject('overtime_warning', [
				'period' => $overtimeData['period'] ?? null,
				'overtime_hours' => $overtimeData['overtime_hours'] ?? 0
			])
			->setMessage('overtime_warning', [
				'overtime_hours' => $overtimeData['overtime_hours'] ?? 0,
				'limit' => $overtimeData['limit'] ?? 0,
				'period' => $overtimeData['period'] ?? 'current'
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Send traffic-light overtime or undertime notification.
	 *
	 * @param array{state: string, direction: string, level: string, balance: float} $trafficData
	 */
	public function notifyOvertimeTrafficLight(string $userId, array $trafficData): void
	{
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('overtime_traffic_light', (string)date('Y-m-d'))
			->setSubject('overtime_traffic_light', [
				'state' => $trafficData['state'] ?? 'green',
				'direction' => $trafficData['direction'] ?? 'over',
				'level' => $trafficData['level'] ?? 'yellow',
				'balance' => (float)($trafficData['balance'] ?? 0.0),
			])
			->setMessage('overtime_traffic_light', [
				'state' => $trafficData['state'] ?? 'green',
				'balance' => (float)($trafficData['balance'] ?? 0.0),
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Notify employee that overtime above the bank cap was paid out (Auszahlung).
	 *
	 * @param array<string, mixed> $payout hours_paid, calendar_year, calendar_month, effective_balance_after, id
	 */
	public function notifyOvertimePayout(string $userId, array $payout): void
	{
		$year = (int)($payout['calendar_year'] ?? 0);
		$month = (int)($payout['calendar_month'] ?? 0);
		$objectId = sprintf('%04d-%02d', $year, $month);

		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('overtime_payout', $objectId)
			->setSubject('overtime_payout', [
				'year' => $year,
				'month' => $month,
				'hours_paid' => (float)($payout['hours_paid'] ?? 0),
			])
			->setMessage('overtime_payout', [
				'hours_paid' => (float)($payout['hours_paid'] ?? 0),
				'year' => $year,
				'month' => $month,
				'effective_balance_after' => (float)($payout['effective_balance_after'] ?? 0),
				'payout_id' => (int)($payout['id'] ?? 0),
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Notify managers about a pending time-entry approval (manual create or correction).
	 * Prefer app-team managers; fall back to legacy per-user manager_id.
	 *
	 * @param string $userId Employee who requested
	 * @param array $timeEntryData Time entry summary
	 * @param string $justification Justification text
	 * @param string $kind {@see Constants::MANAGER_PENDING_KIND_MANUAL} or CORRECTION
	 */
	public function notifyTimeEntryCorrectionRequested(
		string $userId,
		array $timeEntryData,
		string $justification,
		string $kind = Constants::MANAGER_PENDING_KIND_CORRECTION,
	): void {
		$managerIds = $this->resolveManagerIds($userId);
		if ($managerIds === []) {
			return;
		}

		foreach ($managerIds as $managerId) {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('arbeitszeitcheck')
				->setUser($managerId)
				->setDateTime(new \DateTime())
				->setObject('time_entry_correction', (string)($timeEntryData['id'] ?? ''))
				->setSubject('time_entry_correction_requested', [
					'entry_id' => $timeEntryData['id'] ?? null,
					'user_id' => $userId,
					'date' => $timeEntryData['date'] ?? null
				])
				->setMessage('time_entry_correction_requested', [
					'user_id' => $userId,
					'date' => $timeEntryData['date'] ?? null,
					'justification' => $justification
				]);

			$this->notificationManager->notify($notification);
		}

		$this->managerPendingMail?->notifyManagersOfPending($kind, $userId, [
			'justification' => $justification,
			'date' => $timeEntryData['date'] ?? null,
		]);
	}

	/**
	 * @return list<string>
	 */
	private function resolveManagerIds(string $userId): array
	{
		if ($this->teamResolver !== null) {
			$fromTeams = $this->teamResolver->getManagerIdsForEmployee($userId);
			if ($fromTeams !== []) {
				return $fromTeams;
			}
		}
		$legacy = $this->getManagerId($userId);
		return $legacy !== null ? [$legacy] : [];
	}

	/**
	 * Get manager ID for a user
	 *
	 * This uses the per-user setting `manager_id` stored via UserSettingsMapper.
	 * If the setting is not defined, or the referenced user does not exist or
	 * is disabled, no manager is returned and no notification is sent.
	 *
	 * @param string $userId User ID
	 * @return string|null Manager user ID or null if no manager
	 */
	private function getManagerId(string $userId): ?string
	{
		$managerId = $this->userSettingsMapper->getStringSetting($userId, 'manager_id', '');
		if ($managerId === '') {
			return null;
		}

		$manager = $this->userManager->get($managerId);
		if ($manager === null || !$manager->isEnabled()) {
			return null;
		}

		return $manager->getUID();
	}

	/**
	 * Send a notification about time entry correction approval
	 *
	 * @param string $userId User ID to notify
	 * @param array $timeEntryData Time entry data
	 * @return void
	 */
	public function notifyTimeEntryCorrectionApproved(string $userId, array $timeEntryData): void
	{
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('time_entry', (string)($timeEntryData['id'] ?? ''))
			->setSubject('time_entry_correction_approved', [
				'entry_id' => $timeEntryData['id'] ?? null,
				'date' => $timeEntryData['date'] ?? null
			])
			->setMessage('time_entry_correction_approved', [
				'date' => $timeEntryData['date'] ?? null,
				'changes' => $timeEntryData['changes'] ?? []
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Send a warning notification to manager about working time compliance issues
	 * 
	 * @param string $userId User ID who has the compliance issue
	 * @param string $warningType Type of warning ('six_month_average' or 'weekly_hours')
	 * @param array $warningData Warning data (average, limit, etc.)
	 * @return void
	 */
	public function notifyManagerWorkingTimeWarning(string $userId, string $warningType, array $warningData): void
	{
		$managerIds = $this->resolveManagerIds($userId);
		if ($managerIds === []) {
			return;
		}

		foreach ($managerIds as $managerId) {
			$notification = $this->notificationManager->createNotification();
			$notification->setApp('arbeitszeitcheck')
				->setUser($managerId)
				->setDateTime(new \DateTime())
				->setObject('working_time_warning', $userId . '_' . $warningType . '_' . date('Y-m-d'))
				->setSubject('working_time_warning', [
					'user_id' => $userId,
					'warning_type' => $warningType,
					'date' => $warningData['date'] ?? date('Y-m-d')
				])
				->setMessage('working_time_warning', [
					'user_id' => $userId,
					'warning_type' => $warningType,
					'message' => $warningData['message'] ?? '',
					'current_value' => $warningData['current_value'] ?? 0,
					'limit' => $warningData['limit'] ?? 0,
					'date' => $warningData['date'] ?? date('Y-m-d')
				]);

			$this->notificationManager->notify($notification);
		}
	}

	/**
	 * Send a notification about time entry correction rejection
	 *
	 * @param string $userId User ID to notify
	 * @param array $timeEntryData Time entry data
	 * @param string|null $reason Rejection reason
	 * @return void
	 */
	public function notifyTimeEntryCorrectedByManager(string $userId, array $timeEntryData, string $reason): void
	{
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('time_entry', (string)($timeEntryData['id'] ?? ''))
			->setSubject('time_entry_manager_corrected', [
				'entry_id' => $timeEntryData['id'] ?? null,
				'date' => $timeEntryData['startTime'] ?? null,
			])
			->setMessage('time_entry_manager_corrected', [
				'date' => $timeEntryData['startTime'] ?? null,
				'reason' => $reason,
			]);

		$this->notificationManager->notify($notification);
	}

	public function notifyTimeEntryCorrectionRejected(string $userId, array $timeEntryData, ?string $reason = null): void
	{
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('time_entry', (string)($timeEntryData['id'] ?? ''))
			->setSubject('time_entry_correction_rejected', [
				'entry_id' => $timeEntryData['id'] ?? null,
				'date' => $timeEntryData['date'] ?? null,
				'reason' => $reason
			])
			->setMessage('time_entry_correction_rejected', [
				'date' => $timeEntryData['date'] ?? null,
				'reason' => $reason ?? $this->l10n->t('No reason provided')
			]);

		$this->notificationManager->notify($notification);
	}

	/**
	 * Mark a notification as processed/read
	 *
	 * @param string $userId User ID
	 * @param string $objectType Object type
	 * @param string $objectId Object ID
	 * @return void
	 */
	public function markNotificationProcessed(string $userId, string $objectType, string $objectId): void
	{
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setObject($objectType, $objectId);

		$this->notificationManager->markProcessed($notification);
	}
}
