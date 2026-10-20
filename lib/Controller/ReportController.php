<?php

declare(strict_types=1);

/**
 * Report controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\ReportingService;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCA\ArbeitszeitCheck\Service\PremiumSurchargeService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeEntryExportTransformer;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\IL10N;

/**
 * ReportController for generating various reports
 */
class ReportController extends Controller
{
	private ReportingService $reportingService;
	private PermissionService $permissionService;
	private TeamResolverService $teamResolver;
	private TeamMemberMapper $teamMemberMapper;
	private TeamManagerMapper $teamManagerMapper;
	private TimeEntryMapper $timeEntryMapper;
	private TimeEntryExportTransformer $timeEntryExportTransformer;
	private IConfig $config;
	private IUserManager $userManager;
	private IUserSession $userSession;
	private IL10N $l10n;
	private MonthClosureService $monthClosureService;
	private PremiumSurchargeService $premiumSurchargeService;

	public function __construct(
		string $appName,
		IRequest $request,
		ReportingService $reportingService,
		PermissionService $permissionService,
		TeamResolverService $teamResolver,
		TeamMemberMapper $teamMemberMapper,
		TeamManagerMapper $teamManagerMapper,
		TimeEntryMapper $timeEntryMapper,
		TimeEntryExportTransformer $timeEntryExportTransformer,
		IConfig $config,
		IUserManager $userManager,
		IUserSession $userSession,
		IL10N $l10n,
		MonthClosureService $monthClosureService,
		PremiumSurchargeService $premiumSurchargeService,
		private readonly ?\OCA\ArbeitszeitCheck\Service\OversightScopeService $oversightScope = null,
	) {
		parent::__construct($appName, $request);
		$this->reportingService = $reportingService;
		$this->permissionService = $permissionService;
		$this->teamResolver = $teamResolver;
		$this->teamMemberMapper = $teamMemberMapper;
		$this->teamManagerMapper = $teamManagerMapper;
		$this->timeEntryMapper = $timeEntryMapper;
		$this->timeEntryExportTransformer = $timeEntryExportTransformer;
		$this->config = $config;
		$this->userManager = $userManager;
		$this->userSession = $userSession;
		$this->l10n = $l10n;
		$this->monthClosureService = $monthClosureService;
		$this->premiumSurchargeService = $premiumSurchargeService;
	}

