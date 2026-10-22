<?php

declare(strict_types=1);

/**
 * Email team managers about pending absences, manual entries, and corrections.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Util\AbsenceTypeLabel;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use Psr\Log\LoggerInterface;

class ManagerPendingApprovalMailService
{
	public function __construct(
		private IMailer $mailer,
		private IConfig $config,
		private IL10N $l10n,
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private TeamResolverService $teamResolver,
		private TeamManagerMapper $teamManagerMapper,
		private AbsenceMapper $absenceMapper,
		private TimeEntryMapper $timeEntryMapper,
		private ?LoggerInterface $logger = null,
	) {
	}

	public function isKindEnabled(string $kind): bool
	{
		$configKey = match ($kind) {
			Constants::MANAGER_PENDING_KIND_ABSENCE => Constants::CONFIG_MANAGER_PENDING_EMAIL_ABSENCES,
			Constants::MANAGER_PENDING_KIND_MANUAL => Constants::CONFIG_MANAGER_PENDING_EMAIL_MANUAL_ENTRIES,
			Constants::MANAGER_PENDING_KIND_CORRECTION => Constants::CONFIG_MANAGER_PENDING_EMAIL_CORRECTIONS,
			default => null,
		};
		if ($configKey === null) {
			return false;
		}
		return $this->config->getAppValue('arbeitszeitcheck', $configKey, '1') === '1';
	}

	public function getMode(): string
	{
		$mode = (string)$this->config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE,
			Constants::MANAGER_PENDING_EMAIL_MODE_IMMEDIATE
		);
		return $mode === Constants::MANAGER_PENDING_EMAIL_MODE_DIGEST
			? Constants::MANAGER_PENDING_EMAIL_MODE_DIGEST
			: Constants::MANAGER_PENDING_EMAIL_MODE_IMMEDIATE;
	}

	/**
	 * Immediate email when mode is immediate and the kind is enabled.
	 * Digest mode skips here; {@see sendDailyDigests()} covers open items.
	 *
	 * @param array{date?: ?string, start?: ?string, end?: ?string, type?: ?string, days?: float|int|null, justification?: ?string} $payload
	 */
	public function notifyManagersOfPending(string $kind, string $employeeUserId, array $payload = []): void
	{
		if (!$this->isKindEnabled($kind)) {
			return;
		}
		if ($this->getMode() !== Constants::MANAGER_PENDING_EMAIL_MODE_IMMEDIATE) {
			return;
		}

		$managerIds = $this->teamResolver->getManagerIdsForEmployee($employeeUserId);
		if ($managerIds === []) {
			return;
		}

		$employee = $this->userManager->get($employeeUserId);
		$employeeName = $employee ? $employee->getDisplayName() : $employeeUserId;
		$link = $this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.manager.dashboard');
		[$subject, $body] = $this->buildImmediateCopy($kind, $employeeName, $payload, $link);

		foreach (array_unique($managerIds) as $managerId) {
			$this->sendToManager((string)$managerId, $subject, $body, 'notifyManagersOfPending:' . $kind);
		}
	}

	/**
	 * Daily digest for each team manager with at least one open item of an enabled kind.
	 */
	public function sendDailyDigests(): int
	{
		if ($this->getMode() !== Constants::MANAGER_PENDING_EMAIL_MODE_DIGEST) {
			return 0;
		}
		if (!$this->teamResolver->useAppTeams()) {
			return 0;
		}

		$anyEnabled = $this->isKindEnabled(Constants::MANAGER_PENDING_KIND_ABSENCE)
			|| $this->isKindEnabled(Constants::MANAGER_PENDING_KIND_MANUAL)
			|| $this->isKindEnabled(Constants::MANAGER_PENDING_KIND_CORRECTION);
		if (!$anyEnabled) {
			return 0;
		}

		$sent = 0;
		$link = $this->urlGenerator->linkToRouteAbsolute('arbeitszeitcheck.manager.dashboard');
		foreach ($this->teamManagerMapper->findDistinctManagerUserIds() as $managerId) {
			$teamUserIds = $this->teamResolver->getTeamMemberIds($managerId);
			if ($teamUserIds === []) {
				continue;
			}

			$absenceCount = 0;
			$manualCount = 0;
			$correctionCount = 0;

			if ($this->isKindEnabled(Constants::MANAGER_PENDING_KIND_ABSENCE)) {
				$absenceCount = $this->absenceMapper->countPendingForUsers($teamUserIds);
			}
			if ($this->isKindEnabled(Constants::MANAGER_PENDING_KIND_MANUAL)
				|| $this->isKindEnabled(Constants::MANAGER_PENDING_KIND_CORRECTION)) {
				$counts = $this->countPendingTimeEntriesByKind($teamUserIds);
				if ($this->isKindEnabled(Constants::MANAGER_PENDING_KIND_MANUAL)) {
					$manualCount = $counts['manual'];
				}
				if ($this->isKindEnabled(Constants::MANAGER_PENDING_KIND_CORRECTION)) {
					$correctionCount = $counts['correction'];
				}
			}

			if ($absenceCount + $manualCount + $correctionCount === 0) {
				continue;
			}

			$subject = $this->l10n->t('Daily summary: open approvals in ArbeitszeitCheck');
			$lines = [
				$this->l10n->t('You have open approvals waiting:'),
			];
			if ($absenceCount > 0) {
				$lines[] = $this->l10n->n(
					'%n absence request',
					'%n absence requests',
					$absenceCount
				);
			}
			if ($manualCount > 0) {
				$lines[] = $this->l10n->n(
					'%n manual time entry',
					'%n manual time entries',
					$manualCount
				);
			}
			if ($correctionCount > 0) {
				$lines[] = $this->l10n->n(
					'%n time correction',
					'%n time corrections',
					$correctionCount
				);
			}
			$lines[] = '';
			$lines[] = $this->l10n->t('Open the manager dashboard: %s', [$link]);
			$body = implode("\n", $lines);

			if ($this->sendToManager($managerId, $subject, $body, 'sendDailyDigests')) {
				$sent++;
			}
		}

		return $sent;
	}

	/**
	 * Notify about a pending absence that needs manager approval (no substitute gate, or after substitute).
	 */
	public function notifyPendingAbsence(Absence $absence): void
	{
		if ($absence->getStatus() !== Absence::STATUS_PENDING) {
			return;
		}
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		$this->notifyManagersOfPending(
			Constants::MANAGER_PENDING_KIND_ABSENCE,
			$absence->getUserId(),
			[
				'type' => $absence->getType(),
				'start' => $start ? $start->format('Y-m-d') : null,
				'end' => $end ? $end->format('Y-m-d') : null,
				'days' => $absence->getDays(),
			]
		);
	}

	/**
	 * @param list<string> $userIds
	 * @return array{manual: int, correction: int}
	 */
	private function countPendingTimeEntriesByKind(array $userIds): array
	{
		$entries = $this->timeEntryMapper->findPendingApprovalForUsers($userIds, Constants::MAX_LIST_LIMIT, 0);
		$manual = 0;
		$correction = 0;
		foreach ($entries as $entry) {
			if (!$entry instanceof TimeEntry) {
				continue;
			}
			if ($this->isManualCreatePending($entry)) {
				$manual++;
			} else {
				$correction++;
			}
		}
		return ['manual' => $manual, 'correction' => $correction];
	}

	public function isManualCreatePending(TimeEntry $entry): bool
	{
		$raw = (string)($entry->getJustification() ?? '');
		if ($raw === '') {
			return false;
		}
		try {
			$decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
		} catch (\Throwable) {
			return false;
		}
		return is_array($decoded) && ($decoded['type'] ?? '') === 'manual_create';
	}

	/**
	 * @param array{date?: ?string, start?: ?string, end?: ?string, type?: ?string, days?: float|int|null, justification?: ?string} $payload
	 * @return array{0: string, 1: string}
	 */
	private function buildImmediateCopy(string $kind, string $employeeName, array $payload, string $link): array
	{
		return match ($kind) {
			Constants::MANAGER_PENDING_KIND_ABSENCE => $this->copyAbsence($employeeName, $payload, $link),
			Constants::MANAGER_PENDING_KIND_MANUAL => [
				$this->l10n->t('Manual time entry to approve: %s', [$employeeName]),
				$this->l10n->t(
					'%1$s submitted a manual time entry that needs your approval.',
					[$employeeName]
				) . "\n\n" . $this->l10n->t('Go to Manager Dashboard: %s', [$link]),
			],
			Constants::MANAGER_PENDING_KIND_CORRECTION => [
				$this->l10n->t('Time correction to approve: %s', [$employeeName]),
				$this->l10n->t(
					'%1$s requested a time entry correction that needs your approval.',
					[$employeeName]
				) . "\n\n" . $this->l10n->t('Go to Manager Dashboard: %s', [$link]),
			],
			default => [
				$this->l10n->t('Approval needed in ArbeitszeitCheck'),
				$this->l10n->t('Go to Manager Dashboard: %s', [$link]),
			],
		};
	}

	/**
	 * @param array{date?: ?string, start?: ?string, end?: ?string, type?: ?string, days?: float|int|null} $payload
	 * @return array{0: string, 1: string}
	 */
	private function copyAbsence(string $employeeName, array $payload, string $link): array
	{
		$typeLabel = AbsenceTypeLabel::get($this->l10n, (string)($payload['type'] ?? 'vacation'));
		$startStr = (string)($payload['start'] ?? '?');
		$endStr = (string)($payload['end'] ?? '?');
		$subject = $this->l10n->t('Absence to approve: %1$s – %2$s (%3$s – %4$s)', [
			$employeeName,
			$typeLabel,
			$startStr,
			$endStr,
		]);
		$body = $this->l10n->t(
			'%1$s has requested an absence (%2$s) from %3$s to %4$s. Please review and approve or reject the request.',
			[$employeeName, $typeLabel, $startStr, $endStr]
		) . "\n\n" . $this->l10n->t('Go to Manager Dashboard: %s', [$link]);
		return [$subject, $body];
	}

	private function sendToManager(string $managerId, string $subject, string $plainBody, string $logContext): bool
	{
		$manager = $this->userManager->get($managerId);
		if ($manager === null || !$manager->isEnabled()) {
			return false;
		}
		$email = $manager->getEMailAddress();
		if ($email === null || trim($email) === '' || !$this->mailer->validateMailAddress(trim($email))) {
			return false;
		}

		try {
			$message = $this->mailer->createMessage();
			$message->setSubject($subject);
			$message->setPlainBody($plainBody);
			$message->setTo([trim($email) => $manager->getDisplayName()]);
			$this->setFrom($message);
			if (method_exists($message, 'setAutoSubmitted')) {
				$message->setAutoSubmitted(\OCP\Mail\Headers\AutoSubmitted::VALUE_AUTO_GENERATED);
			}
			$this->mailer->send($message);
			return true;
		} catch (\Throwable $e) {
			$this->logger?->warning(
				'arbeitszeitcheck: Failed to send ' . $logContext . ' email to ' . $managerId . ': ' . $e->getMessage(),
				['app' => 'arbeitszeitcheck', 'exception' => $e]
			);
			return false;
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
}
