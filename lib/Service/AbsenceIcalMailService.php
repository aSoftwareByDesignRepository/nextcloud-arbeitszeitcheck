<?php

declare(strict_types=1);

/**
 * Send iCalendar (.ics) emails for approved absences
 *
 * @copyright Copyright (c) 2025, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Generates RFC 5545 iCalendar content for an absence and sends it via email
 * to the employee (and optionally the substitute) after approval.
 */
class AbsenceIcalMailService
{
	private const CONFIG_SEND_ICAL = 'send_ical_approved_absences';
	private const CONFIG_SEND_ICAL_TO_SUBSTITUTE = 'send_ical_to_substitute';
	private const CONFIG_SEND_ICAL_TO_MANAGERS = 'send_ical_to_managers';

	public function __construct(
		private IMailer $mailer,
		private IConfig $config,
		private IL10N $l10n,
		private IUserManager $userManager,
		private TeamResolverService $teamResolver,
		private ?LoggerInterface $logger = null,
	) {
	}

	/** Absence types for which we do NOT send iCal (privacy-sensitive / critical). */
	private const SKIP_ICAL_TYPES = [Absence::TYPE_SICK_LEAVE];

	/**
	 * Send iCal email to the absence owner and optionally to the substitute.
	 * Only sends for non-critical absences (no sick leave). Respects admin settings.
	 * Failures are logged but do not throw (approval must not fail if mail fails).
	 */
	public function sendIcalForApprovedAbsence(Absence $absence): void
	{
		if (in_array($absence->getType(), self::SKIP_ICAL_TYPES, true)) {
			return;
		}

		$appName = 'arbeitszeitcheck';
		if ($this->config->getAppValue($appName, self::CONFIG_SEND_ICAL, '1') !== '1') {
			return;
		}

		$icalBody = $this->buildIcalForAbsence($absence);
		if ($icalBody === null || $icalBody === '') {
			return;
		}

		// Send to employee
		$employee = $this->userManager->get($absence->getUserId());
		if ($employee !== null) {
			$this->sendOneIcalEmail($absence, $employee->getEMailAddress(), $employee->getDisplayName(), $icalBody, false);
		}

		// Optionally send to substitute (when manager approves)
		if ($this->config->getAppValue($appName, self::CONFIG_SEND_ICAL_TO_SUBSTITUTE, '0') === '1') {
			$substituteId = $absence->getSubstituteUserId();
			if ($substituteId !== null && $substituteId !== '') {
				$substitute = $this->userManager->get($substituteId);
				if ($substitute !== null && $substitute->isEnabled()) {
					$this->sendOneIcalEmail($absence, $substitute->getEMailAddress(), $substitute->getDisplayName(), $icalBody, true);
				}
			}
		}

		// Optionally send to managers / team leads of the employee
		if ($this->config->getAppValue($appName, self::CONFIG_SEND_ICAL_TO_MANAGERS, '0') === '1') {
			$managerIds = $this->teamResolver->getManagerIdsForEmployee($absence->getUserId());
			if (!empty($managerIds)) {
				foreach ($managerIds as $managerId) {
					$manager = $this->userManager->get($managerId);
					if ($manager === null || !$manager->isEnabled()) {
						continue;
					}
					$this->sendOneIcalEmail(
						$absence,
						$manager->getEMailAddress(),
						$manager->getDisplayName(),
						$icalBody,
						true
					);
				}
			}
		}
	}

	/**
	 * Send iCal email to the substitute when they approve the substitution (Vertretungs-Freigabe).
	 * Gives the substitute a calendar reminder so they do not forget the coverage period.
	 * Only sends for non-critical absences (no sick leave). Respects admin setting.
	 */
	public function sendIcalToSubstituteOnSubstitutionApproval(Absence $absence): void
	{
		if (in_array($absence->getType(), self::SKIP_ICAL_TYPES, true)) {
			return;
		}

		$appName = 'arbeitszeitcheck';
		if ($this->config->getAppValue($appName, self::CONFIG_SEND_ICAL, '1') !== '1') {
			return;
		}
		// Respect the dedicated admin toggle for substitutes to avoid sending
		// coverage iCals when this feature is disabled.
		if ($this->config->getAppValue($appName, self::CONFIG_SEND_ICAL_TO_SUBSTITUTE, '0') !== '1') {
			return;
		}

		$substituteId = $absence->getSubstituteUserId();
		if ($substituteId === null || $substituteId === '') {
			return;
		}

		$icalBody = $this->buildIcalForAbsence($absence, true);
		if ($icalBody === null || $icalBody === '') {
			return;
		}

		$substitute = $this->userManager->get($substituteId);
		if ($substitute === null || !$substitute->isEnabled()) {
			return;
		}

		$this->sendOneIcalEmailForSubstituteApproval($absence, $substitute->getEMailAddress(), $substitute->getDisplayName(), $icalBody);
	}

