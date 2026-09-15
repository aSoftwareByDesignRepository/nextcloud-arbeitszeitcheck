<?php

declare(strict_types=1);

/**
 * Page controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\OvertimeService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Service\AbsenceService;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\NavigationFlagsService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Service\TimeEntryDeletionPolicy;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\OvertimeDisplayService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutService;
use OCA\ArbeitszeitCheck\Service\EmployeeSettingsSectionCatalog;
use OCA\ArbeitszeitCheck\Support\LaborLawProfile;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\MobileAppLinks;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCA\ArbeitszeitCheck\Service\OvertimeBalancePdfBuilder;
use OCP\AppFramework\Http;
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
	use NavigationFlagsTrait;
	use PageShellTrait;

	private TimeTrackingService $timeTrackingService;
	private OvertimeService $overtimeService;
	private AbsenceService $absenceService;
	private TimeEntryMapper $timeEntryMapper;
	private AbsenceMapper $absenceMapper;
	private UserSettingsMapper $userSettingsMapper;
	private TeamResolverService $teamResolver;
	private IUserSession $userSession;
	private IGroupManager $groupManager;
	private IURLGenerator $urlGenerator;
	private IConfig $config;
	private PermissionService $permissionService;
	private OvertimeDisplayService $overtimeDisplayService;
	private OvertimeBankService $overtimeBankService;
	private OvertimePayoutService $overtimePayoutService;
	private LocaleFormatService $localeFormat;
	private NavigationFlagsService $navigationFlags;
	private ProjectCheckIntegrationService $projectCheckIntegration;
	private TimeCaptureMethodService $timeCaptureMethodService;
	private TimeEntryDeletionPolicy $deletionPolicy;
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
		UserSettingsMapper $userSettingsMapper,
		TeamResolverService $teamResolver,
		IUserSession $userSession,
		IGroupManager $groupManager,
		IURLGenerator $urlGenerator,
		IConfig $config,
		PermissionService $permissionService,
		OvertimeDisplayService $overtimeDisplayService,
		OvertimeBankService $overtimeBankService,
		OvertimePayoutService $overtimePayoutService,
		CSPService $cspService,
		LocaleFormatService $localeFormat,
		NavigationFlagsService $navigationFlags,
		ProjectCheckIntegrationService $projectCheckIntegration,
		TimeCaptureMethodService $timeCaptureMethodService,
		TimeEntryDeletionPolicy $deletionPolicy,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->timeTrackingService = $timeTrackingService;
		$this->overtimeService = $overtimeService;
		$this->absenceService = $absenceService;
		$this->timeEntryMapper = $timeEntryMapper;
		$this->absenceMapper = $absenceMapper;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->teamResolver = $teamResolver;
		$this->userSession = $userSession;
		$this->groupManager = $groupManager;
		$this->urlGenerator = $urlGenerator;
		$this->config = $config;
		$this->permissionService = $permissionService;
		$this->overtimeDisplayService = $overtimeDisplayService;
		$this->overtimeBankService = $overtimeBankService;
		$this->overtimePayoutService = $overtimePayoutService;
		$this->localeFormat = $localeFormat;
		$this->navigationFlags = $navigationFlags;
		$this->projectCheckIntegration = $projectCheckIntegration;
		$this->timeCaptureMethodService = $timeCaptureMethodService;
		$this->deletionPolicy = $deletionPolicy;
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
	 * Configured max daily hours; profile supplies the fallback when unset (DE 10 / AT 12).
	 */
	private function getConfiguredMaxDailyHours(): float
	{
		$country = strtoupper(trim($this->config->getAppValue('arbeitszeitcheck', 'country', 'DE')));
		$default = (string)LaborLawProfileFactory::profileForCountry($country)->dailyMaxHoursDefault;

		return max(1.0, min(24.0, (float)$this->config->getAppValue('arbeitszeitcheck', 'max_daily_hours', $default)));
	}

	/**
	 * Build a sanitized, user-facing error message for the page error fallback.
	 *
	 * Internal exception details (database errors, file paths, stack hints) must never reach
	 * the rendered HTML even though the template itself escapes output. We only surface a
	 * known-safe sentinel for unauthenticated sessions; everything else collapses to a
	 * generic, localized message. The full exception is still recorded via the logger.
	 */
	private function buildSafePageErrorMessage(\Throwable $e): string
	{
		if (strpos($e->getMessage(), 'User not authenticated') !== false) {
			return $this->l10n->t('User not authenticated');
		}
		return $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.');
	}

	/**
	 * Safe defaults when overtime bank / payout queries fail (e.g. pending DB migration).
	 *
	 * @return array{
	 *   overtimeBank: array<string, mixed>,
	 *   overtimeTrafficLight: array<string, mixed>,
	 *   overtimePayoutHistory: array{items: list<mixed>, total: int},
	 *   error: string
	 * }
	 */
	private function loadDashboardOvertimeExtras(string $userId): array
	{
		$defaults = [
			'overtimeBank' => [
				'enabled' => false,
				'bank_max_hours' => 100.0,
				'raw_balance' => 0.0,
				'total_payouts_ytd' => 0.0,
				'total_adjustments_ytd' => 0.0,
				'effective_balance' => 0.0,
				'banked_hours' => 0.0,
				'bank_room_hours' => 100.0,
				'payout_eligible_hours' => 0.0,
				'bank_fill_percent' => 0.0,
				'bank_state' => 'disabled',
				'as_of_date' => (new \DateTime())->format('Y-m-d'),
				'last_payout' => null,
			],
			'overtimeTrafficLight' => [
				'enabled' => false,
				'state' => 'green',
				'direction' => null,
				'level' => null,
				'balance' => 0.0,
				'thresholds' => [
					'yellow_over' => 5.0,
					'red_over' => 15.0,
					'yellow_under' => 5.0,
					'red_under' => 15.0,
				],
				'bank_enabled' => false,
				'bank_state' => null,
				'needs_attention' => false,
			],
			'overtimePayoutHistory' => ['items' => [], 'total' => 0],
			'error' => '',
		];

		try {
			$overtimeBank = $this->overtimeBankService->getBankStatus($userId);
			$trafficLight = $this->overtimeDisplayService->buildTrafficLightViewModel($userId);
			$overtimePayoutHistory = $overtimeBank['enabled']
				? $this->overtimePayoutService->listPayoutHistoryForUser($userId, 24, 0)
				: ['items' => [], 'total' => 0];

			return [
				'overtimeBank' => $overtimeBank,
				'overtimeTrafficLight' => $trafficLight,
				'overtimePayoutHistory' => $overtimePayoutHistory,
				'error' => '',
			];
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'Dashboard overtime extras failed: ' . $e->getMessage(),
				['exception' => $e, 'userId' => $userId]
			);

			return $defaults + [
				'error' => $this->l10n->t(
					'Overtime bank and payout data could not be loaded. Your administrator may need to run database updates for ArbeitszeitCheck (occ upgrade).'
				),
			];
		}
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
	 * Download a printable year-to-date overtime balance PDF for the current user.
	 * Mirrors dashboard Saldo numbers (bank-aware when enabled).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function overtimeBalancePdf(): DataDownloadResponse|JSONResponse
	{
		try {
			$userId = $this->getUserId();
			$user = $this->userSession->getUser();
			$displayName = $user ? ($user->getDisplayName() ?: $userId) : $userId;
			$bank = $this->overtimeBankService->getBankStatus($userId);
			$balance = $this->overtimeDisplayService->getYearToDateBalanceForTrafficLight($userId);
			$asOf = (string)($bank['as_of_date'] ?? date('Y-m-d'));

			$pdf = OvertimeBalancePdfBuilder::build([
				'balance' => $balance,
				'balance_label' => !empty($bank['enabled']) ? 'effective' : 'cumulative',
				'bank_enabled' => !empty($bank['enabled']),
				'bank' => $bank,
				'as_of' => $asOf,
				'display_name' => $displayName,
				'user_id' => $userId,
			], $this->l10n);

			$filename = sprintf('overtime-balance-%s-%s.pdf', preg_replace('/[^a-zA-Z0-9_-]+/', '-', $userId) ?: 'user', $asOf);

			return new DataDownloadResponse($pdf, $filename, 'application/pdf');
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error(
				'PageController::overtimeBalancePdf: ' . $e->getMessage(),
				['exception' => $e]
			);
			return new JSONResponse([
				'success' => false,
				'error' => $this->buildSafePageErrorMessage($e),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Dashboard page
	 *
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		$this->registerFrontEndAssets('arbeitszeitcheck-main', 'dashboard', [
			'dashboard-overtime-bank',
			'dashboard-overtime',
			'common/projectcheck',
		]);

		try {
			$userId = $this->getUserId();
			$status = $this->timeTrackingService->getStatus($userId);
			$recentEntries = $this->timeEntryMapper->findByUser($userId, 10);

			$start = (new \DateTime())->modify('-30 days');
			$end = new \DateTime();
			$overtimeData = $this->overtimeService->calculateOvertime($userId, $start, $end);
			try {
				$weekOvertime = $this->overtimeService->getWeeklyOvertime($userId);
			} catch (\Throwable $e) {
				$weekOvertime = [];
			}
			try {
				$dayOvertime = $this->overtimeService->getDailyOvertime($userId);
			} catch (\Throwable $e) {
				$dayOvertime = [];
			}
			try {
				$monthOvertime = $this->overtimeService->calculateMonthlyOvertime($userId);
			} catch (\Throwable $e) {
				$monthOvertime = [];
			}
			try {
				$yearOvertime = $this->overtimeService->calculateYearlyOvertime($userId);
			} catch (\Throwable $e) {
				$yearOvertime = [];
			}
			$hoursGlance = $this->buildHoursGlancePeriods($dayOvertime, $weekOvertime, $monthOvertime, $yearOvertime);
			$absenceCreditHoursYtd = round((float)($yearOvertime['absence_credit_hours'] ?? 0), 2);
			$overtimeExtras = $this->loadDashboardOvertimeExtras($userId);
			try {
				$overtimeYtdBalance = $this->overtimeDisplayService->getYearToDateBalanceForTrafficLight($userId);
			} catch (\Throwable $e) {
				$overtimeYtdBalance = 0.0;
			}

			// Get stats for sidebar
			$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
			$absenceCount = $this->absenceMapper->countByUser($userId);

			// Check onboarding state:
			// welcome card is shown only if there are no time entries and onboarding was not dismissed yet.
			$onboardingCompleted = false;
			try {
				$onboardingSetting = $this->userSettingsMapper->getSetting($userId, 'onboarding_completed');
				$onboardingCompleted = $onboardingSetting !== null && $onboardingSetting->getSettingValue() === '1';
			} catch (\Throwable $e) {
				$onboardingCompleted = false;
			}
			$isFirstTimeUser = $timeEntryCount === 0 && !$onboardingCompleted;

			$navFlags = $this->getNavigationFlags($userId);
			$pendingCorrectionCount = count($this->timeEntryMapper->findByUserAndStatus(
				$userId,
				\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL
			));

			$currentYear = (int)date('Y');
			$vacationStats = $this->absenceService->getVacationStats($userId, $currentYear);
			$workingTimeModelMissing = $this->isWorkingTimeModelMissing($userId);
			$projectCheckLinkingEnabled = $this->projectCheckIntegration->isLinkingEnabledForUser($userId);

			$premiumSummary = [
				'enabled' => false,
				'buckets' => [],
			];
			try {
				$premiumSvc = \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\PremiumSurchargeService::class);
				if ($premiumSvc->isEnabled()) {
					$premiumSummary = $premiumSvc->summariseForUser(
						$userId,
						new \DateTime('first day of this month'),
						new \DateTime('today')
					);
				}
			} catch (\Throwable) {
				$premiumSummary = ['enabled' => false, 'buckets' => []];
			}

			$params = $this->buildShellParams(
				'dashboard',
				$this->l10n->t('Dashboard'),
				$this->l10n->t('See your current work status, today\'s hours, and recent time entries'),
				array_merge($navFlags, ['pendingCorrectionCount' => $pendingCorrectionCount]),
			) + [
				'status' => $status,
				'overtime' => $overtimeData,
				'weekOvertime' => $weekOvertime,
				'hoursGlance' => $hoursGlance,
				'overtimeYtdBalance' => $overtimeYtdBalance,
				'absenceCreditHoursYtd' => $absenceCreditHoursYtd,
				'overtimeBalancePdfUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.page.overtimeBalancePdf'),
				'overtimeBank' => $overtimeExtras['overtimeBank'],
				'overtimeTrafficLight' => $overtimeExtras['overtimeTrafficLight'],
				'overtimePayoutHistory' => $overtimeExtras['overtimePayoutHistory'],
				'premiumSummary' => $premiumSummary,
				'error' => $overtimeExtras['error'],
				'recentEntries' => $recentEntries,
				'isFirstTimeUser' => $isFirstTimeUser,
				'workingTimeModelMissing' => $workingTimeModelMissing,
				'projectCheckAvailable' => $this->projectCheckIntegration->isProjectCheckAvailable(),
				'projectCheckEnabled' => $projectCheckLinkingEnabled,
				'projectCheckProjects' => $projectCheckLinkingEnabled ? $this->projectCheckIntegration->getAvailableProjects($userId) : [],
				'timeCapture' => $this->timeCaptureMethodService->getSettings($userId),
				'stats' => [
					'total_time_entries' => $timeEntryCount,
					'total_absences' => $absenceCount,
					'vacation_days_remaining' => (float)($vacationStats['remaining'] ?? 0),
					'vacation_days_used_this_year' => (float)($vacationStats['used'] ?? 0),
					'vacation_carryover_days' => (float)($vacationStats['carryover_days'] ?? 0),
					'vacation_carryover_usable' => (float)($vacationStats['carryover_usable'] ?? 0),
					'vacation_carryover_expires_on' => $vacationStats['carryover_expires_on'] ?? null,
					'vacation_carryover_locked_after_deadline' => (bool)($vacationStats['carryover_unused_locked_after_deadline'] ?? false),
					'vacation_annual_remaining' => (float)($vacationStats['annual_remaining_after_approved'] ?? 0),
					'vacation_carryover_remaining' => (float)($vacationStats['carryover_remaining_after_approved'] ?? 0),
					'vacation_carryover_max_cap' => $vacationStats['carryover_max_cap'] ?? null,
					'vacation_annual_entitlement' => (float)($vacationStats['entitlement'] ?? 0),
					'vacation_year' => (int)($vacationStats['year'] ?? $currentYear),
					'vacation_year_mode' => (string)($vacationStats['vacation_year_mode'] ?? 'calendar'),
					'vacation_year_label' => (string)($vacationStats['vacation_year_label'] ?? (string)$currentYear),
					'vacation_year_error' => $vacationStats['vacation_year_error'] ?? null,
					'vacation_unit' => (string)($vacationStats['vacation_unit'] ?? 'days'),
					'vacation_hours_per_day' => (float)($vacationStats['vacation_hours_per_day'] ?? Constants::DEFAULT_VACATION_HOURS_PER_DAY),
				],
			];

			$response = new TemplateResponse('arbeitszeitcheck', 'dashboard', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::dashboard: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $this->buildSafePageErrorMessage($e);
			$user = $this->userSession->getUser();
			$userId = $user !== null ? $user->getUID() : '';
			$navFlags = $this->getNavigationFlagsForSession();
			$response = new TemplateResponse('arbeitszeitcheck', 'dashboard', $this->buildShellParams(
				'dashboard',
				$this->l10n->t('Dashboard'),
				$this->l10n->t('See your current work status, today\'s hours, and recent time entries'),
				$navFlags,
			) + [
				'status' => [],
				'overtime' => [],
				'weekOvertime' => [],
				'hoursGlance' => [],
				'overtimeYtdBalance' => 0.0,
				'absenceCreditHoursYtd' => 0.0,
				'overtimeBank' => ['enabled' => false, 'bank_max_hours' => 100.0, 'banked_hours' => 0.0, 'bank_fill_percent' => 0.0, 'payout_eligible_hours' => 0.0, 'effective_balance' => 0.0, 'bank_state' => 'disabled'],
				'overtimeTrafficLight' => ['enabled' => false, 'state' => 'green', 'direction' => null, 'level' => null, 'balance' => 0.0],
				'overtimePayoutHistory' => ['items' => [], 'total' => 0],
				'premiumSummary' => ['enabled' => false, 'buckets' => []],
				'recentEntries' => [],
				'isFirstTimeUser' => true,
				'workingTimeModelMissing' => false,
				'stats' => [
					'total_time_entries' => 0,
					'total_absences' => 0,
					'vacation_days_remaining' => 0,
					'vacation_days_used_this_year' => 0,
					'vacation_carryover_days' => 0,
					'vacation_carryover_usable' => 0,
					'vacation_carryover_expires_on' => null,
					'vacation_carryover_locked_after_deadline' => false,
					'vacation_annual_remaining' => 0,
					'vacation_carryover_remaining' => 0,
					'vacation_carryover_max_cap' => null,
					'vacation_annual_entitlement' => 0,
					'vacation_year' => (int)date('Y'),
				],
				'error' => $errorMessage,
				'timeCapture' => $userId !== '' ? $this->resolveTimeCaptureSettings($userId) : [
					'clockStampingEnabled' => false,
					'manualTimeEntryEnabled' => false,
				],
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
		$extraScripts = ['time-entry-correction', 'time-entry-form-accessibility'];
		$extraStyles = ['time-entries', 'time-entry-correction', 'time-entry-form-accessibility', 'common/projectcheck'];
		// time-entry-form.js is loaded only on create/edit via TimeEntryController::registerTimeEntryFormAssets()
		$this->registerFrontEndAssets('arbeitszeitcheck-main', null, $extraStyles, [
			'common/datepicker',
			'common/validation',
		]);
		foreach ($extraScripts as $script) {
			\OCP\Util::addScript('arbeitszeitcheck', $script);
		}
		if ($this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_MONTH_CLOSURE_ENABLED, '0') === '1') {
			\OCP\Util::addScript('arbeitszeitcheck', 'month-closure');
		}

		try {
			$userId = $this->getUserId();
			$entries = $this->timeEntryMapper->findByUser($userId, 100);
			$pendingCorrectionCount = count($this->timeEntryMapper->findByUserAndStatus(
				$userId,
				\OCA\ArbeitszeitCheck\Db\TimeEntry::STATUS_PENDING_APPROVAL
			));

			$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
			
			// Get compliance configuration for frontend validation
			$maxDailyHours = $this->getConfiguredMaxDailyHours();
			$complianceStrictMode = $this->config->getAppValue('arbeitszeitcheck', 'compliance_strict_mode', '0') === '1';

			$navFlags = $this->getNavigationFlags($userId);
			$navFlags['pendingCorrectionCount'] = $pendingCorrectionCount;
			
			$deletionEligibility = [];
			foreach ($entries as $entry) {
				$deletionEligibility[$entry->getId()] = $this->deletionPolicy->evaluate($entry);
			}

			$params = $this->buildTimeEntriesShellParams('list', $navFlags) + [
				'entries' => $entries,
				'deletionEligibility' => $deletionEligibility,
				'mode' => 'list',
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
				'monthClosureEnabled' => $this->config->getAppValue('arbeitszeitcheck', Constants::CONFIG_MONTH_CLOSURE_ENABLED, '0') === '1',
				'timeCapture' => $this->timeCaptureMethodService->getSettings($userId),
			];

			$response = new TemplateResponse('arbeitszeitcheck', 'time-entries', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::timeEntries: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $this->buildSafePageErrorMessage($e);
			$navFlags = $this->getNavigationFlagsForSession();
			$user = $this->userSession->getUser();
			$userId = $user !== null ? $user->getUID() : '';
			$response = new TemplateResponse('arbeitszeitcheck', 'time-entries', $this->buildTimeEntriesShellParams('list', $navFlags) + [
				'entries' => [],
				'mode' => 'list',
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'entries_this_month' => 0, 'total_hours' => 0],
				'maxDailyHours' => 10.0,
				'complianceStrictMode' => false,
				'monthClosureEnabled' => false,
				'timeCapture' => $userId !== '' ? $this->resolveTimeCaptureSettings($userId) : [
					'clockStampingEnabled' => false,
					'manualTimeEntryEnabled' => false,
				],
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
		$this->registerFrontEndAssets('arbeitszeitcheck-main', 'absences', ['absences'], ['common/datepicker']);
		Util::addScript('arbeitszeitcheck', 'entitlement-explainer');

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

		// Calculate vacation stats: remaining and used for current year only.
		// Only vacation (type=vacation) absences count; sick leave etc. are excluded.
		$currentYear = (int)date('Y');
		$vacationStats = $this->absenceService->getVacationStats($userId, $currentYear);
		$vacationDaysRemaining = (float)($vacationStats['remaining'] ?? 0);
		$vacationDaysUsedThisYear = (float)($vacationStats['used'] ?? 0);
		$vacationCarryoverDays = (float)($vacationStats['carryover_days'] ?? 0);
		$vacationCarryoverUsable = (float)($vacationStats['carryover_usable'] ?? 0);
		$vacationCarryoverExpiresOn = $vacationStats['carryover_expires_on'] ?? null;
		$vacationCarryoverLockedAfterDeadline = (bool)($vacationStats['carryover_unused_locked_after_deadline'] ?? false);
		$vacationAnnualEntitlement = (float)($vacationStats['entitlement'] ?? 0);
		$vacationAnnualRemaining = (float)($vacationStats['annual_remaining_after_approved'] ?? 0);
		$vacationCarryoverRemaining = (float)($vacationStats['carryover_remaining_after_approved'] ?? 0);
		$vacationCarryoverMaxCap = $vacationStats['carryover_max_cap'] ?? null;

		// Current filter values for the form (European format for date inputs)
		$filterStartDate = $filterStartDt ? $filterStartDt->format('d.m.Y') : '';
		$filterEndDate = $filterEndDt ? $filterEndDt->format('d.m.Y') : '';

		// Check if user has colleagues (for substitute field visibility)
		$colleagueIds = $this->teamResolver->getColleagueIds($userId);
		$hasColleagues = count($colleagueIds) > 0;

		// Which absence types require a substitute (admin setting)
		$requireSubstituteJson = $this->config->getAppValue('arbeitszeitcheck', 'require_substitute_types', '[]');
		$requireSubstituteTypes = json_decode($requireSubstituteJson, true);
		$requireSubstituteTypes = is_array($requireSubstituteTypes) ? $requireSubstituteTypes : [];

		$navFlags = $this->getNavigationFlags($userId);

		$employeeHasAssignableManager = $this->teamResolver->hasAssignableManagerForEmployee($userId);
		$useAppTeams = $this->teamResolver->useAppTeams();

		// Pending requests count: must use full list so filter doesn't affect stats
		$absencesForStats = empty($filters) ? $absences : $this->absenceMapper->findByUser($userId);
		$pendingCount = count(array_filter($absencesForStats, function ($a) {
			return in_array($a->getStatus(), ['pending', 'substitute_pending'], true);
		}));

		// Precompute working days for absences with days=NULL (HolidayService, state-aware)
		$computedWorkingDays = [];
		foreach ($absences as $a) {
			if ($a->getDays() === null) {
				$computedWorkingDays[$a->getId()] = $this->absenceService->getWorkingDaysForDisplay($a);
			}
		}

		$params = $this->buildShellParams(
			'absences',
			$this->l10n->t('Absences'),
			$this->l10n->t('Request time off, track vacation balance, and manage your absence history'),
			$navFlags,
		) + [
			'absences' => $absences,
			'computedWorkingDays' => $computedWorkingDays,
			'hasColleagues' => $hasColleagues,
			'requireSubstituteTypes' => $requireSubstituteTypes,
			'filterStartDate' => $filterStartDate,
			'filterEndDate' => $filterEndDate,
			'filterStatus' => $statusParam ?? '',
			'stats' => [
				'total_time_entries' => $timeEntryCount,
				'total_absences' => $absenceCount,
				'vacation_days_remaining' => $vacationDaysRemaining,
				'vacation_days_used_this_year' => $vacationDaysUsedThisYear,
				'vacation_carryover_days' => $vacationCarryoverDays,
				'vacation_carryover_usable' => $vacationCarryoverUsable,
				'vacation_carryover_expires_on' => $vacationCarryoverExpiresOn,
				'vacation_carryover_locked_after_deadline' => $vacationCarryoverLockedAfterDeadline,
				'vacation_annual_remaining' => $vacationAnnualRemaining,
				'vacation_carryover_remaining' => $vacationCarryoverRemaining,
				'vacation_carryover_max_cap' => $vacationCarryoverMaxCap,
				'vacation_annual_entitlement' => $vacationAnnualEntitlement,
				'vacation_year' => (int)($vacationStats['year'] ?? $currentYear),
				'vacation_year_mode' => (string)($vacationStats['vacation_year_mode'] ?? 'calendar'),
				'vacation_year_label' => (string)($vacationStats['vacation_year_label'] ?? (string)$currentYear),
				'vacation_year_error' => $vacationStats['vacation_year_error'] ?? null,
				'vacation_unit' => (string)($vacationStats['vacation_unit'] ?? 'days'),
				'vacation_hours_per_day' => (float)($vacationStats['vacation_hours_per_day'] ?? Constants::DEFAULT_VACATION_HOURS_PER_DAY),
				'pending_requests' => $pendingCount,
			],
			'employeeHasAssignableManager' => $employeeHasAssignableManager,
			'useAppTeams' => $useAppTeams,
		];

			$response = new TemplateResponse('arbeitszeitcheck', 'absences', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::absences: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $this->buildSafePageErrorMessage($e);
			$navFlags = $this->getNavigationFlagsForSession();
			$response = new TemplateResponse('arbeitszeitcheck', 'absences', $this->buildShellParams(
				'absences',
				$this->l10n->t('Absences'),
				$this->l10n->t('Request time off, track vacation balance, and manage your absence history'),
				$navFlags,
			) + [
				'absences' => [],
				'computedWorkingDays' => [],
				'hasColleagues' => false,
				'requireSubstituteTypes' => [],
				'filterStartDate' => '',
				'filterEndDate' => '',
				'filterStatus' => '',
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0, 'vacation_days_remaining' => 0, 'vacation_days_used_this_year' => 0, 'vacation_carryover_days' => 0, 'vacation_carryover_usable' => 0, 'vacation_carryover_expires_on' => null, 'vacation_carryover_locked_after_deadline' => false, 'vacation_annual_remaining' => 0, 'vacation_carryover_remaining' => 0, 'vacation_carryover_max_cap' => null, 'vacation_annual_entitlement' => 0, 'vacation_year' => (int)date('Y'), 'pending_requests' => 0],
				'employeeHasAssignableManager' => true,
				'useAppTeams' => false,
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
		$this->registerFrontEndAssets('reports', 'reports');
		Util::addScript('arbeitszeitcheck', 'common/datepicker');

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

			$navFlags = $this->getNavigationFlags($userId);

			$params = $this->buildShellParams(
				'reports',
				$this->l10n->t('Reports'),
				$this->l10n->t('Generate and export time entries, absences, and compliance reports'),
				$navFlags,
			) + [
				'stats' => [
					'total_time_entries' => $timeEntryCount,
					'total_absences' => $absenceCount,
				],
				'isAdmin' => $isAdmin,
				'isManager' => $isManager,
				'premiumSurchargesEnabled' => $this->config->getAppValue(
					'arbeitszeitcheck',
					\OCA\ArbeitszeitCheck\Constants::CONFIG_PREMIUM_SURCHARGES_ENABLED,
					'0'
				) === '1',
			];

			$response = new TemplateResponse('arbeitszeitcheck', 'reports', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::reports: ' . $e->getMessage(), ["exception" => $e]);
			$errorMessage = $this->buildSafePageErrorMessage($e);
			$navFlags = $this->getNavigationFlagsForSession();
			$response = new TemplateResponse('arbeitszeitcheck', 'reports', $this->buildShellParams(
				'reports',
				$this->l10n->t('Reports'),
				$this->l10n->t('Generate and export time entries, absences, and compliance reports'),
				$navFlags,
			) + [
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0],
				'isAdmin' => false,
				'isManager' => false,
				'premiumSurchargesEnabled' => false,
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
		$this->registerFrontEndAssets('arbeitszeitcheck-main', 'calendar', ['calendar']);
		Util::addScript('arbeitszeitcheck', 'common/datepicker');

		try {
			$userId = $this->getUserId();

			// Get month from request parameter or use current month
			$requestMonth = $this->request->getParam('month');
			if ($requestMonth && preg_match('/^\d{4}-\d{2}$/', $requestMonth)) {
				$currentMonth = $requestMonth;
			} else {
				$currentMonth = date('Y-m');
			}

			$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
			$absenceCount = $this->absenceMapper->countByUser($userId);
			$navFlags = $this->getNavigationFlags($userId);
			$timeCapture = $this->timeCaptureMethodService->getSettings($userId);

			$params = $this->buildShellParams(
				'calendar',
				$this->l10n->t('Calendar'),
				$this->l10n->t('See your working hours, absences, and public holidays in a monthly or weekly view'),
				$navFlags,
			) + [
				'currentMonth' => $currentMonth,
				'timeCapture' => $timeCapture,
				'timeEntryCreateUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.time_entry.create'),
				'stats' => [
					'total_time_entries' => $timeEntryCount,
					'total_absences' => $absenceCount,
				],
			];

			$response = new TemplateResponse('arbeitszeitcheck', 'calendar', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::calendar: ' . $e->getMessage(), ['exception' => $e]);
			$errorMessage = $this->buildSafePageErrorMessage($e);
			$navFlags = $this->getNavigationFlagsForSession();
			$response = new TemplateResponse('arbeitszeitcheck', 'calendar', $this->buildShellParams(
				'calendar',
				$this->l10n->t('Calendar'),
				$this->l10n->t('See your working hours, absences, and public holidays in a monthly or weekly view'),
				$navFlags,
			) + [
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0],
				'currentMonth' => date('Y-m'),
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
		$this->registerFrontEndAssets('arbeitszeitcheck-main', 'timeline', ['timeline']);

		try {
			$userId = $this->getUserId();

			$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
			$absenceCount = $this->absenceMapper->countByUser($userId);
			$navFlags = $this->getNavigationFlags($userId);

			$params = $this->buildShellParams(
				'timeline',
				$this->l10n->t('Timeline'),
				$this->l10n->t('Browse time entries, absences, and holidays in chronological order'),
				$navFlags,
			) + [
				'stats' => [
					'total_time_entries' => $timeEntryCount,
					'total_absences' => $absenceCount,
				],
			];

			$response = new TemplateResponse('arbeitszeitcheck', 'timeline', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::timeline: ' . $e->getMessage(), ['exception' => $e]);
			$errorMessage = $this->buildSafePageErrorMessage($e);
			$navFlags = $this->getNavigationFlagsForSession();
			$response = new TemplateResponse('arbeitszeitcheck', 'timeline', $this->buildShellParams(
				'timeline',
				$this->l10n->t('Timeline'),
				$this->l10n->t('Browse time entries, absences, and holidays in chronological order'),
				$navFlags,
			) + [
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0],
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Official Android companions (phone + foyer terminal) on Google Play.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function getTheApp(): TemplateResponse
	{
		$this->registerFrontEndAssets('', 'get-the-app');
		$navFlags = $this->getNavigationFlagsForSession();
		$links = new MobileAppLinks();
		$lang = method_exists($this->l10n, 'getLanguageCode') ? (string)$this->l10n->getLanguageCode() : 'en';
		$params = $this->buildShellParams(
			'get-the-app',
			$this->l10n->t('Get the App'),
			$this->l10n->t('Official Android apps for phone and foyer terminal.'),
			$navFlags,
		);
		$params['urls'] = array_merge($params['urls'] ?? [], [
			'playStore' => $links->playStoreUrl(),
			'kioskPlayStore' => $links->kioskPlayStoreUrl(),
			'mobileProductPage' => $links->productPageUrl($lang),
			'mobilePrivacyPage' => $links->privacyPageUrl($lang),
			'kioskPrivacyPage' => $links->kioskPrivacyPageUrl($lang),
		]);

		return $this->configureCSP(new TemplateResponse('arbeitszeitcheck', 'get-the-app', $params));
	}

	/**
	 * My settings index → default section (SETTINGS-PAGES-STANDARD).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settings(): RedirectResponse
	{
		$catalog = new EmployeeSettingsSectionCatalog();
		return new RedirectResponse(
			$catalog->url($this->urlGenerator, EmployeeSettingsSectionCatalog::DEFAULT_SECTION)
		);
	}

	/**
	 * My settings section page (`/settings/{section}`).
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function settingsSection(string $section): Response
	{
		$catalog = new EmployeeSettingsSectionCatalog();
		if (!$catalog->isSection($section)) {
			return new NotFoundResponse();
		}

		$this->registerFrontEndAssets(
			'settings',
			'settings',
			['common/projectcheck'],
			['employee-settings-legacy-redirect']
		);

		try {
			$userId = $this->getUserId();
			$timeEntryCount = $this->timeEntryMapper->countByUser($userId);
			$absenceCount = $this->absenceMapper->countByUser($userId);
			$navFlags = $this->getNavigationFlags($userId);

			$shell = $this->buildShellParams(
				'settings',
				$catalog->label($this->l10n, $section),
				$catalog->help($this->l10n, $section),
				$navFlags,
			);
			// SETTINGS-PAGES-STANDARD §7: App → My settings (link) → section
			$shell['breadcrumbSection'] = '';
			$shell['breadcrumbParent'] = [
				'label' => $this->l10n->t('My settings'),
				'url' => $catalog->url(
					$this->urlGenerator,
					EmployeeSettingsSectionCatalog::DEFAULT_SECTION
				),
			];

			$params = $shell + [
				'settingsSection' => $section,
				'settingsPages' => $catalog->chipBarPayload($this->l10n, $this->urlGenerator, $section),
				'stats' => [
					'total_time_entries' => $timeEntryCount,
					'total_absences' => $absenceCount,
				],
				'projectCheckAvailable' => $this->projectCheckIntegration->isProjectCheckAvailable(),
				'complianceProfile' => $this->buildComplianceProfileForSettings(),
			];

			$response = new TemplateResponse('arbeitszeitcheck', 'settings', $params);
			return $this->configureCSP($response);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in PageController::settingsSection: ' . $e->getMessage(), ['exception' => $e]);
			$errorMessage = $this->buildSafePageErrorMessage($e);
			$navFlags = $this->getNavigationFlagsForSession();
			$shell = $this->buildShellParams(
				'settings',
				$catalog->label($this->l10n, $section),
				$catalog->help($this->l10n, $section),
				$navFlags,
			);
			$shell['breadcrumbSection'] = '';
			$shell['breadcrumbParent'] = [
				'label' => $this->l10n->t('My settings'),
				'url' => $catalog->url(
					$this->urlGenerator,
					EmployeeSettingsSectionCatalog::DEFAULT_SECTION
				),
			];
			$response = new TemplateResponse('arbeitszeitcheck', 'settings', $shell + [
				'settingsSection' => $section,
				'settingsPages' => $catalog->chipBarPayload($this->l10n, $this->urlGenerator, $section),
				'error' => $errorMessage,
				'stats' => ['total_time_entries' => 0, 'total_absences' => 0],
				'complianceProfile' => $this->buildComplianceProfileForSettings(),
			]);
			return $this->configureCSP($response);
		}
	}

	/**
	 * Country-aware compliance blurbs for the personal settings page.
	 *
	 * @return array{
	 *   country: string,
	 *   lawName: string,
	 *   lead: string,
	 *   maxDailyHours: float,
	 *   minRestHours: float,
	 *   breakLines: list<string>,
	 *   sundayNote: string,
	 *   footerBlurb: string
	 * }
	 */
	private function buildComplianceProfileForSettings(): array
	{
		$user = $this->userSession->getUser();
		$userId = $user !== null ? $user->getUID() : null;
		$profile = $this->timeTrackingService->lawProfile($userId);
		$maxDaily = max(1.0, min(24.0, (float)$this->config->getAppValue(
			'arbeitszeitcheck',
			'max_daily_hours',
			(string)$profile->dailyMaxHoursDefault
		)));
		$minRest = max(1.0, min(24.0, (float)$this->config->getAppValue(
			'arbeitszeitcheck',
			'min_rest_period',
			(string)$profile->minRestHoursDefault
		)));

		$breakLines = [];
		foreach ($profile->breakTiersAscending() as $tier) {
			$breakLines[] = $this->l10n->t(
				'From %1$s hours of work: at least %2$d minutes break (%3$s)',
				[
					LaborLawProfile::formatHoursLabel((float)$tier['afterHours']),
					(int)$tier['breakMinutes'],
					$profile->lawLabel('breaks'),
				]
			);
		}

		if ($profile->country === \OCA\ArbeitszeitCheck\Support\RegionRegistry::COUNTRY_AT) {
			return [
				'country' => 'AT',
				'lawName' => $this->l10n->t('Austrian working time law (AZG / ARG)'),
				'lead' => $this->l10n->t('Key rules from Austrian working time law that this app helps you follow.'),
				'maxDailyHours' => $maxDaily,
				'minRestHours' => $minRest,
				'breakLines' => $breakLines,
				'sundayNote' => $this->l10n->t('Sunday and public-holiday work is generally restricted (ARG §3) with exceptions.'),
				'footerBlurb' => $this->l10n->t('Working time tracking oriented on Austrian labour law (AZG/ARG) for Nextcloud'),
			];
		}

		if ($profile->country === \OCA\ArbeitszeitCheck\Support\RegionRegistry::COUNTRY_CH) {
			return [
				'country' => 'CH',
				'lawName' => $this->l10n->t('Swiss Labour Act (ArG)'),
				'lead' => $this->l10n->t('Key rules from Swiss working time law that this app helps you follow.'),
				'maxDailyHours' => $maxDaily,
				'minRestHours' => $minRest,
				'breakLines' => $breakLines,
				'sundayNote' => $this->l10n->t('Sunday and public-holiday work is generally restricted (ArG Art. 18) with exceptions.'),
				'footerBlurb' => $this->l10n->t('Working time tracking oriented on Swiss labour law (ArG) for Nextcloud'),
			];
		}

		return [
			'country' => 'DE',
			'lawName' => $this->l10n->t('German Labor Law (Arbeitszeitgesetz - ArbZG)'),
			'lead' => $this->l10n->t('Key rules from German working time law that this app helps you follow.'),
			'maxDailyHours' => $maxDaily,
			'minRestHours' => $minRest,
			'breakLines' => $breakLines,
			'sundayNote' => $this->l10n->t('Sunday work is generally prohibited with exceptions'),
			'footerBlurb' => $this->l10n->t('German labor law compliant time tracking for Nextcloud'),
		];
	}

	/**
	 * Resolve effective time capture for templates. Never defaults to both enabled —
	 * on failure we fall back to organisation defaults, then to both disabled.
	 *
	 * @return array{clockStampingEnabled: bool, manualTimeEntryEnabled: bool}
	 */
	private function resolveTimeCaptureSettings(string $userId): array
	{
		try {
			return $this->timeCaptureMethodService->getSettings($userId);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Could not resolve user time capture settings, using organisation defaults',
				['userId' => $userId, 'exception' => $e],
			);
			try {
				return $this->timeCaptureMethodService->getOrganizationDefaults();
			} catch (\Throwable $inner) {
				return [
					'clockStampingEnabled' => false,
					'manualTimeEntryEnabled' => false,
				];
			}
		}
	}

	/**
	 * Whether the user has no active working time model assignment (W9 dashboard callout).
	 */
	private function isWorkingTimeModelMissing(string $userId): bool
	{
		try {
			$mapper = \OCP\Server::get(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class);

			return $mapper->findCurrentByUser($userId) === null;
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Could not resolve working time model for user ' . $userId . ': ' . $e->getMessage(),
				['exception' => $e],
			);

			return false;
		}
	}

	/**
	 * Ist/Soll period tiles for “Hours at a glance” (does not replace year Saldo).
	 *
	 * @param array<string, mixed> $day
	 * @param array<string, mixed> $week
	 * @param array<string, mixed> $month
	 * @param array<string, mixed> $year
	 * @return list<array{
	 *   key: string,
	 *   label: string,
	 *   period: string,
	 *   worked: float,
	 *   target: float,
	 *   delta: float
	 * }>
	 */
	private function buildHoursGlancePeriods(array $day, array $week, array $month, array $year): array
	{
		$formatPeriod = static function (array $row): string {
			$start = (string)($row['period_start'] ?? '');
			$end = (string)($row['period_end'] ?? '');
			if ($start !== '' && $end !== '' && $start !== $end) {
				return $start . ' – ' . $end;
			}
			return $start !== '' ? $start : $end;
		};

		$pack = static function (string $key, string $label, array $row) use ($formatPeriod): array {
			$worked = round((float)($row['total_hours_worked'] ?? 0), 2);
			$target = round((float)($row['required_hours'] ?? 0), 2);
			return [
				'key' => $key,
				'label' => $label,
				'period' => $formatPeriod($row),
				'worked' => $worked,
				'target' => $target,
				'delta' => round($worked - $target, 2),
			];
		};

		return [
			$pack('today', $this->l10n->t('Today'), $day),
			$pack('week', $this->l10n->t('This week'), $week),
			$pack('month', $this->l10n->t('This month'), $month),
			$pack('year', $this->l10n->t('This year'), $year),
		];
	}
}