	/**
	 * Ensure current user may access reports for the given target user.
	 * Allowed: own user, team members (manager), or admin.
	 */
	private function oversightScope(): \OCA\ArbeitszeitCheck\Service\OversightScopeService
	{
		return $this->oversightScope ?? \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\OversightScopeService::class);
	}

	private function ensureCanAccessUserReport(string $currentUserId, string $targetUserId): void
	{
		// Existence leg first — deleted users leave stale member rows, so a
		// membership-only check would accept ghost uids. Nonexistent and
		// out-of-scope collapse into the same denial (no existence oracle).
		if ($this->userManager->get($targetUserId) === null) {
			$this->permissionService->logPermissionDenied($currentUserId, 'view_report', 'report', $targetUserId);
			throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
		}
		if ($this->permissionService->canViewUserReport($currentUserId, $targetUserId)) {
			return;
		}
		$this->permissionService->logPermissionDenied($currentUserId, 'view_report', 'report', $targetUserId);
		throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
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

	private function parseDateYmd(?string $value, string $fieldName): ?\DateTime
	{
		if ($value === null || trim($value) === '') {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		$errors = \DateTimeImmutable::getLastErrors();
		$isValid = $parsed !== false
			&& ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
			&& $parsed->format('Y-m-d') === $value;
		if (!$isValid) {
			throw new \Exception($this->l10n->t('Invalid %s format. Use Y-m-d (e.g. 2024-01-15)', [$fieldName]));
		}
		return new \DateTime($parsed->format('Y-m-d'));
	}

	private function parseMonthYm(?string $value): ?\DateTime
	{
		if ($value === null || trim($value) === '') {
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m', $value);
		$errors = \DateTimeImmutable::getLastErrors();
		$isValid = $parsed !== false
			&& ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
			&& $parsed->format('Y-m') === $value;
		if (!$isValid) {
			throw new \Exception($this->l10n->t('Invalid month format. Use Y-m (e.g. 2024-01)'));
		}
		return new \DateTime($parsed->format('Y-m-01'));
	}

	private function buildSafeApiError(\Throwable $e): array
	{
		$raw = $e->getMessage();
		if (strpos($raw, 'User not authenticated') !== false) {
			return ['status' => Http::STATUS_BAD_REQUEST, 'error' => $this->l10n->t('User not authenticated')];
		}
		if (strpos($raw, 'Access denied') !== false) {
			return ['status' => Http::STATUS_FORBIDDEN, 'error' => $this->l10n->t('Access denied')];
		}

		$knownValidationPrefixes = ['Invalid ', 'Start date', 'Export date range', 'No users', 'Either ', 'Failed to encode report'];
		foreach ($knownValidationPrefixes as $prefix) {
			if (strpos($raw, $prefix) === 0) {
				return ['status' => Http::STATUS_BAD_REQUEST, 'error' => $raw];
			}
		}

		return [
			'status' => Http::STATUS_BAD_REQUEST,
			'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
		];
	}

	/**
	 * Generate daily report
	 *
	 * @param string|null $date Date (Y-m-d format, defaults to today)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function daily(?string $date = null, ?string $userId = null): JSONResponse
	{
		try {
			$currentUserId = $this->getUserId();
			// Special case: admin organization scope (userId="") -> generate report for all enabled users.
			// We must not run per-user permission checks in this mode.
			$reportUserId = null;
			if ($userId === '') {
				if (!$this->permissionService->isAdmin($currentUserId)) {
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
			} else {
				$reportUserId = $userId ?? $currentUserId;
				$this->ensureCanAccessUserReport($currentUserId, $reportUserId);
			}

			$reportDate = $this->parseDateYmd($date, 'date') ?? new \DateTime();
			$report = $this->reportingService->generateDailyReport($reportDate, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			$mapped = $this->buildSafeApiError($e);
			return new JSONResponse([
				'success' => false,
				'error' => $mapped['error']
			], $mapped['status']);
		}
	}

	/**
	 * Generate weekly report
	 *
	 * @param string|null $weekStart Week start date (Y-m-d format, defaults to current week)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function weekly(?string $weekStart = null, ?string $userId = null): JSONResponse
	{
		try {
			if ($weekStart) {
				$weekStartDate = $this->parseDateYmd($weekStart, 'week_start');
			} else {
				$weekStartDate = new \DateTime();
				$dayOfWeek = (int)$weekStartDate->format('w');
				$weekStartDate->modify('-' . $dayOfWeek . ' days');
			}
			$weekStartDate->setTime(0, 0, 0);

			$currentUserId = $this->getUserId();
			// Special case: admin organization scope (userId="") -> report for all enabled users.
			$reportUserId = null;
			if ($userId === '') {
				if (!$this->permissionService->isAdmin($currentUserId)) {
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
			} else {
				$reportUserId = $userId ?? $currentUserId;
				$this->ensureCanAccessUserReport($currentUserId, $reportUserId);
			}

			$report = $this->reportingService->generateWeeklyReport($weekStartDate, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			$mapped = $this->buildSafeApiError($e);
			return new JSONResponse([
				'success' => false,
				'error' => $mapped['error']
			], $mapped['status']);
		}
	}

	/**
	 * Generate monthly report
	 *
	 * @param string|null $month Month (Y-m format, defaults to current month)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function monthly(?string $month = null, ?string $userId = null, ?string $startDate = null, ?string $endDate = null): JSONResponse
	{
		try {
			$monthDate = $this->parseMonthYm($month) ?? new \DateTime();

			$currentUserId = $this->getUserId();
			// Special case: admin organization scope (userId="") -> report for all enabled users.
			$reportUserId = null;
			if ($userId === '') {
				if (!$this->permissionService->isAdmin($currentUserId)) {
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
			} else {
				$reportUserId = $userId ?? $currentUserId;
				$this->ensureCanAccessUserReport($currentUserId, $reportUserId);
			}

			$periodStart = null;
			$periodEnd = null;
			if ($startDate !== null && $startDate !== '' && $endDate !== null && $endDate !== '') {
				$periodStart = $this->parseDateYmd($startDate, 'start_date');
				$periodEnd = $this->parseDateYmd($endDate, 'end_date');
				if ($periodStart > $periodEnd) {
					throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
				}
				$days = (int)$periodEnd->diff($periodStart)->format('%a');
				if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
					throw new \Exception($this->l10n->t(
						'Export date range must not exceed %d days. Please narrow the range.',
						[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
					));
				}
			}

			if ($reportUserId !== null && $periodStart === null && $periodEnd === null) {
				$y = (int)$monthDate->format('Y');
				$mo = (int)$monthDate->format('n');
				$snapReport = $this->monthClosureService->getFinalizedMonthlyReportForUser($reportUserId, $y, $mo);
				if ($snapReport !== null) {
					return new JSONResponse([
						'success' => true,
						'report' => $snapReport,
					]);
				}
			}

			$report = $this->reportingService->generateMonthlyReport($monthDate, $reportUserId, $periodStart, $periodEnd);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			$mapped = $this->buildSafeApiError($e);
			return new JSONResponse([
				'success' => false,
				'error' => $mapped['error']
			], $mapped['status']);
		}
	}

	/**
	 * Generate overtime report
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function overtime(?string $startDate = null, ?string $endDate = null, ?string $userId = null): JSONResponse
	{
		try {
			$start = $this->parseDateYmd($startDate, 'start_date') ?? (new \DateTime())->modify('-30 days');
			$end = $this->parseDateYmd($endDate, 'end_date') ?? new \DateTime();
			$start->setTime(0, 0, 0);
			$end->setTime(0, 0, 0);
			if ($start > $end) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}
			$days = (int)$end->diff($start)->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t('Export date range must not exceed %d days. Please narrow the range.', [Constants::MAX_EXPORT_DATE_RANGE_DAYS]));
			}
			$endExclusive = (clone $end)->modify('+1 day');

			$currentUserId = $this->getUserId();
			// Special case: admin organization scope (userId="") -> report for all enabled users.
			$reportUserId = null;
			if ($userId === '') {
				if (!$this->permissionService->isAdmin($currentUserId)) {
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
			} else {
				$reportUserId = $userId ?? $currentUserId;
				$this->ensureCanAccessUserReport($currentUserId, $reportUserId);
			}

			$report = $this->reportingService->generateOvertimeReport($start, $endExclusive, $reportUserId);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			$mapped = $this->buildSafeApiError($e);
			return new JSONResponse([
				'success' => false,
				'error' => $mapped['error']
			], $mapped['status']);
		}
	}

	/**
	 * Hour-premium (Zuschlag) period report — orthogonal to Saldo / Auszahlung.
	 *
	 * @return JSONResponse|DataDownloadResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function premium(?string $startDate = null, ?string $endDate = null, ?string $userId = null): JSONResponse|DataDownloadResponse
	{
		try {
			if (!$this->premiumSurchargeService->isEnabled()) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Hour premiums are turned off for this organisation.'),
					'code' => 'PREMIUM_DISABLED',
				], Http::STATUS_BAD_REQUEST);
			}

			$start = $this->parseDateYmd($startDate, 'start_date') ?? (new \DateTime())->modify('-30 days');
			$end = $this->parseDateYmd($endDate, 'end_date') ?? new \DateTime();
			$start->setTime(0, 0, 0);
			$end->setTime(0, 0, 0);
			if ($start > $end) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}
			$days = (int)$end->diff($start)->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t('Export date range must not exceed %d days. Please narrow the range.', [Constants::MAX_EXPORT_DATE_RANGE_DAYS]));
			}

			$currentUserId = $this->getUserId();
			$userIds = $this->resolvePremiumReportUserIds($currentUserId, $userId);

			$report = $this->premiumSurchargeService->buildPeriodReport(
				$userIds,
				$start,
				$end,
				fn (string $uid): string => $this->getUserDisplayName($uid)
			);

			$download = (string)$this->request->getParam('download', '0') === '1';
			if ($download) {
				$format = strtolower((string)$this->request->getParam('format', 'csv'));
				$filenameBase = 'premium-report-' . $start->format('Y-m-d') . '_' . $end->format('Y-m-d');
				if ($format === 'json') {
					$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					if ($json === false) {
						throw new \Exception($this->l10n->t('Failed to encode report as JSON'));
					}
					return new DataDownloadResponse($json, $filenameBase . '.json', 'application/json');
				}
				$csv = $this->encodeTabularDataToCsv($this->premiumSurchargeService->flattenReportToCsvRows($report));
				return new DataDownloadResponse($csv, $filenameBase . '.csv', 'text/csv');
			}

			return new JSONResponse([
				'success' => true,
				'report' => $report,
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController::premium: ' . $e->getMessage(), ['exception' => $e]);
			$mapped = $this->buildSafeApiError($e);
			return new JSONResponse([
				'success' => false,
				'error' => $mapped['error'],
			], $mapped['status']);
		}
	}

	/**
	 * @return list<string>
	 */
	private function resolvePremiumReportUserIds(string $currentUserId, ?string $userId): array
	{
		$teamIdRaw = trim((string)$this->request->getParam('teamId', ''));
		if ($teamIdRaw !== '') {
			$tid = (int)$teamIdRaw;
			if ($tid <= 0) {
				throw new \Exception($this->l10n->t('Invalid team selected.'));
			}
			$memberIds = $this->resolveTeamMemberIdsFromTeamId($currentUserId, $tid);
			$out = [];
			foreach ($memberIds as $uid) {
				$uid = (string)$uid;
				if ($uid === '') {
					continue;
				}
				if (!$this->permissionService->canViewUserReport($currentUserId, $uid)) {
					$this->permissionService->logPermissionDenied($currentUserId, 'view_premium_report', 'report', $uid);
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
				$out[] = $uid;
			}
			return array_values(array_unique($out));
		}

		// Manager “everyone I manage” when no userId and can access manager dashboard
		if ($userId === null && $this->permissionService->canAccessManagerDashboard($currentUserId)
			&& (string)$this->request->getParam('managerScope', '') === '1') {
			$memberIds = $this->teamResolver->getTeamMemberIds($currentUserId);
			$out = [];
			foreach ($memberIds as $uid) {
				$uid = (string)$uid;
				if ($uid === '' || !$this->permissionService->canViewUserReport($currentUserId, $uid)) {
					continue;
				}
				$out[] = $uid;
			}
			return array_values(array_unique($out));
		}

		if ($userId === '') {
			if (!$this->permissionService->isAdmin($currentUserId)) {
				throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
			}
			$userIds = [];
			$this->userManager->callForAllUsers(function ($user) use (&$userIds) {
				if ($user->isEnabled() && $this->permissionService->isUserAllowedByAccessGroups($user->getUID())) {
					$userIds[] = $user->getUID();
				}
			});
			return $userIds;
		}

		$reportUserId = $userId ?? $currentUserId;
		$this->ensureCanAccessUserReport($currentUserId, $reportUserId);
		return [$reportUserId];
	}

	/**
	 * Generate absence report
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @param string|null $userId User ID (admin only, defaults to current user)
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function absence(
		?string $startDate = null,
		?string $endDate = null,
		?string $userId = null,
		?string $sort = null,
		?string $userIds = null,
		?string $teamId = null,
		?string $scope = null,
	): JSONResponse {
		try {
			$currentUserId = $this->getUserId();
			// Special case: admin organization scope (userId="") -> report for all
			// door-filtered, enabled users. Explicit selectors (userIds / teamId /
			// scope) resolve through the single OversightScopeService.
			$reportUserId = null;
			$reportUserIds = null;
			$hasScopeSelector = ($userIds !== null && trim($userIds) !== '')
				|| ($teamId !== null && trim($teamId) !== '')
				|| ($scope !== null && trim($scope) !== '');
			if ($userId === '' || $hasScopeSelector) {
				if ($userId !== null && trim($userId) !== '' && $hasScopeSelector) {
					throw new \Exception($this->l10n->t('Invalid scope parameters.'));
				}
				$effectiveScope = $userId === '' && !$hasScopeSelector
					? \OCA\ArbeitszeitCheck\Service\OversightScopeService::SCOPE_ORGANIZATION
					: ($scope !== null ? trim($scope) : null);
				$resolution = $this->oversightScope()->resolveRequestedScope(
					$currentUserId, null, $userIds, $teamId, $effectiveScope
				);
				if (!$resolution['ok']) {
					$this->permissionService->logPermissionDenied($currentUserId, 'view_report', 'report', $resolution['code'] ?? 'scope');
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
				if ($resolution['userIds'] !== null) {
					$reportUserIds = $resolution['userIds'];
				} else {
					$reportUserId = $currentUserId;
				}
			} else {
				$reportUserId = $userId ?? $currentUserId;
				$this->ensureCanAccessUserReport($currentUserId, $reportUserId);
			}

		$start = $this->parseDateYmd($startDate, 'start_date') ?? (new \DateTime())->modify('-1 year');
		$end = $this->parseDateYmd($endDate, 'end_date') ?? new \DateTime();
		$start->setTime(0, 0, 0);
		$end->setTime(0, 0, 0);
		if ($start > $end) {
			throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
		}
		$days = (int)$end->diff($start)->format('%a');
		if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
			throw new \Exception($this->l10n->t('Export date range must not exceed %d days. Please narrow the range.', [Constants::MAX_EXPORT_DATE_RANGE_DAYS]));
		}
		// Absence date-range mappers use inclusive-end overlap semantics; the
		// request end date is the last included day (deliberate fix: passing
		// end+1 day used to include absences starting the day after the range).
		$endInclusive = $end;

		$sortMode = strtolower(trim((string)($sort ?? $this->request->getParam('sort') ?? 'date')));
		$report = $this->reportingService->generateAbsenceReport($start, $endInclusive, $reportUserId, $sortMode, $reportUserIds);

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			$mapped = $this->buildSafeApiError($e);
			return new JSONResponse([
				'success' => false,
				'error' => $mapped['error']
			], $mapped['status']);
		}
	}

	/**
	 * Generate team report
	 *
	 * Accepts either userIds (comma-separated) or teamId. When userIds is empty:
	 * - If teamId is provided: resolve team members (admin: any team; manager: must manage that team)
	 * - If teamId is empty and user has manager capabilities: use everyone the manager is responsible for
	 *
	 * @param string|null $startDate Start date (Y-m-d format)
	 * @param string|null $endDate End date (Y-m-d format)
	 * @param string|null $userIds Comma-separated user IDs (optional if teamId or manager scope)
	 * @param string|null $teamId Team ID for app-owned teams (optional)
	 * @return JSONResponse|DataDownloadResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function team(
		?string $startDate = null,
		?string $endDate = null,
		?string $userIds = null,
		?string $teamId = null
	): JSONResponse|DataDownloadResponse {
		try {
			$start = $this->parseDateYmd($startDate, 'start_date') ?? (new \DateTime())->modify('-30 days');
			$end = $this->parseDateYmd($endDate, 'end_date') ?? new \DateTime();
			$start->setTime(0, 0, 0);
			$end->setTime(0, 0, 0);
			if ($start > $end) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}
			$days = (int)$end->diff($start)->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t('Export date range must not exceed %d days. Please narrow the range.', [Constants::MAX_EXPORT_DATE_RANGE_DAYS]));
			}
			$endExclusive = (clone $end)->modify('+1 day');

			$currentUserId = $this->getUserId();
			$teamUserIds = [];

			if ($userIds && trim($userIds) !== '') {
				$teamUserIds = array_values(array_unique(array_filter(array_map('trim', explode(',', $userIds)))));
			} elseif ($teamId !== null && $teamId !== '') {
				$tid = (int) $teamId;
				if ($tid <= 0) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Invalid team selected.')
					], Http::STATUS_BAD_REQUEST);
				}
				$teamUserIds = $this->resolveTeamMemberIdsFromTeamId($currentUserId, $tid);
			} elseif ($this->permissionService->canAccessManagerDashboard($currentUserId)) {
				// Manager scope: "everyone I manage" – resolve via TeamResolverService
				$teamUserIds = $this->teamResolver->getTeamMemberIds($currentUserId);
			}

			$teamUserIds = array_values(array_unique($teamUserIds));
			if (empty($teamUserIds)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('No users to include in the report. Please select a scope or team.')
				], Http::STATUS_BAD_REQUEST);
			}

			// Security: current user may view report only for self or users they can manage
			foreach ($teamUserIds as $uid) {
				if (!$this->permissionService->canViewUserReport($currentUserId, $uid)) {
					$this->permissionService->logPermissionDenied($currentUserId, 'view_team_report', 'report', $uid);
					throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
				}
			}

		$report = $this->reportingService->generateTeamReport($teamUserIds, $start, $endExclusive);

		$download = (string)$this->request->getParam('download', '0') === '1';
		if ($download) {
			$format = (string)$this->request->getParam('format', 'csv');
			$variantParam = (string)$this->request->getParam('variant', 'summary');
			$variant = in_array($variantParam, ['summary', 'time_entries'], true) ? $variantParam : 'summary';
			$layoutParam = (string)$this->request->getParam('layout', 'long');
			$layout = in_array($layoutParam, ['long', 'wide'], true) ? $layoutParam : 'long';

			if ($variant === 'time_entries') {
				$enableMidnightSplit = $this->config->getAppValue('arbeitszeitcheck', 'export_midnight_split_enabled', '1') === '1';
				$longRows = [];
				foreach ($teamUserIds as $uid) {
					$entries = $this->timeEntryMapper->findByUserAndDateRange($uid, $start, $endExclusive);
						$displayName = $this->getUserDisplayName($uid);
						foreach ($this->timeEntryExportTransformer->entriesToExportRows($entries, $enableMidnightSplit) as $row) {
							$row['user_id'] = $uid;
							$row['display_name'] = $displayName;
							$longRows[] = $row;
						}
					}
					usort($longRows, static function (array $a, array $b): int {
						return [
							(string)($a['user_id'] ?? ''),
							(string)($a['date'] ?? ''),
							(string)($a['start_time'] ?? ''),
							(int)($a['id'] ?? 0),
						] <=> [
							(string)($b['user_id'] ?? ''),
							(string)($b['date'] ?? ''),
							(string)($b['start_time'] ?? ''),
							(int)($b['id'] ?? 0),
						];
					});

					$l10n = $this->l10n;
					$weekdayFormatter = static function (string $dateStr) use ($l10n): string {
						$keys = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
						$n = (int)(new \DateTime($dateStr))->format('N');
						return $l10n->t($keys[$n - 1]);
					};

					if ($layout === 'wide') {
						$exportRows = $this->timeEntryExportTransformer->longExportRowsToWideDaily($longRows, $weekdayFormatter);
					} else {
						$exportRows = array_map(static function (array $row): array {
							return [
								'user_id' => $row['user_id'] ?? '',
								'display_name' => $row['display_name'] ?? '',
								'id' => $row['id'] ?? '',
								'date' => $row['date'] ?? '',
								'start_time' => $row['start_time'] ?? '',
								'end_time' => $row['end_time'] ?? '',
								'break_start' => $row['break_start'] ?? '',
								'break_end' => $row['break_end'] ?? '',
								'duration_hours' => $row['duration_hours'] ?? '',
								'break_duration_hours' => $row['break_duration_hours'] ?? '',
								'working_hours' => $row['working_hours'] ?? '',
								'description' => $row['description'] ?? '',
								'work_location' => $row['work_location'] ?? '',
								'status' => $row['status'] ?? '',
								'is_manual_entry' => $row['is_manual_entry'] ?? '',
								'project_id' => $row['project_id'] ?? '',
							];
						}, $longRows);
					}

					$filenameBase = 'team-time-entries-' . date('Y-m-d');
					if ($format === 'json') {
						$json = json_encode($exportRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
						if ($json === false) {
							throw new \Exception($this->l10n->t('Failed to encode report as JSON'));
						}
						return new DataDownloadResponse(
							$json,
							$filenameBase . '.json',
							'application/json; charset=utf-8'
						);
					}

					$csv = $this->encodeTabularDataToCsv($exportRows);
					return new DataDownloadResponse(
						$csv,
						$filenameBase . '.csv',
						'text/csv; charset=utf-8'
					);
				}

				$filenameBase = 'team-report-' . date('Y-m-d');

				if ($format === 'json') {
					$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
					if ($json === false) {
						throw new \Exception($this->l10n->t('Failed to encode report as JSON'));
					}
					return new DataDownloadResponse(
						$json,
						$filenameBase . '.json',
						'application/json; charset=utf-8'
					);
				}

				$csv = $this->buildTeamReportCsv($report);
				return new DataDownloadResponse(
					$csv,
					$filenameBase . '.csv',
					'text/csv; charset=utf-8'
				);
			}

			return new JSONResponse([
				'success' => true,
				'report' => $report
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in ReportController: ' . $e->getMessage(), ["exception" => $e]);
			$mapped = $this->buildSafeApiError($e);
			return new JSONResponse([
				'success' => false,
				'error' => $mapped['error']
			], $mapped['status']);
		}
	}

	/**
	 * Resolve team member user IDs from a team ID, enforcing permissions.
	 * Admins: any team. Managers: only teams they manage.
	 *
	 * @param string $currentUserId
	 * @param int $teamId
	 * @return list<string>
	 */
	private function resolveTeamMemberIdsFromTeamId(string $currentUserId, int $teamId): array {
		if ($this->permissionService->isAdmin($currentUserId)) {
			$members = $this->teamMemberMapper->findByTeamId($teamId);
			$ids = [];
			foreach ($members as $m) {
				$ids[] = $m->getUserId();
			}
			return $ids;
		}

		if ($this->permissionService->canAccessManagerDashboard($currentUserId)) {
			$managedTeamIds = $this->teamManagerMapper->getTeamIdsForManager($currentUserId);
			if (!in_array($teamId, $managedTeamIds, true)) {
				throw new \Exception($this->l10n->t('Access denied. You can only view reports for teams you manage.'));
			}
			$members = $this->teamMemberMapper->findByTeamId($teamId);
			$ids = [];
			foreach ($members as $m) {
				$ids[] = $m->getUserId();
			}
			return $ids;
		}

		throw new \Exception($this->l10n->t('Access denied. You can only view reports for yourself or your team members.'));
	}

	/**
	 * Build a CSV payload for a team report.
	 *
	 * The CSV focuses on per-member aggregates so that it stays compact and
	 * easy to consume in tools like Excel while matching the preview data.
	 *
	 * @param array $report
	 * @return string
	 */
	private function getUserDisplayName(string $userId): string
	{
		$user = $this->userManager->get($userId);
		return $user ? $user->getDisplayName() : $userId;
	}

	private static function sanitizeCsvCellValue(string $value): string
	{
		if ($value === '') {
			return $value;
		}
		if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
			return "'" . $value;
		}
		$first = $value[0];
		if ($first === "\t" || $first === "\r" || $first === "\n") {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * @param list<array<string, mixed>> $rows
	 */
	private function encodeTabularDataToCsv(array $rows): string
	{
		if ($rows === []) {
			return "No data available\n";
		}
		$fp = fopen('php://temp', 'r+');
		if ($fp === false) {
			throw new \RuntimeException('Failed to open temporary stream for CSV export');
		}
		fputcsv($fp, array_keys($rows[0]));
		foreach ($rows as $row) {
			$line = [];
			foreach (array_keys($rows[0]) as $k) {
				$v = $row[$k] ?? '';
				if ($v === null) {
					$v = '';
				} elseif (is_float($v) || is_int($v)) {
					$v = (string)$v;
				} elseif (!is_string($v)) {
					$v = (string)$v;
				}
				$line[] = self::sanitizeCsvCellValue($v);
			}
			fputcsv($fp, $line);
		}
		rewind($fp);
		$csv = stream_get_contents($fp);
		fclose($fp);

		return $csv === false ? '' : $csv;
	}

	private function buildTeamReportCsv(array $report): string
	{
		$headers = [
			'user_id',
			'display_name',
			'total_hours',
			'required_hours',
			'overtime_hours',
			'break_hours',
			'violations_count',
			'absence_days',
			'entries_count',
			'home_office_days',
			'external_appointment_days',
		];

		$fp = fopen('php://temp', 'r+');
		if ($fp === false) {
			throw new \RuntimeException('Failed to open temporary stream for CSV export');
		}

		fputcsv($fp, $headers);

		$members = $report['members'] ?? [];
		if (is_array($members)) {
			foreach ($members as $member) {
				if (!is_array($member)) {
					continue;
				}
				$row = [
					self::sanitizeCsvCellValue((string)($member['user_id'] ?? '')),
					self::sanitizeCsvCellValue((string)($member['display_name'] ?? '')),
					isset($member['total_hours']) ? (string)$member['total_hours'] : '',
					isset($member['required_hours']) ? (string)$member['required_hours'] : '',
					isset($member['overtime_hours']) ? (string)$member['overtime_hours'] : '',
					isset($member['break_hours']) ? (string)$member['break_hours'] : '',
					isset($member['violations_count']) ? (string)$member['violations_count'] : '',
					isset($member['absence_days']) ? (string)$member['absence_days'] : '',
					isset($member['entries_count']) ? (string)$member['entries_count'] : '',
					isset($member['home_office_days']) ? (string)$member['home_office_days'] : '',
					isset($member['external_appointment_days']) ? (string)$member['external_appointment_days'] : '',
				];
				fputcsv($fp, $row);
			}
		}

		rewind($fp);
		$csv = stream_get_contents($fp);
		fclose($fp);

		return $csv === false ? '' : $csv;
	}
}
