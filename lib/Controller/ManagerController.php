<?php

declare(strict_types=1);

/**
 * Manager controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\IL10N;
use OCP\Util;

/**
 * ManagerController
 */
class ManagerController extends Controller
{
	use CSPTrait;

	private AbsenceService $absenceService;
	private TimeTrackingService $timeTrackingService;
	private ComplianceService $complianceService;
	private AbsenceMapper $absenceMapper;
	private IUserSession $userSession;
	private IGroupManager $groupManager;
	private IUserManager $userManager;
	private IL10N $l10n;

	public function __construct(
		string $appName,
		IRequest $request,
		AbsenceService $absenceService,
		TimeTrackingService $timeTrackingService,
		ComplianceService $complianceService,
		AbsenceMapper $absenceMapper,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IUserManager $userManager,
		CSPService $cspService,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->absenceService = $absenceService;
		$this->timeTrackingService = $timeTrackingService;
		$this->complianceService = $complianceService;
		$this->absenceMapper = $absenceMapper;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->setCspService($cspService);
	}

	/**
	 * Get current user ID
	 *
	 * @return string
	 */
	private function getUserId(): string
	{
		$user = $this->userSession->getUser();
		if (!$user) {
			throw new \Exception('User not authenticated');
		}
		return $user->getUID();
	}

	/**
	 * Get team member user IDs for a manager
	 * Uses Nextcloud groups - assumes manager is in a group and team members are in the same group
	 * In production, this could be enhanced with custom manager-team relationships
	 *
	 * @param string $managerId
	 * @return array Array of user IDs
	 */
	private function getTeamMemberIds(string $managerId): array
	{
		$manager = $this->userManager->get($managerId);
		if (!$manager) {
			return [];
		}

		// Get all groups the manager belongs to
		$managerGroups = $this->groupManager->getUserGroups($manager);
		
		if (empty($managerGroups)) {
			return [];
		}

		// Get all users from manager's groups (excluding the manager themselves)
		$teamMemberIds = [];
		foreach ($managerGroups as $group) {
			$groupUsers = $group->getUsers();
			foreach ($groupUsers as $user) {
				$userId = $user->getUID();
				if ($userId !== $managerId && !in_array($userId, $teamMemberIds)) {
					$teamMemberIds[] = $userId;
				}
			}
		}

		return $teamMemberIds;
	}

	/**
	 * Get display name for a user ID
	 *
	 * @param string $userId
	 * @return string
	 */
	private function getDisplayName(string $userId): string
	{
		$displayName = $this->userManager->getDisplayName($userId);
		return $displayName ?: $userId;
	}

