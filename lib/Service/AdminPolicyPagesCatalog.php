<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * Single source of truth for the admin policy settings cluster
 * (Vacation / Overtime / Notifications) after the kitchen-sink split.
 *
 * Design-system contract: {@see planning/check-productivity-suite/SETTINGS-PAGES-STANDARD.md}
 * — catalog drives chip bar, sidebar labels, legacy #anchor redirects, and contract tests.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */
final class AdminPolicyPagesCatalog
{
	public const SECTION_VACATION = 'vacation';
	public const SECTION_VACATION_ENTITLEMENT = 'vacation-entitlement';
	public const SECTION_OVERTIME = 'overtime';
	public const SECTION_PAYOUTS = 'overtime-payouts';
	public const SECTION_PAYOUT_AUDIT = 'overtime-payout-audit';
	public const SECTION_YEAR_END = 'year-end';
	public const SECTION_NOTIFICATIONS = 'notifications';

	/**
	 * Ordered policy-cluster sections (chip bar + contract tests).
	 *
	 * @var list<string>
	 */
	public const SECTIONS = [
		self::SECTION_VACATION,
		self::SECTION_VACATION_ENTITLEMENT,
		self::SECTION_OVERTIME,
		self::SECTION_PAYOUTS,
		self::SECTION_PAYOUT_AUDIT,
		self::SECTION_YEAR_END,
		self::SECTION_NOTIFICATIONS,
	];

	/**
	 * Legacy kitchen-sink anchors → owning section.
	 *
	 * @var array<string, string>
	 */
	public const LEGACY_ANCHORS = [
		'section-absences-heading' => self::SECTION_VACATION,
		'vacation-year-mode-heading' => self::SECTION_VACATION,
		'vacation-unit-heading' => self::SECTION_VACATION,
		'vacation-carryover-expiry-heading' => self::SECTION_VACATION,
		'vacation-proration-heading' => self::SECTION_VACATION,
		'vacation-layers-intro-title' => self::SECTION_VACATION_ENTITLEMENT,
		'layer-l0' => self::SECTION_VACATION_ENTITLEMENT,
		'layer-l1' => self::SECTION_VACATION_ENTITLEMENT,
		'layer-l2' => self::SECTION_VACATION_ENTITLEMENT,
		'layer-sim' => self::SECTION_VACATION_ENTITLEMENT,
		'overtime-bank-heading' => self::SECTION_OVERTIME,
		'premium-surcharges-heading' => self::SECTION_OVERTIME,
		'premium-surcharges-section' => self::SECTION_OVERTIME,
		'block-clock-reminders-heading' => self::SECTION_NOTIFICATIONS,
		'section-absence-workflow-heading' => self::SECTION_NOTIFICATIONS,
		'overtime-trafficlight-heading' => self::SECTION_NOTIFICATIONS,
		'hr-notifications-heading' => self::SECTION_NOTIFICATIONS,
	];

	/**
	 * Map pageId (shell) → catalog section.
	 *
	 * @var array<string, string>
	 */
	public const PAGE_ID_TO_SECTION = [
		'admin-vacation-rules' => self::SECTION_VACATION,
		'admin-vacation-layers' => self::SECTION_VACATION_ENTITLEMENT,
		'admin-overtime-settings' => self::SECTION_OVERTIME,
		'admin-overtime-payouts' => self::SECTION_PAYOUTS,
		'admin-overtime-payout-audit' => self::SECTION_PAYOUT_AUDIT,
		'admin-year-end' => self::SECTION_YEAR_END,
		'admin-notifications' => self::SECTION_NOTIFICATIONS,
	];

	/**
	 * Topic groups for the in-page chip bar (clear hierarchy — not a flat wall).
	 *
	 * @var array<string, list<string>>
	 */
	public const SECTION_GROUPS = [
		'leave' => [
			self::SECTION_VACATION,
			self::SECTION_VACATION_ENTITLEMENT,
		],
		'overtime' => [
			self::SECTION_OVERTIME,
			self::SECTION_PAYOUTS,
			self::SECTION_PAYOUT_AUDIT,
			self::SECTION_YEAR_END,
		],
		'alerts' => [self::SECTION_NOTIFICATIONS],
	];

