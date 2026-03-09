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
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
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
			$this->deleteAbsences($userId);
			$this->clearSubstituteFromAbsences($userId);
			$this->deleteComplianceViolations($userId);
			$this->deleteAuditLogs($userId);
			$this->deleteUserSettings($userId);
			$this->deleteUserWorkingTimeModels($userId);
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
		$count = $this->absenceMapper->clearSubstituteForUser($userId);
		if ($count > 0) {
			$this->logger->info('Cleared substitute from absences', ['app' => 'arbeitszeitcheck', 'userId' => $userId, 'count' => $count]);
		}
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
