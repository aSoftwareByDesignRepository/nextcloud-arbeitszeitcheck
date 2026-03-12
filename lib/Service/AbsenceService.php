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
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\IL10N;
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
	private IUserManager $userManager;
	private IL10N $l10n;
	private ?NotificationService $notificationService;
	private ?AbsenceIcalMailService $absenceIcalMailService;
	private HolidayCalendarService $holidayCalendarService;

	public function __construct(
		AbsenceMapper $absenceMapper,
		AuditLogMapper $auditLogMapper,
		UserSettingsMapper $userSettingsMapper,
		TeamResolverService $teamResolver,
		UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		IConfig $config,
		IUserManager $userManager,
		IL10N $l10n,
		?NotificationService $notificationService = null,
		?AbsenceIcalMailService $absenceIcalMailService = null,
		HolidayCalendarService $holidayCalendarService
	) {
		$this->absenceMapper = $absenceMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->teamResolver = $teamResolver;
		$this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
		$this->config = $config;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->notificationService = $notificationService;
		$this->absenceIcalMailService = $absenceIcalMailService;
		$this->holidayCalendarService = $holidayCalendarService;
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
			return $this->autoApproveForNoManager($savedAbsence);
		}

		// Notify substitute when they need to approve (Vertretungs-Freigabe)
		if ($substituteUserId && $this->notificationService) {
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

		// Check if absence can be updated (pending or substitute_pending can be modified by owner)
		if (!in_array($absence->getStatus(), [Absence::STATUS_PENDING, Absence::STATUS_SUBSTITUTE_PENDING], true)) {
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
		$this->validateAbsenceData($validateData, $userId, $id);

		// Recalculate working days (Mon–Fri minus Feiertage inkl. Firmenfeiertage)
		$workingDays = $this->computeWorkingDaysForUser($userId, $absence->getStartDate(), $absence->getEndDate());
		$absence->setDays($workingDays);
		$absence->setUpdatedAt(new \DateTime());

		$updatedAbsence = $this->absenceMapper->update($absence);

		// Log the action
		$this->auditLogMapper->logAction(
			$userId,
			'absence_updated',
			'absence',
			$updatedAbsence->getId(),
			$oldData,
			$updatedAbsence->getSummary()
		);

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

		// Check if absence can be deleted (pending or substitute_pending can be deleted by owner)
		if (!in_array($absence->getStatus(), [Absence::STATUS_PENDING, Absence::STATUS_SUBSTITUTE_PENDING], true)) {
			throw new \Exception($this->l10n->t('Only pending absences can be deleted'));
		}

		$this->absenceMapper->delete($absence);

		// Log the action
		$this->auditLogMapper->logAction(
			$userId,
			'absence_deleted',
			'absence',
			$id,
			$absence->getSummary(),
			null
		);
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

		$updatedAbsence = $this->absenceMapper->update($absence);

		// Log the action
		$this->auditLogMapper->logAction(
			$approverId,
			'absence_approved',
			'absence',
			$updatedAbsence->getId(),
			$oldData,
			$updatedAbsence->getSummary(),
			$approverId
		);

		// Send notification to the employee
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

		// Send iCal email to employee (and optionally substitute) if enabled in admin settings
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

		$updatedAbsence = $this->absenceMapper->update($absence);

		// Log the action
		$this->auditLogMapper->logAction(
			$approverId,
			'absence_rejected',
			'absence',
			$updatedAbsence->getId(),
			$oldData,
			$updatedAbsence->getSummary(),
			$approverId
		);

		// Send notification to the employee
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

		// Notify employee that substitute approved
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

		// Send iCal to substitute so they can add coverage period to their calendar
		if ($this->absenceIcalMailService) {
			$this->absenceIcalMailService->sendIcalToSubstituteOnSubstitutionApproval($updatedAbsence);
		}

		// Auto-approve when employee has no manager (no colleagues in team/groups)
		if (!$this->employeeHasManager($absence->getUserId())) {
			return $this->autoApproveForNoManager($updatedAbsence);
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

		// Notify employee that substitute declined
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
		try {
			$usedDays = $this->absenceMapper->getVacationDaysUsed($userId, $year);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting vacation days used: ' . $e->getMessage(), ['exception' => $e]);
			$usedDays = 0.0;
		}

		try {
			$sickDays = $this->absenceMapper->getSickLeaveDays($userId, $year);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting sick leave days: ' . $e->getMessage(), ['exception' => $e]);
			$sickDays = 0.0;
		}

		// Get total vacation entitlement from the assigned working time model (single source of truth),
		// falling back to user setting or a safe default of 25 days.
		$totalEntitlement = 25;
		try {
			$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
			if ($currentModel !== null && $currentModel->getVacationDaysPerYear() !== null) {
				$totalEntitlement = $currentModel->getVacationDaysPerYear();
			} else {
				// Legacy / fallback: read from user settings if present
				$totalEntitlement = $this->userSettingsMapper->getIntegerSetting(
					$userId,
					'vacation_days_per_year',
					25
				);
			}
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error getting vacation entitlement: ' . $e->getMessage(), ['exception' => $e]);
			$totalEntitlement = 25;
		}

		return [
			'year' => $year,
			'entitlement' => $totalEntitlement,
			'used' => $usedDays,
			'remaining' => max(0, $totalEntitlement - $usedDays),
			'sick_days' => $sickDays
		];
	}

	/**
	 * Validate absence data
	 *
	 * @param array $data Absence data
	 * @param string $userId User ID (absence owner)
	 * @param int|null $excludeAbsenceId When updating, ID of the absence to exclude from overlap check
	 * @throws \Exception
	 */
	private function validateAbsenceData(array $data, string $userId, ?int $excludeAbsenceId = null): void
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

		// Validate dates are not in the past (with small tolerance for same-day requests)
		$today = new \DateTime();
		$today->setTime(0, 0, 0);

		if ($startDate < $today) {
			throw new \Exception($this->l10n->t('Start date cannot be in the past'));
		}

		// Check for overlapping absences (exclude current absence when updating)
		$overlapping = $this->absenceMapper->findOverlapping($userId, $startDate, $endDate, $excludeAbsenceId);
		if (!empty($overlapping)) {
			throw new \Exception($this->l10n->t('Absence overlaps with existing absence'));
		}

		// Ensure type is a string and whitelist allowed values
		$type = $data['type'];
		if (is_array($type)) {
			$type = !empty($type) ? (string)reset($type) : '';
		} else {
			$type = (string)$type;
		}
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

		// Vacation entitlement: ensure user has enough remaining days
		// (getVacationStats only counts approved absences; when updating, add back old absence's days)
			if ($type === Absence::TYPE_VACATION) {
			$requestedWorkingDaysPerYear = $this->computeWorkingDaysPerYear($startDate, $endDate, $userId);
			$addBackPerYear = [];
			if ($excludeAbsenceId !== null) {
				try {
					$oldAbsence = $this->absenceMapper->find($excludeAbsenceId);
					if ($oldAbsence->getUserId() === $userId && $oldAbsence->getType() === Absence::TYPE_VACATION) {
						$oldStart = $oldAbsence->getStartDate();
						$oldEnd = $oldAbsence->getEndDate();
						if ($oldStart && $oldEnd) {
							$addBackPerYear = $this->computeWorkingDaysPerYear($oldStart, $oldEnd, $userId);
						}
					}
				} catch (DoesNotExistException $e) {
					// Absence no longer exists, no days to add back
				}
			}
			foreach ($requestedWorkingDaysPerYear as $year => $requestedDays) {
				if ($requestedDays <= 0) {
					continue;
				}
				$stats = $this->getVacationStats($userId, (int)$year);
				$remaining = (float)($stats['remaining'] ?? 0);
				$addBack = isset($addBackPerYear[$year]) ? (float)$addBackPerYear[$year] : 0.0;
				$effectiveRemaining = $remaining + $addBack;
				if ($effectiveRemaining < $requestedDays) {
					$msg = $this->l10n->t(
						'Not enough vacation days remaining. You have %1$s days left for %2$s but requested %3$s days.',
						[(string)round($effectiveRemaining, 1), (string)$year, (string)round($requestedDays, 1)]
					);
					throw new \Exception($msg);
				}
			}
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
				if ($days > 365) {
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
				if ($days > 365) {
					throw new \Exception($this->l10n->t('Unpaid leave cannot exceed 365 days'));
				}
				break;
			case Absence::TYPE_HOME_OFFICE:
			case Absence::TYPE_BUSINESS_TRIP:
				if ($days > 365) {
					throw new \Exception($this->l10n->t('Duration cannot exceed 365 days'));
				}
				break;
		}
	}

	/**
	 * Whether the employee has at least one manager (colleague in same team/group who could approve).
	 * Used to auto-approve absences when no one would see them in the manager dashboard.
	 */
	private function employeeHasManager(string $employeeUserId): bool
	{
		$colleagueIds = $this->teamResolver->getColleagueIds($employeeUserId);
		return !empty($colleagueIds);
	}

	/**
	 * Auto-approve an absence when the employee has no manager (no colleagues).
	 * Ensures absences are not stuck in PENDING forever for solo users or users alone in their team.
	 */
	private function autoApproveForNoManager(Absence $absence): Absence
	{
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
		// and will internally delegate to HolidayCalendarService in future iterations if needed.
		unset($start, $end, $userId);
		return [];
	}
}