	public function isSection(string $section): bool
	{
		return in_array($section, self::SECTIONS, true);
	}

	public function sectionForPageId(string $pageId): ?string
	{
		return self::PAGE_ID_TO_SECTION[$pageId] ?? null;
	}

	public function groupLabel(IL10N $l, string $groupId): string
	{
		return match ($groupId) {
			'leave' => $l->t('Leave'),
			'overtime' => $l->t('Overtime'),
			'alerts' => $l->t('Alerts'),
			default => $l->t('Topics'),
		};
	}

	/**
	 * Default section when opening Policy settings from the sidebar.
	 */
	public function defaultSection(): string
	{
		return self::SECTION_VACATION;
	}

	/**
	 * Long H1 / breadcrumb title.
	 */
	public function label(IL10N $l, string $section): string
	{
		return match ($section) {
			self::SECTION_VACATION => $l->t('Vacation rules'),
			self::SECTION_VACATION_ENTITLEMENT => $l->t('Vacation entitlement'),
			self::SECTION_OVERTIME => $l->t('Overtime & premiums'),
			self::SECTION_PAYOUTS => $l->t('Overtime payouts'),
			self::SECTION_PAYOUT_AUDIT => $l->t('Payout audit'),
			self::SECTION_YEAR_END => $l->t('Year-end reset'),
			self::SECTION_NOTIFICATIONS => $l->t('Notifications'),
			default => $l->t('Administration'),
		};
	}

	/**
	 * Short chip / sidebar label (SETTINGS-PAGES-STANDARD §4).
	 */
	public function navLabel(IL10N $l, string $section): string
	{
		return match ($section) {
			self::SECTION_VACATION => $l->t('Rules'),
			self::SECTION_VACATION_ENTITLEMENT => $l->t('Entitlement'),
			self::SECTION_OVERTIME => $l->t('Overtime'),
			self::SECTION_PAYOUTS => $l->t('Payouts'),
			self::SECTION_PAYOUT_AUDIT => $l->t('Payout audit'),
			self::SECTION_YEAR_END => $l->t('Year-end'),
			self::SECTION_NOTIFICATIONS => $l->t('Alerts'),
			default => $l->t('Admin'),
		};
	}

	/**
	 * Absolute in-app URL for a catalog section.
	 */
	public function url(IURLGenerator $urlGenerator, string $section): string
	{
		return match ($section) {
			self::SECTION_VACATION => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.vacationRules'),
			self::SECTION_VACATION_ENTITLEMENT => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.vacationLayers'),
			self::SECTION_OVERTIME => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.overtimeSettings'),
			self::SECTION_PAYOUTS => $urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.index'),
			self::SECTION_PAYOUT_AUDIT => $urlGenerator->linkToRoute('arbeitszeitcheck.overtime_payout.auditIndex'),
			self::SECTION_YEAR_END => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.yearEndPage'),
			self::SECTION_NOTIFICATIONS => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.notifications'),
			default => $urlGenerator->linkToRoute('arbeitszeitcheck.admin.dashboard'),
		};
	}

	/**
	 * Chip bar payload for templates.
	 *
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
			'current' => $this->isSection($currentSection) ? $currentSection : $this->defaultSection(),
			'labels' => $labels,
			'urls' => $urls,
			'groups' => $groups,
			'legacyAnchors' => self::LEGACY_ANCHORS,
		];
	}

	/**
	 * Absolute URL for a legacy anchor’s owning section (optional fragment when staying).
	 */
	public function legacyRedirectTarget(IURLGenerator $urlGenerator, string $anchor): ?string
	{
		$section = self::LEGACY_ANCHORS[$anchor] ?? null;
		if ($section === null) {
			return null;
		}
		$base = $this->url($urlGenerator, $section);
		// Keep fragment when the target page still owns that heading.
		if ($section === self::SECTION_NOTIFICATIONS
			|| $section === self::SECTION_OVERTIME
			|| $section === self::SECTION_VACATION
			|| $section === self::SECTION_VACATION_ENTITLEMENT
		) {
			return $base . '#' . $anchor;
		}
		return $base;
	}
}
