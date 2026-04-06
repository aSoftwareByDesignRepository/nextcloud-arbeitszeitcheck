<?php

declare(strict_types=1);

/**
 * Admin controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\VacationYearBalanceMapper;
use OCA\ArbeitszeitCheck\Db\Team;
use OCA\ArbeitszeitCheck\Db\TeamMapper;
use OCA\ArbeitszeitCheck\Db\TeamMemberMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Db\Holiday;
use OCA\ArbeitszeitCheck\Db\HolidayMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUser;
use OCP\IUserSession;
use OCP\IURLGenerator;
use OCP\IL10N;
use OCP\Util;

/**
 * Admin controller – all routes require admin privileges.
 *
 * Admin access is enforced by Nextcloud middleware when NoAdminRequired
 * is not present. Do not add NoAdminRequired to any method in this class.
 */
class AdminController extends Controller
{
	use CSPTrait;

	/** Max date range for admin exports (prevents heavy queries / DoS) */
	private TimeEntryMapper $timeEntryMapper;
	private ComplianceViolationMapper $violationMapper;
	private UserWorkingTimeModelMapper $userWorkingTimeModelMapper;
	private WorkingTimeModelMapper $workingTimeModelMapper;
	private AuditLogMapper $auditLogMapper;
	private IUserManager $userManager;
	private IAppConfig $appConfig;
	private IL10N $l10n;
	private UserSettingsMapper $userSettingsMapper;
	private TeamMapper $teamMapper;
	private TeamMemberMapper $teamMemberMapper;
	private TeamManagerMapper $teamManagerMapper;
	private IUserSession $userSession;
	private IURLGenerator $urlGenerator;
	private HolidayMapper $holidayMapper;
	private HolidayService $holidayCalendarService;
	private VacationYearBalanceMapper $vacationYearBalanceMapper;

	public function __construct(
		string $appName,
		IRequest $request,
		TimeEntryMapper $timeEntryMapper,
		ComplianceViolationMapper $violationMapper,
		UserWorkingTimeModelMapper $userWorkingTimeModelMapper,
		WorkingTimeModelMapper $workingTimeModelMapper,
		AuditLogMapper $auditLogMapper,
		IUserManager $userManager,
		IAppConfig $appConfig,
		UserSettingsMapper $userSettingsMapper,
		TeamMapper $teamMapper,
		TeamMemberMapper $teamMemberMapper,
		TeamManagerMapper $teamManagerMapper,
		IUserSession $userSession,
		CSPService $cspService,
		IL10N $l10n,
		IURLGenerator $urlGenerator,
		HolidayMapper $holidayMapper,
		HolidayService $holidayCalendarService,
		VacationYearBalanceMapper $vacationYearBalanceMapper
	) {
		parent::__construct($appName, $request);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->violationMapper = $violationMapper;
		$this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
		$this->workingTimeModelMapper = $workingTimeModelMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->userManager = $userManager;
		$this->appConfig = $appConfig;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->teamMapper = $teamMapper;
		$this->teamMemberMapper = $teamMemberMapper;
		$this->teamManagerMapper = $teamManagerMapper;
		$this->userSession = $userSession;
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
		$this->holidayMapper = $holidayMapper;
		$this->holidayCalendarService = $holidayCalendarService;
		$this->vacationYearBalanceMapper = $vacationYearBalanceMapper;
		$this->setCspService($cspService);
	}

	/**
	 * Get the current admin's user ID for audit logging (performedBy).
	 * Falls back to 'system' if session is unavailable (e.g. CLI).
	 */
	private function getPerformedBy(): string
	{
		$user = $this->userSession->getUser();
		return $user !== null ? $user->getUID() : 'system';
	}

	/**
	 * Convert UserWorkingTimeModel to JSON-serializable array for audit log.
	 */
	private function userWorkingTimeModelToAuditValues(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel $model): array
	{
		$start = $model->getStartDate();
		$end = $model->getEndDate();
		return [
			'id' => $model->getId(),
			'userId' => $model->getUserId(),
			'workingTimeModelId' => $model->getWorkingTimeModelId(),
			'vacationDaysPerYear' => $model->getVacationDaysPerYear(),
			'startDate' => $start ? $start->format('Y-m-d') : null,
			'endDate' => $end ? $end->format('Y-m-d') : null,
		];
	}

	/**
	 * Convert WorkingTimeModel to JSON-serializable array for audit log.
	 */
	private function workingTimeModelToAuditValues(\OCA\ArbeitszeitCheck\Db\WorkingTimeModel $model): array
	{
		$created = $model->getCreatedAt();
		$updated = $model->getUpdatedAt();
		return [
			'id' => $model->getId(),
			'name' => $model->getName(),
			'description' => $model->getDescription(),
			'type' => $model->getType(),
			'weeklyHours' => $model->getWeeklyHours(),
			'dailyHours' => $model->getDailyHours(),
			'breakRules' => $model->getBreakRulesArray(),
			'overtimeRules' => $model->getOvertimeRulesArray(),
			'isDefault' => $model->getIsDefault(),
			'createdAt' => $created ? $created->format('c') : null,
			'updatedAt' => $updated ? $updated->format('c') : null,
		];
	}

	/**
	 * Convert Holiday entity to JSON-serializable array for audit log.
	 */
	private function holidayToAuditValues(Holiday $holiday): array
	{
		$date = $holiday->getDate();

		return [
			'id' => $holiday->getId(),
			'state' => $holiday->getState(),
			'date' => $date ? $date->format('Y-m-d') : null,
			'name' => $holiday->getName(),
			'kind' => $holiday->getKind(),
			'scope' => $holiday->getScope(),
			'source' => $holiday->getSource(),
		];
	}

	/**
	 * Normalize working time model type from API (accepts full-time/part-time for backward compat).
	 */
	private function normalizeWorkingTimeModelType(string $type): string
	{
		$type = trim($type);
		if ($type === '') {
			return \OCA\ArbeitszeitCheck\Db\WorkingTimeModel::TYPE_FULL_TIME;
		}
		$hyphenToUnderscore = ['full-time' => 'full_time', 'part-time' => 'part_time'];
		return $hyphenToUnderscore[$type] ?? $type;
	}

	/**
	 * Admin dashboard page (admin-only by default; no NoAdminRequired)
	 *
	 */
	#[NoCSRFRequired]
	public function dashboard(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		// Add common CSS files (including colors, typography for consistent fonts)
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

		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'admin-dashboard');

