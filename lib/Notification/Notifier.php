<?php

declare(strict_types=1);

/**
 * Notifier for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Notification;

use OCA\ArbeitszeitCheck\Util\AbsenceTypeLabel;
use OCA\ArbeitszeitCheck\Util\AbsenceWorkingDaysResolver;
use OCP\IURLGenerator;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Notifier
 */
class Notifier implements INotifier {

	public function __construct(
		private IURLGenerator $urlGenerator,
		private AbsenceWorkingDaysResolver $workingDaysResolver,
	) {
	}

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

		$subjectKey = $notification->getSubject();
		$subjectParameters = $notification->getSubjectParameters();
		$messageParameters = $notification->getMessageParameters();
		$parameters = array_merge($subjectParameters, $messageParameters);

		switch ($subjectKey) {
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
				$days = $this->workingDaysResolver->resolveFromNotificationParameters($parameters);
				$daysLabel = AbsenceTypeLabel::formatWorkingDays($l, $days);
				$message = $l->t('%1$s has asked you to be their substitute from %2$s to %3$s (%4$s). Please approve or decline.', [
					$employeeName,
					$startDate ?? '?',
					$endDate ?? '?',
					$daysLabel,
				]);
				$notification->setParsedSubject(
					$l->t('Substitution request')
				)->setParsedMessage($message)
					->setLink($this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.substitute.index'));
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
						$endDate ?? '?',
					])
				)->setLink($this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.page.absences'));
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
				)->setParsedMessage($message)
					->setLink($this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.page.absences'));
				break;

			case 'absence_approved':
				$typeCode = (string)($parameters['type'] ?? 'vacation');
				$typeLabel = AbsenceTypeLabel::get($l, $typeCode);
				$days = $this->workingDaysResolver->resolveFromNotificationParameters($parameters);
				$daysLabel = AbsenceTypeLabel::formatWorkingDays($l, $days);
				$startDate = $parameters['start_date'] ?? null;
				$endDate = $parameters['end_date'] ?? null;
				$period = ($startDate && $endDate)
					? $l->t('%1$s – %2$s', [$startDate, $endDate])
					: ($startDate ?? $endDate ?? '?');

				$notification->setParsedSubject(
					$l->t('Absence approved')
				)->setParsedMessage(
					$l->t('Your %1$s request for %2$s (%3$s) has been approved.', [
						$typeLabel,
						$daysLabel,
						$period,
					])
				)->setLink($this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.page.absences'));
				break;

			case 'absence_rejected':
				$reason = $parameters['reason'] ?? null;
				$message = $l->t('Your absence request has been rejected.');
				if ($reason) {
					$message .= ' ' . $l->t('Reason: %s', [$reason]);
				}
				$notification->setParsedSubject(
					$l->t('Absence rejected')
				)->setParsedMessage($message)
					->setLink($this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.page.absences'));
				break;

			case 'absence_manager_cancelled':
				$typeCode = (string)($parameters['type'] ?? 'vacation');
				$typeLabel = AbsenceTypeLabel::get($l, $typeCode);
				$startDate = $parameters['start_date'] ?? null;
				$endDate = $parameters['end_date'] ?? null;
				$period = ($startDate && $endDate)
					? $l->t('%1$s – %2$s', [$startDate, $endDate])
					: ($startDate ?? $endDate ?? '?');
				$reason = $parameters['reason'] ?? null;
				$message = $l->t('Your %1$s (%2$s) was cancelled by your manager.', [$typeLabel, $period]);
				if ($reason) {
					$message .= ' ' . $l->t('Reason: %s', [$reason]);
				}
				$notification->setParsedSubject(
					$l->t('Absence cancelled')
				)->setParsedMessage($message)
					->setLink($this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.page.absences'));
				break;

			case 'absence_cancel_requested':
				$typeCode = (string)($parameters['type'] ?? 'vacation');
				$typeLabel = AbsenceTypeLabel::get($l, $typeCode);
				$startDate = $parameters['start_date'] ?? null;
				$endDate = $parameters['end_date'] ?? null;
				$period = ($startDate && $endDate)
					? $l->t('%1$s – %2$s', [$startDate, $endDate])
					: ($startDate ?? $endDate ?? '?');
				$employee = (string)($parameters['employee'] ?? '');
				$reason = $parameters['reason'] ?? null;
				$message = $l->t('%1$s asks to cancel their %2$s (%3$s).', [$employee !== '' ? $employee : $l->t('An employee'), $typeLabel, $period]);
				if ($reason) {
					$message .= ' ' . $l->t('Reason: %s', [$reason]);
				}
				$notification->setParsedSubject(
					$l->t('Cancellation requested')
				)->setParsedMessage($message)
					->setLink($this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.manager.employeeAbsencesPage'));
				break;

			case 'absence_cancel_request_declined':
				$typeCode = (string)($parameters['type'] ?? 'vacation');
				$typeLabel = AbsenceTypeLabel::get($l, $typeCode);
				$startDate = $parameters['start_date'] ?? null;
				$endDate = $parameters['end_date'] ?? null;
				$period = ($startDate && $endDate)
					? $l->t('%1$s – %2$s', [$startDate, $endDate])
					: ($startDate ?? $endDate ?? '?');
				$comment = $parameters['comment'] ?? null;
				$message = $l->t('Your cancellation request for %1$s (%2$s) was declined.', [$typeLabel, $period]);
				if ($comment) {
					$message .= ' ' . $l->t('Comment: %s', [$comment]);
				}
				$notification->setParsedSubject(
					$l->t('Cancellation request declined')
				)->setParsedMessage($message)
					->setLink($this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.page.absences'));
				break;

			case 'reminder_clock_out':
				$hoursWorked = $parameters['hours_worked'] ?? 0;
				$notification->setParsedSubject(
					$l->t('Don\'t forget to clock out')
				)->setParsedMessage(
					$l->t('You are still clocked in. You have worked %s hours today.', [number_format((float)$hoursWorked, 2)])
				);
				break;

			case 'reminder_break':
				$hoursWorked = $parameters['hours_worked'] ?? 0;
				$requiredBreak = $parameters['required_break'] ?? 30;
				$notification->setParsedSubject(
					$l->t('Break reminder')
				)->setParsedMessage(
					$l->t('You have worked %s hours. A %d minute break is required.', [number_format((float)$hoursWorked, 2), (int)$requiredBreak])
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
					$l->t('You have accumulated %s hours of overtime (limit: %s hours).', [number_format((float)$overtimeHours, 2), number_format((float)$limit, 2)])
				);
				break;

			case 'overtime_traffic_light':
				$state = (string)($parameters['state'] ?? 'green');
				$balance = (float)($parameters['balance'] ?? 0.0);
				$title = $l->t('Balance traffic light update');
				$message = $l->t('Your overtime balance is in the green range.');
				if ($state === 'yellow_over') {
					$message = $l->t('Your overtime balance reached yellow level (%s hours).', [number_format($balance, 2)]);
				} elseif ($state === 'red_over') {
					$message = $l->t('Your overtime balance reached red level (%s hours).', [number_format($balance, 2)]);
				} elseif ($state === 'yellow_under') {
					$message = $l->t('Your undertime balance reached yellow level (%s hours).', [number_format($balance, 2)]);
				} elseif ($state === 'red_under') {
					$message = $l->t('Your undertime balance reached red level (%s hours).', [number_format($balance, 2)]);
				}
				$notification->setParsedSubject($title)->setParsedMessage($message);
				break;

			case 'overtime_payout':
				$hoursPaid = (float)($parameters['hours_paid'] ?? 0);
				$year = (int)($parameters['year'] ?? 0);
				$month = (int)($parameters['month'] ?? 0);
				$period = sprintf('%04d-%02d', $year, $month);
				$after = (float)($parameters['effective_balance_after'] ?? 0);
				$notification->setParsedSubject(
					$l->t('Overtime payout for %s', [$period])
				)->setParsedMessage(
					$l->t('%1$s hours were paid out for %2$s. Your overtime bank balance is now %3$s hours.', [
						number_format($hoursPaid, 2),
						$period,
						number_format($after, 2),
					])
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

			case 'time_entry_manager_corrected':
				$date = $parameters['date'] ?? date('Y-m-d');
				$reason = $parameters['reason'] ?? '';
				$notification->setParsedSubject(
					$l->t('Your time entry was corrected by your manager')
				)->setParsedMessage(
					$l->t('Your manager corrected the time entry for %1$s. Reason: %2$s', [$date, $reason])
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
