<?php

declare(strict_types=1);

/**
 * Settings controller for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Service\CSPService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\IURLGenerator;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\DB\Exception as DBException;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\IL10N;
use OCP\Util;

/**
 * SettingsController
 */
class SettingsController extends Controller
{
	use CSPTrait;

	private IUserSession $userSession;
	private UserSettingsMapper $userSettingsMapper;
	private AuditLogMapper $auditLogMapper;
	private IL10N $l10n;
	private IURLGenerator $urlGenerator;
	private PermissionService $permissionService;

	public function __construct(
		string $appName,
		IRequest $request,
		IUserSession $userSession,
		UserSettingsMapper $userSettingsMapper,
		AuditLogMapper $auditLogMapper,
		IL10N $l10n,
		CSPService $cspService,
		IURLGenerator $urlGenerator,
		PermissionService $permissionService
	) {
		parent::__construct($appName, $request);
		$this->userSession = $userSession;
		$this->userSettingsMapper = $userSettingsMapper;
		$this->auditLogMapper = $auditLogMapper;
		$this->l10n = $l10n;
		$this->urlGenerator = $urlGenerator;
		$this->permissionService = $permissionService;
		$this->setCspService($cspService);
	}

	/**
	 * Legacy API: Get settings (alias for index)
	 *
	 * Legacy endpoint for backward compatibility. Returns settings data as JSON.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function index_api(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not authenticated')
				], Http::STATUS_UNAUTHORIZED);
			}

			$userId = $user->getUID();
			$settings = [];

			// Get all user settings
			try {
				// Use getUserSettings method which returns all settings for the user
				$allSettings = $this->userSettingsMapper->getUserSettings($userId);
				foreach ($allSettings as $setting) {
					$settings[$setting->getSettingKey()] = $setting->getSettingValue();
				}
			} catch (\Throwable $e) {
				$msg = $e->getMessage();
				if (str_contains($msg, "doesn't exist") || str_contains($msg, 'at_settings')) {
					\OCP\Log\logger('arbeitszeitcheck')->info('Settings table not found: ' . $msg);
				} else {
					\OCP\Log\logger('arbeitszeitcheck')->warning('Error getting settings: ' . $msg, ['exception' => $e]);
				}
			}

			return new JSONResponse([
				'success' => true,
				'settings' => $settings
			]);
		} catch (\Throwable $e) {
			$msg = $e->getMessage();
			if (str_contains($msg, "doesn't exist") || str_contains($msg, 'at_settings')) {
				\OCP\Log\logger('arbeitszeitcheck')->info('Settings API: table not found, returning empty: ' . $msg);
				return new JSONResponse(['success' => true, 'settings' => []]);
			}
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in SettingsController::index_api: ' . $msg, ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => $msg], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Legacy API (CamelCase alias): Nextcloud routes may call `indexApi()` when the route is defined as `index_api`.
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function indexApi(): JSONResponse
	{
		return $this->index_api();
	}

	/**
	 * Personal settings page
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse
	{
		Util::addTranslations('arbeitszeitcheck');

		// Add common CSS files (including app-layout for full-width layout)
		Util::addStyle('arbeitszeitcheck', 'common/colors');
		Util::addStyle('arbeitszeitcheck', 'common/typography');
		Util::addStyle('arbeitszeitcheck', 'common/base');
		Util::addStyle('arbeitszeitcheck', 'common/components');
		Util::addStyle('arbeitszeitcheck', 'common/layout');
		Util::addStyle('arbeitszeitcheck', 'common/app-layout');
		Util::addStyle('arbeitszeitcheck', 'common/utilities');
		Util::addStyle('arbeitszeitcheck', 'common/responsive');
		Util::addStyle('arbeitszeitcheck', 'common/accessibility');
		Util::addStyle('arbeitszeitcheck', 'navigation');
		Util::addStyle('arbeitszeitcheck', 'arbeitszeitcheck-main');

		// Add common JavaScript files
		Util::addScript('arbeitszeitcheck', 'common/utils');
		Util::addScript('arbeitszeitcheck', 'common/components');
		Util::addScript('arbeitszeitcheck', 'common/messaging');
		Util::addScript('arbeitszeitcheck', 'common/validation');
		Util::addScript('arbeitszeitcheck', 'settings');

		$user = $this->userSession->getUser();
		$userId = $user ? $user->getUID() : null;

		$showManagerLink = false;
		$showReportsLink = false;
		$showAdminNav = false;
		$showSubstitutionLink = false;

		if ($userId !== null) {
			try {
				$showManagerLink = $this->permissionService->canAccessManagerDashboard($userId);
				$showReportsLink = $showManagerLink || $this->permissionService->isAdmin($userId);
				$showAdminNav = $this->permissionService->isAdmin($userId);
			} catch (\Throwable $e) {
				$showManagerLink = false;
				$showReportsLink = false;
				$showAdminNav = false;
			}
		}

		$response = new TemplateResponse('arbeitszeitcheck', 'personal-settings', [
			'l' => $this->l10n,
			'urlGenerator' => $this->urlGenerator,
			'showSubstitutionLink' => $showSubstitutionLink,
			'showManagerLink' => $showManagerLink,
			'showReportsLink' => $showReportsLink,
			'showAdminNav' => $showAdminNav,
		]);
		return $this->configureCSP($response);
	}

	/**
	 * Update personal settings (called via AJAX with JSON; NoCSRFRequired for same reason as absence/time entry endpoints)
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function update(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				throw new \Exception('User not authenticated');
			}

			$userId = $user->getUID();
			$params = $this->request->getParams();

			// List of allowed settings keys (only user preferences, not HR/Admin settings)
			$allowedKeys = [
				'notifications_enabled',
				'break_reminders_enabled',
				'auto_break_calculation'
			];

			$updatedSettings = [];
			$oldValues = [];

			// Update each setting if provided
			foreach ($allowedKeys as $key) {
				if (isset($params[$key])) {
					// Get old value for audit log
					$oldSetting = $this->userSettingsMapper->getSetting($userId, $key);
					$oldValues[$key] = $oldSetting ? $oldSetting->getSettingValue() : null;

					// Update setting
					$value = $params[$key];

					// Validate value based on key type
					if ($key === 'notifications_enabled' || $key === 'break_reminders_enabled' || $key === 'auto_break_calculation') {
						$value = $value === true || $value === 'true' || $value === '1' ? '1' : '0';
					} else {
						$value = (string)$value;
					}

					$this->userSettingsMapper->setSetting($userId, $key, $value);
					$updatedSettings[$key] = $value;
				}
			}

			if (empty($updatedSettings)) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('No valid settings provided')
				], Http::STATUS_BAD_REQUEST);
			}

			// Create audit log entry
			$this->auditLogMapper->logAction(
				$userId,
				'settings_updated',
				'user_settings',
				null,
				$oldValues,
				$updatedSettings
			);

			return new JSONResponse([
				'success' => true,
				'message' => $this->l10n->t('Settings updated successfully'),
				'settings' => $updatedSettings
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in SettingsController::update: ' . $e->getMessage(), ["exception" => $e]);
			// Check if it's an authentication error
			$errorMessage = $e->getMessage();
			if (strpos($errorMessage, 'User not authenticated') !== false) {
				$errorMessage = $this->l10n->t('User not authenticated');
			}
			return new JSONResponse([
				'success' => false,
				'error' => $errorMessage
			], Http::STATUS_BAD_REQUEST);
		}
	}

	/**
	 * Check if user has completed onboarding tour
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	public function getOnboardingCompleted(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not authenticated')
				], Http::STATUS_UNAUTHORIZED);
			}

			$userId = $user->getUID();

			// Try to get the setting, but handle table not existing gracefully
			try {
				$setting = $this->userSettingsMapper->getSetting($userId, 'onboarding_completed');
				$completed = $setting && $setting->getSettingValue() === '1';
			} catch (DBException $e) {
				// Table doesn't exist yet - return default
				\OCP\Log\logger('arbeitszeitcheck')->warning('Settings table not found, returning default', ['exception' => $e]);
				$completed = false;
			} catch (\Throwable $e) {
				// Any other error (including PDO exceptions) - return default
				\OCP\Log\logger('arbeitszeitcheck')->warning('Error getting onboarding setting: ' . $e->getMessage() . ' | Class: ' . get_class($e), ["exception" => $e]);
				$completed = false;
			}

			return new JSONResponse([
				'success' => true,
				'completed' => $completed
			]);
		} catch (\Throwable $e) {
			// Log error but return a safe default response
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in getOnboardingCompleted: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => true,
				'completed' => false // Default to false if there's an error
			]);
		}
	}

	/**
	 * Mark onboarding tour as completed
	 *
	 * @return JSONResponse
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function setOnboardingCompleted(): JSONResponse
	{
		try {
			$user = $this->userSession->getUser();
			if (!$user) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not authenticated')
				], Http::STATUS_UNAUTHORIZED);
			}

			$userId = $user->getUID();
			$completed = $this->request->getParam('completed', true);

			// Try to set the setting, but handle table not existing gracefully
			try {
				$this->userSettingsMapper->setSetting($userId, 'onboarding_completed', $completed ? '1' : '0');
			} catch (DBException $e) {
				// Table doesn't exist yet - just return success (setting will be saved when table is created)
				\OCP\Log\logger('arbeitszeitcheck')->warning('Settings table not found, cannot save setting', ['exception' => $e]);
				return new JSONResponse([
					'success' => true,
					'message' => $this->l10n->t('Onboarding status will be saved after database migration')
				]);
			} catch (\Throwable $e) {
				// Any other error (including PDO exceptions) - log and return success to avoid breaking the UI
				\OCP\Log\logger('arbeitszeitcheck')->warning('Error setting onboarding setting: ' . $e->getMessage() . ' | Class: ' . get_class($e), ["exception" => $e]);
				return new JSONResponse([
					'success' => true,
					'message' => $this->l10n->t('Onboarding status will be saved after database migration')
				]);
			}

			// Create audit log entry (only if mapper is available)
			try {
				$this->auditLogMapper->logAction(
					$userId,
					'onboarding_completed',
					'user_settings',
					null,
					null,
					['onboarding_completed' => $completed ? '1' : '0']
				);
			} catch (\Throwable $e) {
				// Log audit error but don't fail the request
				\OCP\Log\logger('arbeitszeitcheck')->warning('Error logging onboarding action: ' . $e->getMessage(), ["exception" => $e]);
			}

			return new JSONResponse([
				'success' => true,
				'message' => $this->l10n->t('Onboarding status updated')
			]);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in setOnboardingCompleted: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString(), ["exception" => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('Failed to update onboarding status')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
