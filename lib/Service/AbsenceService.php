<?php

declare(strict_types=1);

/**
 * Absence service for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCA\ArbeitszeitCheck\Constants;
use OCP\IUserManager;

/**
 * Absence service for absence management business logic
 */
class AbsenceService
{
	private AbsenceMapper $absenceMapper;
	private AuditLogMapper $auditLogMapper;
	private UserSettingsMapper $userSettingsMapper;
	private TeamResolverService $teamResolver;
	private UserWorkingTimeModelMapper $userWorkingTimeModelMapper;
	private IConfig $config;
	private IDBConnection $db;
	private IUserManager $userManager;
	private IL10N $l10n;
	private ?NotificationService $notificationService;
	private ?AbsenceIcalMailService $absenceIcalMailService;
	private ?AbsenceNotificationMailService $absenceNotificationMailService;
	private HolidayService $holidayCalendarService;
	private VacationYearBalanceMapper $vacationYearBalanceMapper;
	private VacationAllocationService $vacationAllocationService;

	public function __construct(
		AbsenceMapper $absenceMapper,
		AuditLogMapper $auditLogMapper,
		UserSettingsMapper $userSettingsMapper,
		TeamResolverService $teamResolver,
		UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		IConfig $config,
		IDBConnection $db,
		IUserManager $userManager,
		IL10N $l10n,
		?NotificationService $notificationService,
		?AbsenceIcalMailService $absenceIcalMailService,
		HolidayService $holidayCalendarService,
		VacationYearBalanceMapper $vacationYearBalanceMapper,
		VacationAllocationService $vacationAllocationService,
		?AbsenceNotificationMailService $absenceNotificationMailService = null
	) {
		$this->absenceMapper = $absenceMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->teamResolver = $teamResolver;
		$this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
		$this->config = $config;
		$this->db = $db;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->notificationService = $notificationService;
		$this->absenceIcalMailService = $absenceIcalMailService;
		$this->holidayCalendarService = $holidayCalendarService;
		$this->vacationYearBalanceMapper = $vacationYearBalanceMapper;
		$this->absenceNotificationMailService = $absenceNotificationMailService;
		$this->vacationAllocationService = $vacationAllocationService;
	}

