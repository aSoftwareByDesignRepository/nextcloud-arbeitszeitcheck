<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Single source of truth for employee My settings multipage
 * (`/settings/{section}`) — SETTINGS-PAGES-STANDARD.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
final class EmployeeSettingsSectionCatalog
{
	public const DEFAULT_SECTION = 'breaks';

	public const SECTION_BREAKS = 'breaks';
	public const SECTION_NOTIFICATIONS = 'notifications';
	public const SECTION_DATA_PRIVACY = 'data-privacy';
	public const SECTION_HELP_TEXTS = 'help-texts';
	public const SECTION_ABOUT = 'about';

	/**
	 * Ordered chip-bar topics (sidebar stays one “My settings” item).
	 *
	 * @var list<string>
	 */
	public const SECTIONS = [
		self::SECTION_BREAKS,
		self::SECTION_NOTIFICATIONS,
		self::SECTION_DATA_PRIVACY,
		self::SECTION_HELP_TEXTS,
		self::SECTION_ABOUT,
	];

	/**
	 * Topic groups for the in-page chip bar.
	 *
	 * @var array<string, list<string>>
	 */
	public const SECTION_GROUPS = [
		'preferences' => [
			self::SECTION_BREAKS,
			self::SECTION_NOTIFICATIONS,
		],
		'account' => [
			self::SECTION_DATA_PRIVACY,
			self::SECTION_HELP_TEXTS,
			self::SECTION_ABOUT,
		],
	];

	/**
	 * Literal slug → partial under templates/partials/employee-settings/.
	 *
	 * @var array<string, string>
	 */
	public const SECTION_FILES = [
		self::SECTION_BREAKS => 'breaks.php',
		self::SECTION_NOTIFICATIONS => 'notifications.php',
		self::SECTION_DATA_PRIVACY => 'data-privacy.php',
		self::SECTION_HELP_TEXTS => 'help-texts.php',
		self::SECTION_ABOUT => 'about.php',
	];

	/**
	 * Legacy mega-page anchors → owning section.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_ANCHORS = [
		'settings-sections-heading' => self::SECTION_BREAKS,
		'auto-break-calculation' => self::SECTION_BREAKS,
		'settings-model-heading' => self::SECTION_BREAKS,
		'settings-notifications-heading' => self::SECTION_NOTIFICATIONS,
		'settings-data-privacy' => self::SECTION_DATA_PRIVACY,
		'settings-privacy-heading' => self::SECTION_DATA_PRIVACY,
		'settings-hints-heading' => self::SECTION_HELP_TEXTS,
		'settings-compliance-heading' => self::SECTION_ABOUT,
		'settings-version-heading' => self::SECTION_ABOUT,
	];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	public static function routeRequirement(): string
	{
		return implode('|', self::SECTIONS);
	}

	public function defaultSection(): string
	{
		return self::DEFAULT_SECTION;
	}

	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			self::SECTION_BREAKS => $l->t('Breaks'),
			self::SECTION_NOTIFICATIONS => $l->t('Notifications'),
			self::SECTION_DATA_PRIVACY => $l->t('Data and privacy'),
			self::SECTION_HELP_TEXTS => $l->t('Help texts'),
			self::SECTION_ABOUT => $l->t('About'),
			default => $l->t('My settings'),
		};
	}

	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			self::SECTION_BREAKS => $l->t('Breaks'),
			self::SECTION_NOTIFICATIONS => $l->t('Notifications'),
			self::SECTION_DATA_PRIVACY => $l->t('Data & privacy'),
			self::SECTION_HELP_TEXTS => $l->t('Help texts'),
			self::SECTION_ABOUT => $l->t('About'),
			default => $l->t('Settings'),
		};
	}

	public function help(IL10N $l, string $section): string
	{
		return match ($section) {
			self::SECTION_BREAKS => $l->t('How the app calculates your breaks, and your assigned schedule.'),
			self::SECTION_NOTIFICATIONS => $l->t('Choose which reminders you want to receive.'),
			self::SECTION_DATA_PRIVACY => $l->t('Export or permanently delete your personal ArbeitszeitCheck data.'),
			self::SECTION_HELP_TEXTS => $l->t('Help texts you hid on pages stay hidden until you show them again here or use "Show hint" where they appear.'),
			self::SECTION_ABOUT => $l->t('Working-time law summary and installed app version.'),
			default => $l->t('Your personal preferences.'),
		};
	}

	public function url(IURLGenerator $urlGenerator, string $section): string
	{
		if (!$this->isSection($section)) {
			$section = self::DEFAULT_SECTION;
		}
		return $urlGenerator->linkToRoute('arbeitszeitcheck.page.settingsSection', ['section' => $section]);
	}

	public function groupLabel(IL10N $l, string $groupId): string
	{
		return match ($groupId) {
			'preferences' => $l->t('Preferences'),
			'account' => $l->t('Account'),
			default => $l->t('Topics'),
		};
	}

	/**
	 * @return array{
	 *   current: string,
	 *   labels: array<string, string>,
	 *   urls: array<string, string>,
	 *   groups: list<array{id: string, label: string, sections: list<string>}>,
	 *   legacyAnchors: array<string, string>,
	 *   navAriaLabel: string,
	 *   navTitle: string
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
			'navAriaLabel' => $l->t('My settings topics'),
			'navTitle' => $l->t('Choose a topic'),
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
}
