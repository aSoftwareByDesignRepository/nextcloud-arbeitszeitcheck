<?php

declare(strict_types=1);

/**
 * Admin controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Service\CSPService;
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
use OCP\IL10N;
use OCP\Util;

/**
 * AdminController
 */
class AdminController extends Controller
{
	use CSPTrait;

	private TimeEntryMapper $timeEntryMapper;
	private ComplianceViolationMapper $violationMapper;
	private UserWorkingTimeModelMapper $userWorkingTimeModelMapper;
	private WorkingTimeModelMapper $workingTimeModelMapper;
	private AuditLogMapper $auditLogMapper;
	private IUserManager $userManager;
	private IAppConfig $appConfig;
	private IL10N $l10n;

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
		CSPService $cspService,
		IL10N $l10n
	) {
		parent::__construct($appName, $request);
		$this->timeEntryMapper = $timeEntryMapper;
		$this->violationMapper = $violationMapper;
		$this->userWorkingTimeModelMapper = $userWorkingTimeModelMapper;
		$this->workingTimeModelMapper = $workingTimeModelMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->userManager = $userManager;
		$this->appConfig = $appConfig;
		$this->l10n = $l10n;
		$this->setCspService($cspService);
	}

	/**
	 * Admin dashboard page
	 *
	 */
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
					'date' => $violation->getViolationDate() ? $violation->getViolationDate()->format('Y-m-d') : null,
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
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
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
				'error' => $e->getMessage(),
				'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
			]);
			return $this->configureCSP($response, 'admin');
		}
	}

	/**
	 * Admin users management page
	 *
	 */
	#[NoCSRFRequired]
	public function users(): TemplateResponse
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

			$usersData[] = [
				'userId' => $userId,
				'displayName' => $user->getDisplayName(),
				'email' => $user->getEMailAddress(),
				'enabled' => $user->isEnabled(),
				'workingTimeModel' => $workingTimeModel ? [
					'id' => $workingTimeModel->getId(),
					'name' => $workingTimeModel->getName()
				] : null
			];
		}

		$totalCount = $this->userManager->countUsersTotal(0, false);
		if ($totalCount === false) {
			$totalCount = count($usersData);
		}

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-users', [
			'users' => $usersData,
			'total' => $totalCount,
			'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
		]);
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Admin settings page
	 *
	 */
	#[NoCSRFRequired]
	public function settings(): TemplateResponse
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
		Util::addScript('arbeitszeitcheck', 'common/validation');
		Util::addScript('arbeitszeitcheck', 'admin-settings');

		$settings = [
			'autoComplianceCheck' => $this->appConfig->getAppValueString('auto_compliance_check', '1') === '1',
			'requireBreakJustification' => $this->appConfig->getAppValueString('require_break_justification', '1') === '1',
			'enableViolationNotifications' => $this->appConfig->getAppValueString('enable_violation_notifications', '1') === '1',
			'maxDailyHours' => (float)$this->appConfig->getAppValueString('max_daily_hours', '10'),
			'minRestPeriod' => (float)$this->appConfig->getAppValueString('min_rest_period', '11'),
			'germanState' => $this->appConfig->getAppValueString('german_state', 'NW'),
			'retentionPeriod' => (int)$this->appConfig->getAppValueString('retention_period', '2'),
			'defaultWorkingHours' => (float)$this->appConfig->getAppValueString('default_working_hours', '8')
		];

		$response = new TemplateResponse('arbeitszeitcheck', 'admin-settings', [
			'settings' => $settings,
			'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
		]);
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Admin working time models management page
	 *
	 */
	#[NoCSRFRequired]
	public function workingTimeModels(): TemplateResponse
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
			'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
		]);
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Admin audit log viewer page
	 *
	 */
	#[NoCSRFRequired]
	public function auditLog(): TemplateResponse
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
				'action' => $log->getAction(),
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
			'cspNonce' => \OC::$server->getContentSecurityPolicyNonceManager()->getNonce(),
		]);
		return $this->configureCSP($response, 'admin');
	}

	/**
	 * Get admin settings
	 *
	 * @return JSONResponse
	 */
	#[NoCSRFRequired]
	public function getAdminSettings(): JSONResponse
	{
		try {
			$settings = [
				'autoComplianceCheck' => $this->appConfig->getAppValueString('auto_compliance_check', '1') === '1',
				'requireBreakJustification' => $this->appConfig->getAppValueString('require_break_justification', '1') === '1',
				'enableViolationNotifications' => $this->appConfig->getAppValueString('enable_violation_notifications', '1') === '1',
				'maxDailyHours' => (float)$this->appConfig->getAppValueString('max_daily_hours', '10'),
				'minRestPeriod' => (float)$this->appConfig->getAppValueString('min_rest_period', '11'),
				'germanState' => $this->appConfig->getAppValueString('german_state', 'NW'),
				'retentionPeriod' => (int)$this->appConfig->getAppValueString('retention_period', '2'),
				'defaultWorkingHours' => (float)$this->appConfig->getAppValueString('default_working_hours', '8')
			];

			return new JSONResponse([
				'success' => true,
				'settings' => $settings
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Update admin settings
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
				'requireBreakJustification' => 'require_break_justification',
				'enableViolationNotifications' => 'enable_violation_notifications',
				'maxDailyHours' => 'max_daily_hours',
				'minRestPeriod' => 'min_rest_period',
				'germanState' => 'german_state',
				'retentionPeriod' => 'retention_period',
				'defaultWorkingHours' => 'default_working_hours'
			];

			$updatedSettings = [];

			// Update each setting if provided
			foreach ($allowedKeys as $paramKey => $configKey) {
				if (isset($params[$paramKey])) {
					$value = $params[$paramKey];

					// Validate and convert value based on type
					if ($paramKey === 'autoComplianceCheck' || $paramKey === 'realtimeComplianceCheck' || $paramKey === 'complianceStrictMode' || $paramKey === 'requireBreakJustification' || $paramKey === 'enableViolationNotifications') {
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
								'error' => 'Minimum rest period must be between 1 and 24 hours'
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
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get admin dashboard statistics
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
				'error' => $e->getMessage()
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

				// Get user statistics
				$today = new \DateTime();
				$today->setTime(0, 0, 0);
				$hasTimeEntriesToday = $this->timeEntryMapper->countDistinctUsersByDate($today) > 0;

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
					'hasTimeEntriesToday' => $hasTimeEntriesToday
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
				'error' => $e->getMessage()
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
					'error' => 'User not found'
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

			return new JSONResponse([
				'success' => true,
				'user' => [
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
				'error' => $e->getMessage()
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
			$workingTimeModelId = isset($params['workingTimeModelId']) ? (int)$params['workingTimeModelId'] : null;
			$vacationDaysPerYear = isset($params['vacationDaysPerYear']) ? (int)$params['vacationDaysPerYear'] : null;
			$startDate = isset($params['startDate']) ? $params['startDate'] : null;
			$endDate = isset($params['endDate']) ? $params['endDate'] : null;

			// Validate user exists
			$user = $this->userManager->get($userId);
			if (!$user) {
				return new JSONResponse([
					'success' => false,
					'error' => 'User not found'
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
					return new JSONResponse([
						'success' => false,
						'error' => 'Error validating working time model: ' . $e->getMessage()
					], Http::STATUS_BAD_REQUEST);
				}
			}

			// Get current assignment
			$currentModel = $this->userWorkingTimeModelMapper->findCurrentByUser($userId);

			if ($currentModel && $workingTimeModelId !== null) {
				// Update existing assignment
				if ($startDate) {
					$currentModel->setStartDate(new \DateTime($startDate));
				}
				if ($endDate !== null) {
					$currentModel->setEndDate($endDate ? new \DateTime($endDate) : null);
				}
				if ($workingTimeModelId > 0) {
					$currentModel->setWorkingTimeModelId($workingTimeModelId);
				}
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
			} elseif ($workingTimeModelId !== null && $workingTimeModelId > 0) {
				// Create new assignment
				$newModel = new \OCA\ArbeitszeitCheck\Db\UserWorkingTimeModel();
				$newModel->setUserId($userId);
				$newModel->setWorkingTimeModelId($workingTimeModelId);
				$newModel->setVacationDaysPerYear($vacationDaysPerYear ?? 25);
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
			} else {
				return new JSONResponse([
					'success' => false,
					'error' => 'Working time model ID is required'
				], Http::STATUS_BAD_REQUEST);
			}

			return new JSONResponse([
				'success' => true,
				'userWorkingTimeModel' => $updated->getSummary()
			]);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Get all available working time models
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
				'error' => $e->getMessage()
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
				'error' => 'Working time model not found'
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
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
			$model->setType($params['type'] ?? \OCA\ArbeitszeitCheck\Db\WorkingTimeModel::TYPE_FULL_TIME);
			$model->setWeeklyHours(isset($params['weeklyHours']) ? (float)$params['weeklyHours'] : 40.0);
			$model->setDailyHours(isset($params['dailyHours']) ? (float)$params['dailyHours'] : 8.0);
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
				'error' => $e->getMessage()
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
			$params = $this->request->getParams();

			if (isset($params['name'])) {
				$model->setName($params['name']);
			}
			if (isset($params['description'])) {
				$model->setDescription($params['description']);
			}
			if (isset($params['type'])) {
				$model->setType($params['type']);
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
				'error' => 'Working time model not found'
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
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

			$this->workingTimeModelMapper->delete($model);

			return new JSONResponse([
				'success' => true,
				'message' => 'Working time model deleted successfully'
			]);
		} catch (\OCP\AppFramework\Db\DoesNotExistException $e) {
			return new JSONResponse([
				'success' => false,
				'error' => 'Working time model not found'
			], Http::STATUS_NOT_FOUND);
		} catch (\Throwable $e) {
			return new JSONResponse([
				'success' => false,
				'error' => $e->getMessage()
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
			throw new \Exception('Failed to export users: ' . $e->getMessage());
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
					'action' => $log->getAction(),
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
				'error' => $e->getMessage()
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
				'error' => $e->getMessage()
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
			throw new \Exception($this->l10n->t('Failed to export audit logs: %s', [$e->getMessage()]));
		}
	}
}