	/**
	 * Send iCal to substitute when they approved the substitution (different subject/body).
	 */
	private function sendOneIcalEmailForSubstituteApproval(Absence $absence, ?string $email, string $displayName, string $icalBody): void
	{
		$email = $email !== null ? trim($email) : '';
		if ($email === '' || !$this->mailer->validateMailAddress($email)) {
			return;
		}

		try {
			$owner = $this->userManager->get($absence->getUserId());
			$ownerName = $owner ? $owner->getDisplayName() : $absence->getUserId();
			$typeLabel = $this->getTypeLabel($absence->getType());
			$start = $absence->getStartDate();
			$end = $absence->getEndDate();
			$startStr = $start ? $start->format('Y-m-d') : '';
			$endStr = $end ? $end->format('Y-m-d') : '';

			$message = $this->mailer->createMessage();
			$subject = $this->l10n->t(
				'Substitution approved: covering for %1$s (%2$s – %3$s)',
				[$ownerName, $startStr, $endStr]
			);
			$plainBody = $this->l10n->t(
				'You have agreed to cover for %1$s (%2$s) from %3$s to %4$s. An iCalendar file is attached so you can add it to your calendar.',
				[$ownerName, $typeLabel, $startStr, $endStr]
			);

			$message->setSubject($subject);
			$message->setPlainBody($plainBody);
			$message->setTo([$email => $displayName]);
			$this->setFrom($message);

			$attachment = $this->mailer->createAttachment(
				$icalBody,
				'absence.ics',
				'text/calendar; method=PUBLISH; charset=UTF-8'
			);
			$message->attach($attachment);

			if (method_exists($message, 'setAutoSubmitted')) {
				$message->setAutoSubmitted(\OCP\Mail\Headers\AutoSubmitted::VALUE_AUTO_GENERATED);
			}

			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger?->warning('Failed to send substitute-approval iCal to ' . $email . ': ' . $e->getMessage(), [
				'app' => 'arbeitszeitcheck',
				'exception' => $e,
			]);
		}
	}

	/**
	 * Send a single iCal email to one recipient.
	 * Skips if email is empty or invalid. Catches exceptions and logs.
	 */
	private function sendOneIcalEmail(Absence $absence, ?string $email, string $displayName, string $icalBody, bool $isSubstitute): void
	{
		$email = $email !== null ? trim($email) : '';
		if ($email === '' || !$this->mailer->validateMailAddress($email)) {
			return;
		}

		try {
			$message = $this->mailer->createMessage();
			$subject = $this->getSubject($absence, $isSubstitute);
			$plainBody = $this->getPlainBody($absence, $isSubstitute);

			$message->setSubject($subject);
			$message->setPlainBody($plainBody);
			$message->setTo([$email => $displayName]);
			$this->setFrom($message);

			$attachment = $this->mailer->createAttachment(
				$icalBody,
				'absence.ics',
				'text/calendar; method=PUBLISH; charset=UTF-8'
			);
			$message->attach($attachment);

			if (method_exists($message, 'setAutoSubmitted')) {
				$message->setAutoSubmitted(\OCP\Mail\Headers\AutoSubmitted::VALUE_AUTO_GENERATED);
			}

			$this->mailer->send($message);
		} catch (\Throwable $e) {
			$this->logger?->warning('Failed to send absence iCal email to ' . $email . ': ' . $e->getMessage(), [
				'app' => 'arbeitszeitcheck',
				'exception' => $e,
			]);
		}
	}

	private function setFrom(IMessage $message): void
	{
		$fromAddress = (string) $this->config->getSystemValue('mail_from_address', '');
		$fromDomain = (string) $this->config->getSystemValue('mail_domain', 'localhost');
		if ($fromAddress !== '') {
			$from = $fromAddress . '@' . $fromDomain;
			$fromName = (string) $this->config->getSystemValue('mail_from_name', 'ArbeitszeitCheck');
			$message->setFrom([$from => $fromName]);
		}
		// If not set, Nextcloud Mailer uses config default
	}

