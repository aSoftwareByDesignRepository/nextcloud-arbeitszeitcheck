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
use OCA\ArbeitszeitCheck\Service\FrontEndAssetService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Support\SchemaHealth;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\Server;
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
				if ($this->isMissingTableException($e)) {
					\OCP\Log\logger('arbeitszeitcheck')->info('Settings table not yet available, returning empty user settings.');
				} else {
					\OCP\Log\logger('arbeitszeitcheck')->warning('Error getting settings: ' . $e->getMessage(), ['exception' => $e]);
				}
			}

			return new JSONResponse([
				'success' => true,
				'settings' => $settings
			]);
		} catch (\Throwable $e) {
			if ($this->isMissingTableException($e)) {
				\OCP\Log\logger('arbeitszeitcheck')->info('Settings API: table not yet available, returning empty settings.');
				return new JSONResponse(['success' => true, 'settings' => []]);
			}
			\OCP\Log\logger('arbeitszeitcheck')->error('Error in SettingsController::index_api: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.'),
			], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Locale-independent detection of "table/object does not exist" errors.
	 *
	 * Uses Nextcloud's DB exception reason codes when available (the
	 * portable, locale-independent contract from OCP\DB\Exception) and falls
	 * back to a small set of message substrings for legacy/non-DBAL paths.
	 */
	private function isMissingTableException(\Throwable $e): bool
	{
		if ($e instanceof DBException && $e->getReason() === DBException::REASON_DATABASE_OBJECT_NOT_FOUND) {
			return true;
		}
		$previous = $e->getPrevious();
		if ($previous instanceof DBException && $previous->getReason() === DBException::REASON_DATABASE_OBJECT_NOT_FOUND) {
			return true;
		}
		// Defensive fallback for non-DBAL paths (e.g. raw PDO exceptions
		// raised by test doubles). Message-based detection is intentionally
		// last so localised driver errors never reach this branch on a
		// healthy install.
		$msg = (string)$e->getMessage();
		return str_contains($msg, "doesn't exist")
			|| str_contains($msg, 'does not exist')
			|| str_contains($msg, 'no such table')
			|| str_contains($msg, 'undefined table');
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
		FrontEndAssetService::registerCore();

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
	 * Update personal settings (called via AJAX with JSON).
	 *
	 * CSRF protection is enabled (default) — the frontend AJAX wrapper sends
	 * the requesttoken header on every state-changing call.
	 */
	#[NoAdminRequired]
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
				'auto_break_calculation',
				'missing_clock_in_reminders_enabled',
				'hours_display',
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

					if ($key === 'hours_display') {
						$value = \OCA\ArbeitszeitCheck\Support\HoursDisplay::normalize(
							is_scalar($value) ? (string)$value : null
						);
					} else {
						// Boolean toggles: coerce to canonical '1' / '0'.
						$value = ($value === true || $value === 'true' || $value === '1' || $value === 1) ? '1' : '0';
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
			$rawMessage = $e->getMessage();
			if (strpos($rawMessage, 'User not authenticated') !== false) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('User not authenticated')
				], Http::STATUS_UNAUTHORIZED);
			}
			return new JSONResponse([
				'success' => false,
				'error' => $this->l10n->t('An unexpected error occurred. Please try again. If the problem continues, contact your administrator.')
			], Http::STATUS_INTERNAL_SERVER_ERROR);
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

			if (!$this->isDatabaseSchemaReady()) {
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Database update required. Ask an administrator to run “Update apps” or `php occ upgrade`, then try again.'),
					'code' => 'SCHEMA_NOT_READY',
				], Http::STATUS_SERVICE_UNAVAILABLE);
			}

			try {
				$this->userSettingsMapper->setSetting($userId, 'onboarding_completed', $completed ? '1' : '0');
			} catch (DBException $e) {
				\OCP\Log\logger('arbeitszeitcheck')->error('Settings table not found while schema was reported ready', ['exception' => $e]);
				return new JSONResponse([
					'success' => false,
					'error' => $this->l10n->t('Database update required. Ask an administrator to run “Update apps” or `php occ upgrade`, then try again.'),
					'code' => 'SCHEMA_NOT_READY',
				], Http::STATUS_SERVICE_UNAVAILABLE);
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

	private function isDatabaseSchemaReady(): bool
	{
		try {
			return SchemaHealth::isReady(Server::get(IDBConnection::class));
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning('Schema readiness check failed', ['exception' => $e]);
			return false;
		}
	}
}
