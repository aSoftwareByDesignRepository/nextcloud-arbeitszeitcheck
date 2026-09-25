<?php

declare(strict_types=1);

/**
 * Admin settings for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Settings;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\FrontEndAssetService;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCA\ArbeitszeitCheck\Support\RegionRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings
{
	private IAppConfig $appConfig;
	private IL10N $l10n;
	private IGroupManager $groupManager;
	private IAppManager $appManager;
	private IURLGenerator $urlGenerator;
	private IUserManager $userManager;

	public function __construct(
		IAppConfig $appConfig,
		IL10N $l10n,
		IGroupManager $groupManager,
		IAppManager $appManager,
		IURLGenerator $urlGenerator,
		IUserManager $userManager,
	) {
		$this->appConfig = $appConfig;
		$this->l10n = $l10n;
		$this->groupManager = $groupManager;
		$this->appManager = $appManager;
		$this->urlGenerator = $urlGenerator;
		$this->userManager = $userManager;
	}

	/**
	 * Render the admin settings form that appears in the global
	 * Nextcloud “Administration → ArbeitszeitCheck” area.
	 *
	 * This uses the same template and data structure as the in-app
	 * admin settings route so admins see a single, consistent place
	 * for all core configuration (including country, region, and holidays).
	 *
	 * Parity contract: every field the in-app settings page posts
	 * (country, germanState, weeklyAbsoluteMaxHours, vacationDaysSuggestion,
	 * break auto-fallback, time-entry approval flags, …)
	 * MUST be present here — otherwise a save from NC Admin silently resets
	 * real config (e.g. Swiss 50h → 45h, break fallback off → on).
	 */
	public function getForm(): TemplateResponse
	{
		FrontEndAssetService::registerPage(
			'admin-settings',
			'admin-settings',
			['common/projectcheck', 'common/country-region'],
			['common/admin-user-picker', 'admin-outlook-ical-subscription'],
		);

		$requireSubstituteJson = $this->appConfig->getAppValueString('require_substitute_types', '[]');
		$requireSubstituteTypes = json_decode($requireSubstituteJson, true);
		if (!is_array($requireSubstituteTypes)) {
			$requireSubstituteTypes = [];
		}

		$country = $this->readConfiguredCountry();
		$settings = [
			'autoComplianceCheck' => $this->appConfig->getAppValueString('auto_compliance_check', '1') === '1',
			'realtimeComplianceCheck' => $this->appConfig->getAppValueString('realtime_compliance_check', '1') === '1',
			'complianceStrictMode' => $this->appConfig->getAppValueString('compliance_strict_mode', '0') === '1',
			'enableViolationNotifications' => $this->appConfig->getAppValueString('enable_violation_notifications', '1') === '1',
			'breakAutoFallbackEnabled' => $this->appConfig->getAppValueString('break_auto_fallback_enabled', '1') === '1',
			'breakAutoFallbackMinutes' => max(15, min(720, (int)$this->appConfig->getAppValueString('break_auto_fallback_minutes', '180'))),
			'breakAutoFallbackFlexWindowStart' => max(0, min(23, (int)$this->appConfig->getAppValueString('break_auto_fallback_flex_window_start', '11'))),
			'breakAutoFallbackFlexWindowEnd' => max(1, min(24, (int)$this->appConfig->getAppValueString('break_auto_fallback_flex_window_end', '16'))),
			'exportMidnightSplitEnabled' => $this->appConfig->getAppValueString('export_midnight_split_enabled', '1') === '1',
			'datevBeraternummer' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_BERATERNUMMER, ''),
			'datevMandantennummer' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_MANDANTENNUMMER, ''),
			'datevLohnartNormal' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_LOHNART_NORMAL, '1000'),
			'datevLohnartUeberstunden' => $this->appConfig->getAppValueString(Constants::CONFIG_DATEV_LOHNART_UEBERSTUNDEN, '2000'),
			'monthClosureEnabled' => $this->appConfig->getAppValueString(Constants::CONFIG_MONTH_CLOSURE_ENABLED, '0') === '1',
			'monthClosureGraceDaysAfterEom' => max(0, min(90, (int)$this->appConfig->getAppValueString(Constants::CONFIG_MONTH_CLOSURE_GRACE_DAYS_AFTER_EOM, '0'))),
			'requireSubstituteTypes' => $requireSubstituteTypes,
			'sendIcalApprovedAbsences' => $this->appConfig->getAppValueString('send_ical_approved_absences', '1') === '1',
			'sendIcalToSubstitute' => $this->appConfig->getAppValueString('send_ical_to_substitute', '0') === '1',
			'sendIcalToManagers' => $this->appConfig->getAppValueString('send_ical_to_managers', '0') === '1',
			'sendEmailSubstitutionRequest' => $this->appConfig->getAppValueString('send_email_substitution_request', '1') === '1',
			'sendEmailSubstituteApprovedToEmployee' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_employee', '1') === '1',
			'sendEmailSubstituteApprovedToManager' => $this->appConfig->getAppValueString('send_email_substitute_approved_to_manager', '1') === '1',
			'managerPendingEmailAbsences' => $this->appConfig->getAppValueString(Constants::CONFIG_MANAGER_PENDING_EMAIL_ABSENCES, '1') === '1',
			'managerPendingEmailManualEntries' => $this->appConfig->getAppValueString(Constants::CONFIG_MANAGER_PENDING_EMAIL_MANUAL_ENTRIES, '1') === '1',
			'managerPendingEmailCorrections' => $this->appConfig->getAppValueString(Constants::CONFIG_MANAGER_PENDING_EMAIL_CORRECTIONS, '1') === '1',
			'managerPendingEmailMode' => $this->appConfig->getAppValueString(Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE, Constants::MANAGER_PENDING_EMAIL_MODE_IMMEDIATE) === Constants::MANAGER_PENDING_EMAIL_MODE_DIGEST
				? Constants::MANAGER_PENDING_EMAIL_MODE_DIGEST
				: Constants::MANAGER_PENDING_EMAIL_MODE_IMMEDIATE,
			'maxDailyHours' => (float)$this->appConfig->getAppValueString('max_daily_hours', $this->profileMaxDailyHoursDefault()),
			'minRestPeriod' => (float)$this->appConfig->getAppValueString('min_rest_period', $this->profileMinRestHoursDefault()),
			'country' => $country,
			'germanState' => $this->readConfiguredDefaultRegion(),
			'weeklyAbsoluteMaxHours' => $this->readConfiguredSwissWeeklyAbsoluteMax(),
			'vacationDaysSuggestion' => LaborLawProfileFactory::profileForCountry($country)->vacationDaysSuggestion,
			'statutoryAutoReseed' => $this->appConfig->getAppValueString('statutory_auto_reseed', '1') === '1',
			'retentionPeriod' => (int)$this->appConfig->getAppValueString('retention_period', '2'),
			'defaultWorkingHours' => (float)$this->appConfig->getAppValueString('default_working_hours', '8'),
			'timeEntryChangesRequireApproval' => $this->appConfig->getAppValueString(Constants::CONFIG_TIME_ENTRY_CHANGES_REQUIRE_APPROVAL, '0') === '1',
			'manualTimeEntriesRequireApproval' => $this->appConfig->getAppValueString(Constants::CONFIG_MANUAL_TIME_ENTRIES_REQUIRE_APPROVAL, '0') === '1',
			'clockStampingEnabled' => $this->appConfig->getAppValueString(Constants::CONFIG_CLOCK_STAMPING_ENABLED, '1') === '1',
			'manualTimeEntryEnabled' => $this->appConfig->getAppValueString(Constants::CONFIG_MANUAL_TIME_ENTRY_ENABLED, '1') === '1',
			'accessRestrictionEnabled' => $this->isAccessRestrictionEnabledFromSettings(),
			'accessAllowedGroups' => $this->readAccessAllowedGroups(),
			'accessAllowedUserIds' => $this->readConfiguredAccessAllowedUserIds(),
			'appAdminUserIds' => $this->readConfiguredAppAdminUserIds(),
			'projectCheckIntegrationEnabled' => $this->isProjectCheckInstalledOnInstance()
				&& $this->appConfig->getAppValueString(Constants::CONFIG_PROJECTCHECK_INTEGRATION_ENABLED, Constants::CONFIG_PROJECTCHECK_INTEGRATION_DEFAULT) === '1',
		];

		$projectCheckAvailable = $this->isProjectCheckInstalledOnInstance();
		$catalog = new \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog();
		$ncPages = $catalog->chipBarPayload($this->l10n, $this->urlGenerator, '');
		// NC mega-form has no single active section — chips only guide into the app.
		$ncPages['current'] = '';
		$ncPages['navAriaLabel'] = $this->l10n->t('Open a focused settings page in the app');

		return new TemplateResponse('arbeitszeitcheck', 'admin-settings', [
			'settings' => $settings,
			'availableGroups' => $this->getAvailableGroups(),
			'availableAppAdmins' => $this->getAvailableAppAdmins(),
			'availableAccessUsers' => $this->getAvailableAccessUsers(),
			'l' => $this->l10n,
			'urlGenerator' => $this->urlGenerator,
			'settingsShell' => 'nextcloud',
			'inAppAdminSettingsUrl' => $this->urlGenerator->linkToRoute(
				'arbeitszeitcheck.admin.settingsSection',
				['section' => \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::DEFAULT_SECTION]
			),
			'settingsSection' => \OCA\ArbeitszeitCheck\Service\AdminSettingsSectionCatalog::SECTION_ALL,
			'settingsPages' => $ncPages,
			'supportUsUrl' => $this->urlGenerator->linkToRoute('arbeitszeitcheck.admin.supportUs'),
			'projectCheckAvailable' => $projectCheckAvailable,
			'projectCheckEnabledForCurrentUser' => $this->appManager->isEnabledForUser(Constants::APP_ID_PROJECTCHECK) === true,
			'projectCheckAppsUrl' => $this->projectCheckAppsSettingsUrl(),
			'requesttoken' => Util::callRegister(),
			'useAppTeams' => $this->appConfig->getAppValueString('use_app_teams', '0') === '1',
		]);
	}

	private function isProjectCheckInstalledOnInstance(): bool
	{
		return $this->appManager->isInstalled(Constants::APP_ID_PROJECTCHECK) === true;
	}

	private function projectCheckAppsSettingsUrl(): string
	{
		try {
			return $this->urlGenerator->linkToRoute('settings.AppSettings.viewApps');
		} catch (\Throwable $e) {
			return '';
		}
	}

	private function readConfiguredCountry(): string
	{
		$country = strtoupper(trim($this->appConfig->getAppValueString('country', RegionRegistry::COUNTRY_DE)));

		return RegionRegistry::isSupportedCountry($country) ? $country : RegionRegistry::COUNTRY_DE;
	}

	/**
	 * Swiss ArG Art. 9: only 45 or 50 are valid; anything else → 45.
	 * Must match AdminController::getConfiguredSwissWeeklyAbsoluteMax().
	 */
	private function readConfiguredSwissWeeklyAbsoluteMax(): int
	{
		$raw = (int)$this->appConfig->getAppValueString(
			LaborLawProfileFactory::CONFIG_KEY_WEEKLY_ABSOLUTE_MAX,
			'45'
		);

		return $raw === 50 ? 50 : 45;
	}

	private function profileMaxDailyHoursDefault(): string
	{
		return (string)LaborLawProfileFactory::profileForCountry($this->readConfiguredCountry())->dailyMaxHoursDefault;
	}

	private function profileMinRestHoursDefault(): string
	{
		return (string)LaborLawProfileFactory::profileForCountry($this->readConfiguredCountry())->minRestHoursDefault;
	}

	private function readConfiguredDefaultRegion(): string
	{
		$country = $this->readConfiguredCountry();
		$stored = $this->appConfig->getAppValueString(
			'german_state',
			RegionRegistry::defaultRegionForCountry($country)
		);

		return RegionRegistry::resolveDefaultRegionForCountry($country, $stored);
	}

	public function getSection(): string
	{
		return 'arbeitszeitcheck';
	}

	public function getPriority(): int
	{
		return 50;
	}

	/**
	 * @return list<string>
	 */
	private function readAccessAllowedGroups(): array
	{
		$raw = trim($this->appConfig->getAppValueString(Constants::CONFIG_ACCESS_ALLOWED_GROUP_IDS, ''));
		if ($raw !== '') {
			$decoded = json_decode($raw, true);
			if (is_array($decoded)) {
				$out = [];
				foreach ($decoded as $groupId) {
					$candidate = trim((string)$groupId);
					if ($candidate === '' || isset($out[$candidate])) {
						continue;
					}
					if ($this->groupManager->get($candidate) === null) {
						continue;
					}
					$out[$candidate] = true;
				}
				return array_keys($out);
			}
		}

		$decoded = $this->appManager->getAppRestriction('arbeitszeitcheck');
		$out = [];
		foreach ($decoded as $groupId) {
			$candidate = trim((string)$groupId);
			if ($candidate === '') {
				continue;
			}
			$out[$candidate] = true;
		}
		return array_keys($out);
	}

	/**
	 * @return list<string>
	 */
	private function readConfiguredAccessAllowedUserIds(): array
	{
		$raw = $this->appConfig->getAppValueString(Constants::CONFIG_ACCESS_ALLOWED_USER_IDS, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		$unique = [];
		foreach ($decoded as $candidate) {
			$userId = trim((string)$candidate);
			if ($userId === '' || isset($unique[$userId])) {
				continue;
			}
			$user = $this->userManager->get($userId);
			if ($user === null || !$user->isEnabled()) {
				continue;
			}
			$unique[$userId] = true;
		}

		return array_keys($unique);
	}

	private function isAccessRestrictionEnabledFromSettings(): bool
	{
		$raw = trim($this->appConfig->getAppValueString(Constants::CONFIG_ACCESS_RESTRICTION_ENABLED, ''));
		if ($raw === '0') {
			return false;
		}
		if ($raw === '1') {
			return true;
		}

		return $this->readAccessAllowedGroups() !== []
			|| $this->readConfiguredAccessAllowedUserIds() !== [];
	}

	/**
	 * Options for the allowed-users picker: currently selected allow-listed users.
	 *
	 * @return list<array{id: string, displayName: string}>
	 */
	private function getAvailableAccessUsers(): array
	{
		$byId = [];
		foreach ($this->readConfiguredAccessAllowedUserIds() as $userId) {
			$user = $this->userManager->get($userId);
			$displayName = $user !== null ? trim((string)$user->getDisplayName()) : '';
			$byId[$userId] = [
				'id' => $userId,
				'displayName' => $displayName !== '' ? $displayName : $userId,
			];
		}
		$out = array_values($byId);
		usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $out;
	}

	/**
	 * @return list<array{id: string, displayName: string}>
	 */
	private function getAvailableGroups(): array
	{
		$out = [];
		foreach ($this->groupManager->search('') as $group) {
			$gid = trim((string)$group->getGID());
			if ($gid === '') {
				continue;
			}
			$displayName = trim((string)$group->getDisplayName());
			$out[] = ['id' => $gid, 'displayName' => $displayName !== '' ? $displayName : $gid];
		}
		usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $out;
	}

	/**
	 * @return list<string>
	 */
	private function readConfiguredAppAdminUserIds(): array
	{
		$raw = $this->appConfig->getAppValueString(Constants::CONFIG_APP_ADMIN_USER_IDS, '[]');
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}

		$unique = [];
		foreach ($decoded as $candidate) {
			$userId = trim((string)$candidate);
			if ($userId === '' || isset($unique[$userId])) {
				continue;
			}
			$user = $this->userManager->get($userId);
			if ($user === null || !$user->isEnabled()) {
				continue;
			}
			$unique[$userId] = true;
		}

		return array_keys($unique);
	}

	/**
	 * Options for the App Admin picker: dedicated selected users plus Nextcloud admins.
	 *
	 * @return list<array{id: string, displayName: string}>
	 */
	private function getAvailableAppAdmins(): array
	{
		$byId = [];
		foreach ($this->readConfiguredAppAdminUserIds() as $userId) {
			$user = $this->userManager->get($userId);
			$displayName = $user !== null ? trim((string)$user->getDisplayName()) : '';
			$byId[$userId] = [
				'id' => $userId,
				'displayName' => $displayName !== '' ? $displayName : $userId,
			];
		}
		$adminGroup = $this->groupManager->get('admin');
		if ($adminGroup !== null) {
			foreach ($adminGroup->getUsers() as $adminUser) {
				$userId = trim((string)$adminUser->getUID());
				if ($userId === '' || isset($byId[$userId])) {
					continue;
				}
				$displayName = trim((string)$adminUser->getDisplayName());
				$byId[$userId] = [
					'id' => $userId,
					'displayName' => $displayName !== '' ? $displayName : $userId,
				];
			}
		}

		$out = array_values($byId);
		usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));
		return $out;
	}
}