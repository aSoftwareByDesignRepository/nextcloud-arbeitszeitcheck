<?php

declare(strict_types=1);

/**
 * Notifier for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Notification;

use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Notifier
 */
class Notifier implements INotifier {

	/**
	 * @inheritDoc
	 */
	public function getID(): string {
		return 'arbeitszeitcheck';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return 'ArbeitszeitCheck';
	}

	/**
	 * @inheritDoc
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'arbeitszeitcheck') {
			throw new UnknownNotificationException();
		}

		$l = \OCP\Util::getL10N('arbeitszeitcheck', $languageCode);

		$subject = $notification->getSubject();
		$parameters = $notification->getSubjectParameters();

		switch ($subject) {
			case 'compliance_violation':
				$violationType = $parameters['violation_type'] ?? 'unknown';
				$notification->setParsedSubject(
					$l->t('Compliance violation detected')
				)->setParsedMessage(
					$l->t('A labor law compliance violation has been detected: %s', [$violationType])
				);
				break;

			case 'substitution_request':
				$employeeName = $parameters['employee_display_name'] ?? 'unknown';
				$startDate = $parameters['start_date'] ?? null;
				$endDate = $parameters['end_date'] ?? null;
				$days = $parameters['days'] ?? 0;
				$message = $l->t('%s has asked you to be their substitute from %s to %s (%d day(s)). Please approve or decline.', [
					$employeeName,
					$startDate ?? '?',
					$endDate ?? '?',
					(int)$days
				]);
				$notification->setParsedSubject(
					$l->t('Substitution request')
				)->setParsedMessage($message);
				break;

			case 'substitute_approved':
				$substituteName = $parameters['substitute_display_name'] ?? 'unknown';
				$startDate = $parameters['start_date'] ?? null;
				$endDate = $parameters['end_date'] ?? null;
				$notification->setParsedSubject(
					$l->t('Substitute approved')
				)->setParsedMessage(
					$l->t('%s has approved your substitution request (%s – %s). It is now awaiting manager approval.', [
						$substituteName,
						$startDate ?? '?',
						$endDate ?? '?'
					])
				);
				break;

			case 'substitute_declined':
				$substituteName = $parameters['substitute_display_name'] ?? 'unknown';
				$reason = $parameters['reason'] ?? null;
				$message = $l->t('%s has declined your substitution request.', [$substituteName]);
				if ($reason) {
					$message .= ' ' . $l->t('Reason: %s', [$reason]);
				}
				$notification->setParsedSubject(
					$l->t('Substitute declined')
				)->setParsedMessage($message);
				break;

			case 'absence_approved':
				$absenceType = $parameters['type'] ?? 'vacation';
				$days = $parameters['days'] ?? 0;
				$notification->setParsedSubject(
					$l->t('Absence approved')
				)->setParsedMessage(
					$l->t('Your %s request for %d day(s) has been approved.', [$absenceType, $days])
				);
				break;

			case 'absence_rejected':
				$reason = $parameters['reason'] ?? null;
				$message = $l->t('Your absence request has been rejected.');
				if ($reason) {
					$message .= ' ' . $l->t('Reason: %s', [$reason]);
				}
				$notification->setParsedSubject(
					$l->t('Absence rejected')
				)->setParsedMessage($message);
				break;

			case 'reminder_clock_out':
				$hoursWorked = $parameters['hours_worked'] ?? 0;
				$notification->setParsedSubject(
					$l->t('Don\'t forget to clock out')
				)->setParsedMessage(
					$l->t('You are still clocked in. You have worked %s hours today.', [number_format($hoursWorked, 2)])
				);
				break;

			case 'reminder_break':
				$hoursWorked = $parameters['hours_worked'] ?? 0;
				$requiredBreak = $parameters['required_break'] ?? 30;
				$notification->setParsedSubject(
					$l->t('Break reminder')
				)->setParsedMessage(
					$l->t('You have worked %s hours. A %d minute break is required.', [number_format($hoursWorked, 2), $requiredBreak])
				);
				break;

			case 'missing_time_entry':
				$date = $parameters['date'] ?? date('Y-m-d');
				$notification->setParsedSubject(
					$l->t('Missing time entry')
				)->setParsedMessage(
					$l->t('No time entry was recorded for %s. Please add your working hours.', [$date])
				);
				break;

			case 'overtime_warning':
				$overtimeHours = $parameters['overtime_hours'] ?? 0;
				$limit = $parameters['limit'] ?? 0;
				$notification->setParsedSubject(
					$l->t('Overtime warning')
				)->setParsedMessage(
					$l->t('You have accumulated %s hours of overtime (limit: %s hours).', [number_format($overtimeHours, 2), number_format($limit, 2)])
				);
				break;

			case 'time_entry_correction_approved':
				$date = $parameters['date'] ?? date('Y-m-d');
				$notification->setParsedSubject(
					$l->t('Time entry correction approved')
				)->setParsedMessage(
					$l->t('Your time entry correction for %s has been approved.', [$date])
				);
				break;

			case 'time_entry_correction_rejected':
				$date = $parameters['date'] ?? date('Y-m-d');
				$reason = $parameters['reason'] ?? null;
				$message = $l->t('Your time entry correction for %s has been rejected.', [$date]);
				if ($reason) {
					$message .= ' ' . $l->t('Reason: %s', [$reason]);
				}
				$notification->setParsedSubject(
					$l->t('Time entry correction rejected')
				)->setParsedMessage($message);
				break;

			default:
				$notification->setParsedSubject(
					$l->t('ArbeitszeitCheck notification')
				)->setParsedMessage(
					$l->t('You have a new notification from ArbeitszeitCheck.')
				);
				break;
		}

		return $notification;
	}
}