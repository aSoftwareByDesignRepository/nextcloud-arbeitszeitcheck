<?php

declare(strict_types=1);

/**
 * User deleted listener for arbeitszeitcheck app
 *
 * Cleans up arbeitszeitcheck data when a user is deleted from Nextcloud.
 * Ensures no orphaned time entries, absences, violations, or settings remain.
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceCalendarSyncService;
use OCA\ArbeitszeitCheck\Service\HolidayNcCalendarSyncService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Listener for user deletion events
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener
{
	public function __construct(
		private readonly TimeEntryMapper $timeEntryMapper,
		private readonly AbsenceMapper $absenceMapper,
		private readonly ComplianceViolationMapper $complianceViolationMapper,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly UserSettingsMapper $userSettingsMapper,
		private readonly UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		private readonly TeamMemberMapper $teamMemberMapper,
		private readonly TeamManagerMapper $teamManagerMapper,
		private readonly VacationYearBalanceMapper $vacationYearBalanceMapper,
		private readonly AbsenceCalendarSyncService $absenceCalendarSyncService,
		private readonly HolidayNcCalendarSyncService $holidayNcCalendarSyncService,
		private readonly NotificationService $notificationService,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void
	{
		if (!$event instanceof UserDeletedEvent) {
			return;
		}

		$userId = $event->getUser()->getUID();

		try {
			$this->deleteTimeEntries($userId);
			try {
				$this->absenceCalendarSyncService->removeAllMappingsForUser($userId);
			} catch (\Throwable $e) {
				$this->logger->warning('Absence calendar cleanup failed for deleted user', ['exception' => $e, 'userId' => $userId]);
			}
			try {
				$this->holidayNcCalendarSyncService->removeAllForUser($userId);
			} catch (\Throwable $e) {
				$this->logger->warning('Holiday calendar cleanup failed for deleted user', ['exception' => $e, 'userId' => $userId]);
			}
			$this->deleteAbsences($userId);
			$this->clearSubstituteFromAbsences($userId);
			$this->deleteComplianceViolations($userId);
			$this->deleteAuditLogs($userId);
			$this->deleteUserSettings($userId);
			$this->deleteUserWorkingTimeModels($userId);
			$this->vacationYearBalanceMapper->deleteByUserId($userId);
			$this->teamMemberMapper->deleteByUserId($userId);
			$this->teamManagerMapper->deleteByUserId($userId);
		} catch (\Throwable $e) {
			$this->logger->error('Error cleaning up arbeitszeitcheck data for deleted user', [
				'app' => 'arbeitszeitcheck',
				'exception' => $e,
				'userId' => $userId,
			]);
		}
	}

	private function deleteTimeEntries(string $userId): void
	{
		$this->timeEntryMapper->deleteByUser($userId);
		$this->logger->info('Deleted time entries for user', ['app' => 'arbeitszeitcheck', 'userId' => $userId]);
	}

	private function deleteAbsences(string $userId): void
	{
		$this->absenceMapper->deleteByUser($userId);
		$this->logger->info('Deleted absences for user', ['app' => 'arbeitszeitcheck', 'userId' => $userId]);
	}

	private function clearSubstituteFromAbsences(string $userId): void
	{
		$affected = $this->absenceMapper->findBySubstituteUser($userId);
		if (empty($affected)) {
			return;
		}

		$notifiedCount = 0;
		foreach ($affected as $absence) {
			// Remember original status to decide how to transition.
			$status = $absence->getStatus();

			// If the absence was still waiting for this substitute's approval,
			// fall back to a normal pending state so the request is not stuck.
			if ($status === Absence::STATUS_SUBSTITUTE_PENDING) {
				$absence->setStatus(Absence::STATUS_PENDING);
			}

			$absence->setSubstituteUserId(null);
			$absence->setUpdatedAt(new \DateTime());
			$this->absenceMapper->update($absence);

			// Notify the employee that their chosen substitute no longer exists
			// so they can pick a new one if needed.
			try {
				$employeeUserId = $absence->getUserId();
				$startDate = $absence->getStartDate();
				$endDate = $absence->getEndDate();

				$this->notificationService->notifySubstituteDeclined(
					$employeeUserId,
					$userId,
					[
						'id' => $absence->getId(),
						'type' => $absence->getType(),
						'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
						'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
						'days' => $absence->getDays(),
					],
					$this->l10n->t('Your selected substitute account was removed. Please edit this absence and choose a new substitute if required.')
				);
				$notifiedCount++;
			} catch (\Throwable $e) {
				$this->logger->warning('Failed to notify employee about removed substitute', [
					'app' => 'arbeitszeitcheck',
					'userId' => $userId,
					'absenceId' => $absence->getId(),
					'exception' => $e,
				]);
			}
		}

		$this->logger->info('Cleared substitute from absences for deleted user', [
			'app' => 'arbeitszeitcheck',
			'userId' => $userId,
			'count' => \count($affected),
			'notified' => $notifiedCount,
		]);
	}

	private function deleteComplianceViolations(string $userId): void
	{
		$this->complianceViolationMapper->deleteByUser($userId);
		$this->logger->info('Deleted compliance violations for user', ['app' => 'arbeitszeitcheck', 'userId' => $userId]);
	}

	private function deleteAuditLogs(string $userId): void
	{
		$this->auditLogMapper->deleteByUser($userId);
		$this->logger->info('Deleted audit logs for user', ['app' => 'arbeitszeitcheck', 'userId' => $userId]);
	}

	private function deleteUserSettings(string $userId): void
	{
		$this->userSettingsMapper->deleteByUser($userId);
		$this->logger->info('Deleted user settings for user', ['app' => 'arbeitszeitcheck', 'userId' => $userId]);
	}

	private function deleteUserWorkingTimeModels(string $userId): void
	{
		$this->userWorkingTimeModelMapper->deleteByUser($userId);
		$this->logger->info('Deleted working time model assignments for user', ['app' => 'arbeitszeitcheck', 'userId' => $userId]);
	}
}
