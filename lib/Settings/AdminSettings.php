<?php

declare(strict_types=1);

/**
 * Admin settings for the arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;
use OCP\Settings\ISettings;

class AdminSettings implements ISettings
{
	private IAppConfig $appConfig;
	private IL10N $l10n;

	public function __construct(IAppConfig $appConfig, IL10N $l10n)
	{
		$this->appConfig = $appConfig;
		$this->l10n = $l10n;
	}

	/**
	 * Render the admin settings form that appears in the global
	 * Nextcloud “Administration → ArbeitszeitCheck” area.
	 *
	 * This uses the same template and data structure as the in‑app
	 * admin settings route so admins see a single, consistent place
	 * for all core configuration (including Bundesland and holidays).
	 */
	public function getForm(): TemplateResponse
	{
		$requireSubstituteJson = $this->appConfig->getAppValueString('require_substitute_types', '[]');
		$requireSubstituteTypes = json_decode($requireSubstituteJson, true);
		if (!is_array($requireSubstituteTypes)) {
			$requireSubstituteTypes = [];
		}

		$settings = [
			'autoComplianceCheck' => $this->appConfig->getAppValueString('auto_compliance_check', '1') === '1',
			'requireBreakJustification' => $this->appConfig->getAppValueString('require_break_justification', '1') === '1',
			'enableViolationNotifications' => $this->appConfig->getAppValueString('enable_violation_notifications', '1') === '1',
			'requireSubstituteTypes' => $requireSubstituteTypes,
			'sendIcalApprovedAbsences' => $this->appConfig->getAppValueString('send_ical_approved_absences', '1') === '1',
			'sendIcalToSubstitute' => $this->appConfig->getAppValueString('send_ical_to_substitute', '0') === '1',
			'sendIcalToManagers' => $this->appConfig->getAppValueString('send_ical_to_managers', '0') === '1',
			'maxDailyHours' => (float)$this->appConfig->getAppValueString('max_daily_hours', '10'),
			'minRestPeriod' => (float)$this->appConfig->getAppValueString('min_rest_period', '11'),
			'germanState' => $this->appConfig->getAppValueString('german_state', 'NW'),
			'retentionPeriod' => (int)$this->appConfig->getAppValueString('retention_period', '2'),
			'defaultWorkingHours' => (float)$this->appConfig->getAppValueString('default_working_hours', '8'),
		];

		return new TemplateResponse('arbeitszeitcheck', 'admin-settings', [
			'settings' => $settings,
			'l' => $this->l10n,
		]);
	}

	public function getSection(): string
	{
		return 'arbeitszeitcheck';
	}

	public function getPriority(): int
	{
		return 50;
	}
}