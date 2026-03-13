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

use OCA\ArbeitszeitCheck\Db\Absence;
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
		$days = (int)($absence->getDays() ?? 0);
		$link = $this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.substitute.index');

		$subject = $this->l10n->t('Substitution request: %1$s asks you to cover from %2$s to %3$s', [
			$employeeName,
			$startStr,
			$endStr
		]);
		$plainBody = $this->l10n->t(
			'%1$s has requested you as their substitute for %2$s from %3$s to %4$s (%5$d day(s)). Please approve or decline this request.',
			[$employeeName, $typeLabel, $startStr, $endStr, $days]
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
		$map = [
			'vacation' => $this->l10n->t('Vacation'),
			'sick_leave' => $this->l10n->t('Sick Leave'),
			'personal_leave' => $this->l10n->t('Personal Leave'),
			'parental_leave' => $this->l10n->t('Parental Leave'),
			'special_leave' => $this->l10n->t('Special Leave'),
			'unpaid_leave' => $this->l10n->t('Unpaid Leave'),
			'home_office' => $this->l10n->t('Home Office'),
			'business_trip' => $this->l10n->t('Business Trip'),
		];
		return $map[$type] ?? $type;
	}
}