	private function getSubject(Absence $absence, bool $isSubstitute): string
	{
		$typeLabel = $this->getTypeLabel($absence->getType());
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		$startStr = $start ? $start->format('Y-m-d') : '';
		$endStr = $end ? $end->format('Y-m-d') : '';
		if ($isSubstitute) {
			$owner = $this->userManager->get($absence->getUserId());
			$ownerName = $owner ? $owner->getDisplayName() : $absence->getUserId();
			return $this->l10n->t('Absence approved: %1$s – %2$s (%3$s, %4$s)', [$ownerName, $typeLabel, $startStr, $endStr]);
		}
		return $this->l10n->t('Your absence was approved: %1$s (%2$s – %3$s)', [$typeLabel, $startStr, $endStr]);
	}

	private function getPlainBody(Absence $absence, bool $isSubstitute): string
	{
		$typeLabel = $this->getTypeLabel($absence->getType());
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		$startStr = $start ? $start->format('Y-m-d') : '';
		$endStr = $end ? $end->format('Y-m-d') : '';
		if ($isSubstitute) {
			$owner = $this->userManager->get($absence->getUserId());
			$ownerName = $owner ? $owner->getDisplayName() : $absence->getUserId();
			return $this->l10n->t(
				'An absence has been approved. %1$s will be absent (%2$s) from %3$s to %4$s. An iCalendar file is attached so you can add it to your calendar.',
				[$ownerName, $typeLabel, $startStr, $endStr]
			);
		}
		return $this->l10n->t(
			'Your absence request (%1$s, %2$s – %3$s) has been approved. An iCalendar file is attached so you can add it to your calendar.',
			[$typeLabel, $startStr, $endStr]
		);
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

	/**
	 * Build RFC 5545 iCalendar string for an all-day event.
	 * Returns null if dates are missing.
	 *
	 * @param bool $asSubstitute If true, use "Covering for [owner]" as SUMMARY.
	 */
	private function buildIcalForAbsence(Absence $absence, bool $asSubstitute = false): ?string
	{
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		if ($start === null || $end === null) {
			return null;
		}

		$dtStart = $start->format('Ymd');
		$endDay = (clone $end)->modify('+1 day');
		$dtEnd = $endDay->format('Ymd');
		$dtStamp = (new \DateTime())->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
		$uidSuffix = $asSubstitute ? '-sub' : '';
		$uid = 'arbeitszeitcheck-absence-' . $absence->getId() . $uidSuffix . '@' . ((string) $this->config->getSystemValue('mail_domain', 'localhost'));

		$owner = $this->userManager->get($absence->getUserId());
		$ownerName = $owner ? $owner->getDisplayName() : $absence->getUserId();
		$typeLabel = $this->getTypeLabel($absence->getType());
		if ($asSubstitute) {
			$summaryRaw = $this->l10n->t('Covering for %1$s (%2$s)', [$ownerName, $typeLabel]);
		} else {
			$summaryRaw = $this->l10n->t('%1$s: %2$s', [$ownerName, $typeLabel]);
		}
		$summary = $this->escapeIcalText($summaryRaw);
		$description = $summary;
		$reason = $absence->getReason();
		if ($reason !== null && trim($reason) !== '') {
			$description = $this->escapeIcalText(trim($reason));
		}

		$summaryLine = 'SUMMARY:' . $summary;
		$descLine = 'DESCRIPTION:' . $description;
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//ArbeitszeitCheck//Absence//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:' . $uid,
			'DTSTAMP:' . $dtStamp,
			'DTSTART;VALUE=DATE:' . $dtStart,
			'DTEND;VALUE=DATE:' . $dtEnd,
			$this->foldIcalLine($summaryLine),
			$this->foldIcalLine($descLine),
			'STATUS:CONFIRMED',
			'TRANSP:OPAQUE',
			'END:VEVENT',
			'END:VCALENDAR',
		];

		return implode("\r\n", $lines);
	}

	/**
	 * Escape text for iCalendar (RFC 5545): backslash, semicolon, comma, newline
	 */
	private function escapeIcalText(string $text): string
	{
		$text = str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $text);
		return $text;
	}

	/**
	 * Fold a content line per RFC 5545 (max 75 octets per line, UTF-8 safe)
	 */
	private function foldIcalLine(string $line): string
	{
		$maxOctets = 75;
		if (strlen($line) <= $maxOctets) {
			return $line;
		}
		$result = '';
		$offset = 0;
		$len = strlen($line);
		$first = true;
		while ($offset < $len) {
			if (!$first) {
				$result .= "\r\n ";
			}
			$take = $maxOctets - ($first ? 0 : 1);
			$chunk = mb_strcut($line, $offset, $take, 'UTF-8');
			$result .= $chunk;
			$offset += strlen($chunk);
			$first = false;
		}
		return $result;
	}
}
