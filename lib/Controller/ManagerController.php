<?php

declare(strict_types=1);

/**
 * Manager controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;
use OCA\ArbeitszeitCheck\Exception\NotAuthenticatedException;

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
use OCA\ArbeitszeitCheck\Service\OvertimeDisplayService;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\OvertimeTrafficLightService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\MonthClosureFeature;
use OCA\ArbeitszeitCheck\Service\MonthClosureGuard;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Exception\MonthFinalizedException;
use OCA\ArbeitszeitCheck\Exception\AbsenceMutationBusyException;
use OCA\ArbeitszeitCheck\Exception\BusinessRuleException;
use OCA\ArbeitszeitCheck\Exception\CatalogBusyException;
use OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException;
use OCA\ArbeitszeitCheck\Service\AppLocalNaiveDateTimeNormalizer;
use OCA\ArbeitszeitCheck\Service\TimeZoneService;
use OCA\ArbeitszeitCheck\Service\TimeEntryCorrectionService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Service\OversightScopeService;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Support\PeopleSearchQuery;
use OCA\ArbeitszeitCheck\Support\TimeEntryClockPayloadBuilder;
use OCA\ArbeitszeitCheck\Support\UserDirectorySearch;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
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
	use NavigationFlagsTrait;
	use PageShellTrait;

	private AbsenceService $absenceService;
	private TimeTrackingService $timeTrackingService;
	private ComplianceService $complianceService;
	private AbsenceMapper $absenceMapper;
	private TeamResolverService $teamResolver;
	protected PermissionService $permissionService;
	private TeamMapper $teamMapper;
	protected IUserSession $userSession;
	private IUserManager $userManager;
	protected IL10N $l10n;
	protected LocaleFormatService $localeFormat;
	private TeamManagerMapper $teamManagerMapper;
	private OvertimeService $overtimeService;
	private OvertimeDisplayService $overtimeDisplayService;
	private OvertimeBankService $overtimeBankService;
	private OvertimeTrafficLightService $overtimeTrafficLightService;
	private AuditLogMapper $auditLogMapper;
	private NotificationService $notificationService;
	private TimeEntryMapper $timeEntryMapper;
	protected IURLGenerator $urlGenerator;
	private IConfig $config;
	private MonthClosureGuard $monthClosureGuard;
	private MonthClosureService $monthClosureService;
	private TimeZoneService $timeZoneService;
	private TimeEntryCorrectionService $correctionService;
	private ProjectCheckIntegrationService $projectCheckIntegration;
	protected NavigationFlagsService $navigationFlags;

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
		OvertimeDisplayService $overtimeDisplayService,
		OvertimeBankService $overtimeBankService,
		OvertimeTrafficLightService $overtimeTrafficLightService,
		AuditLogMapper $auditLogMapper,
		NotificationService $notificationService,
		TimeEntryMapper $timeEntryMapper,
		IURLGenerator $urlGenerator,
		IConfig $config,
		MonthClosureGuard $monthClosureGuard,
		MonthClosureService $monthClosureService,
		TimeZoneService $timeZoneService,
		TimeEntryCorrectionService $correctionService,
		ProjectCheckIntegrationService $projectCheckIntegration,
		LocaleFormatService $localeFormat,
		NavigationFlagsService $navigationFlags,
		private readonly ?\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig $workforceSuiteConfig = null,
		private readonly ?OversightScopeService $oversightScope = null,
		private readonly ?\OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper $complianceViolationMapper = null,
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
		$this->overtimeDisplayService = $overtimeDisplayService;
		$this->overtimeBankService = $overtimeBankService;
		$this->overtimeTrafficLightService = $overtimeTrafficLightService;
		$this->auditLogMapper = $auditLogMapper;
		$this->notificationService = $notificationService;
		$this->timeEntryMapper = $timeEntryMapper;
		$this->urlGenerator = $urlGenerator;
		$this->config = $config;
		$this->monthClosureGuard = $monthClosureGuard;
		$this->monthClosureService = $monthClosureService;
		$this->timeZoneService = $timeZoneService;
		$this->correctionService = $correctionService;
		$this->projectCheckIntegration = $projectCheckIntegration;
		$this->localeFormat = $localeFormat;
		$this->navigationFlags = $navigationFlags;
		$this->setCspService($cspService);
	}

	/**
	 * @param array<string, mixed> $navFlags
	 * @return array<string, mixed>
	 */
	private function buildManagerShellParams(string $pageId, string $title, string $help, array $navFlags): array
	{
		return $this->buildShellParams($pageId, $title, $help, $navFlags, $this->l10n->t('Manager'));
	}

	/**
	 * Format a stored DateTime as HH:MM in the given user's display TZ.
	 * Centralised so every pending-approval / overlap payload uses the same path.
	 */
	private function displayClock(?\DateTimeInterface $dt, ?string $userId = null): ?string {
		if ($dt === null) {
			return null;
		}
		return $this->timeZoneService->formatForDisplay($dt, 'H:i', $userId);
	}

	/**
	 * Format a stored DateTime as YYYY-MM-DD in the given user's display TZ.
	 * The calendar day boundary is the user's wall clock — never the container UTC zone.
	 */
	private function displayDate(?\DateTimeInterface $dt, ?string $userId = null): ?string {
		if ($dt === null) {
			return null;
		}
		return $this->timeZoneService->formatForDisplay($dt, 'Y-m-d', $userId);
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
			throw new NotAuthenticatedException();
		}
		return $user->getUID();
	}

	private function oversightScope(): OversightScopeService
	{
		return $this->oversightScope ?? \OCP\Server::get(OversightScopeService::class);
	}

	/**
	 * User IDs the actor oversees on manager read surfaces.
	 *
	 * Delegates to {@see OversightScopeService}: managers get their managed
	 * team members, admins get the whole (door-filtered, enabled)
	 * organization — previously every call site here returned an empty list
	 * for admins managing no team, which left pending queues, overviews and
	 * team exports permanently empty for admin/HR users.
	 *
	 * @param string $managerId
	 * @return array Array of user IDs
	 */
	private function getTeamMemberIds(string $managerId): array
	{
		return $this->oversightScope()->managedUserIds($managerId);
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
	private function monthClosureEnabledParam(): bool
	{
		return MonthClosureFeature::isEnabledFromIConfig($this->config);
	}

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

	/**
	 * Same eligibility rules as {@see MonthClosureController::resolveClosureTargetUserId} for a named target
	 * (self or an employee the actor may manage).
	 */
	private function actorMayDownloadRevisionPdfFor(string $actor, string $targetUserId): bool
	{
		if ($actor === $targetUserId) {
			return true;
		}

		return $this->permissionService->canManageEmployee($actor, $targetUserId);
	}

	/**
	 * B1-MH-04: self-targeting through manager write paths gets its own 403 so
	 * the UI can route the actor into the self-service flow (BX-02) instead of
	 * a dead-end error toast. Existence is not leaked — the actor always knows
	 * their own id.
	 */
	private function selfTargetForbiddenResponse(string $area, string $action, string $targetUserId): JSONResponse
	{
		$this->permissionService->logPermissionDenied($this->getUserId(), $action, 'user', $targetUserId);
		return new JSONResponse([
			'success' => false,
			'selfTarget' => true,
			'selfServiceArea' => $area,
			'error' => $this->l10n->t('Please record your own entries via Time Entries / Absences.'),
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
	 * Read a scalar request param as trimmed string (array values → first element).
	 *
	 * @param array<string, mixed> $params
	 */
	private function requestParamString(array $params, string $key): string
	{
		if (!array_key_exists($key, $params)) {
			return '';
		}
		$value = $params[$key];
		if (is_array($value)) {
			$value = !empty($value) ? reset($value) : '';
		}
		if (is_bool($value) || is_object($value)) {
			return '';
		}
		return trim((string)$value);
	}

	/**
	 * Shared template values for manager employee list / filter pages.
	 *
	 * @return array<string, mixed>
	 */
	private function managerEmployeeListTemplateParams(string $page): array
	{
		return [
			'managerEmployeeListPage' => $page,
			'maxManagerListDateRangeDays' => Constants::MAX_EXPORT_DATE_RANGE_DAYS,
		];
	}

	/**
	 * Reject manager list queries whose span exceeds {@see Constants::MAX_EXPORT_DATE_RANGE_DAYS} (DoS guard).
	 *
	 * @param \DateTimeImmutable $endExclusive Day after the last included calendar day
	 */
	private function managerListDateRangeLimitResponse(
		\DateTimeImmutable $start,
		\DateTimeImmutable $endExclusive
	): ?JSONResponse {
		if ($start >= $endExclusive) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Invalid date range. The start date must be before the end date.'),
			], Http::STATUS_BAD_REQUEST);
		}

		$days = (int)$start->diff($endExclusive)->days;
		if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t(
					'Date range must not exceed %d days. Please narrow the range.',
					[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
				),
			], Http::STATUS_BAD_REQUEST);
		}

		return null;
	}

	/**
	 * Localized label for an absence type code (same strings as the absences UI / manager-dashboard l10n).
	 */
	private function getAbsenceTypeLabel(string $type): string
	{
		return AbsenceTypeCatalog::label($this->l10n, $type);
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
		$this->registerFrontEndAssets('manager-dashboard', 'manager-dashboard', ['time-entry-correction'], ['manager-pending-time-entry-card']);

		try {
			$managerId = $this->getUserId();

			// Redirect non-managers (no team, not admin) to dashboard
			if (!$this->permissionService->canAccessManagerDashboard($managerId)) {
				$redirect = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.index');
				return new \OCP\AppFramework\Http\RedirectResponse($redirect);
			}

			$teamUserIds = $this->getTeamMemberIds($managerId);

			// Get team statistics
			$teamStats = [
				'total_members' => count($teamUserIds),
				'active_today' => 0,
				'total_hours_today' => 0,
				'pending_absences' => $this->absenceMapper->countPendingForUsers($teamUserIds),
				'pending_time_entries' => $this->timeEntryMapper->countPendingApprovalForUsers($teamUserIds),
			];

			$teamMembers = [];
			foreach (array_slice($teamUserIds, 0, 10) as $userId) {
				$user = $this->userManager->get($userId);
				if (!$user) continue;

				// Manager day total is tariff-facing — paid basis.
				$todayHours = $this->timeTrackingService->getTodayPaidHours($userId);
				$status = $this->timeTrackingService->getStatus($userId);
				$pendingAbsences = $this->absenceService->getAbsencesByUser($userId, ['status' => 'pending']);

				if ($todayHours > 0) {
					$teamStats['active_today']++;
				}
				$teamStats['total_hours_today'] += $todayHours;

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

			$navFlags = $this->getNavigationFlags($managerId);

			$useAppTeams = $this->config->getAppValue('arbeitszeitcheck', 'use_app_teams', '0') === '1';
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-dashboard', $this->buildManagerShellParams(
				'manager-dashboard',
				$this->l10n->t('Manager Dashboard'),
				$this->l10n->t('See how your team is doing with time tracking and check for any problems'),
				$navFlags,
			) + [
				'teamStats' => $teamStats,
				'teamMembers' => $teamMembers,
				'monthClosureEnabled' => $this->monthClosureEnabledParam(),
				'useAppTeams' => $useAppTeams,
				'adminTeamsUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.admin.teams'),
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::dashboard',
				['exception' => $e]
			);
			$navFlags = $this->getNavigationFlagsForSession();
			$useAppTeams = $this->config->getAppValue('arbeitszeitcheck', 'use_app_teams', '0') === '1';
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-dashboard', $this->buildManagerShellParams(
				'manager-dashboard',
				$this->l10n->t('Manager Dashboard'),
				$this->l10n->t('See how your team is doing with time tracking and check for any problems'),
				$navFlags,
			) + [
				'teamStats' => [
					'total_members' => 0,
					'active_today' => 0,
					'total_hours_today' => 0,
					'pending_absences' => 0,
					'pending_time_entries' => 0,
				],
				'teamMembers' => [],
				'monthClosureEnabled' => $this->monthClosureEnabledParam(),
				'useAppTeams' => $useAppTeams,
				'adminTeamsUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.admin.teams'),
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			]);
			return $this->configureCSP($response);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function employeeTimeEntriesPage(): TemplateResponse|\OCP\AppFramework\Http\RedirectResponse
	{
		$this->registerFrontEndAssets('manager-time-entries', 'manager-time-entries', ['common/time-pair-matrix', 'time-entry-correction'], ['common/admin-user-picker', 'common/manager-scoped-employee-picker']);
		Util::addScript('arbeitszeitcheck', 'common/datepicker');
		if ($this->workforceSuiteConfig !== null
			&& $this->workforceSuiteConfig->isEnabled('work_location_catalog_enabled')) {
			Util::addScript('arbeitszeitcheck', 'common/work-location-catalog');
		}
		Util::addScript('arbeitszeitcheck', 'common/time-entry-clock-form');
		Util::addScript('arbeitszeitcheck', 'manager-correction-dialog');
		Util::addScript('arbeitszeitcheck', 'manager-create-time-entry');

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

			$navFlags = $this->getNavigationFlags($actorUserId);
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-time-entries', array_merge(
				$this->buildManagerShellParams(
					'manager-time-entries',
					$this->l10n->t('Employee time entries'),
					$this->l10n->t('View your employees\' time entries directly in the app. Start by selecting a date range and optionally one person.'),
					$navFlags,
				),
				$this->managerEmployeeListTemplateParams('time-entries'),
				[
					'monthClosureEnabled' => $this->monthClosureEnabledParam(),
					'projectCheckEnabled' => $this->projectCheckIntegration->isAdminIntegrationEnabled(),
				]
			));
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::employeeTimeEntriesPage',
				['exception' => $e]
			);
			$navFlags = $this->getNavigationFlagsForSession();
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-time-entries', $this->buildManagerShellParams(
				'manager-time-entries',
				$this->l10n->t('Employee time entries'),
				$this->l10n->t('View your employees\' time entries directly in the app. Start by selecting a date range and optionally one person.'),
				$navFlags,
			) + [
				'monthClosureEnabled' => $this->monthClosureEnabledParam(),
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			]);
			return $this->configureCSP($response);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function employeeAbsencesPage(): TemplateResponse|\OCP\AppFramework\Http\RedirectResponse
	{
		$this->registerFrontEndAssets('manager-absences', 'manager-time-entries', [], ['common/admin-user-picker', 'common/manager-scoped-employee-picker']);
		Util::addScript('arbeitszeitcheck', 'common/datepicker');

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

			$navFlags = $this->getNavigationFlags($actorUserId);
			$vacationUnitService = new \OCA\ArbeitszeitCheck\Service\VacationUnitService($this->config);
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-absences', array_merge(
				$this->buildManagerShellParams(
					'manager-absences',
					$this->l10n->t('Employee absences'),
					$this->l10n->t('Review and manage absence requests for employees in your scope.'),
					$navFlags,
				),
				$this->managerEmployeeListTemplateParams('absences'),
				[
					'monthClosureEnabled' => $this->monthClosureEnabledParam(),
					'vacationUnit' => $vacationUnitService->getUnit(),
					'vacationHoursPerDay' => $vacationUnitService->getHoursPerDay(),
				]
			));
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::employeeAbsencesPage',
				['exception' => $e]
			);
			$navFlags = $this->getNavigationFlagsForSession();
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-absences', $this->buildManagerShellParams(
				'manager-absences',
				$this->l10n->t('Employee absences'),
				$this->l10n->t('Review and manage absence requests for employees in your scope.'),
				$navFlags,
			) + [
				'monthClosureEnabled' => $this->monthClosureEnabledParam(),
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * People overview page — per-employee vacation balance, approved future
	 * vacation, overtime and open compliance issues for the actor's scope
	 * (managers: their team, admins: the whole organization).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function peoplePage(): TemplateResponse|\OCP\AppFramework\Http\RedirectResponse
	{
		$this->registerFrontEndAssets('manager-people', 'manager-people', [], ['common/view-components', 'common/admin-user-picker', 'common/manager-scoped-employee-picker']);

		try {
			$actorUserId = $this->getUserId();
			if (!$this->permissionService->isAdmin($actorUserId) && !$this->permissionService->canAccessManagerDashboard($actorUserId)) {
				$redirect = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.index');
				return new \OCP\AppFramework\Http\RedirectResponse($redirect);
			}

			$navFlags = $this->getNavigationFlags($actorUserId);
			$vacationUnitService = new \OCA\ArbeitszeitCheck\Service\VacationUnitService($this->config);
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-people', array_merge(
				$this->buildManagerShellParams(
					'manager-people',
					$this->l10n->t('People overview'),
					$this->l10n->t('Vacation balances, overtime and open compliance issues for the employees in your scope.'),
					$navFlags,
				),
				[
					'monthClosureEnabled' => $this->monthClosureEnabledParam(),
					'isAdminScope' => $this->permissionService->isAdmin($actorUserId),
					'vacationUnit' => $vacationUnitService->getUnit(),
					'vacationHoursPerDay' => $vacationUnitService->getHoursPerDay(),
					'peopleOverviewUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.manager.getPeopleOverview'),
					'employeeAbsencesUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.manager.employeeAbsencesPage'),
					'violationsUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.compliance.violations'),
					'adminUserUrl' => $this->permissionService->isAdmin($actorUserId)
						? $this->urlGenerator->linkToRoute('arbeitszeitcheck.admin.userDetail', ['userId' => '__UID__'])
						: '',
				]
			));
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::peoplePage',
				['exception' => $e]
			);
			$navFlags = $this->getNavigationFlagsForSession();
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-people', $this->buildManagerShellParams(
				'manager-people',
				$this->l10n->t('People overview'),
				$this->l10n->t('Vacation balances, overtime and open compliance issues for the employees in your scope.'),
				$navFlags,
			) + [
				'monthClosureEnabled' => $this->monthClosureEnabledParam(),
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Manager business-trip review page (spec §7.5): pending queue plus
	 * the three-card detail — what was recorded, what the rule computed,
	 * what gets booked. Scope is enforced per trip in the API.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function businessTripsPage(): TemplateResponse|\OCP\AppFramework\Http\RedirectResponse
	{
		$tripsEnabled = $this->workforceSuiteConfig !== null
			? $this->workforceSuiteConfig->isEnabled('business_trips_enabled')
			: $this->config->getAppValue('arbeitszeitcheck', 'business_trips_enabled', '0') === '1';
		if (!$tripsEnabled) {
			return new \OCP\AppFramework\Http\RedirectResponse($this->urlGenerator->linkToRoute('arbeitszeitcheck.page.index'));
		}

		$this->registerFrontEndAssets('arbeitszeitcheck-main', 'business-trips', ['business-trips']);
		Util::addScript('arbeitszeitcheck', 'manager-business-trips');

		try {
			$actorUserId = $this->getUserId();
			if (!$this->permissionService->isAdmin($actorUserId) && !$this->permissionService->canAccessManagerDashboard($actorUserId)) {
				return new \OCP\AppFramework\Http\RedirectResponse($this->urlGenerator->linkToRoute('arbeitszeitcheck.page.index'));
			}
			$navFlags = $this->getNavigationFlags($actorUserId);
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-business-trips', $this->buildManagerShellParams(
				'manager-business-trips',
				$this->l10n->t('Business trips'),
				$this->l10n->t('Review submitted business trips of your team members.'),
				$navFlags,
			) + [
				'tripQueueUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.business_trip_manager.index'),
				'tripDetailTemplate' => \OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($this->urlGenerator, 'arbeitszeitcheck.business_trip_manager.show', 'id'),
				'tripApproveTemplate' => \OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($this->urlGenerator, 'arbeitszeitcheck.business_trip_manager.approve', 'id'),
				'tripRejectTemplate' => \OCA\ArbeitszeitCheck\Support\RouteUrlTemplate::for($this->urlGenerator, 'arbeitszeitcheck.business_trip_manager.reject', 'id'),
				'monthClosureEnabled' => $this->monthClosureEnabledParam(),
			]);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::businessTripsPage',
				['exception' => $e]
			);
			$navFlags = $this->getNavigationFlagsForSession();
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-business-trips', $this->buildManagerShellParams(
				'manager-business-trips',
				$this->l10n->t('Business trips'),
				$this->l10n->t('Review submitted business trips of your team members.'),
				$navFlags,
			) + [
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			]);
			return $this->configureCSP($response);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function monthClosuresPage(): TemplateResponse|\OCP\AppFramework\Http\RedirectResponse
	{
		if (!MonthClosureFeature::isEnabledFromIConfig($this->config)) {
			return new \OCP\AppFramework\Http\RedirectResponse($this->urlGenerator->linkToRoute('arbeitszeitcheck.page.index'));
		}

		$this->registerFrontEndAssets('manager-month-closures', 'manager-month-closures');

		try {
			$actorUserId = $this->getUserId();
			if (!$this->permissionService->isAdmin($actorUserId) && !$this->permissionService->canAccessManagerDashboard($actorUserId)) {
				return new \OCP\AppFramework\Http\RedirectResponse($this->urlGenerator->linkToRoute('arbeitszeitcheck.page.index'));
			}

			$isAdmin = $this->permissionService->isAdmin($actorUserId);
			$navFlags = $this->getNavigationFlags($actorUserId);

			$response = new TemplateResponse('arbeitszeitcheck', 'manager-month-closures', $this->buildManagerShellParams(
				'manager-month-closures',
				$this->l10n->t('Revision PDFs (month closure)'),
				$this->l10n->t('Pick a month that already has sealed data, then download the same revision-secure PDF for each person you are allowed to access.'),
				$navFlags,
			) + [
				'monthClosureEnabled' => $this->monthClosureEnabledParam(),
				'isAdmin' => $isAdmin,
				'revisionPdfAvailableMonthsUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.manager.revisionPdfAvailableMonths'),
				'revisionPdfUsersForMonthUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.manager.revisionPdfUsersForMonth'),
				'pdfUrlBase' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.month_closure.pdf'),
			]);

			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::monthClosuresPage',
				['exception' => $e]
			);
			$navFlags = $this->getNavigationFlagsForSession();
			$response = new TemplateResponse('arbeitszeitcheck', 'manager-month-closures', $this->buildManagerShellParams(
				'manager-month-closures',
				$this->l10n->t('Revision PDFs (month closure)'),
				$this->l10n->t('Pick a month that already has sealed data, then download the same revision-secure PDF for each person you are allowed to access.'),
				$navFlags,
			) + [
				'monthClosureEnabled' => $this->monthClosureEnabledParam(),
				'isAdmin' => false,
				'revisionPdfAvailableMonthsUrl' => '',
				'revisionPdfUsersForMonthUrl' => '',
				'pdfUrlBase' => '',
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			]);

			return $this->configureCSP($response);
		}
	}

	/**
	 * Searchable list of accounts the current user may download a revision-secure month PDF for.
	 * Enforces the same rules as month-closure PDF routes (admin: directory search, filtered; manager: team only).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function revisionPdfUsers(?string $search = null, ?int $limit = null): JSONResponse
	{
		try {
			$actor = $this->getUserId();
		} catch (\Exception $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		if (!MonthClosureFeature::isEnabledFromIConfig($this->config)) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Month closure is disabled by the administrator.')], Http::STATUS_FORBIDDEN);
		}

		$accessResponse = $this->ensureManagerReadAccess($actor, 'revision_pdf_users');
		if ($accessResponse !== null) {
			return $accessResponse;
		}

		// HTTP GET passes query params via IRequest; method args may be unset depending on routing.
		$search = PeopleSearchQuery::fromRequest($this->request, $search);
		$reqLimit = $this->request->getParam('limit');
		if ($limit === null && $reqLimit !== null && $reqLimit !== '') {
			$limit = (int)$reqLimit;
		}

		$limit = $this->normalizeLimit($limit);
		$query = $search;

		try {
			if ($this->permissionService->isAdmin($actor)) {
				$fetchCap = (int)min(max($limit * 4, $limit), Constants::MAX_LIST_LIMIT);
				// Match by user id OR display name (issue #14).
				$candidates = UserDirectorySearch::mergeUnique(
					$this->userManager->search($query, $fetchCap, 0),
					$this->userManager->searchDisplayName($query, $fetchCap, 0),
				);
				$usersData = [];
				foreach ($candidates as $user) {
					$uid = $user->getUID();
					if (!$this->actorMayDownloadRevisionPdfFor($actor, $uid)) {
						continue;
					}
					$usersData[] = [
						'userId' => $uid,
						'displayName' => $user->getDisplayName(),
						'email' => $user->getEMailAddress(),
					];
					if (\count($usersData) >= $limit) {
						break;
					}
				}

				return new JSONResponse(['success' => true, 'users' => $usersData]);
			}

			$teamIds = $this->getTeamMemberIds($actor);
			$rows = [];
			foreach ($teamIds as $uid) {
				$user = $this->userManager->get($uid);
				if ($user === null) {
					continue;
				}
				if (!$this->actorMayDownloadRevisionPdfFor($actor, $uid)) {
					continue;
				}
				$dn = $user->getDisplayName() ?: $uid;
				$email = $user->getEMailAddress() ?? '';
				if ($query !== '') {
					$needle = mb_strtolower($query);
					$haystack = mb_strtolower($uid . "\n" . $dn . "\n" . $email);
					if (mb_strpos($haystack, $needle) === false) {
						continue;
					}
				}
				$rows[] = [
					'user' => $user,
					'sort' => $dn,
				];
			}

			usort($rows, static function (array $a, array $b): int {
				return strcasecmp($a['sort'], $b['sort']);
			});

			$rows = array_slice($rows, 0, $limit);
			$usersData = [];
			foreach ($rows as $row) {
				$user = $row['user'];
				$usersData[] = [
					'userId' => $user->getUID(),
					'displayName' => $user->getDisplayName(),
					'email' => $user->getEMailAddress(),
				];
			}

			return new JSONResponse(['success' => true, 'users' => $usersData]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('revisionPdfUsers failed', ['exception' => $e, 'app' => 'arbeitszeitcheck']);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Calendar months where at least one account in the actor’s scope has a finalized closure (actionable revision PDFs exist).
	 * Admins: any finalized month in the app; managers: months where a team member has a finalized closure.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function revisionPdfAvailableMonths(): JSONResponse
	{
		try {
			$actor = $this->getUserId();
		} catch (\Exception $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		if (!MonthClosureFeature::isEnabledFromIConfig($this->config)) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Month closure is disabled by the administrator.')], Http::STATUS_FORBIDDEN);
		}

		$accessResponse = $this->ensureManagerReadAccess($actor, 'revision_pdf_available_months');
		if ($accessResponse !== null) {
			return $accessResponse;
		}

		try {
			if ($this->permissionService->isAdmin($actor)) {
				$months = $this->monthClosureService->listDistinctFinalizedYearMonthsGlobally();
			} else {
				$months = $this->monthClosureService->listDistinctFinalizedYearMonthsForUserIds($this->getTeamMemberIds($actor));
			}

			return new JSONResponse(['success' => true, 'months' => $months]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('revisionPdfAvailableMonths failed', ['exception' => $e, 'app' => 'arbeitszeitcheck']);

			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Error')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Accounts the actor may download a revision PDF for, for one finalized calendar month (must match sealed data in the database).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function revisionPdfUsersForMonth(): JSONResponse
	{
		try {
			$actor = $this->getUserId();
		} catch (\Exception $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Authentication required')], Http::STATUS_UNAUTHORIZED);
		}

		if (!MonthClosureFeature::isEnabledFromIConfig($this->config)) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Month closure is disabled by the administrator.')], Http::STATUS_FORBIDDEN);
		}

		$accessResponse = $this->ensureManagerReadAccess($actor, 'revision_pdf_users_for_month');
		if ($accessResponse !== null) {
			return $accessResponse;
		}

		$year = (int)$this->request->getParam('year', 0);
		$month = (int)$this->request->getParam('month', 0);
		if ($year < 1970 || $year > 2100 || $month < 1 || $month > 12) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Invalid month')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$admin = $this->permissionService->isAdmin($actor);
			$restrict = $admin ? null : $this->getTeamMemberIds($actor);
			$candidateUids = $this->monthClosureService->listUserIdsWithFinalizedMonth($year, $month, $restrict);
			$usersData = [];
			foreach ($candidateUids as $uid) {
				if (!$this->actorMayDownloadRevisionPdfFor($actor, $uid)) {
					continue;
				}
				$user = $this->userManager->get($uid);
				if ($user === null) {
					continue;
				}
				$usersData[] = [
					'userId' => $uid,
					'displayName' => $user->getDisplayName() ?: $uid,
					'email' => $user->getEMailAddress() ?? '',
				];
			}

			usort($usersData, static function (array $a, array $b): int {
				return strcasecmp($a['displayName'], $b['displayName']);
			});

			return new JSONResponse(['success' => true, 'users' => $usersData]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('revisionPdfUsersForMonth failed', ['exception' => $e, 'app' => 'arbeitszeitcheck']);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
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
			$today = $this->timeZoneService->nowInStorage();
			$today->setTime(0, 0, 0);
			$weekStart = clone $today;
			$weekStart->modify('-' . (int)$today->format('w') . ' days')->setTime(0, 0, 0);

			// Batched compliance status for the page — two queries total instead
			// of two per row.
			$complianceByUser = $this->complianceService->getComplianceStatusForUsers($paginatedUserIds);
			// Batched today-hours + pending-absence counts — one query each
			// instead of one per row. Same storage-TZ today window as
			// getTodayHours; pending covers pending + substitute_pending,
			// matching the per-row getAbsencesByUser(status=pending) filter.
			[$todayStartStorage, $todayEndStorage] = $this->timeZoneService->todayWindowInStorage();
			$todayHoursByUser = $this->timeTrackingService->getWorkingHoursForUsersInPeriod(
				$paginatedUserIds,
				$todayStartStorage,
				$todayEndStorage
			);
			$pendingByUser = $this->absenceMapper->countPendingApprovalByUser($paginatedUserIds);

			foreach ($paginatedUserIds as $userId) {
				// Get today's hours
				$todayHours = $todayHoursByUser[$userId] ?? 0.0;

				// Get week's hours using injected OvertimeService
			$weekEnd = clone $weekStart;
			$weekEnd->modify('+6 days');
			$weekEnd->setTime(0, 0, 0);
			$weekEndExclusive = (clone $weekEnd)->modify('+1 day');
			$weekOvertime = $this->overtimeService->calculateOvertime($userId, $weekStart, $weekEndExclusive);
				$weekHours = $weekOvertime['total_hours_worked'];

				// Get current status
				$status = $this->timeTrackingService->getStatus($userId);
				$currentStatus = $status['status'] ?? 'clocked_out';

				// Get pending absences count
				$pendingAbsencesCount = $pendingByUser[$userId] ?? 0;

				// Get compliance status
				$complianceStatus = $complianceByUser[$userId]
					?? $this->complianceService->getComplianceStatus($userId);
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
	 * People overview API — per-employee vacation balance (projected: future
	 * approved vacation already deducted), approved upcoming vacation days,
	 * overtime snapshot, unresolved compliance violations and pending
	 * absence requests for every employee in the actor's scope.
	 *
	 * Scope: managers see their managed team, admins the whole
	 * (door-filtered, enabled) organization — one resolver
	 * ({@see OversightScopeService}) for every mirrored surface.
	 *
	 * Read-only. Per-user stat failures degrade to null fields and are
	 * counted in `partialErrors` instead of failing the whole page.
	 *
	 * @param string|null $employeeId Optional single-employee filter (scope-checked)
	 * @param int|null $year Vacation balance year (default: current year)
	 */
	#[NoAdminRequired]
	public function getPeopleOverview(
		?string $employeeId = null,
		?int $year = null,
		?int $limit = Constants::DEFAULT_LIST_LIMIT,
		?int $offset = 0,
		?string $search = null,
		?string $filter = null,
		?string $sort = null,
	): JSONResponse {
		try {
			$actorUserId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($actorUserId, 'view_people_overview');
			if ($accessResponse !== null) {
				return $accessResponse;
			}
			$limit = $this->normalizeLimit($limit);
			$offset = $this->normalizeOffset($offset);

			if ($year === null) {
				$year = (int)$this->timeZoneService->nowInStorage()->format('Y');
			} elseif ($year < 1970 || $year > 2100) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid year.'),
				], Http::STATUS_BAD_REQUEST);
			}

			$search = $search !== null ? trim($search) : '';
			if (mb_strlen($search) > 200) {
				$search = mb_substr($search, 0, 200);
			}
			$filter = $filter !== null ? strtolower(trim($filter)) : 'all';
			if (!in_array($filter, ['all', 'needs_attention', 'pending_absences'], true)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid filter.'),
				], Http::STATUS_BAD_REQUEST);
			}
			$sort = $sort !== null ? strtolower(trim($sort)) : 'violations';
			if (!in_array($sort, ['violations', 'name', 'vacation', 'overtime'], true)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid sort.'),
				], Http::STATUS_BAD_REQUEST);
			}

			// Only live accounts carry balances; stale/deleted member rows are excluded.
			$scopeUserIds = $this->oversightScope()->managedEnabledUserIds($actorUserId);

			$employeeId = $employeeId !== null ? trim($employeeId) : '';
			if ($employeeId !== '') {
				if (!in_array($employeeId, $scopeUserIds, true)) {
					$this->permissionService->logPermissionDenied($actorUserId, 'view_people_overview_user_filter', 'user', $employeeId);
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Access denied. You can only view employees in your scope.'),
					], Http::STATUS_FORBIDDEN);
				}
				$scopeUserIds = [$employeeId];
			}

			// Display names once — reused by substring search, sort and rows.
			$displayNames = [];
			foreach ($scopeUserIds as $uid) {
				$displayNames[$uid] = $this->getDisplayName($uid);
			}

			// Case-insensitive substring match on display name OR uid — never
			// widens the resolved scope (post-scope filtering, no oracle).
			if ($search !== '') {
				$scopeUserIds = array_values(array_filter(
					$scopeUserIds,
					static function (string $uid) use ($search, $displayNames): bool {
						return mb_stripos($displayNames[$uid] ?? '', $search) !== false
							|| mb_stripos($uid, $search) !== false;
					}
				));
			}

			// Scope-wide batched aggregates — one grouped query each, so
			// filter and sort never introduce N+1 counting.
			$unresolvedSevByUser = [];
			$pendingByUser = [];
			if ($scopeUserIds !== []) {
				try {
					$unresolvedSevByUser = $this->complianceViolationMapper()->countUnresolvedByUserIdsPerSeverity($scopeUserIds);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'getPeopleOverview: unresolved violation counts failed: ' . $e->getMessage(),
						['exception' => $e]
					);
				}
				try {
					// pending + substitute_pending — same "awaiting any approval"
					// definition the team overview row count uses.
					$pendingByUser = $this->absenceMapper->countPendingApprovalByUser($scopeUserIds);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'getPeopleOverview: pending absence counts failed: ' . $e->getMessage(),
						['exception' => $e]
					);
				}
			}
			$openByUser = [];
			$criticalByUser = [];
			foreach ($unresolvedSevByUser as $uid => $sevMap) {
				$openByUser[$uid] = array_sum($sevMap);
				$criticalByUser[$uid] = (int)($sevMap[\OCA\ArbeitszeitCheck\Db\ComplianceViolation::SEVERITY_ERROR] ?? 0);
			}

			// Per-user stat cache — vacation stats have no batch API, so the
			// default path computes them lazily for the rendered page only.
			// The needs_attention filter and the vacation/overtime sorts are
			// defined over the whole scope; computing them there is bounded
			// by the (door-filtered, enabled) scope size.
			$statCache = [];
			$overtimeSnapshots = [];
			$needsFullScopeStats = $filter === 'needs_attention'
				|| $sort === 'vacation'
				|| $sort === 'overtime';
			if ($needsFullScopeStats && $scopeUserIds !== []) {
				try {
					$overtimeSnapshots = $this->overtimeDisplayService->buildManagerMemberSnapshots($scopeUserIds);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'getPeopleOverview: overtime snapshot batch failed: ' . $e->getMessage(),
						['exception' => $e]
					);
				}
			}
			$ensureStats = function (string $uid) use (&$statCache, &$overtimeSnapshots, $year): array {
				if (!isset($statCache[$uid])) {
					$entry = ['stats' => null, 'snapshot' => null, 'errors' => 0];
					try {
						$entry['stats'] = $this->absenceService->getVacationStats($uid, $year);
					} catch (\Throwable $e) {
						$entry['errors']++;
						\OCP\Log\logger('arbeitszeitcheck')->warning(
							'getPeopleOverview: vacation stats failed for one employee: ' . $e->getMessage(),
							['exception' => $e]
						);
					}
					if (array_key_exists($uid, $overtimeSnapshots)) {
						$entry['snapshot'] = $overtimeSnapshots[$uid];
					} else {
						try {
							$entry['snapshot'] = $this->overtimeDisplayService->buildManagerMemberSnapshot($uid);
						} catch (\Throwable $e) {
							$entry['errors']++;
							\OCP\Log\logger('arbeitszeitcheck')->warning(
								'getPeopleOverview: overtime snapshot failed for one employee: ' . $e->getMessage(),
								['exception' => $e]
							);
						}
					}
					$statCache[$uid] = $entry;
				}
				return $statCache[$uid];
			};

			if ($filter === 'pending_absences') {
				$scopeUserIds = array_values(array_filter(
					$scopeUserIds,
					static fn (string $uid): bool => (int)($pendingByUser[$uid] ?? 0) > 0
				));
			} elseif ($filter === 'needs_attention') {
				$scopeUserIds = array_values(array_filter(
					$scopeUserIds,
					static function (string $uid) use ($openByUser, $ensureStats): bool {
						if ((int)($openByUser[$uid] ?? 0) > 0) {
							return true;
						}
						$entry = $ensureStats($uid);
						return (bool)($entry['snapshot']['needs_attention'] ?? false)
							|| ($entry['stats'] !== null && ($entry['stats']['vacation_year_error'] ?? null) !== null);
					}
				));
			}

			$nameCmp = static function (string $a, string $b) use ($displayNames): int {
				return strcasecmp($displayNames[$a] ?? $a, $displayNames[$b] ?? $b) ?: strcmp($a, $b);
			};
			if ($sort === 'violations') {
				// HR triage order: most open violations first, then name.
				usort($scopeUserIds, static function (string $a, string $b) use ($openByUser, $nameCmp): int {
					return ((int)($openByUser[$b] ?? 0) <=> (int)($openByUser[$a] ?? 0)) ?: $nameCmp($a, $b);
				});
			} elseif ($sort === 'name') {
				usort($scopeUserIds, $nameCmp);
			} elseif ($sort === 'vacation' || $sort === 'overtime') {
				$metric = static function (string $uid) use ($sort, $ensureStats): ?float {
					$entry = $ensureStats($uid);
					if ($sort === 'vacation') {
						$v = $entry['stats']['remaining'] ?? null;
					} else {
						$v = $entry['snapshot']['balance'] ?? null;
					}
					return $v === null ? null : (float)$v;
				};
				// Highest value first ("most vacation/overtime" for HR triage);
				// unavailable values sink to the end deterministically.
				usort($scopeUserIds, static function (string $a, string $b) use ($metric, $nameCmp): int {
					$ma = $metric($a);
					$mb = $metric($b);
					if ($ma === null && $mb === null) {
						return $nameCmp($a, $b);
					}
					if ($ma === null) {
						return 1;
					}
					if ($mb === null) {
						return -1;
					}
					return ($mb <=> $ma) ?: $nameCmp($a, $b);
				});
			}

			$total = count($scopeUserIds);
			$pageUserIds = array_slice($scopeUserIds, $offset, $limit);

			// Page-scoped batches — upcoming vacation and (for the light
			// path) overtime snapshots are only needed for rendered rows.
			$upcomingVacationByUser = [];
			if ($pageUserIds !== []) {
				try {
					$upcomingVacationByUser = $this->approvedUpcomingVacationByUser($pageUserIds);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->warning(
						'getPeopleOverview: upcoming vacation aggregation failed: ' . $e->getMessage(),
						['exception' => $e]
					);
				}
				if (!$needsFullScopeStats) {
					try {
						$overtimeSnapshots = $this->overtimeDisplayService->buildManagerMemberSnapshots($pageUserIds);
					} catch (\Throwable $e) {
						// Batch failed — per-user fallback below preserves partial results.
						$overtimeSnapshots = [];
						\OCP\Log\logger('arbeitszeitcheck')->warning(
							'getPeopleOverview: overtime snapshot batch failed: ' . $e->getMessage(),
							['exception' => $e]
						);
					}
				}
			}

			$employees = [];
			$partialErrors = 0;
			foreach ($pageUserIds as $userId) {
				$entry = $ensureStats($userId);
				$partialErrors += $entry['errors'];
				$row = [
					'userId' => $userId,
					'displayName' => $displayNames[$userId] ?? $this->getDisplayName($userId),
					'vacation' => null,
					'overtime' => null,
					'openViolations' => (int)($openByUser[$userId] ?? 0),
					'criticalViolations' => (int)($criticalByUser[$userId] ?? 0),
					'pendingAbsences' => (int)($pendingByUser[$userId] ?? 0),
				];

				$stats = $entry['stats'];
				if ($stats !== null) {
					// Bound the upcoming-vacation sum to this user's displayed
					// balance window (anniversary mode has per-user windows).
					$yearEndInclusive = !empty($stats['vacation_year_end_inclusive'])
						? (string)$stats['vacation_year_end_inclusive']
						: sprintf('%04d-12-31', (int)($stats['year'] ?? $year));
					$approvedUpcoming = 0.0;
					$tomorrowYmd = $this->timeZoneService->nowInStorage()->modify('+1 day')->format('Y-m-d');
					foreach ($upcomingVacationByUser[$userId] ?? [] as $absence) {
						$start = $absence->getStartDate();
						// Strictly future starts only — the batch query uses
						// overlap semantics and would otherwise count an
						// absence spanning today, days already consumed included.
						if ($start !== null && $start->format('Y-m-d') >= $tomorrowYmd && $start->format('Y-m-d') <= $yearEndInclusive) {
							$approvedUpcoming += (float)($absence->getDays() ?? 0.0);
						}
					}
					$row['vacation'] = [
						'year' => $stats['year'] ?? $year,
						'yearLabel' => $stats['vacation_year_label'] ?? null,
						'entitlement' => $stats['entitlement'] ?? null,
						'carryoverDays' => $stats['carryover_days'] ?? null,
						'totalAvailable' => $stats['total_available'] ?? null,
						'used' => $stats['used'] ?? null,
						'remaining' => $stats['remaining'] ?? null,
						'approvedUpcoming' => round($approvedUpcoming, 2),
						'unit' => $stats['vacation_unit'] ?? \OCA\ArbeitszeitCheck\Constants::VACATION_UNIT_DAYS,
						'hoursPerDay' => $stats['vacation_hours_per_day'] ?? null,
						'error' => $stats['vacation_year_error'] ?? null,
					];
				}

				$snapshot = $entry['snapshot'];
				if ($snapshot !== null) {
					$row['overtime'] = [
						'balance' => $snapshot['balance'] ?? null,
						'needsAttention' => (bool)($snapshot['needs_attention'] ?? false),
						'trafficLightState' => $snapshot['traffic_light_state'] ?? null,
						'bankState' => $snapshot['bank_state'] ?? null,
						'payoutEligibleHours' => $snapshot['payout_eligible_hours'] ?? null,
					];
				}

				$employees[] = $row;
			}

			return new JSONResponse([
				'success' => true,
				'scope' => $this->permissionService->isAdmin($actorUserId) ? 'organization' : 'managed',
				'year' => $year,
				'employees' => $employees,
				'total' => $total,
				'limit' => $limit,
				'offset' => $offset,
				'search' => $search,
				'filter' => $filter,
				'sort' => $sort,
				'partialErrors' => $partialErrors,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getPeopleOverview',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Approved vacation days starting after today, per user — the
	 * "future approved vacation already deducted" figure.
	 *
	 * One batched query for the whole page slice; per user the sum is
	 * bounded to absences that start inside the displayed balance window
	 * (anniversary-mode per-user windows are not SQL-able, so the filter is
	 * applied in PHP).
	 *
	 * @param list<string> $userIds
	 * @return array<string, list<\OCA\ArbeitszeitCheck\Db\Absence>> userId => upcoming approved vacation absences
	 */
	private function approvedUpcomingVacationByUser(array $userIds): array
	{
		$tomorrow = $this->timeZoneService->nowInStorage();
		$tomorrow->modify('+1 day')->setTime(0, 0, 0);
		$outerBound = (clone $tomorrow)->modify('+2 years');
		$absences = $this->absenceMapper->findByUsersAndDateRange(
			$userIds,
			$tomorrow,
			$outerBound,
			\OCA\ArbeitszeitCheck\Db\Absence::STATUS_APPROVED,
			\OCA\ArbeitszeitCheck\Db\Absence::TYPE_VACATION
		);
		$grouped = [];
		foreach ($absences as $absence) {
			$grouped[$absence->getUserId()][] = $absence;
		}
		return $grouped;
	}

	private function complianceViolationMapper(): \OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper
	{
		return $this->complianceViolationMapper ?? \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper::class);
	}

	/**
	 * Lightweight employee search within the actor's manager scope (combobox pickers).
	 */
	#[NoAdminRequired]
	public function getScopedEmployees(?string $search = null, ?int $limit = null): JSONResponse
	{
		try {
			$actorUserId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($actorUserId, 'search_scoped_employees');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

			$searchTerm = PeopleSearchQuery::clamp(
				PeopleSearchQuery::fromRequest($this->request, $search),
				200
			);

			$requestLimit = $this->request->getParam('limit');
			$normalizedLimit = max(1, min((int)($requestLimit ?? $limit ?? 20), Constants::PICKER_MAX_RESULTS));

			if (mb_strlen($searchTerm) < Constants::PICKER_MIN_SEARCH_LENGTH) {
				return new JSONResponse([
					'success' => true,
					'users' => [],
					'limit' => $normalizedLimit,
					'requiresMinSearch' => Constants::PICKER_MIN_SEARCH_LENGTH,
				]);
			}

			$isAdmin = $this->permissionService->isAdmin($actorUserId);
			$usersData = [];

			if ($isAdmin) {
				// Match by user id OR display name (issue #14).
				$result = UserDirectorySearch::searchByIdOrName(
					$this->userManager,
					$searchTerm,
					$normalizedLimit,
					0,
					true,
				);
				foreach ($result['users'] as $user) {
					$uid = (string)$user->getUID();
					// Picker surface must match the admin oversight scope
					// (door-filtered org) — offering door-blocked users that can
					// never appear in scoped lists would be an oracle + a trap.
					if (!$this->permissionService->isUserAllowedByAccessGroups($uid)) {
						continue;
					}
					$usersData[] = [
						'userId' => $uid,
						'displayName' => (string)$user->getDisplayName(),
						'minBreakMinutes' => $this->resolveMinBreakMinutesForUser($uid),
					];
				}
			} else {
				$needle = mb_strtolower($searchTerm);
				foreach ($this->getTeamMemberIds($actorUserId) as $uid) {
					$user = $this->userManager->get($uid);
					if ($user === null || !$user->isEnabled()) {
						continue;
					}
					if (!$this->scopedEmployeeMatchesSearch($user, $needle)) {
						continue;
					}
					$usersData[] = [
						'userId' => $uid,
						'displayName' => $this->getDisplayName($uid),
						'minBreakMinutes' => $this->resolveMinBreakMinutesForUser($uid),
					];
					if (count($usersData) >= $normalizedLimit) {
						break;
					}
				}
			}

			usort($usersData, static function (array $a, array $b): int {
				return strcasecmp((string)$a['displayName'], (string)$b['displayName']);
			});

			return new JSONResponse([
				'success' => true,
				'users' => $usersData,
				'limit' => $normalizedLimit,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getScopedEmployees',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * @param \OCP\IUser $user
	 */
	private function scopedEmployeeMatchesSearch($user, string $needle): bool
	{
		if ($needle === '') {
			return true;
		}
		$uid = mb_strtolower((string)$user->getUID());
		$name = mb_strtolower((string)$user->getDisplayName());
		return str_contains($uid, $needle) || str_contains($name, $needle);
	}

	private function resolveMinBreakMinutesForUser(string $userId): int
	{
		try {
			$minutes = \OCP\Server::get(\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::class)
				->getProfile($userId)->minBreakMinutes;
			return $minutes > 0 ? $minutes : 15;
		} catch (\Throwable) {
			return 15;
		}
	}

	/**
	 * Allow list APIs to honor a picker-selected employee outside the capped directory preload.
	 *
	 * Admins may pick any enabled account via scoped-employees search; managers must be team members.
	 *
	 * @param list<array{userId: string, displayName: string, enabled: bool}> $employees
	 * @param array<string, true> $scopedLookup
	 * @return array{allowed: bool, employees: list<array{userId: string, displayName: string, enabled: bool}>}|null
	 *         null when denied (caller returns 403)
	 */
	private function resolveManagerListEmployeeFilter(
		string $actorUserId,
		bool $isAdmin,
		string $employeeId,
		array $employees,
		array $scopedLookup,
		string $denyAction,
	): ?array {
		if (isset($scopedLookup[$employeeId])) {
			return ['allowed' => true, 'employees' => $employees];
		}

		if ($isAdmin) {
			$user = $this->userManager->get($employeeId);
			if ($user === null || !$user->isEnabled()) {
				$this->permissionService->logPermissionDenied($actorUserId, $denyAction, 'user', $employeeId);

				return null;
			}
			$employees[] = [
				'userId' => $employeeId,
				'displayName' => $this->getDisplayName($employeeId),
				'enabled' => true,
			];

			return ['allowed' => true, 'employees' => $employees];
		}

		if (!in_array($employeeId, $this->getTeamMemberIds($actorUserId), true)) {
			$this->permissionService->logPermissionDenied($actorUserId, $denyAction, 'user', $employeeId);

			return null;
		}

		$user = $this->userManager->get($employeeId);
		if ($user === null || !$user->isEnabled()) {
			$this->permissionService->logPermissionDenied($actorUserId, $denyAction, 'user', $employeeId);

			return null;
		}
		$employees[] = [
			'userId' => $employeeId,
			'displayName' => $this->getDisplayName($employeeId),
			'enabled' => $user->isEnabled(),
		];

		return ['allowed' => true, 'employees' => $employees];
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
				? $this->permissionService->listAppAccessUserIds()
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
				$resolved = $this->resolveManagerListEmployeeFilter(
					$actorUserId,
					$isAdmin,
					$employeeId,
					$employees,
					$scopedLookup,
					'view_employee_time_entries_user_filter',
				);
				if ($resolved === null) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Access denied. You can only view time entries for employees in your scope.'),
					], Http::STATUS_FORBIDDEN);
				}
				$employees = $resolved['employees'];
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

			$rangeLimitResponse = $this->managerListDateRangeLimitResponse($start, $endExclusive);
			if ($rangeLimitResponse !== null) {
				return $rangeLimitResponse;
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
				$row = $entry->getSummary();
				$row['displayName'] = $this->getDisplayName($entry->getUserId());
				$entryRows[] = $row;
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
			$allowedTypes = AbsenceTypeCatalog::all();
			$statusFilter = ($status !== null && $status !== '' && in_array($status, $allowedStatuses, true)) ? $status : null;
			$typeFilter = ($type !== null && $type !== '' && in_array($type, $allowedTypes, true)) ? $type : null;

			$scopedUserIds = $isAdmin
				? $this->permissionService->listAppAccessUserIds()
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
				$resolved = $this->resolveManagerListEmployeeFilter(
					$actorUserId,
					$isAdmin,
					$employeeId,
					$employees,
					$scopedLookup,
					'view_employee_absences_user_filter',
				);
				if ($resolved === null) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Access denied. You can only view absences for employees in your scope.'),
					], Http::STATUS_FORBIDDEN);
				}
				$employees = $resolved['employees'];
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
			// Use midnight of the end date; AbsenceMapper compares on DATE columns so
			// only Y-m-d matters, but midnight is the canonical correct value.
			$end = new \DateTimeImmutable($endDate . ' 00:00:00');
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Invalid date range. Please use valid dates in YYYY-MM-DD format.'),
			], Http::STATUS_BAD_REQUEST);
		}

			$endExclusive = $end->modify('+1 day');
			$rangeLimitResponse = $this->managerListDateRangeLimitResponse($start, $endExclusive);
			if ($rangeLimitResponse !== null) {
				return $rangeLimitResponse;
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
					'durationHours' => $absence->getDurationHours(),
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
	 * Schedule-aware vacation hours estimate for a managed employee (full-day range).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function estimateEmployeeVacationHours(): JSONResponse
	{
		try {
			$actorUserId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($actorUserId, 'estimate_employee_vacation_hours');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

			$targetUserId = trim((string)$this->request->getParam('userId', ''));
			$startDate = trim((string)$this->request->getParam('startDate', ''));
			$endDate = trim((string)$this->request->getParam('endDate', $startDate));
			if ($targetUserId === '' || $startDate === '' || $endDate === '') {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('userId, startDate, and endDate are required.'),
				], Http::STATUS_BAD_REQUEST);
			}
			if (!$this->permissionService->canManageEmployee($actorUserId, $targetUserId)) {
				$this->permissionService->logPermissionDenied($actorUserId, 'estimate_employee_vacation_hours', 'user', $targetUserId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only record absences for employees you manage.'),
				], Http::STATUS_FORBIDDEN);
			}
			// After the scope check (fails closed for non-admin managers):
			// reject ghost uids for admin callers too.
			if ($this->userManager->get($targetUserId) === null) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('The selected user does not exist or is disabled.'),
				], Http::STATUS_NOT_FOUND);
			}
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Dates must be in YYYY-MM-DD format.'),
				], Http::STATUS_BAD_REQUEST);
			}
			$start = \DateTime::createFromFormat('!Y-m-d', $startDate);
			$end = \DateTime::createFromFormat('!Y-m-d', $endDate);
			if ($start === false || $end === false) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Dates must be in YYYY-MM-DD format.'),
				], Http::STATUS_BAD_REQUEST);
			}
			/** @var \OCA\ArbeitszeitCheck\Service\VacationHoursDebitService $debit */
			$debit = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\VacationHoursDebitService::class);
			$est = $debit->estimateForUserRange($targetUserId, $start, $end);
			return new JSONResponse([
				'success' => true,
				'hours' => $est['hours'],
				'basis' => $est['basis'],
				'average_daily' => $est['average_daily'],
				'one_day_hours' => $est['one_day_hours'],
				'weekday_nets' => $est['weekday_nets'],
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::estimateEmployeeVacationHours',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Create an absence for a team member and store it as already approved (migration / HR corrections).
	 * JSON body: { "userId": "…", "type": "vacation", "startDate": "YYYY-MM-DD", "endDate": "YYYY-MM-DD", "reason": "…" (optional) }
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createEmployeeAbsence(): JSONResponse
	{
		try {
			$actorUserId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($actorUserId, 'create_employee_absence');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

			// Public OCP API only: Request::getContent() is protected (NC 34+) and is not on IRequest.
			// JSON / form bodies are already mapped into getParams() by AppFramework (same as createEmployeeTimeEntry).
			$payload = $this->request->getParams();

			$targetUserId = $this->requestParamString($payload, 'userId');
			$type = $this->requestParamString($payload, 'type');
			$startDate = $this->requestParamString($payload, 'startDate');
			$endDate = $this->requestParamString($payload, 'endDate');
			$reason = $this->requestParamString($payload, 'reason');

			if ($targetUserId === '' || $type === '' || $startDate === '' || $endDate === '') {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('userId, type, startDate, and endDate are required.'),
				], Http::STATUS_BAD_REQUEST);
			}

			if ($targetUserId === $actorUserId) {
				return $this->selfTargetForbiddenResponse('absences', 'create_employee_absence', $targetUserId);
			}
			if (!$this->permissionService->canManageEmployee($actorUserId, $targetUserId)) {
				$this->permissionService->logPermissionDenied($actorUserId, 'create_employee_absence', 'user', $targetUserId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only record absences for employees you manage.'),
				], Http::STATUS_FORBIDDEN);
			}

			$targetUser = $this->userManager->get($targetUserId);
			if ($targetUser === null || !$targetUser->isEnabled()) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('The selected user does not exist or is disabled.'),
				], Http::STATUS_BAD_REQUEST);
			}

			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Dates must be in YYYY-MM-DD format.'),
				], Http::STATUS_BAD_REQUEST);
			}

			if ((function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason)) > 8000) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Reason is too long.'),
				], Http::STATUS_BAD_REQUEST);
			}

			try {
				$ds = new \DateTime($startDate);
				$de = new \DateTime($endDate);
				$de->setTime(23, 59, 59);
				$this->monthClosureService->assertDateRangeMutable($targetUserId, $ds, $de);
			} catch (MonthFinalizedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.'),
				], Http::STATUS_CONFLICT);
			} catch (\Throwable $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid date range.'),
				], Http::STATUS_BAD_REQUEST);
			}

			$data = [
				'type' => $type,
				'start_date' => $startDate,
				'end_date' => $endDate,
				'reason' => $reason !== '' ? $reason : null,
			];
			if (array_key_exists('durationHours', $payload) || array_key_exists('duration_hours', $payload)) {
				$dh = $payload['durationHours'] ?? $payload['duration_hours'];
				$data['duration_hours'] = is_array($dh) ? (string)reset($dh) : (string)$dh;
			}
			// Form-encoded "false"/"0" are non-empty strings — never truthy here.
			$truthy = static function (mixed $v): bool {
				$v = is_array($v) ? reset($v) : $v;
				return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
			};
			if ($truthy($payload['requireDurationHours'] ?? null) || $truthy($payload['require_duration_hours'] ?? null)) {
				$data['require_duration_hours'] = true;
			}
			if ($truthy($payload['serverMayFillHours'] ?? null) || $truthy($payload['server_may_fill_hours'] ?? null)) {
				$data['server_may_fill_hours'] = true;
			}
			// day_fraction only (SEC-02): never copy request `days` / `working_days`.
			if (array_key_exists('dayFraction', $payload) || array_key_exists('day_fraction', $payload)) {
				$df = $payload['dayFraction'] ?? $payload['day_fraction'];
				$data['day_fraction'] = is_array($df) ? (string)reset($df) : (string)$df;
			}

			try {
				$absence = $this->absenceService->createApprovedAbsenceForEmployeeByManager($actorUserId, $targetUserId, $data);
			} catch (CatalogBusyException $e) {
				return new JSONResponse($e->toHttpPayload(), Http::STATUS_CONFLICT);
			} catch (AbsenceMutationBusyException $e) {
				return new JSONResponse($e->toHttpPayload(), Http::STATUS_LOCKED);
			} catch (BusinessRuleException $e) {
				\OCP\Log\logger('arbeitszeitcheck')->info(
					'ManagerController::createEmployeeAbsence business rule: ' . $e->getMessage(),
					['exception' => $e]
				);
				$payloadOut = [
					'success' => false,
					'error' => $this->l10n->t($e->getMessage()),
				];
				$code = $e->getReasonCode();
				if ($code !== null && $code !== '') {
					$payloadOut['code'] = $code;
					$payloadOut['error_code'] = $code;
				}
				return new JSONResponse($payloadOut, Http::STATUS_BAD_REQUEST);
			} catch (\Exception $e) {
				\OCP\Log\logger('arbeitszeitcheck')->info(
					'ManagerController::createEmployeeAbsence validation or business rule: ' . $e->getMessage(),
					['exception' => $e]
				);
				$payloadOut = [
					'success' => false,
					'error' => $this->l10n->t($e->getMessage()),
				];
				if (preg_match('/\[([A-Z0-9_]{3,64})\]\s*$/', $e->getMessage(), $m) === 1) {
					$payloadOut['code'] = $m[1];
					$payloadOut['error_code'] = $m[1];
				}
				return new JSONResponse($payloadOut, Http::STATUS_BAD_REQUEST);
			} catch (\Throwable $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error(
					'ManagerController::createEmployeeAbsence unexpected failure: ' . $e->getMessage(),
					['exception' => $e]
				);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
				], Http::STATUS_INTERNAL_SERVER_ERROR);
			}

			return new JSONResponse([
				'success' => true,
				'absence' => $absence->getSummary(),
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::createEmployeeAbsence',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * ProjectCheck projects a manager may link when creating or correcting employee time.
	 */
	#[NoAdminRequired]
	public function getManagerAssignableProjectcheckProjects(string $employeeUid): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'manager_assignable_projectcheck_projects');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

			$employeeId = trim($employeeUid);
			if ($employeeId === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Employee is required.')], Http::STATUS_BAD_REQUEST);
			}

			if (!$this->permissionService->canManageEmployee($managerId, $employeeId)) {
				$this->permissionService->logPermissionDenied($managerId, 'manager_assignable_projectcheck_projects', 'user', $employeeId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only manage employees in your scope.'),
				], Http::STATUS_FORBIDDEN);
			}

			// After the scope check (which fails closed for non-admin managers),
			// reject ghost uids so admin callers cannot write/list against
			// nonexistent employees.
			if ($this->userManager->get($employeeId) === null) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Employee not found'),
				], Http::STATUS_NOT_FOUND);
			}

			if (!$this->projectCheckIntegration->isAdminIntegrationEnabled()) {
				return new JSONResponse(['success' => true, 'projects' => []]);
			}

			return new JSONResponse([
				'success' => true,
				'projects' => $this->projectCheckIntegration->getAssignableProjectsForManagerOnBehalfOfEmployee($managerId, $employeeId),
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::getManagerAssignableProjectcheckProjects', ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Create a completed manual time entry for a managed employee (HR / migration corrections).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function createEmployeeTimeEntry(): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'create_employee_time_entry');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

			$params = $this->request->getParams();
			$targetUserId = trim((string)($params['userId'] ?? $params['employeeId'] ?? ''));
			$reason = trim((string)($params['reason'] ?? ''));
			if ($targetUserId === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Employee is required.')], Http::STATUS_BAD_REQUEST);
			}
			if (mb_strlen($reason) < 10) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('A reason of at least 10 characters is required.'),
				], Http::STATUS_BAD_REQUEST);
			}
			if (mb_strlen($reason) > 2000) {
				$reason = mb_substr($reason, 0, 2000);
			}

			if ($targetUserId === $managerId) {
				return $this->selfTargetForbiddenResponse('time-entries', 'create_employee_time_entry', $targetUserId);
			}
			if (!$this->permissionService->canManageEmployee($managerId, $targetUserId)) {
				$this->permissionService->logPermissionDenied($managerId, 'create_employee_time_entry', 'user', $targetUserId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Access denied. You can only record time for employees you manage.'),
				], Http::STATUS_FORBIDDEN);
			}

			$targetUser = $this->userManager->get($targetUserId);
			if ($targetUser === null || !$targetUser->isEnabled()) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('The selected user does not exist or is disabled.'),
				], Http::STATUS_BAD_REQUEST);
			}

			$minBreakMinutes = null;
			try {
				$minBreakMinutes = \OCP\Server::get(\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::class)
					->getProfile($targetUserId)->minBreakMinutes;
			} catch (\Throwable) {
				$minBreakMinutes = null;
			}
			$proposal = TimeEntryClockPayloadBuilder::mergeIntoProposal($params, [], $minBreakMinutes);
			if (!isset($proposal['startTime'], $proposal['endTime'])) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Date, start time, and end time are required.'),
				], Http::STATUS_BAD_REQUEST);
			}
			if (array_key_exists('description', $params)) {
				$proposal['description'] = $params['description'];
			}

			// GH #43: optional location mark; invalid values throw
			// InvalidArgumentException → 400 via the outer catch.
			if (array_key_exists('workLocation', $params) || array_key_exists('work_location', $params)) {
				$proposal['workLocation'] = \OCA\ArbeitszeitCheck\Support\WorkLocation::normalize(
					$params['workLocation'] ?? $params['work_location']
				);
			}

			$rawProjectId = $params['projectCheckProjectId'] ?? $params['project_check_project_id'] ?? null;
			$projectIdStr = $this->normalizeOptionalProjectCheckProjectId($rawProjectId);
			if ($projectIdStr !== null) {
				$proposal['projectCheckProjectId'] = $projectIdStr;
			}

			$entry = new TimeEntry();
			$entry->setUserId($targetUserId);
			$entry->setIsManualEntry(true);
			$nowAt = AppLocalNaiveDateTimeNormalizer::nowMutableInAppStorage($this->config);
			$entry->setCreatedAt(clone $nowAt);
			$entry->setUpdatedAt(clone $nowAt);

			try {
				$saved = $this->correctionService->createManagerRecordedEntry($entry, $proposal, $managerId, $reason);
			} catch (\InvalidArgumentException $e) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_BAD_REQUEST);
			}

			$this->auditLogMapper->logAction(
				$targetUserId,
				'time_entry_manager_created',
				'time_entry',
				$saved->getId(),
				null,
				$saved->getSummary(),
				$managerId
			);

			$this->notificationService->notifyTimeEntryCorrectedByManager(
				$targetUserId,
				$saved->getSummary(),
				$reason
			);

			return new JSONResponse([
				'success' => true,
				'entry' => $saved->getSummary(),
				'billing_warning' => $saved->getBillingSyncWarning(),
				'message' => $this->l10n->t('Time entry recorded for the employee.'),
			], Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t($e->getMessage())], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::createEmployeeTimeEntry', ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function normalizeOptionalProjectCheckProjectId(mixed $raw): ?string
	{
		if ($raw === null) {
			return null;
		}
		$str = trim((string)$raw);
		if ($str === '') {
			return null;
		}
		if (!ctype_digit($str) || (int)$str <= 0) {
			return null;
		}
		if (mb_strlen($str) > TimeEntry::PROJECT_CHECK_PROJECT_ID_MAX_LENGTH) {
			return null;
		}
		return $str;
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
						
						$requestType = (($justificationData['type'] ?? '') === 'manual_create')
							? 'manual_create'
							: 'correction';
						$pendingApprovals[] = [
							'id' => $entry->getId(),
							'type' => 'time_entry',
							'userId' => $entry->getUserId(),
							'displayName' => $this->getDisplayName($entry->getUserId()),
							'summary' => [
								// Render the affected employee's day and clock in *their* display TZ.
								'date' => $this->displayDate($startTime, $entry->getUserId()),
								'startTime' => $this->displayClock($startTime, $entry->getUserId()),
								'endTime' => $this->displayClock($endTime, $entry->getUserId()),
								'durationHours' => $entry->getDurationHours(),
								'paidDurationHours' => $entry->getPaidDurationHours(),
								'paidBreakSeconds' => $entry->getPaidBreakSeconds(),
								'description' => $entry->getDescription(),
								'justification' => $justificationData['justification'] ?? '',
								'requestType' => $requestType,
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
	public function getTeamOvertimeAlerts(): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'view_team_overtime_alerts');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

			$teamUserIds = $this->getTeamMemberIds($managerId);
			$snapshots = $this->overtimeDisplayService->buildManagerMemberSnapshots($teamUserIds);
			$members = [];
			foreach ($teamUserIds as $userId) {
				$snapshot = $snapshots[$userId] ?? null;
				if ($snapshot === null || !$snapshot['needs_attention']) {
					continue;
				}
				$members[] = array_merge([
					'userId' => $userId,
					'displayName' => $this->getDisplayName($userId),
				], $snapshot);
			}

			return new JSONResponse([
				'success' => true,
				'features' => [
					'traffic_light_enabled' => $this->overtimeTrafficLightService->isEnabled(),
					'bank_enabled' => $this->overtimeBankService->isEnabled(),
				],
				'members' => $members,
				'total_team' => count($teamUserIds),
				'alert_count' => count($members),
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::getTeamOvertimeAlerts',
				['exception' => $e]
			);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportTeamOvertimeCsv(?int $months = null): DataDownloadResponse|JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'export_team_overtime');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

			// B-4(b): optional per-month delta columns. One OvertimeService
			// calculation per window (getCumulativeWorkDelta), never a second
			// formula. Capped at 24 months.
			$monthWindows = [];
			$monthsCount = $months !== null ? min(24, max(0, $months)) : 0;
			if ($monthsCount > 0) {
				$cursor = $this->timeZoneService->nowInStorage();
				$cursor->modify('first day of this month')->setTime(0, 0, 0);
				for ($i = 0; $i < $monthsCount; $i++) {
					$monthStart = clone $cursor;
					$monthEnd = (clone $cursor)->modify('+1 month');
					// Current month: only count up to now (a full-month window
					// would project future required hours into the delta).
					$now = $this->timeZoneService->nowInStorage();
					if ($i === 0 && $monthEnd > $now) {
						$monthEnd = $now;
					}
					$monthWindows[] = ['label' => $monthStart->format('Y-m'), 'from' => $monthStart, 'to' => $monthEnd];
					$cursor->modify('-1 month');
				}
			}

			$header = 'user_id;display_name;balance_h;balance_type;traffic_light;bank_state;bank_fill_pct;payout_eligible_h;needs_attention';
			foreach ($monthWindows as $w) {
				$header .= ';delta_' . $w['label'] . '_h';
			}
			$teamUserIds = $this->getTeamMemberIds($managerId);
			$snapshots = $this->overtimeDisplayService->buildManagerMemberSnapshots($teamUserIds);
			$lines = [$header . "\n"];
			foreach ($teamUserIds as $userId) {
				$snapshot = $snapshots[$userId] ?? [];
				$row = [
					$this->csvEscape($userId),
					$this->csvEscape($this->getDisplayName($userId)),
					number_format((float)$snapshot['balance'], 2, '.', ''),
					$this->csvEscape((string)($snapshot['balance_label'] ?? '')),
					$this->csvEscape((string)($snapshot['traffic_light_state'] ?? '')),
					$this->csvEscape((string)($snapshot['bank_state'] ?? '')),
					$snapshot['bank_fill_percent'] !== null ? number_format((float)$snapshot['bank_fill_percent'], 1, '.', '') : '',
					$snapshot['payout_eligible_hours'] !== null ? number_format((float)$snapshot['payout_eligible_hours'], 2, '.', '') : '',
					$snapshot['needs_attention'] ? 'yes' : 'no',
				];
				foreach ($monthWindows as $w) {
					$row[] = number_format(
						$this->overtimeService->getCumulativeWorkDelta($userId, $w['from'], $w['to']),
						2, '.', ''
					);
				}
				$lines[] = implode(';', $row) . "\n";
			}

			$csv = "\xEF\xBB\xBF" . implode('', $lines);
			$filename = 'team-overtime-' . date('Y-m-d') . '.csv';

			return new DataDownloadResponse($csv, $filename, 'text/csv; charset=UTF-8');
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::exportTeamOvertimeCsv',
				['exception' => $e]
			);

			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * B-4(a): team absences grouped by type, manager-scoped.
	 *
	 * One CSV row per member × absence type inside the period (default:
	 * current calendar year, approved only). `days` sums the scheduled days
	 * of the overlapping entries; `entries` counts them.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function exportTeamAbsencesCsv(
		?string $start_date = null,
		?string $end_date = null,
		?string $type = null,
		?string $status = \OCA\ArbeitszeitCheck\Db\Absence::STATUS_APPROVED,
	): DataDownloadResponse|JSONResponse {
		try {
			$managerId = $this->getUserId();
			$accessResponse = $this->ensureManagerReadAccess($managerId, 'export_team_absences');
			if ($accessResponse !== null) {
				return $accessResponse;
			}

			$start = $this->parseReportDate($start_date, $this->timeZoneService->nowInStorage()->modify('first day of january this year'));
			$end = $this->parseReportDate($end_date, $this->timeZoneService->nowInStorage()->modify('last day of december this year'));
			if ($start === null || $end === null || $start > $end) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid date range.'),
				], Http::STATUS_BAD_REQUEST);
			}
			if ($type !== null && $type !== '' && !AbsenceTypeCatalog::isValid($type)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid absence type.'),
				], Http::STATUS_BAD_REQUEST);
			}

			$teamUserIds = $this->getTeamMemberIds($managerId);
			$absences = $this->absenceMapper->findByUsersAndDateRange($teamUserIds, $start, $end, $status, $type);

			// Aggregate per member × type (server-side scope — never a client filter).
			$agg = [];
			foreach ($absences as $absence) {
				$key = $absence->getUserId() . '|' . $absence->getType();
				if (!isset($agg[$key])) {
					$agg[$key] = ['user' => $absence->getUserId(), 'type' => $absence->getType(), 'days' => 0.0, 'count' => 0];
				}
				$agg[$key]['days'] += (float)($absence->getDays() ?? 0.0);
				$agg[$key]['count']++;
			}
			uasort($agg, static fn ($a, $b) => [$a['user'], $a['type']] <=> [$b['user'], $b['type']]);

			$lines = ["user_id;display_name;type;days;entries\n"];
			foreach ($agg as $row) {
				$lines[] = implode(';', [
					$this->csvEscape($row['user']),
					$this->csvEscape($this->getDisplayName($row['user'])),
					$this->csvEscape($row['type']),
					number_format($row['days'], 2, '.', ''),
					(string)$row['count'],
				]) . "\n";
			}

			$csv = "\xEF\xBB\xBF" . implode('', $lines);
			$filename = sprintf('team-absences-%s_bis_%s.csv', $start->format('Y-m-d'), $end->format('Y-m-d'));
			return new DataDownloadResponse($csv, $filename, 'text/csv; charset=UTF-8');
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Error in ManagerController::exportTeamAbsencesCsv',
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Strict ISO `YYYY-MM-DD` for report params; falls back to $default on null.
	 * Rejects anything else (no silent DateTime parsing surprises).
	 */
	private function parseReportDate(?string $raw, \DateTime $default): ?\DateTime
	{
		if ($raw === null || trim($raw) === '') {
			return $default;
		}
		$raw = trim($raw);
		$d = \DateTime::createFromFormat('!Y-m-d', $raw);
		$errors = \DateTime::getLastErrors();
		if ($d === false
			|| ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
			|| $d->format('Y-m-d') !== $raw) {
			return null;
		}
		return $d;
	}

	private function csvEscape(string $value): string
	{
		// Neutralize formula injection in spreadsheet clients.
		if ($value !== '' && preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
			$value = "'" . $value;
		}
		if (str_contains($value, ';') || str_contains($value, '"') || str_contains($value, "\n")) {
			return '"' . str_replace('"', '""', $value) . '"';
		}

		return $value;
	}

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
						'recentViolations' => [],
						'members' => [],
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
				'recentViolations' => [],
				'members' => [],
			];

			$statusByUser = $this->complianceService->getComplianceStatusForUsers($teamUserIds);
			foreach ($teamUserIds as $userId) {
				$status = $statusByUser[$userId] ?? $this->complianceService->getComplianceStatus($userId);

				$bucket = 'compliant';
				if (!$status['compliant']) {
					if ($status['critical_violations'] > 0) {
						$complianceOverview['membersWithViolations']++;
						$bucket = 'critical';
					} else {
						$complianceOverview['membersWithWarnings']++;
						$bucket = 'warning';
					}
				} else {
					$complianceOverview['compliantMembers']++;
				}

				$complianceOverview['totalViolations'] += $status['violation_count'];
				$complianceOverview['unresolvedViolations'] += $status['violation_count'];

				$violationsUrl = $this->urlGenerator->linkToRoute(
					'arbeitszeitcheck.compliance.violations',
					['userId' => $userId]
				);

				$complianceOverview['members'][] = [
					'userId' => $userId,
					'displayName' => $this->getDisplayName($userId),
					'bucket' => $bucket,
					'compliant' => (bool)$status['compliant'],
					'violationCount' => (int)$status['violation_count'],
					'criticalViolations' => (int)$status['critical_violations'],
					'warningViolations' => (int)$status['warning_violations'],
					'complianceScore' => (int)$status['score'],
					'violationsUrl' => $violationsUrl,
				];
			}

			usort($complianceOverview['members'], static function (array $a, array $b): int {
				$order = ['critical' => 0, 'warning' => 1, 'compliant' => 2];
				$oa = $order[$a['bucket']] ?? 3;
				$ob = $order[$b['bucket']] ?? 3;
				if ($oa !== $ob) {
					return $oa <=> $ob;
				}
				return strcasecmp((string)$a['displayName'], (string)$b['displayName']);
			});

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

			$now = $this->timeZoneService->nowInStorage();
			[$todayStart, $todayEnd] = $this->timeZoneService->todayWindowInStorage();

			if ($period === 'today') {
				$start = $todayStart;
				$end = $todayEnd;
			} elseif ($period === 'week') {
				$start = clone $todayStart;
				$dayOfWeek = (int)$now->format('w');
				$start->modify('-' . $dayOfWeek . ' days');
				$end = (clone $start)->modify('+7 days');
			} else {
				$year = (int)$now->format('Y');
				$month = (int)$now->format('n');
				[$start, $end] = $this->timeZoneService->monthWindowInStorage($year, $month);
			}

			// Batched hours fetch — one entry query for the whole scope instead
			// of a per-user-per-day loop.
			$hoursByUser = $this->timeTrackingService->getWorkingHoursForUsersInPeriod($teamUserIds, $start, $end);

			foreach ($teamUserIds as $userId) {
				$hours = $hoursByUser[$userId] ?? 0.0;
				if ($period === 'today') {
					$overtimeData = $this->overtimeService->getDailyOvertime($userId);
				} else {
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
	#[NoCSRFRequired]
	public function approveAbsence(int $absenceId, ?string $comment = null): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$absence = $this->absenceMapper->find($absenceId);
			// Scope check BEFORE the pending-status check: an out-of-scope caller must
			// not learn whether the absence exists or was decided (BOLA oracle).
			if (!$this->permissionService->canManageEmployee($managerId, $absence->getUserId())) {
				$this->permissionService->logPermissionDenied($managerId, 'approve_absence', 'absence', (string) $absenceId);
				// Collapse out-of-scope to the same 404 as a missing id (existence oracle).
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Absence not found')
				], Http::STATUS_NOT_FOUND);
			}
			// Already decided → 409 so clients uniformly refresh (same envelope as time-entry races).
			if ($absence->getStatus() !== \OCA\ArbeitszeitCheck\Db\Absence::STATUS_PENDING) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This absence was already decided by another manager.'),
					'error_code' => 'already_decided',
				], Http::STATUS_CONFLICT);
			}
			try {
				$absence = $this->absenceService->approveAbsence($absenceId, $managerId, $comment);
			} catch (ConcurrentDecisionException $e) {
				return new JSONResponse($e->toHttpPayload(), Http::STATUS_CONFLICT);
			} catch (\OCP\Lock\LockedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This absence was already decided by another manager.'),
					'error_code' => 'already_decided',
				], Http::STATUS_CONFLICT);
			}

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
	#[NoCSRFRequired]
	public function rejectAbsence(int $absenceId, ?string $comment = null): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$absence = $this->absenceMapper->find($absenceId);
			// Scope check BEFORE the pending-status check (BOLA oracle — see approveAbsence()).
			if (!$this->permissionService->canManageEmployee($managerId, $absence->getUserId())) {
				$this->permissionService->logPermissionDenied($managerId, 'reject_absence', 'absence', (string) $absenceId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Absence not found')
				], Http::STATUS_NOT_FOUND);
			}
			if ($absence->getStatus() !== \OCA\ArbeitszeitCheck\Db\Absence::STATUS_PENDING) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This absence was already decided by another manager.'),
					'error_code' => 'already_decided',
				], Http::STATUS_CONFLICT);
			}
			try {
				$absence = $this->absenceService->rejectAbsence($absenceId, $managerId, $comment);
			} catch (ConcurrentDecisionException $e) {
				return new JSONResponse($e->toHttpPayload(), Http::STATUS_CONFLICT);
			} catch (\OCP\Lock\LockedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This absence was already decided by another manager.'),
					'error_code' => 'already_decided',
				], Http::STATUS_CONFLICT);
			}

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
	#[NoCSRFRequired]
	public function approveTimeEntryCorrection(int $timeEntryId, ?string $comment = null): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($timeEntryId);

			// Verify manager may manage this employee (admin or team) BEFORE the
			// pending-status check: an out-of-scope caller must not learn whether
			// the entry exists or was decided (BOLA oracle).
			if (!$this->permissionService->canManageEmployee($managerId, $entry->getUserId())) {
				$this->permissionService->logPermissionDenied($managerId, 'approve_time_entry_correction', 'time_entry', (string) $timeEntryId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Time entry not found')
				], Http::STATUS_NOT_FOUND);
			}

			// Verify entry is pending approval — already decided → 409 so clients
			// uniformly refresh (same envelope as ConcurrentDecisionException).
			if ($entry->getStatus() !== \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This time entry was already decided by another manager.'),
					'error_code' => 'already_decided',
				], Http::STATUS_CONFLICT);
			}

			try {
				$this->monthClosureGuard->assertTimeEntryMutable($entry);
			} catch (MonthFinalizedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.'),
					'error_code' => 'month_finalized',
				], Http::STATUS_CONFLICT);
			}

			if (is_string($comment)) {
				$comment = trim($comment);
				if (mb_strlen($comment) > 2000) {
					$comment = mb_substr($comment, 0, 2000);
				}
				if ($comment === '') {
					$comment = null;
				}
			} else {
				$comment = null;
			}

			$oldValues = $entry->getSummary();
			try {
				$updatedEntry = $this->correctionService->approve($entry, $managerId, $comment);
			} catch (\InvalidArgumentException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t($e->getMessage())
				], Http::STATUS_BAD_REQUEST);
			} catch (ConcurrentDecisionException $e) {
				return new JSONResponse($e->toHttpPayload(), Http::STATUS_CONFLICT);
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
				'billing_warning' => $updatedEntry->getBillingSyncWarning(),
				'message' => $this->l10n->t('Time entry correction approved successfully')
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Time entry not found')
			], Http::STATUS_NOT_FOUND);
		} catch (ConcurrentDecisionException $e) {
			return new JSONResponse($e->toHttpPayload(), Http::STATUS_CONFLICT);
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
	#[NoCSRFRequired]
	public function rejectTimeEntryCorrection(int $timeEntryId, ?string $reason = null): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($timeEntryId);

			// Verify manager may manage this employee (admin or team) BEFORE the
			// pending-status check (BOLA oracle — see approveTimeEntryCorrection()).
			if (!$this->permissionService->canManageEmployee($managerId, $entry->getUserId())) {
				$this->permissionService->logPermissionDenied($managerId, 'reject_time_entry_correction', 'time_entry', (string) $timeEntryId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Time entry not found')
				], Http::STATUS_NOT_FOUND);
			}

			// Verify entry is pending approval — already decided → 409 so clients
			// uniformly refresh (same envelope as ConcurrentDecisionException).
			if ($entry->getStatus() !== \OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This time entry was already decided by another manager.'),
					'error_code' => 'already_decided',
				], Http::STATUS_CONFLICT);
			}

			$oldValues = $entry->getSummary();

			try {
				$this->monthClosureGuard->assertTimeEntryMutable($entry);
			} catch (MonthFinalizedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.'),
					'error_code' => 'month_finalized',
				], Http::STATUS_CONFLICT);
			}

			if (is_string($reason)) {
				$reason = trim($reason);
				if (mb_strlen($reason) > 2000) {
					$reason = mb_substr($reason, 0, 2000);
				}
				if ($reason === '') {
					$reason = null;
				}
			} else {
				$reason = null;
			}

			$updatedEntry = $this->correctionService->reject($entry, $managerId, $reason);

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
		} catch (ConcurrentDecisionException $e) {
			return new JSONResponse($e->toHttpPayload(), Http::STATUS_CONFLICT);
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
	 * Apply a manager-initiated correction directly (audit trail + employee notification).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function correctTimeEntry(int $timeEntryId): JSONResponse
	{
		try {
			$managerId = $this->getUserId();
			$entry = $this->timeEntryMapper->find($timeEntryId);

			if ($entry->getUserId() === $managerId) {
				return $this->selfTargetForbiddenResponse('time-entries', 'correct_time_entry', (string)$timeEntryId);
			}
			if (!$this->permissionService->canManageEmployee($managerId, $entry->getUserId())) {
				$this->permissionService->logPermissionDenied($managerId, 'correct_time_entry', 'time_entry', (string)$timeEntryId);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Time entry not found')
				], Http::STATUS_NOT_FOUND);
			}

			if ($entry->getStatus() === TimeEntry::STATUS_PENDING_APPROVAL) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Resolve the pending employee correction first (approve or reject).'),
					'error_code' => 'correction_pending',
				], Http::STATUS_CONFLICT);
			}

			$params = $this->request->getParams();
			$expectedUpdatedAt = trim((string)($params['expectedUpdatedAt'] ?? ''));
			if ($expectedUpdatedAt !== '' && $entry->getUpdatedAt() !== null) {
				$currentUpdatedAt = $entry->getUpdatedAt()->format('c');
				if ($currentUpdatedAt !== $expectedUpdatedAt) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('This time entry was changed by someone else. Please reload and try again.'),
						'error_code' => 'entry_modified',
					], Http::STATUS_CONFLICT);
				}
			}
			$reason = trim((string)($params['reason'] ?? ''));
			if (mb_strlen($reason) < 10) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('A reason of at least 10 characters is required.')
				], Http::STATUS_BAD_REQUEST);
			}
			// Bound for DB/audit-log safety.
			if (mb_strlen($reason) > 2000) {
				$reason = mb_substr($reason, 0, 2000);
			}

			$minBreakMinutes = null;
			try {
				$minBreakMinutes = \OCP\Server::get(\OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory::class)
					->getProfile($entry->getUserId())->minBreakMinutes;
			} catch (\Throwable) {
				$minBreakMinutes = null;
			}
			$proposal = TimeEntryClockPayloadBuilder::mergeIntoProposal($params, [], $minBreakMinutes);
			if (array_key_exists('description', $params)) {
				$proposal['description'] = $params['description'];
			}

			// GH #43: optional location mark; invalid values throw
			// InvalidArgumentException → 400 via the catch below.
			if (array_key_exists('workLocation', $params) || array_key_exists('work_location', $params)) {
				$proposal['workLocation'] = \OCA\ArbeitszeitCheck\Support\WorkLocation::normalize(
					$params['workLocation'] ?? $params['work_location']
				);
			}
			$rawProjectId = $params['projectCheckProjectId'] ?? $params['project_check_project_id'] ?? null;
			$projectIdStr = $this->normalizeOptionalProjectCheckProjectId($rawProjectId);
			if ($projectIdStr !== null) {
				$proposal['projectCheckProjectId'] = $projectIdStr;
			} elseif (\array_key_exists('projectCheckProjectId', $params) || \array_key_exists('project_check_project_id', $params)) {
				$proposal['projectCheckProjectId'] = null;
			}
			if ($proposal === []) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('At least one field to correct is required.')
				], Http::STATUS_BAD_REQUEST);
			}

			try {
				$this->monthClosureGuard->assertTimeEntryMutable($entry);
			} catch (MonthFinalizedException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('This calendar month is finalized. Contact an administrator if a correction must be made.'),
					'error_code' => 'month_finalized',
				], Http::STATUS_CONFLICT);
			}

			$oldValues = $entry->getSummary();
			try {
				$updatedEntry = $this->correctionService->applyManagerCorrection($entry, $proposal, $managerId, $reason);
			} catch (\InvalidArgumentException $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t($e->getMessage())
				], Http::STATUS_BAD_REQUEST);
			}

			$this->auditLogMapper->logAction(
				$entry->getUserId(),
				'time_entry_manager_corrected',
				'time_entry',
				$timeEntryId,
				$oldValues,
				$updatedEntry->getSummary(),
				$managerId
			);

			$this->notificationService->notifyTimeEntryCorrectedByManager(
				$entry->getUserId(),
				$updatedEntry->getSummary(),
				$reason
			);

			return new JSONResponse([
				'success' => true,
				'entry' => $updatedEntry->getSummary(),
				'billing_warning' => $updatedEntry->getBillingSyncWarning(),
				'message' => $this->l10n->t('Time entry corrected successfully.')
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Time entry not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ManagerController::correctTimeEntry', ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An internal error occurred. Please contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
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

			// Batched IN() — org-wide admin scope must not loop per user.
			$pendingEntries = $this->timeEntryMapper->findPendingApprovalForUsers($teamUserIds);

			foreach ($pendingEntries as $entry) {
				try {
					// Parse justification to get correction details
					$justificationData = json_decode($entry->getJustification() ?? '{}', true);

					$requestType = (($justificationData['type'] ?? '') === 'manual_create')
						? 'manual_create'
						: 'correction';
					$corrections[] = [
						'id' => $entry->getId(),
						'userId' => $entry->getUserId(),
						'displayName' => $this->getDisplayName($entry->getUserId()),
						'startTime' => ($startTime = $entry->getStartTime()) ? $startTime->format('c') : null,
						'endTime' => $entry->getEndTime() ? $entry->getEndTime()->format('c') : null,
						'durationHours' => $entry->getDurationHours(),
						'paidDurationHours' => $entry->getPaidDurationHours(),
						'paidBreakSeconds' => $entry->getPaidBreakSeconds(),
						'description' => $entry->getDescription(),
						'status' => $entry->getStatus(),
						'justification' => $justificationData['justification'] ?? '',
						'requestType' => $requestType,
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
			$end = $endDate ? new \DateTime($endDate) : $this->timeZoneService->nowInStorage();
			$start = $startDate ? new \DateTime($startDate) : clone $end;
			if (!$startDate) {
				$start->modify('first day of this month')->setTime(0, 0, 0);
			}

			$calendarData = [];

			// Batched IN() — org-wide admin scope must not loop per user.
			$absences = $this->absenceMapper->findByUsersAndDateRange($teamUserIds, $start, $end);

			foreach ($absences as $absence) {
				try {
					$calendarData[] = [
						'id' => $absence->getId(),
						'userId' => $absence->getUserId(),
						'displayName' => $this->getDisplayName($absence->getUserId()),
						'type' => $absence->getType(),
						'startDate' => ($absenceStart = $absence->getStartDate()) ? $absenceStart->format('Y-m-d') : null,
						'endDate' => ($absenceEnd = $absence->getEndDate()) ? $absenceEnd->format('Y-m-d') : null,
						'days' => $absence->getDays(),
						'status' => $absence->getStatus(),
						'summary' => $absence->getSummary()
					];
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error processing absence ' . $absence->getId() . ' in calendar data: ' . $e->getMessage(), ["exception" => $e]);
					continue;
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