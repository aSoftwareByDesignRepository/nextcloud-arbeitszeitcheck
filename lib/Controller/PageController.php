<?php

declare(strict_types=1);

/**
 * Page controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\Util;

/**
 * PageController
 */
class PageController extends Controller
{
	use CSPTrait;

	private TimeTrackingService $timeTrackingService;
	private OvertimeService $overtimeService;
	private AbsenceService $absenceService;
	private TimeEntryMapper $timeEntryMapper;
	private AbsenceMapper $absenceMapper;
	private TeamResolverService $teamResolver;
	private IUserSession $userSession;
	private IGroupManager $groupManager;
	private IURLGenerator $urlGenerator;
	private IConfig $config;
	private PermissionService $permissionService;
	private IL10N $l10n;

	/**
	 * PageController constructor
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param TimeTrackingService $timeTrackingService
	 * @param OvertimeService $overtimeService
	 * @param AbsenceService $absenceService
	 * @param TimeEntryMapper $timeEntryMapper
	 * @param AbsenceMapper $absenceMapper
	 * @param IUserSession $userSession
	 * @param CSPService $cspService
	 * @param IL10N $l10n
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		TimeTrackingService $timeTrackingService,
		OvertimeService $overtimeService,
		AbsenceService $absenceService,
		TimeEntryMapper $timeEntryMapper,
		AbsenceMapper $absenceMapper,
		TeamResolverService $teamResolver,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IURLGenerator $urlGenerator,
		IConfig $config,
		PermissionService $permissionService,
		CSPService $cspService,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->timeTrackingService = $timeTrackingService;
		$this->overtimeService = $overtimeService;
		$this->absenceService = $absenceService;
		$this->timeEntryMapper = $timeEntryMapper;
		$this->absenceMapper = $absenceMapper;
		$this->teamResolver = $teamResolver;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->urlGenerator = $urlGenerator;
		$this->config = $config;
		$this->permissionService = $permissionService;
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
	 * Main index page - redirects to dashboard
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		return $this->dashboard();
	}

	/**
	 * Dashboard page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		try {
			$userId = $this->getUserId();
			$status = $this->timeTrackingService->getStatus($userId);
			$recentEntries = $this->timeEntryMapper->findByUser($userId, 10);

			$start = (new \DateTime())->modify('-30 days');
			$end = new \DateTime();
			$overtimeData = $this->overtimeService->calculateOvertime($userId, $start, $end);

			// Get stats for sidebar
			$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
			$absenceCount = $this->absenceMapper->countByUser($userId);

			// Check if this is a first-time user (no time entries yet)
			$isFirstTimeUser = $timeEntryCount === 0;

			$params = [
				'status' => $status,
				'overtime' => $overtimeData,
				'recentEntries' => $recentEntries,
				'isFirstTimeUser' => $isFirstTimeUser,
				'stats' => [
					'total_time_entries' => $timeEntryCount,
					'total_absences' => $absenceCount,
				],
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			];

			$response = new TemplateResponse('arbeitszeitcheck', 'dashboard', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::dashboard: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$response = new TemplateResponse('arbeitszeitcheck', 'dashboard', [
				'status' => [],
				'overtime' => [],
				'recentEntries' => [],
				'isFirstTimeUser' => true,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0],
				'error' => $errorMessage,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Time entries page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function timeEntries(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		try {
			$userId = $this->getUserId();
			$entries = $this->timeEntryMapper->findByUser($userId, 100);
			
			$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
			
			// Get compliance configuration for frontend validation
			$maxDailyHours = (float)$this->config->getAppValue('arbeitszeitcheck', 'max_daily_hours', '10');
			$complianceStrictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';
			
			$params = [
				'entries' => $entries,
				'stats' => [
					'total_time_entries' => $timeEntryCount,
					'entries_this_month' => count(array_filter($entries, function($entry) {
						return $entry->getStartTime() && $entry->getStartTime()->format('Y-m') === date('Y-m');
					})),
					'total_hours' => array_reduce($entries, function($sum, $entry) {
						return $sum + $entry->getWorkingDurationHours();
					}, 0)
				],
				'maxDailyHours' => $maxDailyHours,
				'complianceStrictMode' => $complianceStrictMode,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			];

			$response = new TemplateResponse('arbeitszeitcheck', 'time-entries', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::timeEntries: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$response = new TemplateResponse('arbeitszeitcheck', 'time-entries', [
				'entries' => [],
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'entries_this_month' => 0, 'total_hours' => 0],
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Absences page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function absences(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		try {
			$userId = $this->getUserId();

		// Read filter params (query string: start_date, end_date, status)
		$startDateParam = $this->request->getParam('start_date');
		$endDateParam = $this->request->getParam('end_date');
		$statusParam = $this->request->getParam('status');

		$filters = [];
		$filterStartDt = null;
		$filterEndDt = null;
		if (!empty($startDateParam) && !empty($endDateParam)) {
			try {
				$filterStartDt = new \DateTime($startDateParam);
				$filterEndDt = new \DateTime($endDateParam);
				$filters['date_range'] = ['start' => $filterStartDt, 'end' => $filterEndDt];
			} catch (\Throwable $e) {
				// ignore invalid dates
			}
		}
		if (!empty($statusParam) && in_array($statusParam, ['pending', 'approved', 'rejected', 'substitute_pending', 'substitute_declined'], true)) {
			$filters['status'] = $statusParam;
		}

		if (!empty($filters)) {
			$absences = $this->absenceService->getAbsencesByUser($userId, $filters);
			// Service uses either date_range or status; if we had both, apply status filter in PHP
			if (isset($filters['date_range']) && isset($filters['status'])) {
				$absences = array_values(array_filter($absences, function ($a) use ($filters) {
					return $a->getStatus() === $filters['status'];
				}));
			}
		} else {
			$absences = $this->absenceMapper->findByUser($userId);
		}

		// Get stats for sidebar (always from full list for consistent sidebar numbers)
		$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
		$absenceCount = $this->absenceMapper->countByUser($userId);

		// Calculate vacation days remaining from user settings
		$currentYear = (int)date('Y');
		$vacationStats = $this->absenceService->getVacationStats($userId, $currentYear);
		$vacationDaysRemaining = $vacationStats['remaining'] ?? 25;

		// Current filter values for the form (European format for date inputs)
		$filterStartDate = $filterStartDt ? $filterStartDt->format('d.m.Y') : '';
		$filterEndDate = $filterEndDt ? $filterEndDt->format('d.m.Y') : '';

		// Check if user has colleagues (for substitute field visibility)
		$colleagueIds = $this->teamResolver->getColleagueIds($userId);
		$hasColleagues = count($colleagueIds) > 0;

		$params = [
			'absences' => $absences,
			'hasColleagues' => $hasColleagues,
			'filterStartDate' => $filterStartDate,
			'filterEndDate' => $filterEndDate,
			'filterStatus' => $statusParam ?? '',
			'stats' => [
				'total_time_entries' => $timeEntryCount,
				'total_absences' => $absenceCount,
				'vacation_days_remaining' => $vacationDaysRemaining,
				'pending_requests' => count(array_filter($absences, function($absence) {
					return in_array($absence->getStatus(), ['pending', 'substitute_pending'], true);
				})),
				'days_taken_this_year' => array_reduce($absences, function($sum, $absence) {
					if ($absence->getStartDate() && $absence->getStartDate()->format('Y') === date('Y') && $absence->getStatus() === 'approved') {
						return $sum + $absence->getDays();
					}
					return $sum;
				}, 0)
			],
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
		];

			$response = new TemplateResponse('arbeitszeitcheck', 'absences', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::absences: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$response = new TemplateResponse('arbeitszeitcheck', 'absences', [
				'absences' => [],
				'hasColleagues' => false,
				'filterStartDate' => '',
				'filterEndDate' => '',
				'filterStatus' => '',
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0, 'vacation_days_remaining' => 0, 'pending_requests' => 0, 'days_taken_this_year' => 0],
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Reports page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function reports(): TemplateResponse|RedirectResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		try {
			$userId = $this->getUserId();
			// Use PermissionService as single source of truth for roles and permissions
			$canAccessReports = $this->permissionService->canAccessManagerDashboard($userId);
			$isAdmin = $this->permissionService->isAdmin($userId);
			$isManager = $canAccessReports && !$isAdmin;

			// Hard security gate: only admins and users with manager capabilities may access the reports area.
			// Everyone else is redirected back to the main dashboard so that the Reports page truly only exists
			// for managers and administrators.
			if (!$isAdmin && !$canAccessReports) {
				$redirectUrl = $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.index');
				return new RedirectResponse($redirectUrl);
			}

			// Get stats for sidebar
			$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
			$absenceCount = $this->absenceMapper->countByUser($userId);

			$params = [
				'stats' => [
					'total_time_entries' => $timeEntryCount,
					'total_absences' => $absenceCount,
				],
				'isAdmin' => $isAdmin,
				'isManager' => $isManager,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			];

			$response = new TemplateResponse('arbeitszeitcheck', 'reports', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::reports: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$response = new TemplateResponse('arbeitszeitcheck', 'reports', [
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0],
				'isAdmin' => false,
				'isManager' => false,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Calendar page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function calendar(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		try {
			$userId = $this->getUserId();
			
			// Get month from request parameter or use current month
			$requestMonth = $this->request->getParam('month');
			if ($requestMonth && preg_match('/^\d{4}-\d{2}$/', $requestMonth)) {
				$currentMonth = $requestMonth;
			} else {
				$currentMonth = date('Y-m');
			}
			
			// Get stats for sidebar
			$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
			$absenceCount = $this->absenceMapper->countByUser($userId);

			$params = [
				'currentMonth' => $currentMonth,
				'stats' => [
					'total_time_entries' => $timeEntryCount,
					'total_absences' => $absenceCount,
				],
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			];

			$response = new TemplateResponse('arbeitszeitcheck', 'calendar', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::calendar: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$response = new TemplateResponse('arbeitszeitcheck', 'calendar', [
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0],
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Timeline page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function timeline(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		try {
			$userId = $this->getUserId();
		
		// Get stats for sidebar
		$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
		$absenceCount = $this->absenceMapper->countByUser($userId);

		$params = [
			'stats' => [
				'total_time_entries' => $timeEntryCount,
				'total_absences' => $absenceCount,
			],
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
		];

			$response = new TemplateResponse('arbeitszeitcheck', 'timeline', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::timeline: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$response = new TemplateResponse('arbeitszeitcheck', 'timeline', [
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0],
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Settings page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		try {
			$userId = $this->getUserId();
		
		// Get stats for sidebar
		$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
		$absenceCount = $this->absenceMapper->countByUser($userId);

		$params = [
			'stats' => [
				'total_time_entries' => $timeEntryCount,
				'total_absences' => $absenceCount,
			],
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
		];

			$response = new TemplateResponse('arbeitszeitcheck', 'settings', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::settings: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			$response = new TemplateResponse('arbeitszeitcheck', 'settings', [
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0],
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
			]);
			return $this->configureCSP($response);
		}
	}
}
