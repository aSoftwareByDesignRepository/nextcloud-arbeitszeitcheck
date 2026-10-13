<?php

declare(strict_types=1);

/**
 * Sends plain-text notification emails for absence and substitution workflows.
 * Distinct from AbsenceIcalMailService which sends iCal calendar attachments.
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Util\AbsenceTypeLabel;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\IUserManager;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class AbsenceNotificationMailService
{
	private const CONFIG_SEND_SUBSTITUTION_REQUEST = 'send_email_substitution_request';
	private const CONFIG_SEND_SUBSTITUTE_APPROVED_TO_EMPLOYEE = 'send_email_substitute_approved_to_employee';
	private const CONFIG_SEND_SUBSTITUTE_APPROVED_TO_MANAGER = 'send_email_substitute_approved_to_manager';
	private const MAX_HR_RECIPIENTS = 20;

	public function __construct(
		private IMailer $mailer,
		private IConfig $config,
		private IL10N $l10n,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private TeamResolverService $teamResolver,
		private ?LoggerInterface $logger = null,
	) {
	}

	public function sendHrOfficeNotification(Absence $absence, string $eventKey, ?string $actorUserId = null): void
	{
		$config = $this->getHrNotificationConfig();
		if (!$config['enabled']) {
			return;
		}
		if (!$this->shouldSendHrEvent($config['matrix'], $absence->getType(), $eventKey)) {
			return;
		}
		$recipients = $config['recipients'];
		if ($recipients === []) {
			return;
		}

		$employee = $this->userManager->get($absence->getUserId());
		$employeeName = $employee ? $employee->getDisplayName() : $absence->getUserId();
		$actorName = $actorUserId ? ($this->userManager->get($actorUserId)?->getDisplayName() ?? $actorUserId) : null;
		$typeLabel = $this->getTypeLabel($absence->getType());
		$eventLabel = $this->getHrEventLabel($eventKey);
		$startStr = $absence->getStartDate()?->format('Y-m-d') ?? '?';
		$endStr = $absence->getEndDate()?->format('Y-m-d') ?? '?';
		$daysLabel = AbsenceTypeLabel::formatWorkingDays($this->l10n, (float)($absence->getDays() ?? 0));
		$employeeLink = $this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.page.absences');
		$managerLink = $this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.manager.dashboard');

		$subject = $this->l10n->t('HR notification: %1$s (%2$s) - %3$s', [
			$employeeName,
			$typeLabel,
			$eventLabel,
		]);

		$plainBody = $this->l10n->t(
			'Absence event: %1$s',
			[$eventLabel]
		) . "\n" . $this->l10n->t(
			'Employee: %1$s',
			[$employeeName]
		) . "\n" . $this->l10n->t(
			'Type: %1$s',
			[$typeLabel]
		) . "\n" . $this->l10n->t(
			'Period: %1$s to %2$s',
			[$startStr, $endStr]
		) . "\n" . $this->l10n->t(
			'Days: %1$s',
			[$daysLabel]
		);

		if ($actorName !== null) {
			$plainBody .= "\n" . $this->l10n->t('Triggered by: %1$s', [$actorName]);
		}
		$plainBody .= "\n\n" . $this->l10n->t('Employee view: %s', [$employeeLink]);
		$plainBody .= "\n" . $this->l10n->t('Manager view: %s', [$managerLink]);

		foreach ($recipients as $email) {
			$this->sendMail(
				$email,
				$email,
				$subject,
				$plainBody,
				'sendHrOfficeNotification:' . $eventKey,
				$absence->getUserId()
			);
		}
	}

	/**
	 * Send email to substitute when a substitution request is created.
	 * Only when admin has enabled this and the absence has a substitute.
	 */
	public function sendSubstitutionRequestToSubstitute(Absence $absence): void
	{
		$appName = 'arbeitszeitcheck';
		if ($this->config->getAppValue($appName, self::CONFIG_SEND_SUBSTITUTION_REQUEST, '1') !== '1') {
			return;
		}

		$substituteId = $absence->getSubstituteUserId();
		if ($substituteId === null || $substituteId === '') {
			return;
		}

		$substitute = $this->userManager->get($substituteId);
		if ($substitute === null || !$substitute->isEnabled()) {
			return;
		}

		$email = $substitute->getEMailAddress();
		if ($email === null || trim($email) === '' || !$this->mailer->validateMailAddress(trim($email))) {
			return;
		}

		$employee = $this->userManager->get($absence->getUserId());
		$employeeName = $employee ? $employee->getDisplayName() : $absence->getUserId();
		$typeLabel = $this->getTypeLabel($absence->getType());
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		$startStr = $start ? $start->format('Y-m-d') : '?';
		$endStr = $end ? $end->format('Y-m-d') : '?';
		$daysLabel = AbsenceTypeLabel::formatWorkingDays($this->l10n, (float)($absence->getDays() ?? 0));
		$link = $this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.substitute.index');

		$subject = $this->l10n->t('Substitution request: %1$s asks you to cover from %2$s to %3$s', [
			$employeeName,
			$startStr,
			$endStr
		]);
		$plainBody = $this->l10n->t(
			'%1$s has requested you as their substitute for %2$s from %3$s to %4$s (%5$s). Please approve or decline this request.',
			[$employeeName, $typeLabel, $startStr, $endStr, $daysLabel]
		) . "\n\n" . $this->l10n->t('Go to substitution requests: %s', [$link]);

		$this->sendMail(
			$email,
			$substitute->getDisplayName(),
			$subject,
			$plainBody,
			'sendSubstitutionRequestToSubstitute',
			$substituteId
		);
	}

	/**
	 * Send email to employee when substitute has approved the substitution.
	 */
	public function sendSubstituteApprovedToEmployee(Absence $absence): void
	{
		$appName = 'arbeitszeitcheck';
		if ($this->config->getAppValue($appName, self::CONFIG_SEND_SUBSTITUTE_APPROVED_TO_EMPLOYEE, '1') !== '1') {
			return;
		}

		$employee = $this->userManager->get($absence->getUserId());
		if ($employee === null || !$employee->isEnabled()) {
			return;
		}

		$email = $employee->getEMailAddress();
		if ($email === null || trim($email) === '' || !$this->mailer->validateMailAddress(trim($email))) {
			return;
		}

		$substitute = $this->userManager->get($absence->getSubstituteUserId() ?? '');
		$substituteName = $substitute ? $substitute->getDisplayName() : $absence->getSubstituteUserId();
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		$startStr = $start ? $start->format('Y-m-d') : '?';
		$endStr = $end ? $end->format('Y-m-d') : '?';
		$link = $this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.page.absences');

		$subject = $this->l10n->t('Substitute approved: %1$s will cover for you (%2$s – %3$s)', [
			$substituteName,
			$startStr,
			$endStr
		]);
		$plainBody = $this->l10n->t(
			'%1$s has approved your substitution request (%2$s – %3$s). Your absence is now awaiting manager approval.',
			[$substituteName, $startStr, $endStr]
		) . "\n\n" . $this->l10n->t('View your absences: %s', [$link]);

		$this->sendMail(
			$email,
			$employee->getDisplayName(),
			$subject,
			$plainBody,
			'sendSubstituteApprovedToEmployee',
			$absence->getUserId()
		);
	}

	/**
	 * Send email to managers when substitute has approved; the absence now needs manager approval.
	 * Only when app teams are used (getManagerIdsForEmployee returns IDs); otherwise managers
	 * see pending items in the dashboard on next login.
	 */
	public function sendSubstituteApprovedToManagers(Absence $absence): void
	{
		$appName = 'arbeitszeitcheck';
		if ($this->config->getAppValue($appName, self::CONFIG_SEND_SUBSTITUTE_APPROVED_TO_MANAGER, '1') !== '1') {
			return;
		}

		$managerIds = $this->teamResolver->getManagerIdsForEmployee($absence->getUserId());
		if (empty($managerIds)) {
			return;
		}

		$employee = $this->userManager->get($absence->getUserId());
		$employeeName = $employee ? $employee->getDisplayName() : $absence->getUserId();
		$typeLabel = $this->getTypeLabel($absence->getType());
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		$startStr = $start ? $start->format('Y-m-d') : '?';
		$endStr = $end ? $end->format('Y-m-d') : '?';
		$substitute = $this->userManager->get($absence->getSubstituteUserId() ?? '');
		$substituteName = $substitute ? $substitute->getDisplayName() : $absence->getSubstituteUserId();
		$link = $this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.manager.dashboard');

		$subject = $this->l10n->t('Absence to approve: %1$s – %2$s (%3$s – %4$s)', [
			$employeeName,
			$typeLabel,
			$startStr,
			$endStr
		]);
		$plainBody = $this->l10n->t(
			'%1$s has requested an absence (%2$s) from %3$s to %4$s. The substitute %5$s has approved. Please review and approve or reject the request.',
			[$employeeName, $typeLabel, $startStr, $endStr, $substituteName]
		) . "\n\n" . $this->l10n->t('Go to Manager Dashboard: %s', [$link]);

		foreach (array_unique($managerIds) as $managerId) {
			$manager = $this->userManager->get($managerId);
			if ($manager === null || !$manager->isEnabled()) {
				continue;
			}
			$email = $manager->getEMailAddress();
			if ($email === null || trim($email) === '' || !$this->mailer->validateMailAddress(trim($email))) {
				continue;
			}
			$this->sendMail(
				$email,
				$manager->getDisplayName(),
				$subject,
				$plainBody,
				'sendSubstituteApprovedToManagers',
				$managerId
			);
		}
	}

	private function sendMail(
		string $toEmail,
		string $toDisplayName,
		string $subject,
		string $plainBody,
		string $logContext,
		string $userId
	): void {
		$toEmail = trim($toEmail);
		if ($toEmail === '' || !$this->mailer->validateMailAddress($toEmail)) {
			return;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setSubject($subject);
			$message->setPlainBody($plainBody);
			$message->setTo([$toEmail => $toDisplayName]);
			$this->setFrom($message);

			if (method_exists($message, 'setAutoSubmitted')) {
				$message->setAutoSubmitted(\OCP\Mail\Headers\AutoSubmitted::VALUE_AUTO_GENERATED);
			}

			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger?->warning(
				'arbeitszeitcheck: Failed to send ' . $logContext . ' email to ' . $userId . ': ' . $e->getMessage(),
				['app' => 'arbeitszeitcheck', 'exception' => $e]
			);
		}
	}

	private function setFrom(IMessage $message): void
	{
		$fromAddress = (string)$this->config->getSystemValue('mail_from_address', '');
		$fromDomain = (string)$this->config->getSystemValue('mail_domain', 'localhost');
		if ($fromAddress !== '') {
			$from = $fromAddress . '@' . $fromDomain;
			$fromName = (string)$this->config->getSystemValue('mail_from_name', 'ArbeitszeitCheck');
			$message->setFrom([$from => $fromName]);
		}
	}

	private function getTypeLabel(string $type): string
	{
		return AbsenceTypeCatalog::label($this->l10n, $type);
	}

	/**
	 * @return array{enabled: bool, recipients: list<string>, matrix: array<string, array<string, bool>>}
	 */
	private function getHrNotificationConfig(): array
	{
		$enabled = $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_HR_NOTIFICATIONS_ENABLED, '0') === '1';
		$recipients = $this->parseHrRecipients(
			$this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS, '')
		);
		$decoded = json_decode(
			$this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_HR_NOTIFICATION_MATRIX_V1, '[]'),
			true
		);

		return [
			'enabled' => $enabled,
			'recipients' => $recipients,
			'matrix' => $this->normalizeHrMatrix(is_array($decoded) ? $decoded : []),
		];
	}

	/**
	 * @return list<string>
	 */
	private function parseHrRecipients(string $raw): array
	{
		$parts = explode(',', $raw);
		$unique = [];
		foreach ($parts as $part) {
			$email = strtolower(trim($part));
			if ($email === '') {
				continue;
			}
			if (!$this->mailer->validateMailAddress($email)) {
				continue;
			}
			$unique[$email] = true;
			if (count($unique) >= self::MAX_HR_RECIPIENTS) {
				break;
			}
		}
		return array_keys($unique);
	}

	/**
	 * @param array<string, mixed> $input
	 * @return array<string, array<string, bool>>
	 */
	private function normalizeHrMatrix(array $input): array
	{
		$out = [];
		foreach (Constants::ABSENCE_TYPES as $absenceType) {
			$out[$absenceType] = [];
			$inType = (isset($input[$absenceType]) && is_array($input[$absenceType])) ? $input[$absenceType] : [];
			foreach (Constants::HR_NOTIFICATION_EVENTS as $eventKey) {
				$out[$absenceType][$eventKey] = $this->toBool($inType[$eventKey] ?? false);
			}
		}
		return $out;
	}

	private function shouldSendHrEvent(array $matrix, string $absenceType, string $eventKey): bool
	{
		return isset($matrix[$absenceType], $matrix[$absenceType][$eventKey]) && $matrix[$absenceType][$eventKey] === true;
	}

	private function toBool(mixed $value): bool
	{
		return $value === true || $value === 1 || $value === '1' || $value === 'true';
	}

	private function getHrEventLabel(string $eventKey): string
	{
		$map = [
			'request_created' => $this->l10n->t('Request created'),
			'substitute_approved' => $this->l10n->t('Substitute approved'),
			'substitute_declined' => $this->l10n->t('Substitute declined'),
			'manager_approved' => $this->l10n->t('Manager approved'),
			'manager_rejected' => $this->l10n->t('Manager rejected'),
			'employee_cancelled' => $this->l10n->t('Employee cancelled'),
			'employee_shortened' => $this->l10n->t('Employee shortened'),
		];
		return $map[$eventKey] ?? $eventKey;
	}
}