	/**
	 * Manager dashboard page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');
		
		// Add common CSS files
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');
		
		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'manager-dashboard');
		
		try {
			$managerId = $this->getUserId();
			$teamUserIds = $this->getTeamMemberIds($managerId);

			// Get team statistics
			$today = new \DateTime();
			$today->setTime(0, 0, 0);
			
			$teamStats = [
				'total_members' => count($teamUserIds),
				'active_today' => 0,
				'total_hours_today' => 0,
				'pending_absences' => 0
			];

			$teamMembers = [];
			foreach (array_slice($teamUserIds, 0, 10) as $userId) {
				$user = $this->userManager->get($userId);
				if (!$user) continue;

				$todayHours = $this->timeTrackingService->getTodayHours($userId);
				$status = $this->timeTrackingService->getStatus($userId);
				$pendingAbsences = $this->absenceService->getAbsencesByUser($userId, ['status' => 'pending']);

				if ($todayHours > 0) {
					$teamStats['active_today']++;
				}
				$teamStats['total_hours_today'] += $todayHours;
				$teamStats['pending_absences'] += count($pendingAbsences);

				$teamMembers[] = [
					'userId' => $userId,
					'displayName' => $user->getDisplayName(),
					'todayHours' => round($todayHours, 2),
					'status' => $status['status'] ?? 'clocked_out',
					'pendingAbsences' => count($pendingAbsences)
				];
			}

			$response = new TemplateResponse('arbeitszeitcheck', 'manager-dashboard', [
				'teamStats' => $teamStats,
				'teamMembers' => $teamMembers,
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-dashboard', [
				'teamStats' => [
					'total_members' => 0,
					'active_today' => 0,
					'total_hours_today' => 0,
					'pending_absences' => 0
				],
				'teamMembers' => [],
				'error' => $e->getMessage(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Get team overview data
	 *
	 *
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getTeamOverview(?int $limit = 50, ?int $offset = 0): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			
			// Get actual team member IDs from Nextcloud groups
			$teamUserIds = $this->getTeamMemberIds($managerId);

			if (empty($teamUserIds)) {
				return new JSONResponse([
					'success' => true,
					'teamMembers' => [],
					'total' => 0
				]);
			}

			// Apply pagination
			$paginatedUserIds = array_slice($teamUserIds, $offset ?? 0, $limit ?? 50);

			$teamMembers = [];
			$today = new \DateTime();
			$today->setTime(0, 0, 0);
			$weekStart = clone $today;
			$weekStart->modify('-' . (int)$today->format('w') . ' days')->setTime(0, 0, 0);

			foreach ($paginatedUserIds as $userId) {
				// Get today's hours
				$todayHours = $this->timeTrackingService->getTodayHours($userId);

				// Get week's hours using OvertimeService
				$weekEnd = clone $weekStart;
				$weekEnd->modify('+6 days');
				$weekEnd->setTime(23, 59, 59);
				$overtimeService = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\OvertimeService::class);
				$weekOvertime = $overtimeService->calculateOvertime($userId, $weekStart, $weekEnd);
				$weekHours = $weekOvertime['total_hours_worked'];

				// Get current status
				$status = $this->timeTrackingService->getStatus($userId);
				$currentStatus = $status['status'] ?? 'clocked_out';

				// Get pending absences count
				$pendingAbsences = $this->absenceService->getAbsencesByUser($userId, ['status' => 'pending']);
				$pendingAbsencesCount = count($pendingAbsences);

				// Get compliance status
				$complianceStatus = $this->complianceService->getComplianceStatus($userId);
				$complianceStatusText = $complianceStatus['compliant'] ? 'good' : 'warning';

				// Calculate overtime using OvertimeService
				$overtimeService = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\OvertimeService::class);
				$dailyOvertime = $overtimeService->getDailyOvertime($userId);
				$overtimeHours = $dailyOvertime['overtime_hours'];

				$teamMembers[] = [
					'userId' => $userId,
					'displayName' => $this->getDisplayName($userId),
					'todayHours' => round($todayHours, 2),
					'weekHours' => round($weekHours, 2),
					'overtimeHours' => round($overtimeHours, 2),
					'currentStatus' => $currentStatus,
					'pendingAbsences' => $pendingAbsencesCount,
					'complianceStatus' => $complianceStatusText
				];
			}

			return new JSONResponse([
				'success' => true,
				'teamMembers' => $teamMembers,
				'total' => count($teamUserIds)
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::getPendingApprovals: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get pending approvals
	 *
	 *
	 * @param string|null $type Filter by type ('absence', 'time_entry', or null for all)
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getPendingApprovals(?string $type = null, ?int $limit = 25, ?int $offset = 0): JSONResponse
	{
		try {
			$managerId = $this->getUserId();

			// Get actual team member IDs from Nextcloud groups
			$teamUserIds = $this->getTeamMemberIds($managerId);

			if (empty($teamUserIds)) {
				return new JSONResponse([
					'success' => true,
					'pendingApprovals' => [],
					'total' => 0
				]);
			}

			$pendingApprovals = [];

			// Get pending absences if requested
			if ($type === null || $type === 'absence') {
				// Get pending absences for team members using the mapper's method designed for multiple users
				$pendingAbsences = $this->absenceMapper->findPendingForUsers($teamUserIds, $limit, $offset);

				foreach ($pendingAbsences as $absence) {
					try {
						$createdAt = $absence->getCreatedAt();
						$pendingApprovals[] = [
							'id' => $absence->getId(),
							'type' => 'absence',
							'userId' => $absence->getUserId(),
							'displayName' => $this->getDisplayName($absence->getUserId()),
							'summary' => $absence->getSummary(),
							'requestedAt' => $createdAt ? $createdAt->format('c') : null
						];
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->error('Error processing absence ' . $absence->getId() . ' in pending approvals: ' . $e->getMessage(), ["exception" => $e]);
						continue;
					}
				}
			}

			// Get pending time entry corrections if requested
			if ($type === null || $type === 'time_entry') {
				$timeEntryMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class);
				$pendingTimeEntries = $timeEntryMapper->findPendingApprovalForUsers($teamUserIds, $limit, $offset);

				foreach ($pendingTimeEntries as $entry) {
					try {
						// Parse justification to get correction details
						$justificationData = json_decode($entry->getJustification() ?? '{}', true);
						
						$startTime = $entry->getStartTime();
						$endTime = $entry->getEndTime();
						$createdAt = $entry->getCreatedAt();
						
						$pendingApprovals[] = [
							'id' => $entry->getId(),
							'type' => 'time_entry',
							'userId' => $entry->getUserId(),
							'displayName' => $this->getDisplayName($entry->getUserId()),
							'summary' => [
								'date' => $startTime ? $startTime->format('Y-m-d') : null,
								'startTime' => $startTime ? $startTime->format('H:i') : null,
								'endTime' => $endTime ? $endTime->format('H:i') : null,
								'durationHours' => $entry->getDurationHours(),
								'description' => $entry->getDescription(),
								'justification' => $justificationData['justification'] ?? '',
								'original' => $justificationData['original'] ?? [],
								'proposed' => $justificationData['proposed'] ?? []
							],
							'requestedAt' => $justificationData['requested_at'] ?? ($createdAt ? $createdAt->format('c') : null)
						];
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->error('Error processing pending time entry ' . $entry->getId() . ': ' . $e->getMessage(), ["exception" => $e]);
						continue;
					}
				}
			}

			// Sort by requested date (most recent first)
			usort($pendingApprovals, function ($a, $b) {
				$aTime = strtotime($a['requestedAt'] ?? '1970-01-01');
				$bTime = strtotime($b['requestedAt'] ?? '1970-01-01');
				return $bTime - $aTime;
			});

			// Apply pagination
			$total = count($pendingApprovals);
			$paginatedApprovals = array_slice($pendingApprovals, $offset ?? 0, $limit ?? 25);

			return new JSONResponse([
				'success' => true,
				'pendingApprovals' => $paginatedApprovals,
				'total' => $total
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::getPendingApprovals: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get team compliance overview
	 *
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getTeamCompliance(): JSONResponse
	{
		try {
			$managerId = $this->getUserId();

			// Get actual team member IDs from Nextcloud groups
			$teamUserIds = $this->getTeamMemberIds($managerId);

			if (empty($teamUserIds)) {
				return new JSONResponse([
					'success' => true,
					'compliance' => [
						'totalMembers' => 0,
						'compliantMembers' => 0,
						'membersWithWarnings' => 0,
						'membersWithViolations' => 0,
						'totalViolations' => 0,
						'unresolvedViolations' => 0,
						'recentViolations' => []
					]
				]);
			}

			$complianceOverview = [
				'totalMembers' => count($teamUserIds),
				'compliantMembers' => 0,
				'membersWithWarnings' => 0,
				'membersWithViolations' => 0,
				'totalViolations' => 0,
				'unresolvedViolations' => 0,
				'recentViolations' => []
			];

			$thirtyDaysAgo = new \DateTime();
			$thirtyDaysAgo->modify('-30 days');

			foreach ($teamUserIds as $userId) {
				$status = $this->complianceService->getComplianceStatus($userId);
				
				if ($status['compliant']) {
					$complianceOverview['compliantMembers']++;
				} else {
					if ($status['critical_violations'] > 0) {
						$complianceOverview['membersWithViolations']++;
					} else {
						$complianceOverview['membersWithWarnings']++;
					}
				}

				$complianceOverview['totalViolations'] += $status['violation_count'];
				$complianceOverview['unresolvedViolations'] += $status['violation_count'];
			}

			return new JSONResponse([
				'success' => true,
				'compliance' => $complianceOverview
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::getPendingApprovals: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get team hours summary
	 *
	 *
	 * @param string|null $period Period: 'today', 'week', 'month'
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getTeamHoursSummary(?string $period = 'today'): JSONResponse
	{
		try {
			$managerId = $this->getUserId();

			// Get actual team member IDs from Nextcloud groups
			$teamUserIds = $this->getTeamMemberIds($managerId);

			if (empty($teamUserIds)) {
				return new JSONResponse([
					'success' => true,
					'summary' => [
						'totalHours' => 0,
						'averageHours' => 0,
						'totalOvertime' => 0,
						'members' => []
					]
				]);
			}

			$summary = [
				'totalHours' => 0,
				'averageHours' => 0,
				'totalOvertime' => 0,
				'members' => []
			];

			$overtimeService = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\OvertimeService::class);
			
			foreach ($teamUserIds as $userId) {
				$hours = $this->timeTrackingService->getTodayHours($userId);
				$dailyOvertime = $overtimeService->getDailyOvertime($userId);
				$overtime = $dailyOvertime['overtime_hours'];

				$summary['totalHours'] += $hours;
				$summary['totalOvertime'] += $overtime;

				$summary['members'][] = [
					'userId' => $userId,
					'displayName' => $this->getDisplayName($userId),
					'hours' => round($hours, 2),
					'overtime' => round($overtime, 2)
				];
			}

			$summary['averageHours'] = count($teamUserIds) > 0 
				? round($summary['totalHours'] / count($teamUserIds), 2) 
				: 0;

			return new JSONResponse([
				'success' => true,
				'summary' => $summary
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::getPendingApprovals: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Approve an absence request
	 *
	 *
	 * @param int $absenceId
	 * @param string|null $comment
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function approveAbsence(int $absenceId, ?string $comment = null): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$absence = $this->absenceService->approveAbsence($absenceId, $managerId, $comment);

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::getPendingApprovals: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Reject an absence request
	 *
	 *
	 * @param int $absenceId
	 * @param string|null $comment
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function rejectAbsence(int $absenceId, ?string $comment = null): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$absence = $this->absenceService->rejectAbsence($absenceId, $managerId, $comment);

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::getPendingApprovals: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Approve a time entry correction
	 *
	 *
	 * @param int $timeEntryId Time entry ID
	 * @param string|null $comment Optional approval comment
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function approveTimeEntryCorrection(int $timeEntryId, ?string $comment = null): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$timeEntryMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class);
			$entry = $timeEntryMapper->find($timeEntryId);

			// Verify entry is pending approval
			if ($entry->getStatus() !== \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL) {
				return new JSONResponse([
					'success' => false,
					'error' => 'Time entry is not pending approval'
				], Http::STATUS_BAD_REQUEST);
			}

			// Verify manager has access to this user's team
			$teamUserIds = $this->getTeamMemberIds($managerId);
			if (!in_array($entry->getUserId(), $teamUserIds)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied - user is not in your team')
				], Http::STATUS_FORBIDDEN);
			}

			// Approve the correction - finalize the proposed changes
			$entry->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED);
			$entry->setApprovedBy((int)$managerId);
			$entry->setApprovedAt(new \DateTime());
			$entry->setUpdatedAt(new \DateTime());

			// If there's a comment, append it to justification
			if ($comment) {
				$justificationData = json_decode($entry->getJustification() ?? '{}', true);
				if (is_array($justificationData)) {
					$justificationData['approval_comment'] = $comment;
					$justificationData['approved_at'] = date('c');
					$justificationData['approved_by'] = $managerId;
					$entry->setJustification(json_encode($justificationData));
				}
			}

			$updatedEntry = $timeEntryMapper->update($entry);

			// Real-time compliance check when approving a time entry
			// Based on industry best practices: immediate compliance checking upon approval
			if ($updatedEntry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED && $updatedEntry->getEndTime() !== null) {
				try {
					$config = \OCP\Server::get(\OCP\IConfig::class);
					$realTimeComplianceEnabled = $config->getAppValue('arbeitszeitcheck', 'realtime_compliance_check', '1') === '1';
					
					if ($realTimeComplianceEnabled && $this->complianceService) {
						$strictMode = $config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';
						$this->complianceService->checkComplianceForCompletedEntry($updatedEntry, $strictMode);
						
						\OCP\Log\logger('arbeitszeitcheck')->info('Real-time compliance check performed on approved entry', [
							'time_entry_id' => $updatedEntry->getId(),
							'user_id' => $updatedEntry->getUserId(),
							'approved_by' => $managerId
						]);
					}
				} catch (\Throwable $e) {
					// Log error but don't fail the approval
					\OCP\Log\logger('arbeitszeitcheck')->error('Error in compliance check during approval: ' . $e->getMessage(), [
						'time_entry_id' => $updatedEntry->getId(),
						'exception' => $e
					]);
				}
			}

			// Create audit log
			$auditLogMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class);
			$auditLogMapper->logAction(
				$entry->getUserId(), // userId - the employee who owns the entry
				'time_entry_correction_approved',
				'time_entry',
				$timeEntryId, // entityId
				null, // oldValues
				[
					'comment' => $comment,
					'approved_by' => $managerId
				], // newValues
				$managerId // performedBy - the manager who approved
			);

			// Send notification to employee
			$notificationService = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\NotificationService::class);
			$notificationService->notifyTimeEntryCorrectionApproved(
				$entry->getUserId(),
				$updatedEntry->getSummary()
			);

			return new JSONResponse([
				'success' => true,
				'entry' => $updatedEntry->getSummary(),
				'message' => 'Time entry correction approved successfully'
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Time entry not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::approveTimeEntryCorrection: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Reject a time entry correction
	 *
	 *
	 * @param int $timeEntryId Time entry ID
	 * @param string|null $reason Rejection reason
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function rejectTimeEntryCorrection(int $timeEntryId, ?string $reason = null): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$timeEntryMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class);
			$entry = $timeEntryMapper->find($timeEntryId);

			// Verify entry is pending approval
			if ($entry->getStatus() !== \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL) {
				return new JSONResponse([
					'success' => false,
					'error' => 'Time entry is not pending approval'
				], Http::STATUS_BAD_REQUEST);
			}

			// Verify manager has access to this user's team
			$teamUserIds = $this->getTeamMemberIds($managerId);
			if (!in_array($entry->getUserId(), $teamUserIds)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied - user is not in your team')
				], Http::STATUS_FORBIDDEN);
			}

			// Get original data from justification to restore
			$justificationData = json_decode($entry->getJustification() ?? '{}', true);
			$originalData = $justificationData['original'] ?? [];

			// Restore original values
			if (isset($originalData['date'])) {
				$entry->setStartTime(new \DateTime($originalData['date']));
			}
			if (isset($originalData['hours']) && $entry->getStartTime()) {
				$endTime = clone $entry->getStartTime();
				$endTime->modify('+' . round($originalData['hours'] * 3600) . ' seconds');
				$entry->setEndTime($endTime);
			}
			if (isset($originalData['description'])) {
				$entry->setDescription($originalData['description']);
			}

			// Reject the correction
			$entry->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_REJECTED);
			$entry->setUpdatedAt(new \DateTime());

			// Store rejection reason
			if ($reason) {
				$justificationData['rejection_reason'] = $reason;
				$justificationData['rejected_at'] = date('c');
				$justificationData['rejected_by'] = $managerId;
				$entry->setJustification(json_encode($justificationData));
			}

			$updatedEntry = $timeEntryMapper->update($entry);

			// Create audit log
			$auditLogMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\AuditLogMapper::class);
			$auditLogMapper->logAction(
				$entry->getUserId(), // userId - the employee who owns the entry
				'time_entry_correction_rejected',
				'time_entry',
				$timeEntryId, // entityId
				null, // oldValues
				[
					'reason' => $reason,
					'rejected_by' => $managerId
				], // newValues
				$managerId // performedBy - the manager who rejected
			);

			// Send notification to employee
			$notificationService = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\NotificationService::class);
			$notificationService->notifyTimeEntryCorrectionRejected(
				$entry->getUserId(),
				$updatedEntry->getSummary(),
				$reason
			);

			return new JSONResponse([
				'success' => true,
				'entry' => $updatedEntry->getSummary(),
				'message' => $this->l10n->t('Time entry correction rejected')
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Time entry not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::rejectTimeEntryCorrection: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get pending time entry corrections for manager's team
	 *
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getPendingTimeEntryCorrections(): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$teamUserIds = $this->getTeamMemberIds($managerId);

			if (empty($teamUserIds)) {
				return new JSONResponse([
					'success' => true,
					'corrections' => []
				]);
			}

			$timeEntryMapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\TimeEntryMapper::class);
			$corrections = [];

			foreach ($teamUserIds as $userId) {
				// Get pending approval entries for this user
				$pendingEntries = $timeEntryMapper->findByUserAndStatus(
					$userId,
					\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL
				);

				foreach ($pendingEntries as $entry) {
					try {
						// Parse justification to get correction details
						$justificationData = json_decode($entry->getJustification() ?? '{}', true);
						
						$corrections[] = [
							'id' => $entry->getId(),
							'userId' => $entry->getUserId(),
							'displayName' => $this->getDisplayName($entry->getUserId()),
							'startTime' => ($startTime = $entry->getStartTime()) ? $startTime->format('c') : null,
							'endTime' => $entry->getEndTime() ? $entry->getEndTime()->format('c') : null,
							'durationHours' => $entry->getDurationHours(),
							'description' => $entry->getDescription(),
							'status' => $entry->getStatus(),
							'justification' => $justificationData['justification'] ?? '',
							'original' => $justificationData['original'] ?? [],
							'proposed' => $justificationData['proposed'] ?? [],
							'requestedAt' => $justificationData['requested_at'] ?? null,
							'summary' => $entry->getSummary()
						];
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->error('Error processing time entry correction ' . $entry->getId() . ': ' . $e->getMessage(), ["exception" => $e]);
						continue;
					}
				}
			}

			// Sort by requested date (most recent first)
			usort($corrections, function ($a, $b) {
				$aTime = $a['requestedAt'] ? strtotime($a['requestedAt']) : 0;
				$bTime = $b['requestedAt'] ? strtotime($b['requestedAt']) : 0;
				return $bTime - $aTime;
			});

			return new JSONResponse([
				'success' => true,
				'corrections' => $corrections
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::getPendingApprovals: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get team absence calendar
	 *
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getTeamAbsenceCalendar(?string $startDate = null, ?string $endDate = null): JSONResponse
	{
		try {
			$managerId = $this->getUserId();

			// Get actual team member IDs from Nextcloud groups
			$teamUserIds = $this->getTeamMemberIds($managerId);

			if (empty($teamUserIds)) {
				return new JSONResponse([
					'success' => true,
					'absences' => []
				]);
			}

			// Determine date range (default to current month)
			$end = $endDate ? new \DateTime($endDate) : new \DateTime();
			$start = $startDate ? new \DateTime($startDate) : clone $end;
			if (!$startDate) {
				$start->modify('first day of this month')->setTime(0, 0, 0);
			}

			$calendarData = [];

			foreach ($teamUserIds as $userId) {
				// Get absences for user in date range using mapper directly
				$absences = $this->absenceMapper->findByUserAndDateRange($userId, $start, $end);

				foreach ($absences as $absence) {
					try {
						$calendarData[] = [
							'id' => $absence->getId(),
							'userId' => $absence->getUserId(),
							'displayName' => $this->getDisplayName($absence->getUserId()),
							'type' => $absence->getType(),
							'startDate' => ($startDate = $absence->getStartDate()) ? $startDate->format('Y-m-d') : null,
							'endDate' => ($endDate = $absence->getEndDate()) ? $endDate->format('Y-m-d') : null,
							'days' => $absence->getDays(),
							'status' => $absence->getStatus(),
							'summary' => $absence->getSummary()
						];
					} catch (\Throwable $e) {
						\OCP\Log\logger('arbeitszeitcheck')->error('Error processing absence ' . $absence->getId() . ' in calendar data: ' . $e->getMessage(), ["exception" => $e]);
						continue;
					}
				}
			}

			return new JSONResponse([
				'success' => true,
				'absences' => $calendarData
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::getPendingApprovals: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}