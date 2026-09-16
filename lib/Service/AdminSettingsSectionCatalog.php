<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Single source of truth for Global settings multipage
 * (`/admin/settings/{section}`) after the SETTINGS-PAGES-STANDARD split.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
final class AdminSettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'access';

	public const SECTION_ACCESS = 'access';
	public const SECTION_COMPLIANCE = 'compliance';
	public const SECTION_TIME_RECORDING = 'time-recording';
	public const SECTION_TIME_APPROVALS = 'time-approvals';
	public const SECTION_EXPORTS = 'exports';
	public const SECTION_OUTLOOK_SUBSCRIPTION = 'outlook-subscription';
	public const SECTION_MONTH_CLOSURE = 'month-closure';
	public const SECTION_HOURS = 'hours';
	public const SECTION_REGIONAL = 'regional';
	public const SECTION_RETENTION = 'retention';
	public const SECTION_PROJECTCHECK = 'projectcheck';

	/** Sentinel for Nextcloud Administration mega-form (full write parity). */
	public const SECTION_ALL = 'all';

	/**
	 * Ordered in-app section slugs (chip bar topics — single sidebar entry only).
	 *
	 * @var list<string>
	 */
	public const SECTIONS = [
		self::SECTION_ACCESS,
		self::SECTION_COMPLIANCE,
		self::SECTION_TIME_RECORDING,
		self::SECTION_TIME_APPROVALS,
		self::SECTION_EXPORTS,
		self::SECTION_OUTLOOK_SUBSCRIPTION,
		self::SECTION_MONTH_CLOSURE,
		self::SECTION_HOURS,
		self::SECTION_REGIONAL,
		self::SECTION_RETENTION,
		self::SECTION_PROJECTCHECK,
	];

	/**
	 * Topic groups for the in-page chip bar (clear hierarchy — not a flat wall of 10).
	 *
	 * @var array<string, list<string>>
	 */
	public const SECTION_GROUPS = [
		'access' => [self::SECTION_ACCESS],
		'time' => [
			self::SECTION_COMPLIANCE,
			self::SECTION_TIME_RECORDING,
			self::SECTION_TIME_APPROVALS,
			self::SECTION_HOURS,
			self::SECTION_REGIONAL,
		],
		'ops' => [
			self::SECTION_EXPORTS,
			self::SECTION_OUTLOOK_SUBSCRIPTION,
			self::SECTION_MONTH_CLOSURE,
			self::SECTION_RETENTION,
		],
		'connections' => [self::SECTION_PROJECTCHECK],
	];

	/**
	 * Literal slug → partial filename under templates/partials/admin-settings/.
	 * ProjectCheck uses a shared include outside this map.
	 *
	 * @var array<string, string>
	 */
	public const SECTION_FILES = [
		self::SECTION_ACCESS => 'access.php',
		self::SECTION_COMPLIANCE => 'compliance.php',
		self::SECTION_TIME_RECORDING => 'time-recording.php',
		self::SECTION_TIME_APPROVALS => 'time-approvals.php',
		self::SECTION_EXPORTS => 'exports.php',
		self::SECTION_OUTLOOK_SUBSCRIPTION => 'outlook-ical-subscription.php',
		self::SECTION_MONTH_CLOSURE => 'month-closure.php',
		self::SECTION_HOURS => 'hours.php',
		self::SECTION_REGIONAL => 'regional.php',
		self::SECTION_RETENTION => 'retention.php',
		self::SECTION_PROJECTCHECK => 'projectcheck', // special: shared partial
	];

	/**
	 * Legacy mega-page anchors → owning section.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_ANCHORS = [
		'section-access-heading' => self::SECTION_ACCESS,
		'section-compliance-heading' => self::SECTION_COMPLIANCE,
		'section-time-capture-heading' => self::SECTION_TIME_RECORDING,
		'section-time-approval-heading' => self::SECTION_TIME_APPROVALS,
		'section-export-heading' => self::SECTION_EXPORTS,
		'section-outlook-subscription-heading' => self::SECTION_OUTLOOK_SUBSCRIPTION,
		'section-month-closure-heading' => self::SECTION_MONTH_CLOSURE,
		'section-hours-heading' => self::SECTION_HOURS,
		'section-regional-heading' => self::SECTION_REGIONAL,
		'section-retention-heading' => self::SECTION_RETENTION,
		'section-projectcheck-heading' => self::SECTION_PROJECTCHECK,
	];

	/**
	 * Parameter keys owned by each section (partial-save allowlist).
	 * Special keys (access lists, clock methods, DATEV) are gated separately.
	 *
	 * @var array<string, list<string>>
	 */
	public const SECTION_PARAM_KEYS = [
		self::SECTION_ACCESS => [],
		self::SECTION_COMPLIANCE => [
			'autoComplianceCheck',
			'realtimeComplianceCheck',
			'complianceStrictMode',
			'enableViolationNotifications',
			'breakAutoFallbackEnabled',
			'breakAutoFallbackMinutes',
			'breakAutoFallbackFlexWindowStart',
			'breakAutoFallbackFlexWindowEnd',
			'requireSubstituteTypes',
		],
		self::SECTION_TIME_RECORDING => [
			'clockStampingEnabled',
			'manualTimeEntryEnabled',
			'offlineStampMaxPastHours',
		],
		self::SECTION_TIME_APPROVALS => [
			'timeEntryChangesRequireApproval',
			'manualTimeEntriesRequireApproval',
		],
		self::SECTION_EXPORTS => [
			'exportMidnightSplitEnabled',
			'datevBeraternummer',
			'datevMandantennummer',
			'datevLohnartNormal',
			'datevLohnartUeberstunden',
		],
		self::SECTION_OUTLOOK_SUBSCRIPTION => [],
		self::SECTION_MONTH_CLOSURE => [
			'monthClosureEnabled',
			'monthClosureGraceDaysAfterEom',
		],
		self::SECTION_HOURS => [
			'maxDailyHours',
			'minRestPeriod',
			'defaultWorkingHours',
			'timePickerMinuteStep',
		],
		self::SECTION_REGIONAL => [
			'country',
			'germanState',
			'statutoryAutoReseed',
			'weeklyAbsoluteMaxHours',
		],
		self::SECTION_RETENTION => [
			'retentionPeriod',
		],
		self::SECTION_PROJECTCHECK => [
			'projectCheckIntegrationEnabled',
		],
	];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	public function isWritableScope(string $scope): bool
	{
		return $scope === self::SECTION_ALL || $this->isSection($scope);
	}

	public static function routeRequirement(): string
	{
		return implode('|', self::SECTIONS);
	}

	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			self::SECTION_ACCESS => $l->t('Access control'),
			self::SECTION_COMPLIANCE => $l->t('Compliance and working time rules'),
			self::SECTION_TIME_RECORDING => $l->t('Time recording'),
			self::SECTION_TIME_APPROVALS => $l->t('Time entry approvals'),
			self::SECTION_EXPORTS => $l->t('Exports and reporting'),
			self::SECTION_OUTLOOK_SUBSCRIPTION => $l->t('Calendar subscription'),
			self::SECTION_MONTH_CLOSURE => $l->t('Month closure'),
			self::SECTION_HOURS => $l->t('Daily hours and rest periods'),
			self::SECTION_REGIONAL => $l->t('Country and region'),
			self::SECTION_RETENTION => $l->t('Data retention'),
			self::SECTION_PROJECTCHECK => $l->t('ProjectCheck connection'),
			default => $l->t('Global settings'),
		};
	}

	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			self::SECTION_ACCESS => $l->t('Access'),
			self::SECTION_COMPLIANCE => $l->t('Compliance'),
			self::SECTION_TIME_RECORDING => $l->t('Time recording'),
			self::SECTION_TIME_APPROVALS => $l->t('Approvals'),
			self::SECTION_EXPORTS => $l->t('Exports'),
			self::SECTION_OUTLOOK_SUBSCRIPTION => $l->t('Calendar'),
			self::SECTION_MONTH_CLOSURE => $l->t('Month close'),
			self::SECTION_HOURS => $l->t('Hours & rest'),
			self::SECTION_REGIONAL => $l->t('Country'),
			self::SECTION_RETENTION => $l->t('Retention'),
			self::SECTION_PROJECTCHECK => $l->t('ProjectCheck'),
			default => $l->t('Settings'),
		};
	}

	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			self::SECTION_ACCESS => $l->t('Choose who may administer ArbeitszeitCheck and who may open the app.'),
			self::SECTION_COMPLIANCE => $l->t('Compliance checks, substitute rules, and break fallback.'),
			self::SECTION_TIME_RECORDING => $l->t('Choose how the organisation records working time, including how long offline stamps may be replayed.'),
			self::SECTION_TIME_APPROVALS => $l->t('When edits and new manual entries need manager approval.'),
			self::SECTION_EXPORTS => $l->t('Midnight split for exports and DATEV payroll numbers.'),
			self::SECTION_OUTLOOK_SUBSCRIPTION => $l->t('Generate privacy-safe calendar subscription links per team and manager scope.'),
			self::SECTION_MONTH_CLOSURE => $l->t('Finalize months safely, and reopen when needed.'),
			self::SECTION_HOURS => $l->t('Daily maximum, rest between days, and default hours.'),
			self::SECTION_REGIONAL => $l->t('Country, default holiday region, and statutory holiday seeding.'),
			self::SECTION_RETENTION => $l->t('How long time records are kept before cleanup.'),
			self::SECTION_PROJECTCHECK => $l->t('Optional link from time entries to ProjectCheck projects.'),
			default => '',
		};
	}

	public function url(IURLGenerator $urlGenerator, string $section): string
	{
		if (!$this->isSection($section)) {
			$section = self::DEFAULT_SECTION;
		}
		return $urlGenerator->linkToRoute('arbeitszeitcheck.admin.settingsSection', ['section' => $section]);
	}

	public function groupLabel(IL10N $l, string $groupId): string
	{
		return match ($groupId) {
			'access' => $l->t('Who can use the app'),
			'time' => $l->t('Working time rules'),
			'ops' => $l->t('Exports & data'),
			'connections' => $l->t('Connections'),
			default => $l->t('Topics'),
		};
	}

	/**
	 * @return array{
	 *   current: string,
	 *   labels: array<string, string>,
	 *   urls: array<string, string>,
	 *   groups: list<array{id: string, label: string, sections: list<string>}>,
	 *   legacyAnchors: array<string, string>
	 * }
	 */
	public function chipBarPayload(IL10N $l, IURLGenerator $urlGenerator, string $currentSection): array
	{
		$labels = [];
		$urls = [];
		foreach (self::SECTIONS as $section) {
			$labels[$section] = $this->navLabel($l, $section);
			$urls[$section] = $this->url($urlGenerator, $section);
		}

		$groups = [];
		foreach (self::SECTION_GROUPS as $groupId => $sectionIds) {
			$groups[] = [
				'id' => $groupId,
				'label' => $this->groupLabel($l, $groupId),
				'sections' => array_values($sectionIds),
			];
		}

		return [
			'current' => $this->isSection($currentSection) ? $currentSection : self::DEFAULT_SECTION,
			'labels' => $labels,
			'urls' => $urls,
			'groups' => $groups,
			'legacyAnchors' => self::LEGACY_ANCHORS,
		];
	}

	public function legacyRedirectTarget(IURLGenerator $urlGenerator, string $anchor): ?string
	{
		$section = self::LEGACY_ANCHORS[$anchor] ?? null;
		if ($section === null) {
			return null;
		}
		return $this->url($urlGenerator, $section) . '#' . $anchor;
	}

	/**
	 * @return list<string>|null null means all keys (SECTION_ALL)
	 */
	public function allowedParamKeys(string $scope): ?array
	{
		if ($scope === self::SECTION_ALL || $scope === '') {
			return null;
		}
		if (!$this->isSection($scope)) {
			return [];
		}
		return self::SECTION_PARAM_KEYS[$scope] ?? [];
	}
}