		try {
			// Get statistics
			$totalUsers = $this->userManager->countUsersTotal(0, false);
			if ($totalUsers === false) {
				$totalUsers = 0;
			}

			$today = new \DateTime();
			$activeUsersToday = $this->timeEntryMapper->countDistinctUsersByDate($today);
			$unresolvedCount = $this->violationMapper->count(['resolved' => false]);

			// Get recent violations
			$recentViolations = $this->violationMapper->findUnresolved(10);
			$violationsData = [];
			foreach ($recentViolations as $violation) {
				$user = $this->userManager->get($violation->getUserId());
				$violationsData[] = [
					'id' => $violation->getId(),
					'userId' => $violation->getUserId(),
					'userDisplayName' => $user ? $user->getDisplayName() : $violation->getUserId(),
					'type' => $violation->getViolationType(),
					'severity' => $violation->getSeverity(),
					'date' => $violation->getDate() ? $violation->getDate()->format('Y-m-d') : null,
					'resolved' => $violation->getResolved()
				];
			}

			$response = new TemplateResponse('arbeitszeitcheck', 'admin-dashboard', [
				'statistics' => [
					'total_users' => $totalUsers,
					'active_users_today' => $activeUsersToday,
					'unresolved_violations' => $unresolvedCount
				],
				'recent_violations' => $violationsData,
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
				'showSubstitutionLink' => false,
				'showManagerLink' => true,
				'showReportsLink' => true,
				'showAdminNav' => true,
			]);
			return $this->configureCSP($response, 'admin');
		} catch (\Throwable $e) {
			$response = new TemplateResponse('arbeitszeitcheck', 'admin-dashboard', [
				'statistics' => [
					'total_users' => 0,
					'active_users_today' => 0,
					'unresolved_violations' => 0
				],
				'recent_violations' => [],
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
				'urlGenerator' => $this->urlGenerator,
				'l' => $this->l10n,
				'showSubstitutionLink' => false,
				'showManagerLink' => true,
				'showReportsLink' => true,
				'showAdminNav' => true,
			]);
			return $this->configureCSP($response, 'admin');
		}
	}

	/**
	 * Admin users management page (admin-only by default)
	 *
	 */
	#[NoCSRFRequired]
	public function users(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		// Add common CSS files (including colors, typography for consistent fonts)
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
		Util::addStyle('arbeitszeitcheck', 'admin-users');

		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/datepicker');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'admin-users');

		// Get initial users list (first 50)
		$users = $this->userManager->search('', 50, 0);
		$usersData = [];
		foreach ($users as $user) {
			$userId = $user->getUID();
			$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);
			$workingTimeModel = null;
			if ($currentModel) {
				try {
					$workingTimeModel = $this->workingTimeModelMapper->find($currentModel->getWorkingTimeModelId());
				} catch (\Throwable $e) {
					// Model might have been deleted
				}
			}

			$startDate = $currentModel ? $currentModel->getStartDate() : null;
			$endDate = $currentModel ? $currentModel->getEndDate() : null;

			$usersData[] = [
				'userId' => $userId,
				'displayName' => $user->getDisplayName(),
				'email' => $user->getEMailAddress(),
				'enabled' => $user->isEnabled(),
				'workingTimeModel' => $workingTimeModel ? [
					'id' => $workingTimeModel->getId(),
					'name' => $workingTimeModel->getName()
				] : null,
				'vacationDaysPerYear' => $currentModel ? $currentModel->getVacationDaysPerYear() : null,
				'workingTimeModelStartDate' => $startDate ? $startDate->format('Y-m-d') : null,
				'workingTimeModelEndDate' => $endDate ? $endDate->format('Y-m-d') : null,
			];
		}

		$totalCount = $this->userManager->countUsersTotal(0, false);
		if ($totalCount === false) {
			$totalCount = count($usersData);
		}

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-users', [
			'users' => $usersData,
			'total' => $totalCount,
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
			'showSubstitutionLink' => false,
			'showManagerLink' => true,
			'showReportsLink' => true,
			'showAdminNav' => true,
		]);
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Admin settings page (admin-only by default)
	 *
	 */
	#[NoCSRFRequired]
	public function settings(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		// Add common CSS files (including colors, typography for consistent fonts)
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
		Util::addStyle('arbeitszeitcheck', 'admin-settings');

		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'common/validation');
		Util::addScript('arbeitszeitcheck', 'admin-settings');

		$requireSubstituteJson = $this->appConfig->getAppValueString('require_substitute_types', '[]');
		$requireSubstituteTypes = json_decode($requireSubstituteJson, true);
		if (!is_array($requireSubstituteTypes)) {
			$requireSubstituteTypes = [];
		}
		$settings = [
			'autoComplianceCheck' => $this->appConfig->getAppValueString('auto_compliance_check', '1') === '1',
			'realtimeComplianceCheck' => $this->appConfig->getAppValueString('realtime_compliance_check', '1') === '1',
			'complianceStrictMode' => $this->appConfig->getAppValueString('compliance_strict_mode', '0') === '1',
			'enableViolationNotifications' => $this->appConfig->getAppValueString('enable_violation_notifications', '1') === '1',
			'exportMidnightSplitEnabled' => $this->appConfig->getAppValueString('export_midnight_split_enabled', '1') === '1',
			'requireSubstituteTypes' => $requireSubstituteTypes,
			'sendIcalApprovedAbsences' => $this->appConfig->getAppValueString('send_ical_approved_absences', '1') === '1',
			'sendIcalToSubstitute' => $this->appConfig->getAppValueString('send_ical_to_substitute', '0') === '1',
			'sendIcalToManagers' => $this->appConfig->getAppValueString('send_ical_to_managers', '0') === '1',
			'sendEmailSubstitutionRequest' => $this->appConfig->getAppValueString('send_email_substitution_request', '1') === '1',
			'sendEmailSubstituteApprovedToEmployee' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_employee', '1') === '1',
			'sendEmailSubstituteApprovedToManager' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_manager', '1') === '1',
			'maxDailyHours' => (float)$this->appConfig->getAppValueString('max_daily_hours', '10'),
			'minRestPeriod' => (float)$this->appConfig->getAppValueString('min_rest_period', '11'),
			'germanState' => $this->appConfig->getAppValueString('german_state', 'NW'),
			'statutoryAutoReseed' => $this->appConfig->getAppValueString('statutory_auto_reseed', '1') === '1',
			'retentionPeriod' => (int)$this->appConfig->getAppValueString('retention_period', '2'),
			'defaultWorkingHours' => (float)$this->appConfig->getAppValueString('default_working_hours', '8'),
			'vacationCarryoverExpiryMonth' => max(1, min(12, (int)$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3'))),
			'vacationCarryoverExpiryDay' => max(1, min(31, (int)$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31'))),
		];

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-settings', [
			'settings' => $settings,
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
			'showSubstitutionLink' => false,
			'showManagerLink' => true,
			'showReportsLink' => true,
			'showAdminNav' => true,
		]);
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Admin holidays / calendars page (admin-only by default)
	 *
	 * Dedicated UI to explain and manage holiday calendars per state.
	 */
	#[NoCSRFRequired]
	public function holidays(): TemplateResponse
	{
		// One-time legacy migration: import old company_holidays JSON into at_holidays
		$this->migrateLegacyCompanyHolidaysIfNeeded();

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
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');
		Util::addStyle('arbeitszeitcheck', 'admin-holidays');

		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/datepicker');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'admin-holidays');

		$defaultState = $this->appConfig->getAppValueString('german_state', 'NW');

		$urlGenerator = $this->urlGenerator;

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-holidays', [
			'defaultState' => $defaultState,
			'urlGenerator' => $urlGenerator,
			'l' => $this->l10n,
			'showSubstitutionLink' => false,
			'showManagerLink' => true,
			'showReportsLink' => true,
			'showAdminNav' => true,
		]);

		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Get additional company holidays configuration (legacy, app-wide list).
	 *
	 * New code should use getStateHolidays() which is backed by at_holidays.
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function getCompanyHolidays(): JSONResponse
	{
		try {
			$json = $this->appConfig->getAppValueString('company_holidays', '[]');
			$items = json_decode($json, true);
			if (!is_array($items)) {
				$items = [];
			}

			// Normalize items
			$holidays = [];
			foreach ($items as $item) {
				if (!is_array($item)) {
					continue;
				}
				$date = isset($item['date']) ? (string)$item['date'] : '';
				$name = isset($item['name']) ? (string)$item['name'] : '';
				if ($date === '' || $name === '') {
					continue;
				}
				$holidays[] = [
					'date' => $date,
					'name' => $name,
					'scope' => isset($item['scope']) ? (string)$item['scope'] : '',
					'kind' => isset($item['kind']) && $item['kind'] === 'half' ? 'half' : 'full',
				];
			}

			return new JSONResponse([
				'success' => true,
				'holidays' => $holidays,
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Create or update a single company holiday entry (identified by date).
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function saveCompanyHoliday(): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$date = isset($params['date']) ? trim((string)$params['date']) : '';
			$name = isset($params['name']) ? trim((string)$params['name']) : '';
			$scope = isset($params['scope']) ? trim((string)$params['scope']) : '';
			$kind = isset($params['kind']) && (string)$params['kind'] === 'half' ? 'half' : 'full';

			if ($date === '' || $name === '') {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Date and name are required for a holiday'),
				], Http::STATUS_BAD_REQUEST);
			}

			// Basic date validation (ISO yyyy-mm-dd)
			try {
				$d = new \DateTime($date);
				// Normalize format
				$date = $d->format('Y-m-d');
			} catch (\Throwable $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid date format. Expected yyyy-mm-dd.'),
				], Http::STATUS_BAD_REQUEST);
			}

			// Load existing entries
			$json = $this->appConfig->getAppValueString('company_holidays', '[]');
			$items = json_decode($json, true);
			if (!is_array($items)) {
				$items = [];
			}

			// Upsert by date
			$found = false;
			foreach ($items as &$item) {
				if (isset($item['date']) && (string)$item['date'] === $date) {
					$item['name'] = $name;
					$item['scope'] = $scope;
					$item['kind'] = $kind;
					$found = true;
					break;
				}
			}
			unset($item);

			if (!$found) {
				$items[] = [
					'date' => $date,
					'name' => $name,
					'scope' => $scope,
					'kind' => $kind,
				];
			}

			$this->appConfig->setAppValueString('company_holidays', json_encode($items));

			return new JSONResponse([
				'success' => true,
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Delete a company holiday identified by date.
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function deleteCompanyHoliday(): JSONResponse
	{
		try {
			$date = isset($this->request->getParams()['date']) ? trim((string)$this->request->getParams()['date']) : '';
			if ($date === '') {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Date is required to delete a holiday'),
				], Http::STATUS_BAD_REQUEST);
			}

			$json = $this->appConfig->getAppValueString('company_holidays', '[]');
			$items = json_decode($json, true);
			if (!is_array($items)) {
				$items = [];
			}

			$newItems = [];
			foreach ($items as $item) {
				if (!is_array($item) || !isset($item['date']) || (string)$item['date'] !== $date) {
					$newItems[] = $item;
				}
			}

			$this->appConfig->setAppValueString('company_holidays', json_encode($newItems));

			return new JSONResponse([
				'success' => true,
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get holidays for a given state and year (backed by at_holidays).
	 *
	 * @param string $state
	 * @param int $year
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function getStateHolidays(string $state, int $year): JSONResponse
	{
		try {
			$this->migrateLegacyCompanyHolidaysIfNeeded();

			$state = strtoupper(trim($state));
			if ($state === '') {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('State is required'),
				], Http::STATUS_BAD_REQUEST);
			}
			if ($year < 1970 || $year > 2100) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid year'),
				], Http::STATUS_BAD_REQUEST);
			}

			$start = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
			$end = new \DateTimeImmutable(sprintf('%04d-12-31', $year));

			// Use HolidayService as primary source (DB-backed, incl. any
			// manually erfasste Firmen-/Custom-Feiertage).
			$dtoList = $this->holidayCalendarService->getHolidaysForRange(
				$state,
				new \DateTime($start->format('Y-m-d')),
				new \DateTime($end->format('Y-m-d'))
			);

			// Sicherheitsnetz: Stelle sicher, dass alle gesetzlichen
			// Basis-Feiertage des Jahres immer sichtbar sind, selbst wenn es
			// einmal zu Problemen beim Seeding oder manuellen Änderungen kam.
			// Gesetzliche Feiertage werden hier NICHT aus der DB gelöscht,
			// sondern bei Bedarf nur "virtuell" ergänzt.
			$existingStatutoryByDate = [];
			foreach ($dtoList as $item) {
				if (
					isset($item['scope'], $item['date'])
					&& $item['scope'] === Holiday::SCOPE_STATUTORY
					&& is_string($item['date'])
				) {
					$existingStatutoryByDate[$item['date']] = true;
				}
			}

			try {
				$baseHolidays = HolidayService::getGermanPublicHolidaysForYear($year);
			} catch (\Throwable $e) {
				$baseHolidays = [];
			}

			foreach ($baseHolidays as $dateStr => $name) {
				if (isset($existingStatutoryByDate[$dateStr])) {
					continue;
				}
				$dtoList[] = [
					'id' => null,
					'state' => $state,
					'date' => $dateStr,
					'name' => $this->l10n->t($name),
					'kind' => Holiday::KIND_FULL,
					'scope' => Holiday::SCOPE_STATUTORY,
					'source' => Holiday::SOURCE_GENERATED,
					'weight' => 1.0,
				];
			}

			// Konsistente Sortierung nach Datum
			usort($dtoList, static function (array $a, array $b): int {
				return strcmp((string)($a['date'] ?? ''), (string)($b['date'] ?? ''));
			});

			return new JSONResponse([
				'success' => true,
				'state' => $state,
				'year' => $year,
				'holidays' => $dtoList,
				'period' => [
					'start' => $start->format('Y-m-d'),
					'end' => $end->format('Y-m-d'),
				],
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Create or update a state holiday (backed by at_holidays).
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function saveStateHoliday(): JSONResponse
	{
		try {
			$this->migrateLegacyCompanyHolidaysIfNeeded();

			// Support both traditional form-encoded requests and modern JSON bodies
			$params = $this->request->getParams();
			if ($params === [] || $params === null) {
				$contentType = (string)$this->request->getHeader('Content-Type');
				if (str_contains($contentType, 'application/json')) {
					$raw = @file_get_contents('php://input');
					if (is_string($raw) && $raw !== '') {
						$decoded = json_decode($raw, true);
						if (is_array($decoded)) {
							$params = $decoded;
						}
					}
				}
				if (!is_array($params)) {
					$params = [];
				}
			}
			$id = isset($params['id']) ? (int)$params['id'] : 0;
			$state = isset($params['state']) ? strtoupper(trim((string)$params['state'])) : '';
			$date = isset($params['date']) ? trim((string)$params['date']) : '';
			$name = isset($params['name']) ? trim((string)$params['name']) : '';
			$kind = isset($params['kind']) && (string)$params['kind'] === Holiday::KIND_HALF ? Holiday::KIND_HALF : Holiday::KIND_FULL;
			$scope = isset($params['scope']) ? trim((string)$params['scope']) : Holiday::SCOPE_COMPANY;

			if ($state === '' || $date === '' || $name === '') {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('State, date, and name are required for a holiday'),
				], Http::STATUS_BAD_REQUEST);
			}

			try {
				$dateObj = new \DateTime($date);
				$dateObj->setTime(0, 0, 0);
			} catch (\Throwable $e) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid date format. Expected yyyy-mm-dd.'),
				], Http::STATUS_BAD_REQUEST);
			}

			if (!in_array($scope, [Holiday::SCOPE_STATUTORY, Holiday::SCOPE_COMPANY, Holiday::SCOPE_CUSTOM], true)) {
				$scope = Holiday::SCOPE_COMPANY;
			}

			$holiday = new Holiday();
			$oldValues = null;
			if ($id > 0) {
				$holiday->setId($id);
				// Load existing holiday for audit log (best-effort).
				try {
					$existing = $this->holidayMapper->findById($id);
					$oldValues = $this->holidayToAuditValues($existing);
				} catch (\Throwable) {
					$oldValues = null;
				}
			}
			if ($holiday->getCreatedAt() === null) {
				$holiday->setCreatedAt(new \DateTime());
			}

			$holiday->setState($state);
			$holiday->setDate($dateObj);
			$holiday->setName($name);
			$holiday->setKind($kind);
			$holiday->setScope($scope);
			$holiday->setSource(Holiday::SOURCE_MANUAL);
			$holiday->setUpdatedAt(new \DateTime());

			if (!$holiday->isValid()) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Holiday definition is invalid'),
				], Http::STATUS_BAD_REQUEST);
			}

			if ($id > 0) {
				$holiday = $this->holidayMapper->update($holiday);
				$action = 'state_holiday_updated';
			} else {
				$holiday = $this->holidayMapper->insert($holiday);
				$action = 'state_holiday_created';
			}

			// Ensure subsequent reads see the updated set of holidays
			$this->holidayCalendarService->clearCacheForStateYear($state, (int)$dateObj->format('Y'));

			// Audit log
			$newValues = $this->holidayToAuditValues($holiday);
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$performedBy,
				$action,
				'state_holiday',
				$holiday->getId(),
				$oldValues,
				$newValues,
				$performedBy
			);

			return new JSONResponse([
				'success' => true,
				'holiday' => [
					'id' => $holiday->getId(),
					'state' => $holiday->getState(),
					'date' => $holiday->getDate() ? $holiday->getDate()->format('Y-m-d') : null,
					'name' => $holiday->getName(),
					'kind' => $holiday->getKind(),
					'scope' => $holiday->getScope(),
					'source' => $holiday->getSource(),
				],
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Delete a state holiday by ID (backed by at_holidays).
	 *
	 * @param int $id
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function deleteStateHoliday(int $id): JSONResponse
	{
		try {
			if ($id <= 0) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Invalid holiday ID'),
				], Http::STATUS_BAD_REQUEST);
			}

			// Resolve state/year before deletion so we can clear the cache precisely.
			$state = '';
			$year = null;
			$oldValues = null;
			try {
				$existing = $this->holidayMapper->findById($id);
				if ($existing !== null) {
					$oldValues = $this->holidayToAuditValues($existing);
					$state = $existing->getState();
					$date = $existing->getDate();
					if ($date !== null) {
						$year = (int)$date->format('Y');
					}
				}
			} catch (DoesNotExistException $e) {
				// Idempotent delete: if the holiday is already gone, treat this
				// as success to keep the admin UI robust and avoid confusing 404s.
				return new JSONResponse([
					'success' => true,
				]);
			}

			$this->holidayMapper->deleteById($id);

			if ($state !== '' && $year !== null) {
				$this->holidayCalendarService->clearCacheForStateYear($state, $year);
			}

			// Audit log: deletion
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$performedBy,
				'state_holiday_deleted',
				'state_holiday',
				$id,
				$oldValues,
				null,
				$performedBy
			);

			return new JSONResponse([
				'success' => true,
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Holiday not found'),
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * One-time migration of legacy app config "company_holidays" into at_holidays.
	 * After successful migration a flag "company_holidays_migrated" is set so this
	 * method becomes a cheap no-op.
	 */
	private function migrateLegacyCompanyHolidaysIfNeeded(): void
	{
		try {
			$alreadyMigrated = $this->appConfig->getAppValueString('company_holidays_migrated', '0') === '1';
			if ($alreadyMigrated) {
				return;
			}

			$json = $this->appConfig->getAppValueString('company_holidays', '[]');
			$items = json_decode($json, true);
			if (!is_array($items) || $items === []) {
				$this->appConfig->setAppValueString('company_holidays_migrated', '1');
				return;
			}

			$states = [
				'BW', 'BY', 'BE', 'BB', 'HB', 'HH', 'HE', 'MV',
				'NI', 'NW', 'RP', 'SL', 'SN', 'ST', 'SH', 'TH',
			];

			$now = new \DateTime();

			foreach ($items as $item) {
				if (!is_array($item)) {
					continue;
				}
				$dateStr = isset($item['date']) ? (string)$item['date'] : '';
				$name = isset($item['name']) ? trim((string)$item['name']) : '';
				if ($dateStr === '' || $name === '') {
					continue;
				}
				$kind = (isset($item['kind']) && (string)$item['kind'] === 'half') ? Holiday::KIND_HALF : Holiday::KIND_FULL;

				try {
					$date = new \DateTime($dateStr);
					$date->setTime(0, 0, 0);
				} catch (\Throwable) {
					continue;
				}

				foreach ($states as $state) {
					$existing = $this->holidayMapper->findByStateAndRange($state, $date, $date);
					$duplicate = false;
					foreach ($existing as $existingHoliday) {
						if ($existingHoliday->getScope() === Holiday::SCOPE_COMPANY
							&& $existingHoliday->getName() === $name) {
							$duplicate = true;
							break;
						}
					}
					if ($duplicate) {
						continue;
					}

					$holiday = new Holiday();
					$holiday->setState($state);
					$holiday->setDate(clone $date);
					$holiday->setName($name);
					$holiday->setKind($kind);
					$holiday->setScope(Holiday::SCOPE_COMPANY);
					$holiday->setSource(Holiday::SOURCE_MANUAL);
					$holiday->setCreatedAt(clone $now);
					$holiday->setUpdatedAt(clone $now);

					if (!$holiday->isValid()) {
						continue;
					}

					try {
						$this->holidayMapper->insert($holiday);
					} catch (\Throwable) {
						// ignore individual insert errors, continue with others
					}
				}
			}

			$this->appConfig->setAppValueString('company_holidays_migrated', '1');
		} catch (\Throwable) {
			// Never break admin UI because of a failed migration; it can be retried later.
		}
	}

	/**
	 * Admin working time models management page (admin-only by default)
	 *
	 */
	#[NoCSRFRequired]
	public function workingTimeModels(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		// Add common CSS files (including colors, typography for consistent fonts)
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

		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'common/validation');
		Util::addScript('arbeitszeitcheck', 'working-time-models');

		$models = $this->workingTimeModelMapper->findAll();
		$modelsData = [];
		foreach ($models as $model) {
			$modelsData[] = [
				'id' => $model->getId(),
				'name' => $model->getName(),
				'description' => $model->getDescription(),
				'type' => $model->getType(),
				'weeklyHours' => $model->getWeeklyHours(),
				'dailyHours' => $model->getDailyHours(),
				'isDefault' => $model->getIsDefault()
			];
		}

		$response = new TemplateResponse('arbeitszeitcheck', 'working-time-models', [
			'models' => $modelsData,
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
			'showSubstitutionLink' => false,
			'showManagerLink' => true,
			'showReportsLink' => true,
			'showAdminNav' => true,
		]);
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Admin audit log viewer page (admin-only by default)
	 *
	 */
	#[NoCSRFRequired]
	public function auditLog(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		// Add common CSS files (including colors, typography for consistent fonts)
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
		Util::addStyle('arbeitszeitcheck', 'audit-log');

		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/datepicker');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'audit-log-viewer');

		// Get recent audit logs (last 30 days, first 50)
		$endDate = new \DateTime();
		$endDate->setTime(23, 59, 59);
		$startDate = clone $endDate;
		$startDate->modify('-30 days');
		$startDate->setTime(0, 0, 0);

		$logs = $this->auditLogMapper->findByDateRange($startDate, $endDate, null, null, null);
		$logs = array_slice($logs, 0, 50);

		$logsData = [];
		foreach ($logs as $log) {
			$user = $this->userManager->get($log->getUserId());
			$performedBy = $log->getPerformedBy() ? $this->userManager->get($log->getPerformedBy()) : null;

			$logsData[] = [
				'id' => $log->getId(),
				'userId' => $log->getUserId(),
				'userDisplayName' => $user ? $user->getDisplayName() : $log->getUserId(),
				'action' => $this->l10n->t($log->getAction()),
				'entityType' => $log->getEntityType(),
				'entityId' => $log->getEntityId(),
				'performedBy' => $log->getPerformedBy(),
				'performedByDisplayName' => $performedBy ? $performedBy->getDisplayName() : ($log->getPerformedBy() ?? $log->getUserId()),
				'createdAt' => ($createdAt = $log->getCreatedAt()) ? $createdAt->format('Y-m-d H:i:s') : null
			];
		}

		$response = new TemplateResponse('arbeitszeitcheck', 'audit-log', [
			'logs' => $logsData,
			'total' => count($logs),
			'startDate' => $startDate->format('d.m.Y'),
			'endDate' => $endDate->format('d.m.Y'),
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
			'showSubstitutionLink' => false,
			'showManagerLink' => true,
			'showReportsLink' => true,
			'showAdminNav' => true,
		]);
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Get admin settings (admin-only by default)
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function getAdminSettings(): JSONResponse
	{
		try {
			$requireSubstituteJson = $this->appConfig->getAppValueString('require_substitute_types', '[]');
			$requireSubstituteTypes = json_decode($requireSubstituteJson, true);
			if (!is_array($requireSubstituteTypes)) {
				$requireSubstituteTypes = [];
			}
			$settings = [
				'autoComplianceCheck' => $this->appConfig->getAppValueString('auto_compliance_check', '1') === '1',
				'enableViolationNotifications' => $this->appConfig->getAppValueString('enable_violation_notifications', '1') === '1',
				'exportMidnightSplitEnabled' => $this->appConfig->getAppValueString('export_midnight_split_enabled', '1') === '1',
				'requireSubstituteTypes' => $requireSubstituteTypes,
				'sendIcalApprovedAbsences' => $this->appConfig->getAppValueString('send_ical_approved_absences', '1') === '1',
				'sendIcalToSubstitute' => $this->appConfig->getAppValueString('send_ical_to_substitute', '0') === '1',
				'sendIcalToManagers' => $this->appConfig->getAppValueString('send_ical_to_managers', '0') === '1',
				'sendEmailSubstitutionRequest' => $this->appConfig->getAppValueString('send_email_substitution_request', '1') === '1',
				'sendEmailSubstituteApprovedToEmployee' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_employee', '1') === '1',
				'sendEmailSubstituteApprovedToManager' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_manager', '1') === '1',
				'maxDailyHours' => (float)$this->appConfig->getAppValueString('max_daily_hours', '10'),
				'minRestPeriod' => (float)$this->appConfig->getAppValueString('min_rest_period', '11'),
				'germanState' => $this->appConfig->getAppValueString('german_state', 'NW'),
				'statutoryAutoReseed' => $this->appConfig->getAppValueString('statutory_auto_reseed', '1') === '1',
				'retentionPeriod' => (int)$this->appConfig->getAppValueString('retention_period', '2'),
				'defaultWorkingHours' => (float)$this->appConfig->getAppValueString('default_working_hours', '8'),
				'vacationCarryoverExpiryMonth' => max(1, min(12, (int)$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH, '3'))),
				'vacationCarryoverExpiryDay' => max(1, min(31, (int)$this->appConfig->getAppValueString(Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY, '31'))),
			];

			return new JSONResponse([
				'success' => true,
				'settings' => $settings
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update admin settings (admin-only by default)
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function updateAdminSettings(): JSONResponse
	{
		try {
			$params = $this->request->getParams();

			// List of allowed admin settings keys
			$allowedKeys = [
				'autoComplianceCheck' => 'auto_compliance_check',
				'realtimeComplianceCheck' => 'realtime_compliance_check',
				'complianceStrictMode' => 'compliance_strict_mode',
				'enableViolationNotifications' => 'enable_violation_notifications',
				'exportMidnightSplitEnabled' => 'export_midnight_split_enabled',
				'requireSubstituteTypes' => 'require_substitute_types',
				'sendIcalApprovedAbsences' => 'send_ical_approved_absences',
				'sendIcalToSubstitute' => 'send_ical_to_substitute',
				'sendIcalToManagers' => 'send_ical_to_managers',
				'sendEmailSubstitutionRequest' => 'send_email_substitution_request',
				'sendEmailSubstituteApprovedToEmployee' => 'send_email_substitute_approved_to_employee',
				'sendEmailSubstituteApprovedToManager' => 'send_email_substitute_approved_to_manager',
				'maxDailyHours' => 'max_daily_hours',
				'minRestPeriod' => 'min_rest_period',
				'germanState' => 'german_state',
				'statutoryAutoReseed' => 'statutory_auto_reseed',
				'retentionPeriod' => 'retention_period',
				'defaultWorkingHours' => 'default_working_hours',
				'vacationCarryoverExpiryMonth' => Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_MONTH,
				'vacationCarryoverExpiryDay' => Constants::CONFIG_VACATION_CARRYOVER_EXPIRY_DAY,
			];

			$updatedSettings = [];

			// Update each setting if provided
			foreach ($allowedKeys as $paramKey => $configKey) {
				if (isset($params[$paramKey])) {
					$value = $params[$paramKey];

					// Validate and convert value based on type
					if (in_array($paramKey, [
						'autoComplianceCheck', 'realtimeComplianceCheck', 'complianceStrictMode', 'enableViolationNotifications',
						'exportMidnightSplitEnabled',
						'sendIcalApprovedAbsences', 'sendIcalToSubstitute', 'sendIcalToManagers',
						'sendEmailSubstitutionRequest', 'sendEmailSubstituteApprovedToEmployee', 'sendEmailSubstituteApprovedToManager',
						'statutoryAutoReseed'
					], true)) {
						$value = ($value === true || $value === 'true' || $value === '1') ? '1' : '0';
					} elseif ($paramKey === 'maxDailyHours' || $paramKey === 'minRestPeriod' || $paramKey === 'defaultWorkingHours') {
						$value = (string)max(0, (float)$value);
						// Validate ranges
						if ($paramKey === 'maxDailyHours' && ((float)$value < 1 || (float)$value > 24)) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Maximum daily hours must be between 1 and 24')
							], Http::STATUS_BAD_REQUEST);
						}
						if ($paramKey === 'minRestPeriod' && ((float)$value < 1 || (float)$value > 24)) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Minimum rest period must be between 1 and 24 hours')
							], Http::STATUS_BAD_REQUEST);
						}
					} elseif ($paramKey === 'retentionPeriod') {
						$value = (string)max(1, min(10, (int)$value));
					} elseif ($paramKey === 'germanState') {
						$validStates = ['NW', 'BY', 'BW', 'HE', 'NI', 'RP', 'SL', 'BE', 'BB', 'HB', 'HH', 'MV', 'SN', 'ST', 'SH', 'TH'];
						if (!in_array($value, $validStates)) {
							return new JSONResponse([
								'success' => false,
								'error' => $this->l10n->t('Invalid German state code')
							], Http::STATUS_BAD_REQUEST);
						}
						$value = (string)$value;
					} elseif ($paramKey === 'vacationCarryoverExpiryMonth') {
						$m = max(1, min(12, (int)$value));
						$value = (string)$m;
					} elseif ($paramKey === 'vacationCarryoverExpiryDay') {
						$d = max(1, min(31, (int)$value));
						$value = (string)$d;
					} elseif ($paramKey === 'requireSubstituteTypes') {
						$validTypes = ['vacation', 'sick_leave', 'personal_leave', 'parental_leave', 'special_leave', 'unpaid_leave', 'home_office', 'business_trip'];
						$arr = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : []);
						if (!is_array($arr)) {
							$arr = [];
						}
						$arr = array_values(array_unique(array_filter($arr, function ($t) use ($validTypes) {
							return in_array((string)$t, $validTypes, true);
						})));
						$value = json_encode($arr);
					} else {
						$value = (string)$value;
					}

					$this->appConfig->setAppValueString($configKey, $value);
					$updatedSettings[$paramKey] = $value;
				}
			}

			if (empty($updatedSettings)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('No valid settings provided')
				], Http::STATUS_BAD_REQUEST);
			}

			return new JSONResponse([
				'success' => true,
				'message' => $this->l10n->t('Settings updated successfully'),
				'settings' => $updatedSettings
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get admin dashboard statistics (admin-only by default)
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function getStatistics(): JSONResponse
	{
		try {
			// Get total users count
			$totalUsers = $this->userManager->countUsersTotal(0, false);
			if ($totalUsers === false) {
				$totalUsers = 0;
			}

			// Get active users today (users with time entries today)
			$today = new \DateTime();
			$activeUsersToday = $this->timeEntryMapper->countDistinctUsersByDate($today);

			// Get system compliance status (unresolved violations count)
			$unresolvedCount = $this->violationMapper->count(['resolved' => false]);

			// Calculate compliance percentage (users with no unresolved violations)
			// Use findUnresolved to get distinct users with violations (limited to avoid memory issues)
			// For large installations, we'll use a more efficient approach
			$unresolvedViolations = $this->violationMapper->findUnresolved(1000); // Limit to 1000 for performance
			$usersWithViolations = [];
			foreach ($unresolvedViolations as $violation) {
				$userId = $violation->getUserId();
				if (!isset($usersWithViolations[$userId])) {
					$usersWithViolations[$userId] = true;
				}
			}
			$usersWithViolationsCount = count($usersWithViolations);
			// If we hit the limit, estimate that there might be more users with violations
			// For accuracy, we'd need to query distinct user_ids, but this is a reasonable approximation
			$compliantUsers = max(0, $totalUsers - $usersWithViolationsCount);
			$compliancePercentage = $totalUsers > 0 ? round(($compliantUsers / $totalUsers) * 100, 1) : 100.0;

			return new JSONResponse([
				'success' => true,
				'statistics' => [
					'total_users' => $totalUsers,
					'active_users_today' => $activeUsersToday,
					'unresolved_violations' => $unresolvedCount,
					'compliance_percentage' => $compliancePercentage,
					'compliant_users' => $compliantUsers
				]
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get list of all users with their working time models
	 *
	 * @param string|null $search Search query
	 * @param int|null $limit
	 * @param int|null $offset
	 * @return JSONResponse
	 */
	public function getUsers(?string $search = null, ?int $limit = 50, ?int $offset = 0): JSONResponse
	{
		try {
			// Get all users from Nextcloud
			$users = $this->userManager->search($search ?? '', $limit, $offset);

			$usersData = [];
			$currentYear = (int)date('Y');
			foreach ($users as $user) {
				$userId = $user->getUID();

				// Get current working time model assignment
				$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);

				// Get working time model details if assigned
				$workingTimeModel = null;
				if ($currentModel) {
					try {
						$workingTimeModel = $this->workingTimeModelMapper->find($currentModel->getWorkingTimeModelId());
					} catch (\Throwable $e) {
						// Model might have been deleted
					}
				}

				// Get user statistics (per-user: does this user have entries today?)
				$today = new \DateTime();
				$today->setTime(0, 0, 0);
				$hasTimeEntriesToday = $this->timeEntryMapper->hasEntriesOnDate($userId, $today);

				$usersData[] = [
					'userId' => $userId,
					'displayName' => $user->getDisplayName(),
					'email' => $user->getEMailAddress(),
					'enabled' => $user->isEnabled(),
					'workingTimeModel' => $workingTimeModel ? [
						'id' => $workingTimeModel->getId(),
						'name' => $workingTimeModel->getName(),
						'type' => $workingTimeModel->getType(),
						'weeklyHours' => $workingTimeModel->getWeeklyHours(),
						'dailyHours' => $workingTimeModel->getDailyHours()
					] : null,
					'vacationDaysPerYear' => $currentModel ? $currentModel->getVacationDaysPerYear() : null,
					'workingTimeModelStartDate' => $currentModel && ($startDate = $currentModel->getStartDate()) ? $startDate->format('Y-m-d') : null,
					'workingTimeModelEndDate' => $currentModel && ($endDate = $currentModel->getEndDate()) ? $endDate->format('Y-m-d') : null,
					'hasTimeEntriesToday' => $hasTimeEntriesToday,
					'vacationCarryoverDays' => $this->vacationYearBalanceMapper->getCarryoverDays($userId, $currentYear),
					'vacationCarryoverYear' => $currentYear,
				];
			}

			// Get total count for pagination
			$totalCount = $this->userManager->countUsersTotal(0, false);
			if ($totalCount === false) {
				$totalCount = count($users);
			}

			return new JSONResponse([
				'success' => true,
				'users' => $usersData,
				'total' => $totalCount
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get user details including working time model
	 *
	 * @param string $userId
	 * @return JSONResponse
	 */
	public function getUser(string $userId): JSONResponse
	{
		try {
			$user = $this->userManager->get($userId);
			if (!$user) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not found')
				], Http::STATUS_NOT_FOUND);
			}

			// Get current working time model assignment
			$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);

			// Get working time model details if assigned
			$workingTimeModel = null;
			if ($currentModel) {
				try {
					$workingTimeModel = $this->workingTimeModelMapper->find($currentModel->getWorkingTimeModelId());
				} catch (\Throwable $e) {
					// Model might have been deleted
				}
			}

			// Get all available working time models
			$allModels = $this->workingTimeModelMapper->findAll();

			// Resolve Bundesland / holiday calendar for this user:
			// per-user setting (german_state) falls back to global default.
			$defaultState = $this->appConfig->getAppValueString('german_state', 'NW');
			$userGermanState = $this->userSettingsMapper->getStringSetting($userId, 'german_state', $defaultState);

			$startDate = $currentModel ? $currentModel->getStartDate() : null;
			$endDate = $currentModel ? $currentModel->getEndDate() : null;
			$currentYear = (int)date('Y');

			return new JSONResponse([
				'success' => true,
				'user' => [
					'userId' => $userId,
					'displayName' => $user->getDisplayName(),
					'email' => $user->getEMailAddress(),
					'enabled' => $user->isEnabled(),
					'vacationCarryoverDays' => $this->vacationYearBalanceMapper->getCarryoverDays($userId, $currentYear),
					'vacationCarryoverYear' => $currentYear,
					'workingTimeModel' => $workingTimeModel ? [
						'id' => $workingTimeModel->getId(),
						'name' => $workingTimeModel->getName(),
						'type' => $workingTimeModel->getType(),
						'weeklyHours' => $workingTimeModel->getWeeklyHours(),
						'dailyHours' => $workingTimeModel->getDailyHours()
					] : null,
					'vacationDaysPerYear' => $currentModel ? $currentModel->getVacationDaysPerYear() : null,
					'workingTimeModelStartDate' => $startDate ? $startDate->format('Y-m-d') : null,
					'workingTimeModelEndDate' => $endDate ? $endDate->format('Y-m-d') : null,
					'germanState' => $userGermanState,
					'userWorkingTimeModel' => $currentModel ? $currentModel->getSummary() : null,
					'availableWorkingTimeModels' => array_map(function ($model) {
						return [
							'id' => $model->getId(),
							'name' => $model->getName(),
							'type' => $model->getType(),
							'weeklyHours' => $model->getWeeklyHours(),
							'dailyHours' => $model->getDailyHours()
						];
					}, $allModels)
				]
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update user working time model assignment
	 *
	 * @param string $userId
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function updateUserWorkingTimeModel(string $userId): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$workingTimeModelIdRaw = $params['workingTimeModelId'] ?? null;
			$workingTimeModelId = ($workingTimeModelIdRaw !== null && $workingTimeModelIdRaw !== '')
				? (int)$workingTimeModelIdRaw
				: null;
			$vacationDaysPerYear = isset($params['vacationDaysPerYear']) ? (int)$params['vacationDaysPerYear'] : null;
			$startDate = $params['startDate'] ?? null;
			$endDate = $params['endDate'] ?? null;
			$germanState = isset($params['germanState']) ? (string)$params['germanState'] : null;

			if ($vacationDaysPerYear !== null && ($vacationDaysPerYear < 0 || $vacationDaysPerYear > 366)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Vacation days per year must be between 0 and 366')
				], Http::STATUS_BAD_REQUEST);
			}

			// Validate user exists
			$user = $this->userManager->get($userId);
			if (!$user) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not found')
				], Http::STATUS_NOT_FOUND);
			}

			// Validate working time model exists if provided
			if ($workingTimeModelId !== null) {
				try {
					$this->workingTimeModelMapper->find($workingTimeModelId);
				} catch (DoesNotExistException $e) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Working time model not found')
					], Http::STATUS_NOT_FOUND);
				} catch (\Throwable $e) {
					\OCP\Log\logger('arbeitszeitcheck')->error('Error validating working time model: ' . $e->getMessage(), ['exception' => $e]);
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Validation failed. Please check your input.')
					], Http::STATUS_BAD_REQUEST);
				}
			}

			// Validate germanState if provided (optional; empty string means "use global default")
			if ($germanState !== null && $germanState !== '') {
				$validStates = ['NW', 'BY', 'BW', 'HE', 'NI', 'RP', 'SL', 'BE', 'BB', 'HB', 'HH', 'MV', 'SN', 'ST', 'SH', 'TH'];
				if (!in_array($germanState, $validStates, true)) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Invalid German state code')
					], Http::STATUS_BAD_REQUEST);
				}
			}

			// Get current assignment
			$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);

			$oldValues = $currentModel ? $this->userWorkingTimeModelToAuditValues($currentModel) : null;

			if ($currentModel && $workingTimeModelId !== null && $workingTimeModelId > 0) {
				// Update existing assignment
				if ($startDate) {
					$currentModel->setStartDate(new \DateTime($startDate));
				}
				if ($endDate !== null) {
					$currentModel->setEndDate($endDate ? new \DateTime($endDate) : null);
				}
				$currentModel->setWorkingTimeModelId($workingTimeModelId);
				if ($vacationDaysPerYear !== null) {
					$currentModel->setVacationDaysPerYear($vacationDaysPerYear);
				}
				$currentModel->setUpdatedAt(new \DateTime());

				// Validate
				$errors = $currentModel->validate();
				if (!empty($errors)) {
					// Translate validation errors
					$translatedErrors = [];
					foreach ($errors as $field => $message) {
						$translatedErrors[$field] = $this->l10n->t($message);
					}
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Validation failed'),
						'errors' => $translatedErrors
					], Http::STATUS_BAD_REQUEST);
				}

				$updated = $this->userWorkingTimeModelMapper->update($currentModel);
				$newValues = $this->userWorkingTimeModelToAuditValues($updated);
				$this->auditLogMapper->logAction(
					$userId,
					'user_working_time_model_updated',
					'user_working_time_model',
					$updated->getId(),
					$oldValues,
					$newValues,
					$this->getPerformedBy()
				);
			} elseif ($workingTimeModelId !== null && $workingTimeModelId > 0) {
				// Create new assignment
				$newModel = new \OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel();
				$newModel->setUserId($userId);
				$newModel->setWorkingTimeModelId($workingTimeModelId);
				$newModel->setVacationDaysPerYear($vacationDaysPerYear ?? Constants::DEFAULT_VACATION_DAYS_PER_YEAR);
				$newModel->setStartDate(new \DateTime($startDate ?? 'now'));
				if ($endDate) {
					$newModel->setEndDate(new \DateTime($endDate));
				}
				$newModel->setCreatedAt(new \DateTime());
				$newModel->setUpdatedAt(new \DateTime());

				// Validate
				$errors = $newModel->validate();
				if (!empty($errors)) {
					return new JSONResponse([
						'success' => false,
						'error' => 'Validation failed',
						'errors' => $errors
					], Http::STATUS_BAD_REQUEST);
				}

				$updated = $this->userWorkingTimeModelMapper->insert($newModel);
				$newValues = $this->userWorkingTimeModelToAuditValues($updated);
				$this->auditLogMapper->logAction(
					$userId,
					'user_working_time_model_created',
					'user_working_time_model',
					$updated->getId(),
					null,
					$newValues,
					$this->getPerformedBy()
				);
			} elseif ($workingTimeModelId === null || $workingTimeModelId === 0) {
				// Remove assignment: end current assignment when user selects "No Model Assigned"
				if ($currentModel) {
					$endDateForRemoval = $endDate ? new \DateTime($endDate) : new \DateTime();
					$updated = $this->userWorkingTimeModelMapper->endCurrentAssignment($userId, $endDateForRemoval);
					$newValues = $updated ? $this->userWorkingTimeModelToAuditValues($updated) : null;
					$this->auditLogMapper->logAction(
						$userId,
						'user_working_time_model_ended',
						'user_working_time_model',
						$currentModel->getId(),
						$oldValues,
						$newValues,
						$this->getPerformedBy()
					);
				} else {
					$updated = null;
				}
			} else {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Working time model ID is required')
				], Http::STATUS_BAD_REQUEST);
			}

			// Persist per-user Bundesland / holiday calendar selection.
			// Empty string clears the user-specific setting and falls back to global default.
			if ($germanState !== null) {
				if ($germanState === '') {
					$this->userSettingsMapper->deleteSetting($userId, 'german_state');
				} else {
					$this->userSettingsMapper->setSetting($userId, 'german_state', $germanState);
				}
			}

			// Vacation carryover (Resturlaub) for a calendar year — admin-only via this API.
			if (array_key_exists('vacationCarryoverDays', $params) && $params['vacationCarryoverDays'] !== '' && $params['vacationCarryoverDays'] !== null) {
				$carryoverYear = isset($params['vacationCarryoverYear']) ? (int)$params['vacationCarryoverYear'] : (int)date('Y');
				if ($carryoverYear < 2000 || $carryoverYear > 2100) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Invalid year for vacation carryover')
					], Http::STATUS_BAD_REQUEST);
				}
				$carryoverVal = (float)$params['vacationCarryoverDays'];
				if ($carryoverVal < 0 || $carryoverVal > 366) {
					return new JSONResponse([
						'success' => false,
						'error' => $this->l10n->t('Vacation carryover must be between 0 and 366 days')
					], Http::STATUS_BAD_REQUEST);
				}
				$this->vacationYearBalanceMapper->upsert($userId, $carryoverYear, $carryoverVal);
			}

			return new JSONResponse([
				'success' => true,
				'userWorkingTimeModel' => $updated !== null ? $updated->getSummary() : null
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get assignment history for a user (all past and current work schedule assignments)
	 *
	 * @param string $userId
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function getUserAssignmentHistory(string $userId): JSONResponse
	{
		try {
			$user = $this->userManager->get($userId);
			if (!$user) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not found')
				], Http::STATUS_NOT_FOUND);
			}

			$assignments = $this->userWorkingTimeModelMapper->findByUser($userId);
			$history = [];
			foreach ($assignments as $assignment) {
				$modelName = null;
				try {
					$model = $this->workingTimeModelMapper->find($assignment->getWorkingTimeModelId());
					$modelName = $model->getName();
				} catch (\Throwable $e) {
					$modelName = $this->l10n->t('Unknown (deleted)');
				}
				$startDate = $assignment->getStartDate();
				$endDate = $assignment->getEndDate();
				$history[] = [
					'id' => $assignment->getId(),
					'workingTimeModelId' => $assignment->getWorkingTimeModelId(),
					'modelName' => $modelName,
					'vacationDaysPerYear' => $assignment->getVacationDaysPerYear(),
					'startDate' => $startDate ? $startDate->format('Y-m-d') : null,
					'endDate' => $endDate ? $endDate->format('Y-m-d') : null,
					'isActive' => $assignment->isActive(),
				];
			}

			return new JSONResponse([
				'success' => true,
				'history' => $history
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get all available working time models (admin-only by default)
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function getWorkingTimeModels(): JSONResponse
	{
		try {
			$models = $this->workingTimeModelMapper->findAll();

			return new JSONResponse([
				'success' => true,
				'models' => array_map(function ($model) {
					return [
						'id' => $model->getId(),
						'name' => $model->getName(),
						'description' => $model->getDescription(),
						'type' => $model->getType(),
						'weeklyHours' => $model->getWeeklyHours(),
						'dailyHours' => $model->getDailyHours(),
						'isDefault' => $model->getIsDefault()
					];
				}, $models)
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get working time model by ID
	 *
	 * @param int $id
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function getWorkingTimeModel(int $id): JSONResponse
	{
		try {
			$model = $this->workingTimeModelMapper->find($id);

			return new JSONResponse([
				'success' => true,
				'model' => [
					'id' => $model->getId(),
					'name' => $model->getName(),
					'description' => $model->getDescription(),
					'type' => $model->getType(),
					'weeklyHours' => $model->getWeeklyHours(),
					'dailyHours' => $model->getDailyHours(),
					'breakRules' => $model->getBreakRulesArray(),
					'overtimeRules' => $model->getOvertimeRulesArray(),
					'isDefault' => $model->getIsDefault()
				]
			]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Working time model not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Create a new working time model
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function createWorkingTimeModel(): JSONResponse
	{
		try {
			$params = $this->request->getParams();

			$model = new \OCA\ArbeitszeitCheck\Db\WorkingTimeModel();
			$model->setName($params['name'] ?? '');
			$model->setDescription($params['description'] ?? null);
			$defaultDaily = max(0.5, min(24.0, (float)$this->appConfig->getAppValueString('default_working_hours', '8')));
			$model->setType($this->normalizeWorkingTimeModelType($params['type'] ?? ''));
			$model->setWeeklyHours(isset($params['weeklyHours']) ? (float)$params['weeklyHours'] : 40.0);
			$model->setDailyHours(isset($params['dailyHours']) ? (float)$params['dailyHours'] : $defaultDaily);
			$model->setIsDefault(isset($params['isDefault']) ? (bool)$params['isDefault'] : false);
			$model->setCreatedAt(new \DateTime());
			$model->setUpdatedAt(new \DateTime());

			// Handle break rules and overtime rules if provided
			if (isset($params['breakRules']) && is_array($params['breakRules'])) {
				$model->setBreakRulesArray($params['breakRules']);
			}
			if (isset($params['overtimeRules']) && is_array($params['overtimeRules'])) {
				$model->setOvertimeRulesArray($params['overtimeRules']);
			}

			// Validate
			$errors = $model->validate();
			if (!empty($errors)) {
				// Translate validation errors
				$translatedErrors = [];
				foreach ($errors as $field => $message) {
					$translatedErrors[$field] = $this->l10n->t($message);
				}
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Validation failed'),
					'errors' => $translatedErrors
				], Http::STATUS_BAD_REQUEST);
			}

			// If this is set as default, unset other defaults
			if ($model->getIsDefault()) {
				$currentDefault = $this->workingTimeModelMapper->findDefault();
				if ($currentDefault) {
					$currentDefault->setIsDefault(false);
					$currentDefault->setUpdatedAt(new \DateTime());
					$this->workingTimeModelMapper->update($currentDefault);
				}
			}

			$savedModel = $this->workingTimeModelMapper->insert($model);
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$performedBy,
				'working_time_model_created',
				'working_time_model',
				$savedModel->getId(),
				null,
				$this->workingTimeModelToAuditValues($savedModel),
				$performedBy
			);

			return new JSONResponse([
				'success' => true,
				'model' => [
					'id' => $savedModel->getId(),
					'name' => $savedModel->getName(),
					'description' => $savedModel->getDescription(),
					'type' => $savedModel->getType(),
					'weeklyHours' => $savedModel->getWeeklyHours(),
					'dailyHours' => $savedModel->getDailyHours(),
					'isDefault' => $savedModel->getIsDefault()
				]
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update a working time model
	 *
	 * @param int $id
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function updateWorkingTimeModel(int $id): JSONResponse
	{
		try {
			$model = $this->workingTimeModelMapper->find($id);
			$oldValues = $this->workingTimeModelToAuditValues($model);
			$params = $this->request->getParams();

			if (isset($params['name'])) {
				$model->setName($params['name']);
			}
			if (isset($params['description'])) {
				$model->setDescription($params['description']);
			}
			if (isset($params['type'])) {
				$model->setType($this->normalizeWorkingTimeModelType($params['type']));
			}
			if (isset($params['weeklyHours'])) {
				$model->setWeeklyHours((float)$params['weeklyHours']);
			}
			if (isset($params['dailyHours'])) {
				$model->setDailyHours((float)$params['dailyHours']);
			}
			if (isset($params['isDefault'])) {
				$newDefaultValue = (bool)$params['isDefault'];
				// If setting as default, unset other defaults
				if ($newDefaultValue && !$model->getIsDefault()) {
					$currentDefault = $this->workingTimeModelMapper->findDefault();
					if ($currentDefault && $currentDefault->getId() !== $model->getId()) {
						$currentDefault->setIsDefault(false);
						$currentDefault->setUpdatedAt(new \DateTime());
						$this->workingTimeModelMapper->update($currentDefault);
					}
				}
				$model->setIsDefault($newDefaultValue);
			}
			if (isset($params['breakRules']) && is_array($params['breakRules'])) {
				$model->setBreakRulesArray($params['breakRules']);
			}
			if (isset($params['overtimeRules']) && is_array($params['overtimeRules'])) {
				$model->setOvertimeRulesArray($params['overtimeRules']);
			}

			$model->setUpdatedAt(new \DateTime());

			// Validate
			$errors = $model->validate();
			if (!empty($errors)) {
				// Translate validation errors
				$translatedErrors = [];
				foreach ($errors as $field => $message) {
					$translatedErrors[$field] = $this->l10n->t($message);
				}
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Validation failed'),
					'errors' => $translatedErrors
				], Http::STATUS_BAD_REQUEST);
			}

			$updatedModel = $this->workingTimeModelMapper->update($model);
			$newValues = $this->workingTimeModelToAuditValues($updatedModel);
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$performedBy,
				'working_time_model_updated',
				'working_time_model',
				$updatedModel->getId(),
				$oldValues,
				$newValues,
				$performedBy
			);

			return new JSONResponse([
				'success' => true,
				'model' => [
					'id' => $updatedModel->getId(),
					'name' => $updatedModel->getName(),
					'description' => $updatedModel->getDescription(),
					'type' => $updatedModel->getType(),
					'weeklyHours' => $updatedModel->getWeeklyHours(),
					'dailyHours' => $updatedModel->getDailyHours(),
					'isDefault' => $updatedModel->getIsDefault()
				]
			]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Working time model not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Delete a working time model
	 *
	 * @param int $id
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function deleteWorkingTimeModel(int $id): JSONResponse
	{
		try {
			$model = $this->workingTimeModelMapper->find($id);

			// Check if any users are assigned to this model
			$userAssignments = $this->userWorkingTimeModelMapper->findByWorkingTimeModel($id, false);
			if (!empty($userAssignments)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Cannot delete working time model: %d user(s) are assigned to this model. Please reassign users first.', [count($userAssignments)])
				], Http::STATUS_BAD_REQUEST);
			}

			$oldValues = $this->workingTimeModelToAuditValues($model);
			$performedBy = $this->getPerformedBy();
			$this->workingTimeModelMapper->delete($model);
			$this->auditLogMapper->logAction(
				$performedBy,
				'working_time_model_deleted',
				'working_time_model',
				$id,
				$oldValues,
				null,
				$performedBy
			);

			return new JSONResponse([
				'success' => true,
				'message' => 'Working time model deleted successfully'
			]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Working time model not found')
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Export users data
	 *
	 * @param string $format Format: csv, json
	 * @return DataDownloadResponse
	 */
	#[NoCSRFRequired]
	public function exportUsers(string $format = 'csv'): DataDownloadResponse
	{
		try {
			// Get all users from Nextcloud
			$users = $this->userManager->search('', null, 0);

			$data = [];
			foreach ($users as $user) {
				$userId = $user->getUID();

				// Get current working time model assignment
				$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);

				// Get working time model details if assigned
				$workingTimeModelName = null;
				if ($currentModel) {
					try {
						$workingTimeModel = $this->workingTimeModelMapper->find($currentModel->getWorkingTimeModelId());
						$workingTimeModelName = $workingTimeModel->getName();
					} catch (\Throwable $e) {
						// Model might have been deleted
					}
				}

				$data[] = [
					'user_id' => $userId,
					'display_name' => $user->getDisplayName(),
					'email' => $user->getEMailAddress() ?? '',
					'enabled' => $user->isEnabled() ? 'Yes' : 'No',
					'working_time_model' => $workingTimeModelName ?? '',
					'vacation_days_per_year' => $currentModel ? $currentModel->getVacationDaysPerYear() : '',
					'working_time_model_start_date' => $currentModel && ($startDate = $currentModel->getStartDate()) ? $startDate->format('Y-m-d') : '',
					'working_time_model_end_date' => $currentModel && ($endDate = $currentModel->getEndDate()) ? $endDate->format('Y-m-d') : ''
				];
			}

			$filename = 'users-export-' . date('Y-m-d') . '.' . $format;

			return match ($format) {
				'csv' => $this->exportAsCsv($data, $filename),
				'json' => $this->exportAsJson($data, $filename),
				default => $this->exportAsCsv($data, $filename)
			};
		} catch (\Throwable $e) {
			throw new \Exception($this->l10n->t('Failed to export users.'));
		}
	}

	/**
	 * Export data as CSV
	 *
	 * @param array $data Data to export
	 * @param string $filename Filename for download
	 * @return DataDownloadResponse
	 */
	private function exportAsCsv(array $data, string $filename): DataDownloadResponse
	{
		if (empty($data)) {
			$csv = "No data available\n";
		} else {
			// Get headers from first row
			$headers = array_keys($data[0]);

			// Create CSV content
			$csv = '';

			// Add headers
			$csv .= implode(',', array_map(function ($header) {
				return '"' . str_replace('"', '""', $header) . '"';
			}, $headers)) . "\n";

			// Add data rows
			foreach ($data as $row) {
				$csv .= implode(',', array_map(function ($value) {
					return '"' . str_replace('"', '""', (string)$value) . '"';
				}, array_values($row))) . "\n";
			}
		}

		return new DataDownloadResponse($csv, $filename, 'text/csv');
	}

	/**
	 * Export data as JSON
	 *
	 * @param array $data Data to export
	 * @param string $filename Filename for download
	 * @return DataDownloadResponse
	 */
	private function exportAsJson(array $data, string $filename): DataDownloadResponse
	{
		$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
		return new DataDownloadResponse($json, $filename, 'application/json');
	}

	/**
	 * Get audit logs with filters
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function getAuditLogs(): JSONResponse
	{
		try {
			$params = $this->request->getParams();

			// Parse date filters
			$startDate = null;
			$endDate = null;
			if (isset($params['start_date']) && $params['start_date']) {
				$startDate = new \DateTime($params['start_date']);
				$startDate->setTime(0, 0, 0);
			}
			if (isset($params['end_date']) && $params['end_date']) {
				$endDate = new \DateTime($params['end_date']);
				$endDate->setTime(23, 59, 59);
			}

			// Default to last 30 days if no dates provided
			if ($startDate === null && $endDate === null) {
				$endDate = new \DateTime();
				$endDate->setTime(23, 59, 59);
				$startDate = clone $endDate;
				$startDate->modify('-30 days');
				$startDate->setTime(0, 0, 0);
			}

			if ($startDate > $endDate) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Start date must be before or equal to end date')
				], Http::STATUS_BAD_REQUEST);
			}

			// Enforce max date range to prevent heavy queries
			$diff = $startDate->diff($endDate);
			$days = (int) $diff->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Date range must not exceed %d days. Please narrow the range.', [Constants::MAX_EXPORT_DATE_RANGE_DAYS])
				], Http::STATUS_BAD_REQUEST);
			}

			// Build filters
			$filters = [];
			if (isset($params['user_id']) && $params['user_id']) {
				$filters['user_id'] = $params['user_id'];
			}
			if (isset($params['action']) && $params['action']) {
				$filters['action'] = $params['action'];
			}
			if (isset($params['entity_type']) && $params['entity_type']) {
				$filters['entity_type'] = $params['entity_type'];
			}

			// Get pagination
			$limit = isset($params['limit']) ? (int)$params['limit'] : 50;
			$offset = isset($params['offset']) ? (int)$params['offset'] : 0;

			// Fetch audit logs
			$logs = $this->auditLogMapper->findByDateRange(
				$startDate,
				$endDate,
				$filters['user_id'] ?? null,
				$filters['action'] ?? null,
				$filters['entity_type'] ?? null
			);

			// Apply pagination
			$totalCount = count($logs);
			$paginatedLogs = array_slice($logs, $offset, $limit);

			// Format logs for response
			$formattedLogs = [];
			foreach ($paginatedLogs as $log) {
				$user = $this->userManager->get($log->getUserId());
				$performedBy = $log->getPerformedBy() ? $this->userManager->get($log->getPerformedBy()) : null;

				$formattedLogs[] = [
					'id' => $log->getId(),
					'user_id' => $log->getUserId(),
					'user_display_name' => $user ? $user->getDisplayName() : $log->getUserId(),
					'action' => $this->l10n->t($log->getAction()),
					'entity_type' => $log->getEntityType(),
					'entity_id' => $log->getEntityId(),
					'old_values' => $log->getOldValues() ? json_decode($log->getOldValues(), true) : null,
					'new_values' => $log->getNewValues() ? json_decode($log->getNewValues(), true) : null,
					'performed_by' => $log->getPerformedBy(),
					'performed_by_display_name' => $performedBy ? $performedBy->getDisplayName() : ($log->getPerformedBy() ?? $log->getUserId()),
					'ip_address' => $log->getIpAddress(),
					'user_agent' => $log->getUserAgent(),
					'created_at' => ($createdAt = $log->getCreatedAt()) ? $createdAt->format('c') : null
				];
			}

			return new JSONResponse([
				'success' => true,
				'logs' => $formattedLogs,
				'total' => $totalCount,
				'limit' => $limit,
				'offset' => $offset
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get audit log statistics
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function getAuditLogStats(): JSONResponse
	{
		try {
			$params = $this->request->getParams();

			$filters = [];
			if (isset($params['start_date']) && $params['start_date']) {
				$filters['start_date'] = new \DateTime($params['start_date']);
			}
			if (isset($params['end_date']) && $params['end_date']) {
				$filters['end_date'] = new \DateTime($params['end_date']);
			}

			$stats = $this->auditLogMapper->getStatistics($filters);

			return new JSONResponse([
				'success' => true,
				'statistics' => $stats
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Export audit logs
	 *
	 * @param string $format Format: csv, json
	 * @return DataDownloadResponse
	 */
	#[NoCSRFRequired]
	public function exportAuditLogs(string $format = 'csv'): DataDownloadResponse
	{
		try {
			$params = $this->request->getParams();

			// Parse date filters
			$startDate = null;
			$endDate = null;
			if (isset($params['start_date']) && $params['start_date']) {
				$startDate = new \DateTime($params['start_date']);
				$startDate->setTime(0, 0, 0);
			}
			if (isset($params['end_date']) && $params['end_date']) {
				$endDate = new \DateTime($params['end_date']);
				$endDate->setTime(23, 59, 59);
			}

			// Default to last 30 days if no dates provided
			if ($startDate === null && $endDate === null) {
				$endDate = new \DateTime();
				$endDate->setTime(23, 59, 59);
				$startDate = clone $endDate;
				$startDate->modify('-30 days');
				$startDate->setTime(0, 0, 0);
			}

			if ($startDate > $endDate) {
				throw new \Exception($this->l10n->t('Start date must be before or equal to end date'));
			}

			// Enforce max date range to prevent heavy queries
			$diff = $startDate->diff($endDate);
			$days = (int) $diff->format('%a');
			if ($days > Constants::MAX_EXPORT_DATE_RANGE_DAYS) {
				throw new \Exception($this->l10n->t(
					'Export date range must not exceed %d days. Please narrow the range.',
					[Constants::MAX_EXPORT_DATE_RANGE_DAYS]
				));
			}

			// Build filters
			$userId = isset($params['user_id']) && $params['user_id'] ? $params['user_id'] : null;
			$action = isset($params['action']) && $params['action'] ? $params['action'] : null;
			$entityType = isset($params['entity_type']) && $params['entity_type'] ? $params['entity_type'] : null;

			// Fetch all audit logs (no pagination for export)
			$logs = $this->auditLogMapper->findByDateRange(
				$startDate,
				$endDate,
				$userId,
				$action,
				$entityType
			);

			// Format logs for export
			$exportData = [];
			foreach ($logs as $log) {
				$user = $this->userManager->get($log->getUserId());
				$performedBy = $log->getPerformedBy() ? $this->userManager->get($log->getPerformedBy()) : null;

				$exportData[] = [
					'id' => $log->getId(),
					'date_time' => ($createdAt = $log->getCreatedAt()) ? $createdAt->format('Y-m-d H:i:s') : '',
					'user_id' => $log->getUserId(),
					'user_display_name' => $user ? $user->getDisplayName() : $log->getUserId(),
					'action' => $log->getAction(),
					'entity_type' => $log->getEntityType(),
					'entity_id' => $log->getEntityId(),
					'performed_by' => $log->getPerformedBy() ?? $log->getUserId(),
					'performed_by_display_name' => $performedBy ? $performedBy->getDisplayName() : ($log->getPerformedBy() ?? $log->getUserId()),
					'ip_address' => $log->getIpAddress() ?? '',
					'user_agent' => $log->getUserAgent() ?? '',
					'old_values' => $log->getOldValues() ?? '',
					'new_values' => $log->getNewValues() ?? ''
				];
			}

			$filename = 'audit-logs-export-' . date('Y-m-d') . '.' . $format;

			return match ($format) {
				'csv' => $this->exportAsCsv($exportData, $filename),
				'json' => $this->exportAsJson($exportData, $filename),
				default => $this->exportAsCsv($exportData, $filename)
			};
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::exportAuditLogs: ' . $e->getMessage(), ["exception" => $e]);
			throw new \Exception($this->l10n->t('Failed to export audit logs.'));
		}
	}

	// ---------- Admin Teams (app-owned teams/departments) ----------

	#[NoCSRFRequired]
	public function teams(): TemplateResponse
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
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');
		Util::addStyle('arbeitszeitcheck', 'admin-teams');
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'admin-teams');

		return new TemplateResponse('arbeitszeitcheck', 'admin-teams', [
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
			'showSubstitutionLink' => false,
			'showManagerLink' => true,
			'showReportsLink' => true,
			'showAdminNav' => true,
		]);
	}

	#[NoCSRFRequired]
	public function getTeams(): JSONResponse
	{
		try {
			$teams = $this->teamMapper->findAll();
			$tree = $this->buildTeamTree($teams, null);
			return new JSONResponse(['success' => true, 'teams' => $tree]);
		} catch (\Throwable $e) {
			// Table oc_at_teams may not exist if migration hasn't run yet
			$msg = $e->getMessage();
			if (str_contains($msg, "doesn't exist") || str_contains($msg, 'at_teams')) {
				\OCP\Log\logger('arbeitszeitcheck')->info('Admin teams table not found, returning empty: ' . $msg);
				return new JSONResponse(['success' => true, 'teams' => []]);
			}
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::getTeams: ' . $msg, ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $msg], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get a summary of what will be affected if a team is deleted.
	 *
	 * Returns counts of members, managers, and direct sub-teams. This is used
	 * by the admin UI to present a clear, WCAG-compliant confirmation dialog
	 * before performing the destructive action.
	 */
	#[NoCSRFRequired]
	public function getTeamDeleteImpact(int $id): JSONResponse
	{
		try {
			$team = $this->teamMapper->find($id);

			$members = $this->teamMemberMapper->findByTeamId($id);
			$managers = $this->teamManagerMapper->findByTeamId($id);
			$children = $this->teamMapper->findByParentId($id);

			return new JSONResponse([
				'success' => true,
				'impact' => [
					'teamId' => $team->getId(),
					'teamName' => $team->getName(),
					'memberCount' => count($members),
					'managerCount' => count($managers),
					'childTeamCount' => count($children),
				],
			]);
		} catch (DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Team not found'),
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::getTeamDeleteImpact: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/** @param Team[] $teams */
	private function buildTeamTree(array $teams, ?int $parentId): array
	{
		$out = [];
		foreach ($teams as $team) {
			if ($team->getParentId() !== $parentId) {
				continue;
			}
			$node = $team->getSummary();
			$node['children'] = $this->buildTeamTree($teams, $team->getId());
			$out[] = $node;
		}
		return $out;
	}

	#[NoCSRFRequired]
	public function createTeam(): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$name = trim((string)($params['name'] ?? ''));
			$parentId = isset($params['parentId']) ? (int)$params['parentId'] : null;
			if ($parentId === 0) {
				$parentId = null;
			}
			$sortOrder = isset($params['sortOrder']) ? (int)$params['sortOrder'] : 0;
			if ($name === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team name is required')], Http::STATUS_BAD_REQUEST);
			}
			$team = new Team();
			$team->setName($name);
			$team->setParentId($parentId);
			$team->setSortOrder($sortOrder);
			$team->setCreatedAt(new \DateTime());
			$inserted = $this->teamMapper->insert($team);
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$performedBy,
				'team_created',
				'team',
				$inserted->getId(),
				null,
				$inserted->getSummary(),
				$performedBy
			);
			return new JSONResponse(['success' => true, 'team' => $inserted->getSummary()], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::createTeam: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoCSRFRequired]
	public function updateTeam(int $id): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$team = $this->teamMapper->find($id);
			$name = trim((string)($params['name'] ?? ''));
			$parentId = isset($params['parentId']) ? (int)$params['parentId'] : null;
			if ($parentId === 0) {
				$parentId = null;
			}
			$sortOrder = isset($params['sortOrder']) ? (int)$params['sortOrder'] : 0;
			if ($name === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team name is required')], Http::STATUS_BAD_REQUEST);
			}
			if ($parentId === $id) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('A team cannot be its own parent')], Http::STATUS_BAD_REQUEST);
			}
			$oldValues = $team->getSummary();
			$team->setName($name);
			$team->setParentId($parentId);
			$team->setSortOrder($sortOrder);
			$this->teamMapper->update($team);
			$newValues = $team->getSummary();
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$performedBy,
				'team_updated',
				'team',
				$id,
				$oldValues,
				$newValues,
				$performedBy
			);
			return new JSONResponse(['success' => true, 'team' => $team->getSummary()]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::updateTeam: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoCSRFRequired]
	public function deleteTeam(int $id): JSONResponse
	{
		try {
			$team = $this->teamMapper->find($id);
			$children = $this->teamMapper->findByParentId($id);
			if (count($children) > 0) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Cannot delete a team that has sub-teams. Move or delete sub-teams first.')
				], Http::STATUS_BAD_REQUEST);
			}
			$oldValues = $team->getSummary();
			$performedBy = $this->getPerformedBy();
			$this->teamMemberMapper->deleteByTeamId($id);
			$this->teamManagerMapper->deleteByTeamId($id);
			$this->teamMapper->delete($team);
			$this->auditLogMapper->logAction(
				$performedBy,
				'team_deleted',
				'team',
				$id,
				$oldValues,
				null,
				$performedBy
			);
			return new JSONResponse(['success' => true]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::deleteTeam: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoCSRFRequired]
	public function getTeamMembers(int $id): JSONResponse
	{
		try {
			$this->teamMapper->find($id);
			$members = $this->teamMemberMapper->findByTeamId($id);
			$list = [];
			foreach ($members as $m) {
				$u = $this->userManager->get($m->getUserId());
				$list[] = ['userId' => $m->getUserId(), 'displayName' => $u ? $u->getDisplayName() : $m->getUserId()];
			}
			return new JSONResponse(['success' => true, 'members' => $list]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoCSRFRequired]
	public function addTeamMember(int $id): JSONResponse
	{
		try {
			$userId = (string)($this->request->getParams()['userId'] ?? '');
			if ($userId === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User is required')], Http::STATUS_BAD_REQUEST);
			}
			$team = $this->teamMapper->find($id);
			if ($this->userManager->get($userId) === null) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User not found')], Http::STATUS_BAD_REQUEST);
			}
			$existing = $this->teamMemberMapper->findByTeamId($id);
			foreach ($existing as $m) {
				if ($m->getUserId() === $userId) {
					return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User is already a member of this team')], Http::STATUS_BAD_REQUEST);
				}
			}
			$this->teamMemberMapper->addMember($id, $userId);
			$u = $this->userManager->get($userId);
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$userId,
				'team_member_added',
				'team_member',
				$id,
				null,
				['teamId' => $id, 'teamName' => $team->getName(), 'userId' => $userId],
				$performedBy
			);
			return new JSONResponse(['success' => true, 'member' => ['userId' => $userId, 'displayName' => $u ? $u->getDisplayName() : $userId]]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::addTeamMember: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoCSRFRequired]
	public function removeTeamMember(int $id, string $userId): JSONResponse
	{
		try {
			$team = $this->teamMapper->find($id);
			$performedBy = $this->getPerformedBy();
			$this->teamMemberMapper->removeMember($id, $userId);
			$this->auditLogMapper->logAction(
				$userId,
				'team_member_removed',
				'team_member',
				$id,
				['teamId' => $id, 'teamName' => $team->getName(), 'userId' => $userId],
				null,
				$performedBy
			);
			return new JSONResponse(['success' => true]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoCSRFRequired]
	public function getTeamManagers(int $id): JSONResponse
	{
		try {
			$this->teamMapper->find($id);
			$managers = $this->teamManagerMapper->findByTeamId($id);
			$list = [];
			foreach ($managers as $m) {
				$u = $this->userManager->get($m->getUserId());
				$list[] = ['userId' => $m->getUserId(), 'displayName' => $u ? $u->getDisplayName() : $m->getUserId()];
			}
			return new JSONResponse(['success' => true, 'managers' => $list]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoCSRFRequired]
	public function addTeamManager(int $id): JSONResponse
	{
		try {
			$userId = (string)($this->request->getParams()['userId'] ?? '');
			if ($userId === '') {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User is required')], Http::STATUS_BAD_REQUEST);
			}
			$team = $this->teamMapper->find($id);
			if ($this->userManager->get($userId) === null) {
				return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User not found')], Http::STATUS_BAD_REQUEST);
			}
			$existing = $this->teamManagerMapper->findByTeamId($id);
			foreach ($existing as $m) {
				if ($m->getUserId() === $userId) {
					return new JSONResponse(['success' => false, 'error' => $this->l10n->t('User is already a manager of this team')], Http::STATUS_BAD_REQUEST);
				}
			}
			$this->teamManagerMapper->addManager($id, $userId);
			$u = $this->userManager->get($userId);
			$performedBy = $this->getPerformedBy();
			$this->auditLogMapper->logAction(
				$userId,
				'team_manager_added',
				'team_manager',
				$id,
				null,
				['teamId' => $id, 'teamName' => $team->getName(), 'userId' => $userId],
				$performedBy
			);
			return new JSONResponse(['success' => true, 'manager' => ['userId' => $userId, 'displayName' => $u ? $u->getDisplayName() : $userId]]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in AdminController::addTeamManager: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoCSRFRequired]
	public function removeTeamManager(int $id, string $userId): JSONResponse
	{
		try {
			$team = $this->teamMapper->find($id);
			$performedBy = $this->getPerformedBy();
			$this->teamManagerMapper->removeManager($id, $userId);
			$this->auditLogMapper->logAction(
				$userId,
				'team_manager_removed',
				'team_manager',
				$id,
				['teamId' => $id, 'teamName' => $team->getName(), 'userId' => $userId],
				null,
				$performedBy
			);
			return new JSONResponse(['success' => true]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse(['success' => false, 'error' => $this->l10n->t('Team not found')], Http::STATUS_NOT_FOUND);
		}
	}

	#[NoCSRFRequired]
	public function getTeamsUseAppTeams(): JSONResponse
	{
		$use = $this->appConfig->getAppValueString('use_app_teams', '0') === '1';
		return new JSONResponse(['success' => true, 'useAppTeams' => $use]);
	}

	#[NoCSRFRequired]
	public function setTeamsUseAppTeams(): JSONResponse
	{
		$params = $this->request->getParams();
		$use = !empty($params['useAppTeams']);
		$this->appConfig->setAppValueString('use_app_teams', $use ? '1' : '0');
		return new JSONResponse(['success' => true, 'useAppTeams' => $use]);
	}
}