	/**
	 * Create a new absence request
	 *
	 * @param array $data Absence data
	 * @param string $userId User ID creating the request
	 * @return Absence
	 * @throws \Exception
	 */
	public function createAbsence(array $data, string $userId): Absence
	{
		$this->validateAbsenceData($data, $userId);

		$absence = new Absence();
		$absence->setUserId($userId);
		$absence->setType($data['type']);
		$absence->setStartDate($this->parseDate($data['start_date']));
		$absence->setEndDate($this->parseDate($data['end_date']));
		$absence->setReason($data['reason'] ?? null);
		$substituteUserId = isset($data['substitute_user_id']) ? trim((string)$data['substitute_user_id']) : null;
		$absence->setSubstituteUserId($substituteUserId ?: null);
		// If substitute is selected: wait for substitute approval first (Vertretungs-Freigabe)
		$absence->setStatus($substituteUserId ? Absence::STATUS_SUBSTITUTE_PENDING : Absence::STATUS_PENDING);
		$absence->setCreatedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

		// Calculate working days (Mon–Fri minus Feiertage inkl. Firmenfeiertage)
		$workingDays = $this->computeWorkingDaysForUser($userId, $absence->getStartDate(), $absence->getEndDate());
		$absence->setDays($workingDays);

		// All DB writes are atomic: if the audit log insertion fails, the absence
		// insertion is rolled back and the user sees an error rather than having an
		// absence in the DB with no audit trail.
		$savedAbsence = null;
		$autoApproved = false;

		$this->db->beginTransaction();
		try {
			$savedAbsence = $this->absenceMapper->insert($absence);

			$this->auditLogMapper->logAction(
				$userId,
				'absence_created',
				'absence',
				$savedAbsence->getId(),
				null,
				$savedAbsence->getSummary()
			);

			// Auto-approve when employee has no manager (no colleagues in team/groups)
			if ($savedAbsence->getStatus() === Absence::STATUS_PENDING && !$this->employeeHasManager($userId)) {
				$savedAbsence = $this->doAutoApproveDbWork($savedAbsence);
				$autoApproved = true;
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Side effects (notifications / emails) always happen after the DB commit so
		// that a) no DB lock is held while sending mail, and b) notifications are only
		// dispatched when the data is actually persisted.
		if ($autoApproved) {
			if ($this->notificationService) {
				$startDate = $savedAbsence->getStartDate();
				$endDate = $savedAbsence->getEndDate();
				$this->notificationService->notifyAbsenceApproved($savedAbsence->getUserId(), [
					'id' => $savedAbsence->getId(),
					'type' => $savedAbsence->getType(),
					'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
					'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
					'days' => $savedAbsence->getDays()
				]);
			}
			if ($this->absenceIcalMailService) {
				$this->absenceIcalMailService->sendIcalForApprovedAbsence($savedAbsence);
			}
		} elseif ($substituteUserId) {
			if ($this->notificationService) {
				$startDate = $savedAbsence->getStartDate();
				$endDate = $savedAbsence->getEndDate();
				$this->notificationService->notifySubstitutionRequest(
					$substituteUserId,
					$userId,
					[
						'id' => $savedAbsence->getId(),
						'type' => $savedAbsence->getType(),
						'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
						'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
						'days' => $savedAbsence->getDays()
					]
				);
			}
			if ($this->absenceNotificationMailService) {
				$this->absenceNotificationMailService->sendSubstitutionRequestToSubstitute($savedAbsence);
			}
		}

		return $savedAbsence;
	}

	/**
	 * Get an absence by ID
	 *
	 * @param int $id Absence ID
	 * @param string $userId User ID (for access control)
	 * @return Absence|null
	 */
	public function getAbsence(int $id, string $userId): ?Absence
	{
		try {
			$absence = $this->absenceMapper->find($id);

			// Check if user has access to this absence
			// Note: Manager/admin access is handled at the controller level
			// Managers use ManagerController methods which check permissions separately
			if ($absence->getUserId() !== $userId) {
				return null;
			}

			return $absence;
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Update an absence
	 *
	 * @param int $id Absence ID
	 * @param array $data Update data
	 * @param string $userId User ID performing the update
	 * @return Absence
	 * @throws \Exception
	 */
	public function updateAbsence(int $id, array $data, string $userId): Absence
	{
		$absence = $this->getAbsence($id, $userId);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}

		// Check if absence can be updated (pending, substitute_pending, or substitute_declined can be modified by owner)
		if (!in_array($absence->getStatus(), [Absence::STATUS_PENDING, Absence::STATUS_SUBSTITUTE_PENDING, Absence::STATUS_SUBSTITUTE_DECLINED], true)) {
			throw new \Exception($this->l10n->t('Only pending absences can be updated'));
		}

		$oldData = $absence->getSummary();

		// Update allowed fields (use parseDate for consistent validation)
		if (isset($data['start_date'])) {
			$absence->setStartDate($this->parseDate($data['start_date']));
		}
		if (isset($data['end_date'])) {
			$absence->setEndDate($this->parseDate($data['end_date']));
		}
		if (isset($data['reason'])) {
			$absence->setReason($data['reason']);
		}
		if (array_key_exists('substitute_user_id', $data)) {
			$absence->setSubstituteUserId($data['substitute_user_id'] ? (string)$data['substitute_user_id'] : null);
		}

		$startDate = $absence->getStartDate();
		$endDate = $absence->getEndDate();
		if (!$startDate || !$endDate) {
			throw new \Exception($this->l10n->t('Start date and end date are required'));
		}
		$validateData = [
			'type' => $absence->getType(),
			'start_date' => $startDate->format('Y-m-d'),
			'end_date' => $endDate->format('Y-m-d'),
			'reason' => $absence->getReason(),
		];
		if (array_key_exists('substitute_user_id', $data)) {
			$validateData['substitute_user_id'] = $data['substitute_user_id'];
		}
		$this->validateAbsenceData($validateData, $userId, $id, $absence->getCreatedAt());

		// Recalculate working days (Mon–Fri minus Feiertage inkl. Firmenfeiertage)
		$workingDays = $this->computeWorkingDaysForUser($userId, $absence->getStartDate(), $absence->getEndDate());
		$absence->setDays($workingDays);
		$absence->setUpdatedAt(new \DateTime());

		// When resubmitting after substitute_declined: clear decline comment, set status, notify new substitute
		$wasDeclined = $absence->getStatus() === Absence::STATUS_SUBSTITUTE_DECLINED;
		$newSubstituteId = $absence->getSubstituteUserId();

		if ($wasDeclined) {
			$absence->setApproverComment(null);
			$absence->setStatus($newSubstituteId ? Absence::STATUS_SUBSTITUTE_PENDING : Absence::STATUS_PENDING);
		}

		$updatedAbsence = null;

		$this->db->beginTransaction();
		try {
			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$userId,
				'absence_updated',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary()
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Side effects after commit
		if ($wasDeclined && $newSubstituteId && $this->notificationService) {
			$startDate = $updatedAbsence->getStartDate();
			$endDate = $updatedAbsence->getEndDate();
			$this->notificationService->notifySubstitutionRequest(
				$newSubstituteId,
				$updatedAbsence->getUserId(),
				[
					'id' => $updatedAbsence->getId(),
					'type' => $updatedAbsence->getType(),
					'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
					'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
					'days' => $updatedAbsence->getDays(),
				]
			);
		}
		if ($wasDeclined && $newSubstituteId && $this->absenceNotificationMailService) {
			$this->absenceNotificationMailService->sendSubstitutionRequestToSubstitute($updatedAbsence);
		}

		return $updatedAbsence;
	}

	/**
	 * Delete an absence
	 *
	 * @param int $id Absence ID
	 * @param string $userId User ID performing the deletion
	 * @throws \Exception
	 */
	public function deleteAbsence(int $id, string $userId): void
	{
		$absence = $this->getAbsence($id, $userId);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}

		// Check if absence can be deleted (pending, substitute_pending, or substitute_declined can be deleted by owner)
		if (!in_array($absence->getStatus(), [Absence::STATUS_PENDING, Absence::STATUS_SUBSTITUTE_PENDING, Absence::STATUS_SUBSTITUTE_DECLINED], true)) {
			throw new \Exception($this->l10n->t('Only pending absences can be deleted'));
		}

		$this->db->beginTransaction();
		try {
			$this->absenceMapper->delete($absence);

			$this->auditLogMapper->logAction(
				$userId,
				'absence_deleted',
				'absence',
				$id,
				$absence->getSummary(),
				null
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * Cancel an existing absence.
	 *
	 * Security / business rules:
	 * - Only the owner can cancel their own absences (enforced via getAbsence()).
	 * - Cancellation is only allowed if the absence has not started yet
	 *   (start date strictly greater than today, in server timezone).
	 * - Only absences in one of the "active" states can be cancelled:
	 *   pending, substitute_pending, or approved.
	 *
	 * We keep the record (status = cancelled) for auditability instead of
	 * deleting it, so reports and logs remain consistent.
	 *
	 * @param int $id Absence ID
	 * @param string $userId User ID performing the cancellation
	 * @return Absence
	 * @throws \Exception
	 */
	public function cancelAbsence(int $id, string $userId): Absence
	{
		$absence = $this->getAbsence($id, $userId);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}

		$status = $absence->getStatus();
		if (!in_array($status, [Absence::STATUS_PENDING, Absence::STATUS_SUBSTITUTE_PENDING, Absence::STATUS_APPROVED], true)) {
			throw new \Exception($this->l10n->t('This absence cannot be cancelled.'));
		}

		$startDate = $absence->getStartDate();
		if (!$startDate) {
			throw new \Exception($this->l10n->t('Start date is missing for this absence.'));
		}

		$today = new \DateTimeImmutable('today');
		// Only allow cancellation before the first day of the absence
		if ($startDate <= $today) {
			throw new \Exception($this->l10n->t('You can only cancel absences that have not started yet.'));
		}

		$oldData = $absence->getSummary();

		$absence->setStatus(Absence::STATUS_CANCELLED);
		$absence->setUpdatedAt(new \DateTime());

		$updatedAbsence = null;

		$this->db->beginTransaction();
		try {
			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$userId,
				'absence_cancelled',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary()
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $updatedAbsence;
	}

	/**
	 * Shorten an approved absence (early return).
	 *
	 * Security / business rules:
	 * - Only the owner can shorten their own absences (enforced via getAbsence()).
	 * - Only approved absences can be shortened.
	 * - The absence must have started (start_date <= today) but not yet ended
	 *   (end_date > today), i.e. the employee is currently in the absence period.
	 * - The new end date must be strictly earlier than the original end date.
	 * - The new end date must be >= start date.
	 *
	 * Recalculates working days for the new range and logs the change for audit.
	 *
	 * @param int $id Absence ID
	 * @param string $userId User ID performing the change
	 * @param string $newEndDate New end date (Y-m-d or d.m.Y)
	 * @return Absence
	 * @throws \Exception
	 */
	public function shortenAbsence(int $id, string $userId, string $newEndDate): Absence
	{
		$absence = $this->getAbsence($id, $userId);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}

		if ($absence->getStatus() !== Absence::STATUS_APPROVED) {
			throw new \Exception($this->l10n->t('Only approved absences can be shortened.'));
		}

		$startDate = $absence->getStartDate();
		$originalEndDate = $absence->getEndDate();
		if (!$startDate || !$originalEndDate) {
			throw new \Exception($this->l10n->t('Start date or end date is missing for this absence.'));
		}

		$today = new \DateTimeImmutable('today');
		if ($startDate > $today) {
			throw new \Exception($this->l10n->t('You can only shorten absences that have already started.'));
		}
		if ($originalEndDate <= $today) {
			throw new \Exception($this->l10n->t('This absence has already ended. It cannot be shortened.'));
		}

		$newEnd = $this->parseDate($newEndDate);
		$newEnd->setTime(0, 0, 0);

		if ($newEnd < $startDate) {
			throw new \Exception($this->l10n->t('The new end date cannot be before the start date.'));
		}
		if ($newEnd >= $originalEndDate) {
			throw new \Exception($this->l10n->t('The new end date must be earlier than the original end date.'));
		}

		$oldData = $absence->getSummary();

		$absence->setEndDate($newEnd);
		$workingDays = $this->computeWorkingDaysForUser($userId, $absence->getStartDate(), $newEnd);
		$absence->setDays($workingDays);
		$absence->setUpdatedAt(new \DateTime());

		$updatedAbsence = null;

		$this->db->beginTransaction();
		try {
			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$userId,
				'absence_shortened',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary()
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $updatedAbsence;
	}

	/**
	 * Approve an absence request
	 *
	 * @param int $id Absence ID
	 * @param string $approverId User ID of the approver
	 * @param string|null $comment Approval comment
	 * @return Absence
	 * @throws \Exception
	 */
	public function approveAbsence(int $id, string $approverId, ?string $comment = null): Absence
	{
		$absence = $this->absenceMapper->find($id);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}

		if ($absence->getStatus() !== Absence::STATUS_PENDING) {
			throw new \Exception($this->l10n->t('Absence is not pending approval'));
		}

		$oldData = $absence->getSummary();

		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setApproverComment($comment);
		// Note: approvedBy stores approver user ID as string in database via entity mapping
		// The audit log stores it properly as string in performedBy field
		$absence->setApprovedBy(null); // Store approver ID in audit log instead (performedBy field)
		$absence->setApprovedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

		$updatedAbsence = null;

		$this->db->beginTransaction();
		try {
			if ($absence->getType() === Absence::TYPE_VACATION) {
				$sd = $absence->getStartDate();
				$ed = $absence->getEndDate();
				if ($sd && $ed) {
					$this->assertVacationAllocationForRequest($absence->getUserId(), $sd, $ed, null, $absence->getCreatedAt());
				}
			}

			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$approverId,
				'absence_approved',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary(),
				$approverId
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Side effects after commit
		if ($this->notificationService) {
			$startDate = $updatedAbsence->getStartDate();
			$endDate = $updatedAbsence->getEndDate();
			$this->notificationService->notifyAbsenceApproved($updatedAbsence->getUserId(), [
				'id' => $updatedAbsence->getId(),
				'type' => $updatedAbsence->getType(),
				'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
				'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
				'days' => $updatedAbsence->getDays()
			]);
		}

		if ($this->absenceIcalMailService) {
			$this->absenceIcalMailService->sendIcalForApprovedAbsence($updatedAbsence);
		}

		return $updatedAbsence;
	}

	/**
	 * Reject an absence request
	 *
	 * @param int $id Absence ID
	 * @param string $approverId User ID of the approver
	 * @param string|null $comment Rejection comment
	 * @return Absence
	 * @throws \Exception
	 */
	public function rejectAbsence(int $id, string $approverId, ?string $comment = null): Absence
	{
		$absence = $this->absenceMapper->find($id);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}

		if ($absence->getStatus() !== Absence::STATUS_PENDING) {
			throw new \Exception($this->l10n->t('Absence is not pending approval'));
		}

		$oldData = $absence->getSummary();

		$absence->setStatus(Absence::STATUS_REJECTED);
		$absence->setApproverComment($comment);
		// Note: approvedBy stores approver user ID as string in database via entity mapping
		// The audit log stores it properly as string in performedBy field
		$absence->setApprovedBy(null); // Store approver ID in audit log instead (performedBy field)
		$absence->setApprovedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

		$updatedAbsence = null;

		$this->db->beginTransaction();
		try {
			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$approverId,
				'absence_rejected',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary(),
				$approverId
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Side effect after commit
		if ($this->notificationService) {
			$startDate = $updatedAbsence->getStartDate();
			$endDate = $updatedAbsence->getEndDate();
			$this->notificationService->notifyAbsenceRejected($updatedAbsence->getUserId(), [
				'id' => $updatedAbsence->getId(),
				'type' => $updatedAbsence->getType(),
				'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
				'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
				'days' => $updatedAbsence->getDays()
			], $comment);
		}
		return $updatedAbsence;
	}

	/**
	 * Approve absence by substitute (Vertretungs-Freigabe)
	 * Transitions status from substitute_pending to pending (ready for manager approval)
	 *
	 * @param int $id Absence ID
	 * @param string $substituteUserId User ID of the substitute (must match absence.substitute_user_id)
	 * @return Absence
	 * @throws \Exception
	 */
	public function approveBySubstitute(int $id, string $substituteUserId): Absence
	{
		$absence = $this->absenceMapper->find($id);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}

		if ($absence->getStatus() !== Absence::STATUS_SUBSTITUTE_PENDING) {
			throw new \Exception($this->l10n->t('Absence is not awaiting substitute approval'));
		}

		$actualSubstitute = $absence->getSubstituteUserId();
		if ($actualSubstitute === null || $actualSubstitute !== $substituteUserId) {
			throw new \Exception($this->l10n->t('You are not the designated substitute for this absence'));
		}

		$oldData = $absence->getSummary();
		$absence->setStatus(Absence::STATUS_PENDING);
		$absence->setUpdatedAt(new \DateTime());

		$updatedAbsence = null;
		$wasAutoApproved = false;

		$this->db->beginTransaction();
		try {
			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$substituteUserId,
				'absence_substitute_approved',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary(),
				$substituteUserId
			);

			// When employee has no manager: auto-approve immediately (DB work only; notifications after commit)
			if (!$this->employeeHasManager($absence->getUserId())) {
				$updatedAbsence = $this->doAutoApproveDbWork($updatedAbsence);
				$wasAutoApproved = true;
			}

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Side effects after commit
		if ($wasAutoApproved) {
			if ($this->notificationService) {
				$startDate = $updatedAbsence->getStartDate();
				$endDate = $updatedAbsence->getEndDate();
				$this->notificationService->notifyAbsenceApproved($updatedAbsence->getUserId(), [
					'id' => $updatedAbsence->getId(),
					'type' => $updatedAbsence->getType(),
					'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
					'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
					'days' => $updatedAbsence->getDays()
				]);
			}
			if ($this->absenceIcalMailService) {
				$this->absenceIcalMailService->sendIcalForApprovedAbsence($updatedAbsence);
			}
		} else {
			// Employee has manager: notify about substitute approval and that manager approval is pending
			if ($this->notificationService) {
				$startDate = $updatedAbsence->getStartDate();
				$endDate = $updatedAbsence->getEndDate();
				$this->notificationService->notifySubstituteApproved(
					$updatedAbsence->getUserId(),
					$substituteUserId,
					[
						'id' => $updatedAbsence->getId(),
						'type' => $updatedAbsence->getType(),
						'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
						'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
						'days' => $updatedAbsence->getDays()
					]
				);
			}

			if ($this->absenceNotificationMailService) {
				$this->absenceNotificationMailService->sendSubstituteApprovedToEmployee($updatedAbsence);
				$this->absenceNotificationMailService->sendSubstituteApprovedToManagers($updatedAbsence);
			}

			if ($this->absenceIcalMailService) {
				$this->absenceIcalMailService->sendIcalToSubstituteOnSubstitutionApproval($updatedAbsence);
			}
		}
		return $updatedAbsence;
	}

	/**
	 * Decline absence by substitute
	 * Transitions status to substitute_declined
	 *
	 * @param int $id Absence ID
	 * @param string $substituteUserId User ID of the substitute
	 * @param string|null $comment Optional comment for the employee
	 * @return Absence
	 * @throws \Exception
	 */
	public function declineBySubstitute(int $id, string $substituteUserId, ?string $comment = null): Absence
	{
		$absence = $this->absenceMapper->find($id);
		if (!$absence) {
			throw new \Exception($this->l10n->t('Absence not found'));
		}

		if ($absence->getStatus() !== Absence::STATUS_SUBSTITUTE_PENDING) {
			throw new \Exception($this->l10n->t('Absence is not awaiting substitute approval'));
		}

		$actualSubstitute = $absence->getSubstituteUserId();
		if ($actualSubstitute === null || $actualSubstitute !== $substituteUserId) {
			throw new \Exception($this->l10n->t('You are not the designated substitute for this absence'));
		}

		$oldData = $absence->getSummary();
		$absence->setStatus(Absence::STATUS_SUBSTITUTE_DECLINED);
		$absence->setApproverComment($comment);
		$absence->setUpdatedAt(new \DateTime());

		$updatedAbsence = null;

		$this->db->beginTransaction();
		try {
			$updatedAbsence = $this->absenceMapper->update($absence);

			$this->auditLogMapper->logAction(
				$substituteUserId,
				'absence_substitute_declined',
				'absence',
				$updatedAbsence->getId(),
				$oldData,
				$updatedAbsence->getSummary(),
				$substituteUserId
			);

			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		// Side effect after commit
		if ($this->notificationService) {
			$startDate = $updatedAbsence->getStartDate();
			$endDate = $updatedAbsence->getEndDate();
			$this->notificationService->notifySubstituteDeclined(
				$updatedAbsence->getUserId(),
				$substituteUserId,
				[
					'id' => $updatedAbsence->getId(),
					'type' => $updatedAbsence->getType(),
					'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
					'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
					'days' => $updatedAbsence->getDays()
				],
				$comment
			);
		}
		return $updatedAbsence;
	}

	/**
	 * Get absences for a user with optional filters
	 *
	 * @param string $userId User ID (empty string to get all users - for manager views)
	 * @param array $filters Optional filters (status, type, date_range)
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return Absence[]
	 */
	public function getAbsencesByUser(string $userId, array $filters = [], ?int $limit = null, ?int $offset = null): array
	{
		// Handle date range filter
		if (isset($filters['date_range']) && isset($filters['date_range']['start']) && isset($filters['date_range']['end'])) {
			if (!empty($userId)) {
				return $this->absenceMapper->findByUserAndDateRange(
					$userId,
					$filters['date_range']['start'],
					$filters['date_range']['end']
				);
			}
		}

		// Handle status filter
		if (isset($filters['status'])) {
			if (empty($userId)) {
				return [];
			}
			$status = $filters['status'];
			$allAbsences = $this->absenceMapper->findByUser($userId, $limit, $offset);
			return array_values(array_filter($allAbsences, function ($absence) use ($status) {
				// "pending" = awaiting any approval (substitute or manager)
				if ($status === 'pending') {
					return in_array($absence->getStatus(), [Absence::STATUS_PENDING, Absence::STATUS_SUBSTITUTE_PENDING], true);
				}
				return $absence->getStatus() === $status;
			}));
		}

		// Default: require non-empty userId (no cross-user listing)
		if (empty($userId)) {
			return [];
		}

		return $this->absenceMapper->findByUser($userId, $limit, $offset);
	}

	/**
	 * Get vacation statistics for a user
	 *
	 * @param string $userId User ID
	 * @param int $year Year to get statistics for
	 * @return array
	 */
	public function getVacationStats(string $userId, int $year): array
	{
		$sickDays = 0.0;
		try {
			$sickDays = $this->absenceMapper->getSickLeaveDays($userId, $year);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting sick leave days: ' . $e->getMessage(), ['exception' => $e]);
			$sickDays = 0.0;
		}

		try {
			$today = new \DateTime('today');
			$alloc = $this->vacationAllocationService->computeYearAllocation($userId, $year, null, null, null, $today, null);
			$totalEntitlement = (float)$alloc['entitlement'];
			$carryoverOpening = (float)$alloc['carryover_opening'];
			$totalAvailable = $totalEntitlement + $carryoverOpening;
			$usedDays = (float)$alloc['used_total_working_days'];
			$remaining = (float)$alloc['total_remaining_for_new_requests'];
			$carryoverRem = (float)($alloc['carryover_remaining_after_approved'] ?? 0);
			$annualRem = (float)($alloc['annual_remaining_after_approved'] ?? 0);
			$carryoverBlocked = $carryoverRem > 0.0001
				&& !$this->vacationAllocationService->isCarryoverUsableForNewRequests($year, $today);
			$cap = $this->vacationAllocationService->getMaxCarryoverOpeningCap();

			return [
				'year' => $year,
				'entitlement' => (int)round($totalEntitlement),
				'carryover_days' => round($carryoverOpening, 2),
				'carryover_usable' => round((float)$alloc['carryover_usable_for_new_requests'], 2),
				'carryover_expires_on' => $alloc['carryover_expires_on'],
				'carryover_unused_locked_after_deadline' => $carryoverBlocked,
				'carryover_remaining_after_approved' => round($carryoverRem, 2),
				'annual_remaining_after_approved' => round($annualRem, 2),
				'carryover_max_cap' => $cap !== null ? round($cap, 2) : null,
				'total_available' => round($totalAvailable, 2),
				'used' => round($usedDays, 2),
				'remaining' => round($remaining, 2),
				'sick_days' => $sickDays,
			];
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting vacation stats: ' . $e->getMessage(), ['exception' => $e]);
			return [
				'year' => $year,
				'entitlement' => Constants::DEFAULT_VACATION_DAYS_PER_YEAR,
				'carryover_days' => 0.0,
				'carryover_usable' => 0.0,
				'carryover_expires_on' => null,
				'carryover_unused_locked_after_deadline' => false,
				'carryover_remaining_after_approved' => 0.0,
				'annual_remaining_after_approved' => 0.0,
				'carryover_max_cap' => null,
				'total_available' => (float)Constants::DEFAULT_VACATION_DAYS_PER_YEAR,
				'used' => 0.0,
				'remaining' => (float)Constants::DEFAULT_VACATION_DAYS_PER_YEAR,
				'sick_days' => $sickDays,
			];
		}
	}

	/**
	 * Enforce FIFO carryover + annual pools for vacation (create/update/approve).
	 *
	 * @throws \Exception
	 */
	private function assertVacationAllocationForRequest(string $userId, \DateTime $startDate, \DateTime $endDate, ?int $excludeAbsenceId = null, ?\DateTimeInterface $prospectiveRequestCreatedAt = null): void
	{
		$requestedWorkingDaysPerYear = $this->computeWorkingDaysPerYear($startDate, $endDate, $userId);
		if ($requestedWorkingDaysPerYear === []) {
			$requestedWorkingDaysPerYear = HolidayService::computeWorkingDaysPerYear(
				clone $startDate,
				clone $endDate,
				[]
			);
		}
		$today = new \DateTime('today');
		foreach ($requestedWorkingDaysPerYear as $y => $requestedDays) {
			if ($requestedDays <= 0) {
				continue;
			}
			$year = (int)$y;
			$alloc = $this->vacationAllocationService->computeYearAllocation(
				$userId,
				$year,
				$excludeAbsenceId,
				$startDate,
				$endDate,
				$today,
				$prospectiveRequestCreatedAt
			);
			if ($alloc['allocation_valid']) {
				continue;
			}
			$before = $this->vacationAllocationService->computeYearAllocation(
				$userId,
				$year,
				$excludeAbsenceId,
				null,
				null,
				$today,
				null
			);
			$msg = $this->l10n->t(
				'Not enough vacation days remaining. You have %1$s days left for %2$s but requested %3$s days.',
				[
					(string)round($before['total_remaining_for_new_requests'], 1),
					(string)$year,
					(string)round($requestedDays, 1),
				]
			);
			throw new \Exception($msg);
		}
	}

	/**
	 * Validate absence data
	 *
	 * @param array $data Absence data
	 * @param string $userId User ID (absence owner)
	 * @param int|null $excludeAbsenceId When updating, ID of the absence to exclude from overlap check
	 * @throws \Exception
	 */
	private function validateAbsenceData(array $data, string $userId, ?int $excludeAbsenceId = null, ?\DateTimeInterface $vacationRequestCreatedAt = null): void
	{
		// Validate required fields
		if (empty($data['type']) || empty($data['start_date']) || empty($data['end_date'])) {
			throw new \Exception($this->l10n->t('Type, start date, and end date are required'));
		}

		// Validate dates (parseDate handles both ISO and German format)
		$startDate = $this->parseDate($data['start_date']);
		$endDate = $this->parseDate($data['end_date']);

		if ($startDate > $endDate) {
			throw new \Exception($this->l10n->t('Start date cannot be after end date'));
		}

		// Extract type early (needed for past-date and overlap logic)
		$type = isset($data['type']) && !is_array($data['type']) ? (string)$data['type'] : (is_array($data['type'] ?? null) && !empty($data['type']) ? (string)reset($data['type']) : '');

		// Validate dates: past start allowed only for sick leave (up to SICK_LEAVE_MAX_PAST_DAYS)
		$today = new \DateTime();
		$today->setTime(0, 0, 0);
		if ($startDate < $today) {
			if ($type === Absence::TYPE_SICK_LEAVE) {
				$cutoff = (clone $today)->modify('-' . Constants::SICK_LEAVE_MAX_PAST_DAYS . ' days');
				if ($startDate < $cutoff) {
					throw new \Exception($this->l10n->t('Sick leave start date cannot be more than %s days in the past.', [(string)Constants::SICK_LEAVE_MAX_PAST_DAYS]));
				}
			} else {
				throw new \Exception($this->l10n->t('Start date cannot be in the past'));
			}
		}

		// Check for overlapping absences (exclude current absence when updating)
		$overlapping = $this->absenceMapper->findOverlapping($userId, $startDate, $endDate, $excludeAbsenceId);
		if (!empty($overlapping)) {
			$first = $overlapping[0];
			$overlapType = $first->getType();
			$overlapStart = $first->getStartDate() ? $first->getStartDate()->format('d.m.Y') : '?';
			$overlapEnd = $first->getEndDate() ? $first->getEndDate()->format('d.m.Y') : '?';
			$typeLabels = [
				'vacation' => $this->l10n->t('Vacation'),
				'sick_leave' => $this->l10n->t('Sick Leave'),
				'personal_leave' => $this->l10n->t('Personal Leave'),
				'parental_leave' => $this->l10n->t('Parental Leave'),
				'special_leave' => $this->l10n->t('Special Leave'),
				'unpaid_leave' => $this->l10n->t('Unpaid Leave'),
				'home_office' => $this->l10n->t('Home Office'),
				'business_trip' => $this->l10n->t('Business Trip'),
			];
			$overlapTypeLabel = $typeLabels[$overlapType] ?? $this->l10n->t('Absence');
			$baseMsg = $this->l10n->t('This period overlaps with an existing %1$s (%2$s – %3$s).', [$overlapTypeLabel, $overlapStart, $overlapEnd]);
			if ($type === Absence::TYPE_SICK_LEAVE && $overlapType === Absence::TYPE_VACATION) {
				$hint = $this->l10n->t('If you were sick during vacation, please shorten or cancel the vacation first, then submit a separate sick leave request.');
				throw new \Exception($baseMsg . ' ' . $hint);
			}
			throw new \Exception($baseMsg);
		}

		// Validate type
		if (empty($type)) {
			throw new \Exception($this->l10n->t('Absence type is required'));
		}
		$validTypes = [
			Absence::TYPE_VACATION,
			Absence::TYPE_SICK_LEAVE,
			Absence::TYPE_PERSONAL_LEAVE,
			Absence::TYPE_PARENTAL_LEAVE,
			Absence::TYPE_SPECIAL_LEAVE,
			Absence::TYPE_UNPAID_LEAVE,
			Absence::TYPE_HOME_OFFICE,
			Absence::TYPE_BUSINESS_TRIP,
		];
		if (!in_array($type, $validTypes, true)) {
			throw new \Exception($this->l10n->t('Invalid absence type'));
		}
		$this->validateAbsenceTypeRules($type, $startDate, $endDate);

		// Vacation entitlement: FIFO carryover + annual (see VacationAllocationService)
		if ($type === Absence::TYPE_VACATION) {
			$requestedWorkingDaysPerYear = $this->computeWorkingDaysPerYear($startDate, $endDate, $userId);
			if ($requestedWorkingDaysPerYear === []) {
				$requestedWorkingDaysPerYear = HolidayService::computeWorkingDaysPerYear(
					clone $startDate,
					clone $endDate,
					[]
				);
			}
			$totalRequested = array_sum($requestedWorkingDaysPerYear);
			if ($totalRequested < 0.01) {
				throw new \Exception($this->l10n->t('Vacation must include at least one working day. The selected period contains only weekends or public holidays.'));
			}
			$this->assertVacationAllocationForRequest($userId, $startDate, $endDate, $excludeAbsenceId, $vacationRequestCreatedAt);
		}

		// Require substitute for configured types (admin setting)
		$requireSubstituteTypesJson = $this->config->getAppValue('arbeitszeitcheck', 'require_substitute_types', '[]');
		$requireSubstituteTypes = json_decode($requireSubstituteTypesJson, true);
		if (is_array($requireSubstituteTypes) && in_array($type, $requireSubstituteTypes, true)) {
			$substituteId = isset($data['substitute_user_id']) ? trim((string)$data['substitute_user_id']) : '';
			if ($substituteId === '') {
				throw new \Exception($this->l10n->t('A substitute is required for this absence type. Please select who will cover for you.'));
			}
		}

		// Validate substitute: must be a colleague (same team/group), existing and enabled (not self)
		$substituteId = isset($data['substitute_user_id']) ? trim((string)$data['substitute_user_id']) : '';
		if ($substituteId !== '') {
			if ($substituteId === $userId) {
				throw new \Exception($this->l10n->t('Substitute cannot be yourself'));
			}
			$substituteUser = $this->userManager->get($substituteId);
			if ($substituteUser === null || !$substituteUser->isEnabled()) {
				throw new \Exception($this->l10n->t('Substitute must be an existing user'));
			}
			$colleagueIds = $this->teamResolver->getColleagueIds($userId);
			if (!in_array($substituteId, $colleagueIds, true)) {
				throw new \Exception($this->l10n->t('Substitute must be a colleague in your team. Please select someone who shares a team or group with you.'));
			}
		}
	}

	/**
	 * Validate absence type specific rules
	 *
	 * @param string $type Absence type
	 * @param \DateTime $startDate
	 * @param \DateTime $endDate
	 * @throws \Exception
	 */
	/**
	 * Validate absence type rules (max calendar days per type).
	 * Limits are documented here and enforced for consistency and abuse prevention.
	 */
	private function validateAbsenceTypeRules(string $type, \DateTime $startDate, \DateTime $endDate): void
	{
		$days = $startDate->diff($endDate)->days + 1;

		switch ($type) {
			case Absence::TYPE_VACATION:
				if ($days > 30) {
					throw new \Exception($this->l10n->t('Vacation cannot exceed 30 days'));
				}
				break;
			case Absence::TYPE_SICK_LEAVE:
				if ($days > Constants::MAX_ABSENCE_DAYS) {
					throw new \Exception($this->l10n->t('Sick leave duration seems unreasonable'));
				}
				break;
			case Absence::TYPE_PERSONAL_LEAVE:
				if ($days > 5) {
					throw new \Exception($this->l10n->t('Personal leave cannot exceed 5 days'));
				}
				break;
			case Absence::TYPE_PARENTAL_LEAVE:
				if ($days > 1095) { // ~3 years
					throw new \Exception($this->l10n->t('Parental leave cannot exceed 3 years per request'));
				}
				break;
			case Absence::TYPE_SPECIAL_LEAVE:
				if ($days > 30) {
					throw new \Exception($this->l10n->t('Special leave cannot exceed 30 days'));
				}
				break;
			case Absence::TYPE_UNPAID_LEAVE:
				if ($days > Constants::MAX_ABSENCE_DAYS) {
					throw new \Exception($this->l10n->t('Unpaid leave cannot exceed 365 days'));
				}
				break;
			case Absence::TYPE_HOME_OFFICE:
			case Absence::TYPE_BUSINESS_TRIP:
				if ($days > Constants::MAX_ABSENCE_DAYS) {
					throw new \Exception($this->l10n->t('Duration cannot exceed 365 days'));
				}
				break;
		}
	}

	/**
	 * Whether the employee has at least one manager who could approve.
	 * Uses getManagerIdsForEmployee when app-owned teams are enabled.
	 * Falls back to getColleagueIds for group-based mode (legacy setups).
	 * Used to auto-approve absences when no one would see them in the manager dashboard.
	 */
	private function employeeHasManager(string $employeeUserId): bool
	{
		$managerIds = $this->teamResolver->getManagerIdsForEmployee($employeeUserId);
		if (!empty($managerIds)) {
			return true;
		}
		// Group-based mode: no explicit managers, fall back to colleagues for legacy
		$colleagueIds = $this->teamResolver->getColleagueIds($employeeUserId);
		return !empty($colleagueIds);
	}

	/**
	 * Perform only the DB writes needed to auto-approve an absence (no notifications/emails).
	 *
	 * Call this inside an open transaction so the status update and the audit log are
	 * committed atomically. Send notifications after the caller commits.
	 */
	private function doAutoApproveDbWork(Absence $absence): Absence
	{
		if ($absence->getType() === Absence::TYPE_VACATION) {
			$sd = $absence->getStartDate();
			$ed = $absence->getEndDate();
			if ($sd && $ed) {
				$this->assertVacationAllocationForRequest($absence->getUserId(), $sd, $ed, null, $absence->getCreatedAt());
			}
		}

		$oldData = $absence->getSummary();
		$absence->setStatus(Absence::STATUS_APPROVED);
		$absence->setApproverComment($this->l10n->t('Auto-approved: no manager assigned in your team.'));
		$absence->setApprovedBy(null);
		$absence->setApprovedAt(new \DateTime());
		$absence->setUpdatedAt(new \DateTime());

		$updatedAbsence = $this->absenceMapper->update($absence);

		$this->auditLogMapper->logAction(
			$absence->getUserId(),
			'absence_auto_approved',
			'absence',
			$updatedAbsence->getId(),
			$oldData,
			$updatedAbsence->getSummary(),
			'system'
		);

		return $updatedAbsence;
	}

	/**
	 * Auto-approve an absence when the employee has no manager (no colleagues).
	 * Ensures absences are not stuck in PENDING forever for solo users or users alone in their team.
	 *
	 * This method wraps `doAutoApproveDbWork` in its own transaction and then sends
	 * notifications. Prefer calling `doAutoApproveDbWork` directly inside a caller-owned
	 * transaction (e.g. createAbsence, approveBySubstitute) to keep everything atomic.
	 */
	private function autoApproveForNoManager(Absence $absence): Absence
	{
		$updatedAbsence = null;

		$this->db->beginTransaction();
		try {
			$updatedAbsence = $this->doAutoApproveDbWork($absence);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		if ($this->notificationService) {
			$startDate = $updatedAbsence->getStartDate();
			$endDate = $updatedAbsence->getEndDate();
			$this->notificationService->notifyAbsenceApproved($updatedAbsence->getUserId(), [
				'id' => $updatedAbsence->getId(),
				'type' => $updatedAbsence->getType(),
				'start_date' => $startDate ? $startDate->format('Y-m-d') : null,
				'end_date' => $endDate ? $endDate->format('Y-m-d') : null,
				'days' => $updatedAbsence->getDays()
			]);
		}

		if ($this->absenceIcalMailService) {
			$this->absenceIcalMailService->sendIcalForApprovedAbsence($updatedAbsence);
		}

		return $updatedAbsence;
	}

	/**
	 * Parse date string - supports both ISO (yyyy-mm-dd) and German format (dd.mm.yyyy)
	 *
	 * @param string $dateString Date string in either format
	 * @return \DateTime
	 * @throws \Exception if date cannot be parsed
	 */
	private function parseDate(string $dateString): \DateTime
	{
		$dateString = trim($dateString);
		if ($dateString === '') {
			throw new \Exception($this->l10n->t('Date is required and cannot be empty'));
		}

		// Try German format first (dd.mm.yyyy)
		if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $dateString, $matches)) {
			$day = (int)$matches[1];
			$month = (int)$matches[2];
			$year = (int)$matches[3];
			
			// Validate date
			if (!checkdate($month, $day, $year)) {
				throw new \Exception($this->l10n->t('Invalid date: %s', [$dateString]));
			}
			
			return new \DateTime(sprintf('%04d-%02d-%02d', $year, $month, $day));
		}
		
		// Try ISO format (yyyy-mm-dd)
		try {
			return new \DateTime($dateString);
		} catch (\Throwable $e) {
			throw new \Exception($this->l10n->t('Invalid date format. Expected yyyy-mm-dd or dd.mm.yyyy: %s', [$dateString]));
		}
	}

	/**
	 * Compute working days per year for a date range (excludes weekends and German public holidays)
	 *
	 * @param \DateTime $start
	 * @param \DateTime $end
	 * @return array<int, float> year => working days
	 */
	private function computeWorkingDaysPerYear(\DateTime $start, \DateTime $end, string $userId): array
	{
		return $this->holidayCalendarService->computeWorkingDaysPerYearForUser($userId, $start, $end);
	}

	/**
	 * Compute working days for a user absence, taking into account
	 * company-wide holidays (full and half days).
	 */
	private function computeWorkingDaysForUser(string $userId, \DateTime $start, \DateTime $end): float
	{
		return $this->holidayCalendarService->computeWorkingDaysForUser($userId, $start, $end);
	}

	/**
	 * Get working days for display (state-aware).
	 * Uses stored days when set; otherwise computes via HolidayService
	 * for consistency with vacation stats and company/state holidays.
	 */
	public function getWorkingDaysForDisplay(Absence $absence): float
	{
		if ($absence->getDays() !== null) {
			return (float)$absence->getDays();
		}
		$start = $absence->getStartDate();
		$end = $absence->getEndDate();
		if (!$start || !$end) {
			return 0.0;
		}
		return $this->holidayCalendarService->computeWorkingDaysForUser(
			$absence->getUserId(),
			$start,
			$end
		);
	}

	/**
	 * Build a map of additional holiday weights (full/half Firmenfeiertage)
	 * for the given date range and user.
	 *
	 * NOTE:
	 * - Aktuell sind Firmenfeiertage organisationsweit konfiguriert
	 *   (ohne Bundeslandspezifik). Pro-User-Bundesland wirkt sich daher
	 *   nur auf spätere, state-spezifische Erweiterungen aus.
	 *
	 * @return array<string,float> date (Y-m-d) => weight
	 */
	private function buildExtraHolidayWeights(\DateTime $start, \DateTime $end, string $userId): array
	{
		// Legacy helper is kept for backward compatibility with Absence::calculateWorkingDays()
		// and will internally delegate to HolidayService in future iterations if needed.
		unset($start, $end, $userId);
		return [];
	}
}