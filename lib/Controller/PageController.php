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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
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
	private IUserSession $userSession;
	private IGroupManager $groupManager;
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
		IUserSession $userSession,
		IGroupManager $groupManager,
		CSPService $cspService,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->timeTrackingService = $timeTrackingService;
		$this->overtimeService = $overtimeService;
		$this->absenceService = $absenceService;
		$this->timeEntryMapper = $timeEntryMapper;
		$this->absenceMapper = $absenceMapper;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
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
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index(): TemplateResponse
	{
		return $this->dashboard();
	}

	/**
	 * Dashboard page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
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
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
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
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Time entries page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function timeEntries(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		try {
			$userId = $this->getUserId();
			$entries = $this->timeEntryMapper->findByUser($userId, 100);
			
			$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
			
			// Get compliance configuration for frontend validation
			$appConfig = \OC::$server->get(\OCP\IConfig::class);
			$maxDailyHours = (float)$appConfig->getAppValue('arbeitszeitcheck', 'max_daily_hours', '10');
			$complianceStrictMode = $appConfig->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';
			
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
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
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
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Absences page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function absences(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		try {
			$userId = $this->getUserId();
		$absences = $this->absenceMapper->findByUser($userId);

		// Get stats for sidebar
		$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
		$absenceCount = $this->absenceMapper->countByUser($userId);

		// Calculate vacation days remaining from user settings
		$currentYear = (int)date('Y');
		$vacationStats = $this->absenceService->getVacationStats($userId, $currentYear);
		$vacationDaysRemaining = $vacationStats['remaining'] ?? 25;

		$params = [
			'absences' => $absences,
			'stats' => [
				'total_time_entries' => $timeEntryCount,
				'total_absences' => $absenceCount,
				'vacation_days_remaining' => $vacationDaysRemaining,
				'pending_requests' => count(array_filter($absences, function($absence) {
					return $absence->getStatus() === 'pending';
				})),
				'days_taken_this_year' => array_reduce($absences, function($sum, $absence) {
					if ($absence->getStartDate() && $absence->getStartDate()->format('Y') === date('Y') && $absence->getStatus() === 'approved') {
						return $sum + $absence->getDays();
					}
					return $sum;
				}, 0)
			],
			'urlGenerator' => \OC::$server->getURLGenerator(),
			'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
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
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0, 'vacation_days_remaining' => 0, 'pending_requests' => 0, 'days_taken_this_year' => 0],
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Reports page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function reports(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		try {
			$userId = $this->getUserId();
			$user = $this->userSession->getUser();
			
			// Check if user is admin or manager
			$isAdmin = $user && $this->groupManager->isAdmin($user->getUID());
			$isManager = false;
			if ($user && !$isAdmin) {
				// Check if user is in a manager group (you can customize this logic)
				$userGroups = $this->groupManager->getUserGroups($user);
				foreach ($userGroups as $group) {
					if (stripos($group->getGID(), 'manager') !== false || stripos($group->getGID(), 'leiter') !== false) {
						$isManager = true;
						break;
					}
				}
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
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
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
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Calendar page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
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
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
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
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Timeline page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
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
			'urlGenerator' => \OC::$server->getURLGenerator(),
			'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
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
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Settings page
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
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
			'urlGenerator' => \OC::$server->getURLGenerator(),
			'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
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
				'urlGenerator' => \OC::$server->getURLGenerator(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response);
		}
	}
}
