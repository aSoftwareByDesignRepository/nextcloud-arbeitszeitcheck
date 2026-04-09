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
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Constants;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IUserManager;
use OCP\IURLGenerator;
use OCP\IConfig;
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
	private TeamResolverService $teamResolver;
	private PermissionService $permissionService;
	private TeamMapper $teamMapper;
	private IUserSession $userSession;
	private IUserManager $userManager;
	private IL10N $l10n;
	private TeamManagerMapper $teamManagerMapper;
	private OvertimeService $overtimeService;
	private AuditLogMapper $auditLogMapper;
	private NotificationService $notificationService;
	private TimeEntryMapper $timeEntryMapper;
	private IURLGenerator $urlGenerator;
	private IConfig $config;

	public function __construct(
		string $appName,
		IRequest $request,
		AbsenceService $absenceService,
		TimeTrackingService $timeTrackingService,
		ComplianceService $complianceService,
		AbsenceMapper $absenceMapper,
		TeamResolverService $teamResolver,
		PermissionService $permissionService,
		TeamMapper $teamMapper,
		IUserSession $userSession,
		IUserManager $userManager,
		CSPService $cspService,
		IL10N $l10n,
		TeamManagerMapper $teamManagerMapper,
		OvertimeService $overtimeService,
		AuditLogMapper $auditLogMapper,
		NotificationService $notificationService,
		TimeEntryMapper $timeEntryMapper,
		IURLGenerator $urlGenerator,
		IConfig $config
	) {
		parent::__construct($appName, $request);
		$this->absenceService = $absenceService;
		$this->timeTrackingService = $timeTrackingService;
		$this->complianceService = $complianceService;
		$this->absenceMapper = $absenceMapper;
		$this->teamResolver = $teamResolver;
		$this->permissionService = $permissionService;
		$this->teamMapper = $teamMapper;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->l10n = $l10n;
		$this->teamManagerMapper = $teamManagerMapper;
		$this->overtimeService = $overtimeService;
		$this->auditLogMapper = $auditLogMapper;
		$this->notificationService = $notificationService;
		$this->timeEntryMapper = $timeEntryMapper;
		$this->urlGenerator = $urlGenerator;
		$this->config = $config;
		$this->setCspService($cspService);
	}

	/**
	 * Get list of app-owned teams the current user manages (for reporting / dashboards).
	 *
	 * When app teams are disabled, or the user does not manage any teams, an empty list is returned.
	 * This endpoint is intentionally read-only and returns only minimal metadata (id, name, path).
	 */
	#[NoAdminRequired]
	public function getManagedTeams(): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'view_managed_teams');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

			// If app teams are not enabled, there is no concept of multiple named teams for managers.
			if (!$this->teamResolver->useAppTeams()) {
				return new JSONResponse([
					'success' => true,
					'teams' => [],
				]);
			}

			// Collect all team IDs where this user is manager.
			$managedTeamIds = $this->teamManagerMapper->getTeamIdsForManager($managerId);

			if (empty($managedTeamIds)) {
				return new JSONResponse([
					'success' => true,
					'teams' => [],
				]);
			}

			// Build lightweight team DTOs, including hierarchical path for clarity in the UI.
			$teams = [];
			foreach ($managedTeamIds as $teamId) {
				try {
					$team = $this->teamMapper->find($teamId);
				} catch (\Throwable $e) {
					continue;
				}

				$teams[] = [
					'id' => $team->getId(),
					'name' => $team->getName(),
					'parentId' => $team->getParentId(),
				];
			}

			return new JSONResponse([
				'success' => true,
				'teams' => $teams,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getManagedTeams',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
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
	 * Get team member user IDs for a manager (shared group membership).
	 *
	 * @param string $managerId
	 * @return array Array of user IDs
	 */
	private function getTeamMemberIds(string $managerId): array
	{
		return $this->teamResolver->getTeamMemberIds($managerId);
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
	 * Guard read-only manager/admin endpoints.
	 *
	 * @return JSONResponse|null Returns a 403 response when access is denied, otherwise null.
	 */
	private function ensureManagerReadAccess(string $actorUserId, string $action): ?JSONResponse
	{
		if ($this->permissionService->isAdmin($actorUserId) || $this->permissionService->canAccessManagerDashboard($actorUserId)) {
			return null;
		}

		$this->permissionService->logPermissionDenied($actorUserId, $action, 'manager_api');
		return new JSONResponse([
			'success' => false,
			'error' => $this->l10n->t('Access denied. This area is available to managers and administrators only.'),
		], Http::STATUS_FORBIDDEN);
	}

	private function normalizeLimit(?int $limit): int
	{
		$normalized = $limit ?? Constants::DEFAULT_LIST_LIMIT;
		if ($normalized < 1) {
			$normalized = Constants::DEFAULT_LIST_LIMIT;
		}
		return min($normalized, Constants::MAX_LIST_LIMIT);
	}

	private function normalizeOffset(?int $offset): int
	{
		return max(0, (int)($offset ?? 0));
	}

	/**
	 * Localized label for an absence type code (same strings as the absences UI / manager-dashboard l10n).
	 */
	private function getAbsenceTypeLabel(string $type): string
	{
		$map = [
			'vacation' => $this->l10n->t('Vacation'),
			'sick_leave' => $this->l10n->t('Sick leave'),
			'personal_leave' => $this->l10n->t('Personal leave'),
			'parental_leave' => $this->l10n->t('Parental leave'),
			'special_leave' => $this->l10n->t('Special leave'),
			'unpaid_leave' => $this->l10n->t('Unpaid leave'),
			'home_office' => $this->l10n->t('Home office'),
			'business_trip' => $this->l10n->t('Business trip'),
		];
		return $map[$type] ?? $type;
	}

	/**
	 * Localized label for an absence status code.
	 */
	private function getAbsenceStatusLabel(string $status): string
	{
		$map = [
			'pending' => $this->l10n->t('Pending'),
			'substitute_pending' => $this->l10n->t('Substitute pending'),
			'substitute_declined' => $this->l10n->t('Substitute declined'),
			'approved' => $this->l10n->t('Approved'),
			'rejected' => $this->l10n->t('Rejected'),
			'cancelled' => $this->l10n->t('Cancelled'),
		];
		return $map[$status] ?? $status;
	}

	/**
	 * Manager dashboard page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse|\OCP\AppFramework\Http\RedirectResponse
	{
		Util::addTranslations('arbeitszeitcheck');
		
		// Add common CSS files (including colors, typography for consistent fonts across all views)
		Util::addStyle('arbeitszeitcheck', 'common/colors');
		Util::addStyle('arbeitszeitcheck', 'common/typography');
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
		Util::addStyle('arbeitszeitcheck', 'common/accessibility');
		Util::addStyle('arbeitszeitcheck', 'common/app-layout');
		Util::addStyle('arbeitszeitcheck', 'common/responsive');
		Util::addStyle('arbeitszeitcheck', 'navigation');
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');
		Util::addStyle('arbeitszeitcheck', 'manager-dashboard');
		
		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'manager-dashboard');
		
		try {
			$managerId = $this->getUserId();

			// Redirect non-managers (no team, not admin) to dashboard
			if (!$this->permissionService->canAccessManagerDashboard($managerId)) {
				$redirect = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.index');
				return new \OCP\AppFramework\Http\RedirectResponse($redirect);
			}

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

			$showSubstitutionLink = false;
			try {
				$pending = $this->absenceMapper->findSubstitutePendingForUser($managerId, 1, 0);
				$showSubstitutionLink = \is_array($pending) && \count($pending) > 0;
			} catch (\Throwable $e) {
				$showSubstitutionLink = false;
			}

			$isAdmin = $this->permissionService->isAdmin($managerId);

			$response = new TemplateResponse('arbeitszeitcheck', 'manager-dashboard', [
				'teamStats' => $teamStats,
				'teamMembers' => $teamMembers,
				'showManagerLink' => true,
				'showSubstitutionLink' => $showSubstitutionLink,
				'showReportsLink' => true,
				'showAdminNav' => $isAdmin,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::dashboard',
				['exception' => $e]
			);
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-dashboard', [
				'teamStats' => [
					'total_members' => 0,
					'active_today' => 0,
					'total_hours_today' => 0,
					'pending_absences' => 0
				],
				'teamMembers' => [],
				'showManagerLink' => true,
				'showSubstitutionLink' => false,
				'showReportsLink' => true,
				'showAdminNav' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function employeeTimeEntriesPage(): TemplateResponse|\OCP\AppFramework\Http\RedirectResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		Util::addStyle('arbeitszeitcheck', 'common/colors');
		Util::addStyle('arbeitszeitcheck', 'common/typography');
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
		Util::addStyle('arbeitszeitcheck', 'common/accessibility');
		Util::addStyle('arbeitszeitcheck', 'common/app-layout');
		Util::addStyle('arbeitszeitcheck', 'common/responsive');
		Util::addStyle('arbeitszeitcheck', 'navigation');
		Util::addStyle('arbeitszeitcheck', 'manager-time-entries');

		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'common/datepicker');
		Util::addScript('arbeitszeitcheck', 'manager-time-entries');

		try {
			$actorUserId = $this->getUserId();
			if (!$this->permissionService->isAdmin($actorUserId) && !$this->permissionService->canAccessManagerDashboard($actorUserId)) {
				$redirect = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.index');
				return new \OCP\AppFramework\Http\RedirectResponse($redirect);
			}

			$showSubstitutionLink = false;
			try {
				$pending = $this->absenceMapper->findSubstitutePendingForUser($actorUserId, 1, 0);
				$showSubstitutionLink = \is_array($pending) && \count($pending) > 0;
			} catch (\Throwable $e) {
				$showSubstitutionLink = false;
			}

			$isAdmin = $this->permissionService->isAdmin($actorUserId);
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-time-entries', [
				'showManagerLink' => true,
				'showSubstitutionLink' => $showSubstitutionLink,
				'showReportsLink' => true,
				'showAdminNav' => $isAdmin,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::employeeTimeEntriesPage',
				['exception' => $e]
			);
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-time-entries', [
				'showManagerLink' => true,
				'showSubstitutionLink' => false,
				'showReportsLink' => true,
				'showAdminNav' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function employeeAbsencesPage(): TemplateResponse|\OCP\AppFramework\Http\RedirectResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		Util::addStyle('arbeitszeitcheck', 'common/colors');
		Util::addStyle('arbeitszeitcheck', 'common/typography');
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
		Util::addStyle('arbeitszeitcheck', 'common/accessibility');
		Util::addStyle('arbeitszeitcheck', 'common/app-layout');
		Util::addStyle('arbeitszeitcheck', 'common/responsive');
		Util::addStyle('arbeitszeitcheck', 'navigation');
		Util::addStyle('arbeitszeitcheck', 'manager-time-entries');

		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'common/datepicker');
		Util::addScript('arbeitszeitcheck', 'manager-absences');

		try {
			$actorUserId = $this->getUserId();
			if (!$this->permissionService->isAdmin($actorUserId) && !$this->permissionService->canAccessManagerDashboard($actorUserId)) {
				$redirect = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.index');
				return new \OCP\AppFramework\Http\RedirectResponse($redirect);
			}

			$showSubstitutionLink = false;
			try {
				$pending = $this->absenceMapper->findSubstitutePendingForUser($actorUserId, 1, 0);
				$showSubstitutionLink = \is_array($pending) && \count($pending) > 0;
			} catch (\Throwable $e) {
				$showSubstitutionLink = false;
			}

			$isAdmin = $this->permissionService->isAdmin($actorUserId);
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-absences', [
				'showManagerLink' => true,
				'showSubstitutionLink' => $showSubstitutionLink,
				'showReportsLink' => true,
				'showAdminNav' => $isAdmin,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::employeeAbsencesPage',
				['exception' => $e]
			);
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-absences', [
				'showManagerLink' => true,
				'showSubstitutionLink' => false,
				'showReportsLink' => true,
				'showAdminNav' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
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
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'view_team_overview');
			if ($accessResponse !== null) {
				return $accessResponse;
			}
			$limit = $this->normalizeLimit($limit);
			$offset = $this->normalizeOffset($offset);
			
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
			$paginatedUserIds = array_slice($teamUserIds, $offset, $limit);

			$teamMembers = [];
			$today = new \DateTime();
			$today->setTime(0, 0, 0);
			$weekStart = clone $today;
			$weekStart->modify('-' . (int)$today->format('w') . ' days')->setTime(0, 0, 0);

			foreach ($paginatedUserIds as $userId) {
				// Get today's hours
				$todayHours = $this->timeTrackingService->getTodayHours($userId);

				// Get week's hours using injected OvertimeService
				$weekEnd = clone $weekStart;
				$weekEnd->modify('+6 days');
				$weekEnd->setTime(23, 59, 59);
				$weekOvertime = $this->overtimeService->calculateOvertime($userId, $weekStart, $weekEnd);
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

				// Calculate overtime using injected OvertimeService
				$dailyOvertime = $this->overtimeService->getDailyOvertime($userId);
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
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getTeamOverview',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Read-only employee time entries list for managers/admins.
	 * Query executes only when start_date and end_date are provided (empty-safe default).
	 */
	#[NoAdminRequired]
	public function getEmployeeTimeEntries(
		?string $employeeId = null,
		?string $startDate = null,
		?string $endDate = null,
		?string $status = null,
		?int $limit = Constants::DEFAULT_LIST_LIMIT,
		?int $offset = 0
	): JSONResponse {
		try {
			$actorUserId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($actorUserId, 'view_employee_time_entries');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

			$isAdmin = $this->permissionService->isAdmin($actorUserId);
			$normalizedLimit = $this->normalizeLimit($limit);
			$normalizedOffset = $this->normalizeOffset($offset);
			$status = $status !== null ? trim($status) : null;
			$allowedStatuses = [
				TimeEntry::STATUS_ACTIVE,
				TimeEntry::STATUS_BREAK,
				TimeEntry::STATUS_PAUSED,
				TimeEntry::STATUS_COMPLETED,
				TimeEntry::STATUS_PENDING_APPROVAL,
				TimeEntry::STATUS_REJECTED,
			];
			$statusFilter = ($status !== null && $status !== '' && in_array($status, $allowedStatuses, true)) ? $status : null;

			$scopedUserIds = $isAdmin
				? array_values(array_map(static fn ($u) => $u->getUID(), $this->userManager->search('', Constants::MAX_LIST_LIMIT, 0)))
				: $this->getTeamMemberIds($actorUserId);
			sort($scopedUserIds);

			$employees = [];
			foreach ($scopedUserIds as $uid) {
				$user = $this->userManager->get($uid);
				if ($user === null) {
					continue;
				}
				$employees[] = [
					'userId' => $uid,
					'displayName' => $this->getDisplayName($uid),
					'enabled' => $user->isEnabled(),
				];
			}

			$scopedLookup = array_fill_keys(array_column($employees, 'userId'), true);
			if ($employeeId !== null && $employeeId !== '') {
				if (!isset($scopedLookup[$employeeId])) {
					$this->permissionService->logPermissionDenied($actorUserId, 'view_employee_time_entries_user_filter', 'user', $employeeId);
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Access denied. You can only view time entries for employees in your scope.'),
					], Http::STATUS_FORBIDDEN);
				}
				$scopedUserIds = [$employeeId];
			}

			$hasRequiredFilters = !empty($startDate) && !empty($endDate);
			if (!$hasRequiredFilters || empty($scopedUserIds)) {
				return new JSONResponse([
					'success' => true,
					'requiresFilters' => true,
					'entries' => [],
					'total' => 0,
					'employees' => $employees,
					'filters' => [
						'employeeId' => $employeeId,
						'startDate' => $startDate,
						'endDate' => $endDate,
						'status' => $statusFilter,
						'limit' => $normalizedLimit,
						'offset' => $normalizedOffset,
					],
				]);
			}

			try {
				$start = new \DateTimeImmutable($startDate . ' 00:00:00');
				$endExclusive = (new \DateTimeImmutable($endDate . ' 00:00:00'))->modify('+1 day');
			} catch (\Throwable $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid date range. Please use valid dates in YYYY-MM-DD format.'),
				], Http::STATUS_BAD_REQUEST);
			}

			if ($start >= $endExclusive) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid date range. The start date must be before the end date.'),
				], Http::STATUS_BAD_REQUEST);
			}

			$entries = $this->timeEntryMapper->findByUsersAndDateRange(
				$scopedUserIds,
				$start,
				$endExclusive,
				$statusFilter,
				$normalizedLimit,
				$normalizedOffset
			);
			$total = $this->timeEntryMapper->countByUsersAndDateRange(
				$scopedUserIds,
				$start,
				$endExclusive,
				$statusFilter
			);

			$entryRows = [];
			foreach ($entries as $entry) {
				$entryRows[] = [
					'id' => $entry->getId(),
					'userId' => $entry->getUserId(),
					'displayName' => $this->getDisplayName($entry->getUserId()),
					'startTime' => $entry->getStartTime()?->format('c'),
					'endTime' => $entry->getEndTime()?->format('c'),
					'durationHours' => $entry->getDurationHours(),
					'workingDurationHours' => $entry->getWorkingDurationHours(),
					'breakDurationHours' => $entry->getBreakDurationHours(),
					'description' => $entry->getDescription(),
					'status' => $entry->getStatus(),
					'isManualEntry' => $entry->getIsManualEntry(),
				];
			}

			return new JSONResponse([
				'success' => true,
				'requiresFilters' => false,
				'entries' => $entryRows,
				'total' => $total,
				'employees' => $employees,
				'filters' => [
					'employeeId' => $employeeId,
					'startDate' => $startDate,
					'endDate' => $endDate,
					'status' => $statusFilter,
					'limit' => $normalizedLimit,
					'offset' => $normalizedOffset,
				],
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getEmployeeTimeEntries',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Read-only employee absences list for managers/admins.
	 * Query executes only when start_date and end_date are provided (empty-safe default).
	 */
	#[NoAdminRequired]
	public function getEmployeeAbsences(
		?string $employeeId = null,
		?string $startDate = null,
		?string $endDate = null,
		?string $status = null,
		?string $type = null,
		?int $limit = Constants::DEFAULT_LIST_LIMIT,
		?int $offset = 0
	): JSONResponse {
		try {
			$actorUserId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($actorUserId, 'view_employee_absences');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

			$isAdmin = $this->permissionService->isAdmin($actorUserId);
			$normalizedLimit = $this->normalizeLimit($limit);
			$normalizedOffset = $this->normalizeOffset($offset);

			$status = $status !== null ? trim($status) : null;
			$type = $type !== null ? trim($type) : null;
			$allowedStatuses = [
				\OCA\ArbeitszeitCheck\Db\Absence::STATUS_PENDING,
				\OCA\ArbeitszeitCheck\Db\Absence::STATUS_SUBSTITUTE_PENDING,
				\OCA\ArbeitszeitCheck\Db\Absence::STATUS_SUBSTITUTE_DECLINED,
				\OCA\ArbeitszeitCheck\Db\Absence::STATUS_APPROVED,
				\OCA\ArbeitszeitCheck\Db\Absence::STATUS_REJECTED,
				\OCA\ArbeitszeitCheck\Db\Absence::STATUS_CANCELLED,
			];
			$allowedTypes = [
				\OCA\ArbeitszeitCheck\Db\Absence::TYPE_VACATION,
				\OCA\ArbeitszeitCheck\Db\Absence::TYPE_SICK_LEAVE,
				\OCA\ArbeitszeitCheck\Db\Absence::TYPE_PERSONAL_LEAVE,
				\OCA\ArbeitszeitCheck\Db\Absence::TYPE_PARENTAL_LEAVE,
				\OCA\ArbeitszeitCheck\Db\Absence::TYPE_SPECIAL_LEAVE,
				\OCA\ArbeitszeitCheck\Db\Absence::TYPE_UNPAID_LEAVE,
				\OCA\ArbeitszeitCheck\Db\Absence::TYPE_HOME_OFFICE,
				\OCA\ArbeitszeitCheck\Db\Absence::TYPE_BUSINESS_TRIP,
			];
			$statusFilter = ($status !== null && $status !== '' && in_array($status, $allowedStatuses, true)) ? $status : null;
			$typeFilter = ($type !== null && $type !== '' && in_array($type, $allowedTypes, true)) ? $type : null;

			$scopedUserIds = $isAdmin
				? array_values(array_map(static fn ($u) => $u->getUID(), $this->userManager->search('', Constants::MAX_LIST_LIMIT, 0)))
				: $this->getTeamMemberIds($actorUserId);
			sort($scopedUserIds);

			$employees = [];
			foreach ($scopedUserIds as $uid) {
				$user = $this->userManager->get($uid);
				if ($user === null) {
					continue;
				}
				$employees[] = [
					'userId' => $uid,
					'displayName' => $this->getDisplayName($uid),
					'enabled' => $user->isEnabled(),
				];
			}

			$scopedLookup = array_fill_keys(array_column($employees, 'userId'), true);
			if ($employeeId !== null && $employeeId !== '') {
				if (!isset($scopedLookup[$employeeId])) {
					$this->permissionService->logPermissionDenied($actorUserId, 'view_employee_absences_user_filter', 'user', $employeeId);
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Access denied. You can only view absences for employees in your scope.'),
					], Http::STATUS_FORBIDDEN);
				}
				$scopedUserIds = [$employeeId];
			}

			$hasRequiredFilters = !empty($startDate) && !empty($endDate);
			if (!$hasRequiredFilters || empty($scopedUserIds)) {
				return new JSONResponse([
					'success' => true,
					'requiresFilters' => true,
					'entries' => [],
					'total' => 0,
					'employees' => $employees,
					'filters' => [
						'employeeId' => $employeeId,
						'startDate' => $startDate,
						'endDate' => $endDate,
						'status' => $statusFilter,
						'type' => $typeFilter,
						'limit' => $normalizedLimit,
						'offset' => $normalizedOffset,
					],
				]);
			}

			try {
				$start = new \DateTimeImmutable($startDate . ' 00:00:00');
				$end = new \DateTimeImmutable($endDate . ' 23:59:59');
			} catch (\Throwable $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid date range. Please use valid dates in YYYY-MM-DD format.'),
				], Http::STATUS_BAD_REQUEST);
			}

			if ($start > $end) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid date range. The start date must be before the end date.'),
				], Http::STATUS_BAD_REQUEST);
			}

			$absences = $this->absenceMapper->findByUsersAndDateRange(
				$scopedUserIds,
				$start,
				$end,
				$statusFilter,
				$typeFilter,
				$normalizedLimit,
				$normalizedOffset
			);
			$total = $this->absenceMapper->countByUsersAndDateRange(
				$scopedUserIds,
				$start,
				$end,
				$statusFilter,
				$typeFilter
			);

			$rows = [];
			foreach ($absences as $absence) {
				$rows[] = [
					'id' => $absence->getId(),
					'userId' => $absence->getUserId(),
					'displayName' => $this->getDisplayName($absence->getUserId()),
					'type' => $absence->getType(),
					'typeLabel' => $this->getAbsenceTypeLabel($absence->getType()),
					'startDate' => $absence->getStartDate()?->format('Y-m-d'),
					'endDate' => $absence->getEndDate()?->format('Y-m-d'),
					'days' => $absence->getDays(),
					'status' => $absence->getStatus(),
					'statusLabel' => $this->getAbsenceStatusLabel($absence->getStatus()),
					'reason' => $absence->getReason(),
					'createdAt' => $absence->getCreatedAt()?->format('c'),
				];
			}

			return new JSONResponse([
				'success' => true,
				'requiresFilters' => false,
				'entries' => $rows,
				'total' => $total,
				'employees' => $employees,
				'filters' => [
					'employeeId' => $employeeId,
					'startDate' => $startDate,
					'endDate' => $endDate,
					'status' => $statusFilter,
					'type' => $typeFilter,
					'limit' => $normalizedLimit,
					'offset' => $normalizedOffset,
				],
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getEmployeeAbsences',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
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
	public function getPendingApprovals(?string $type = null, ?int $limit = Constants::DEFAULT_LIST_LIMIT, ?int $offset = 0): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'view_pending_approvals');
			if ($accessResponse !== null) {
				return $accessResponse;
			}
			$limit = $this->normalizeLimit($limit);
			$offset = $this->normalizeOffset($offset);

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
						$summary = $absence->getSummary();
						$summary['typeLabel'] = $this->getAbsenceTypeLabel($absence->getType());
						$pendingApprovals[] = [
							'id' => $absence->getId(),
							'type' => 'absence',
							'userId' => $absence->getUserId(),
							'displayName' => $this->getDisplayName($absence->getUserId()),
							'summary' => $summary,
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
				$pendingTimeEntries = $this->timeEntryMapper->findPendingApprovalForUsers($teamUserIds, $limit, $offset);

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
			$paginatedApprovals = array_slice($pendingApprovals, $offset, $limit);

			return new JSONResponse([
				'success' => true,
				'pendingApprovals' => $paginatedApprovals,
				'total' => $total
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getPendingApprovals',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')
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
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'view_team_compliance');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

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
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getTeamCompliance',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')
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
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'view_team_hours_summary');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

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

			$period = $period ?? 'today';
			if (!in_array($period, ['today', 'week', 'month'], true)) {
				$period = 'today';
			}

			$today = new \DateTime();
			$today->setTime(0, 0, 0);

			if ($period === 'today') {
				$start = clone $today;
				$end = clone $today;
				$end->modify('+1 day');
			} elseif ($period === 'week') {
				$dayOfWeek = (int)$today->format('w');
				$start = clone $today;
				$start->modify('-' . $dayOfWeek . ' days');
				$start->setTime(0, 0, 0);
				$end = clone $start;
				$end->modify('+7 days');
			} else {
				$start = new \DateTime($today->format('Y-m-01'));
				$end = clone $start;
				$end->modify('first day of next month');
			}

			foreach ($teamUserIds as $userId) {
				if ($period === 'today') {
					$hours = $this->timeTrackingService->getTodayHours($userId);
					$overtimeData = $this->overtimeService->getDailyOvertime($userId);
				} else {
					$hours = $this->timeEntryMapper->getTotalHoursByUserAndDateRange($userId, $start, $end);
					$overtimeData = $this->overtimeService->calculateOvertime($userId, $start, $end, false);
				}
				$overtime = $overtimeData['overtime_hours'];

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
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getTeamHoursSummary',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')
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
			$absence = $this->absenceMapper->find($absenceId);
			if (!$this->permissionService->canManageEmployee($managerId, $absence->getUserId())) {
				$this->permissionService->logPermissionDenied($managerId, 'approve_absence', 'absence', (string) $absenceId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only approve absences for members of your team.')
				], Http::STATUS_FORBIDDEN);
			}
			$absence = $this->absenceService->approveAbsence($absenceId, $managerId, $comment);

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Absence not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::approveAbsence',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')
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
			$absence = $this->absenceMapper->find($absenceId);
			if (!$this->permissionService->canManageEmployee($managerId, $absence->getUserId())) {
				$this->permissionService->logPermissionDenied($managerId, 'reject_absence', 'absence', (string) $absenceId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only reject absences for members of your team.')
				], Http::STATUS_FORBIDDEN);
			}
			$absence = $this->absenceService->rejectAbsence($absenceId, $managerId, $comment);

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary()
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Absence not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::rejectAbsence',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')
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
			$entry = $this->timeEntryMapper->find($timeEntryId);

			// Verify entry is pending approval
			if ($entry->getStatus() !== \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Time entry is not pending approval')
				], Http::STATUS_BAD_REQUEST);
			}

			// Verify manager may manage this employee (admin or team)
			if (!$this->permissionService->canManageEmployee($managerId, $entry->getUserId())) {
				$this->permissionService->logPermissionDenied($managerId, 'approve_time_entry_correction', 'time_entry', (string) $timeEntryId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only approve time entries for members of your team.')
				], Http::STATUS_FORBIDDEN);
			}

			$oldValues = $entry->getSummary();

			// Approve the correction - finalize the proposed changes
			$entry->setStatus(\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED);
			$entry->setApprovedByUserId($managerId);
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

			$updatedEntry = $this->timeEntryMapper->update($entry);

			// Real-time compliance check when approving a time entry
			// Based on industry best practices: immediate compliance checking upon approval
			if ($updatedEntry->getStatus() === \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_COMPLETED && $updatedEntry->getEndTime() !== null) {
				try {
					$realTimeComplianceEnabled = $this->config->getAppValue('arbeitszeitcheck', 'realtime_compliance_check', '1') === '1';
					
					if ($realTimeComplianceEnabled && $this->complianceService) {
						$strictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';
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

			// Create audit log (full before/after for payroll evidence)
			$newValues = $updatedEntry->getSummary();
			$newValues['approval_comment'] = $comment;
			$this->auditLogMapper->logAction(
				$entry->getUserId(),
				'time_entry_correction_approved',
				'time_entry',
				$timeEntryId,
				$oldValues,
				$newValues,
				$managerId
			);

			// Send notification to employee
			$this->notificationService->notifyTimeEntryCorrectionApproved(
				$entry->getUserId(),
				$updatedEntry->getSummary()
			);

			return new JSONResponse([
				'success' => true,
				'entry' => $updatedEntry->getSummary(),
				'message' => $this->l10n->t('Time entry correction approved successfully')
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Time entry not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::approveTimeEntryCorrection',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')
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
			$entry = $this->timeEntryMapper->find($timeEntryId);

			// Verify entry is pending approval
			if ($entry->getStatus() !== \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Time entry is not pending approval')
				], Http::STATUS_BAD_REQUEST);
			}

			// Verify manager may manage this employee (admin or team)
			if (!$this->permissionService->canManageEmployee($managerId, $entry->getUserId())) {
				$this->permissionService->logPermissionDenied($managerId, 'reject_time_entry_correction', 'time_entry', (string) $timeEntryId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only reject time entries for members of your team.')
				], Http::STATUS_FORBIDDEN);
			}

			$oldValues = $entry->getSummary();

			// Get original data from justification to restore
			$justificationData = json_decode($entry->getJustification() ?? '{}', true);
			$originalData = $justificationData['original'] ?? [];

			// Restore original values (supports both formats: startTime/endTime and legacy date/hours)
			if (isset($originalData['startTime'])) {
				$entry->setStartTime(new \DateTime($originalData['startTime']));
			} elseif (isset($originalData['date'])) {
				$entry->setStartTime(new \DateTime($originalData['date']));
			}
			if (isset($originalData['endTime'])) {
				$entry->setEndTime(new \DateTime($originalData['endTime']));
			} elseif (isset($originalData['hours']) && $entry->getStartTime()) {
				$endTime = clone $entry->getStartTime();
				$endTime->modify('+' . (int) round((float) $originalData['hours'] * 3600) . ' seconds');
				$entry->setEndTime($endTime);
			}
			if (isset($originalData['breakStartTime']) && $originalData['breakStartTime'] !== null) {
				$entry->setBreakStartTime(new \DateTime($originalData['breakStartTime']));
			} else {
				$entry->setBreakStartTime(null);
			}
			if (isset($originalData['breakEndTime']) && $originalData['breakEndTime'] !== null) {
				$entry->setBreakEndTime(new \DateTime($originalData['breakEndTime']));
			} else {
				$entry->setBreakEndTime(null);
			}
			if (array_key_exists('description', $originalData)) {
				$entry->setDescription($originalData['description'] ?? '');
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

			$updatedEntry = $this->timeEntryMapper->update($entry);

			// Create audit log (full before/after for payroll evidence)
			$newValues = $updatedEntry->getSummary();
			$newValues['rejection_reason'] = $reason ?? '';
			$newValues['rejected_by'] = $managerId;
			$this->auditLogMapper->logAction(
				$entry->getUserId(),
				'time_entry_correction_rejected',
				'time_entry',
				$timeEntryId,
				$oldValues,
				$newValues,
				$managerId
			);

			// Send notification to employee
			$this->notificationService->notifyTimeEntryCorrectionRejected(
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
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::rejectTimeEntryCorrection',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')
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
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'view_pending_time_entry_corrections');
			if ($accessResponse !== null) {
				return $accessResponse;
			}
			$teamUserIds = $this->getTeamMemberIds($managerId);

			if (empty($teamUserIds)) {
				return new JSONResponse([
					'success' => true,
					'corrections' => []
				]);
			}

			$corrections = [];

			foreach ($teamUserIds as $userId) {
				// Get pending approval entries for this user
				$pendingEntries = $this->timeEntryMapper->findByUserAndStatus(
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
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getPendingTimeEntryCorrections',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')
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
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'view_team_absence_calendar');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

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
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getTeamAbsenceCalendar',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}