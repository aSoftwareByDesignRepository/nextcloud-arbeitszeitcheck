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
use OCP\IL10N;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;

/**
 * NotificationService for sending notifications to users
 */
class NotificationService
{
	private INotificationManager $notificationManager;
	private IL10N $l10n;
	private UserSettingsMapper $userSettingsMapper;
	private IUserManager $userManager;

	public function __construct(
		INotificationManager $notificationManager,
		IL10N $l10n,
		UserSettingsMapper $userSettingsMapper,
		IUserManager $userManager
	) {
		$this->notificationManager = $notificationManager;
		$this->l10n = $l10n;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->userManager = $userManager;
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
	 * Send an absence approval notification
	 *
	 * @param string $userId User ID to notify
	 * @param array $absenceData Absence data
	 * @return void
	 */
	public function notifyAbsenceApproved(string $userId, array $absenceData): void
	{
		$notification = $this->notificationManager->createNotification();
		$notification->setApp('arbeitszeitcheck')
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('absence', (string)($absenceData['id'] ?? ''))
			->setSubject('absence_approved', [
				'absence_id' => $absenceData['id'] ?? null,
				'start_date' => $absenceData['start_date'] ?? null,
				'end_date' => $absenceData['end_date'] ?? null
			])
			->setMessage('absence_approved', [
				'type' => $absenceData['type'] ?? 'vacation',
				'days' => $absenceData['days'] ?? 0
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
				'start_time' => $timeEntryData['start_time'] ?? null
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
				'hours_worked' => $timeEntryData['hours_worked'] ?? 0
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
	 * Send a notification about time entry correction request
	 * Notifies the user's manager about the correction request
	 *
	 * @param string $userId User ID who requested correction
	 * @param array $timeEntryData Time entry data
	 * @param string $justification Justification for correction
	 * @return void
	 */
	public function notifyTimeEntryCorrectionRequested(string $userId, array $timeEntryData, string $justification): void
	{
		// Get manager for this user (simplified - in production, use proper manager lookup)
		$managerId = $this->getManagerId($userId);
		if (!$managerId) {
			return; // No manager to notify
		}

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
		$managerId = $this->getManagerId($userId);
		if (!$managerId) {
			return; // No manager to notify
		}

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

	/**
	 * Send a notification about time entry correction rejection
	 *
	 * @param string $userId User ID to notify
	 * @param array $timeEntryData Time entry data
	 * @param string|null $reason Rejection reason
	 * @return void
	 */
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
