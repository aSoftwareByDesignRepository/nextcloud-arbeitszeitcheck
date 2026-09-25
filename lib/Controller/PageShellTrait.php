<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Controller;

use OCA\ArbeitszeitCheck\Service\FrontEndAssetService;
use OCA\ArbeitszeitCheck\Service\AdminPolicyPagesCatalog;
use OCA\ArbeitszeitCheck\Service\LocaleFormatService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Shared page shell parameters for ArbeitszeitCheck templates.
 *
 * Controllers using this trait must expose:
 *  - protected IUserSession $userSession
 *  - protected IURLGenerator $urlGenerator
 *  - protected IL10N $l10n
 *  - protected PermissionService $permissionService
 *  - protected LocaleFormatService $localeFormat
 *
 * Use {@see NavigationFlagsTrait} with {@see \OCA\ArbeitszeitCheck\Service\NavigationFlagsService}
 * for nav visibility flags (do not duplicate getNavigationFlags in controllers).
 */
trait PageShellTrait
{
	/** Page IDs that keep a readable max width (dense single-entity forms). */
	private const CONSTRAINED_SHELL_PAGE_IDS = [
	];

	/** Page IDs that use full-width shell (tables, dashboards, dense admin/manager lists). */
	private const WIDE_SHELL_PAGE_IDS = [
		'dashboard',
		'time-entries',
		'absences',
		'calendar',
		'timeline',
		'reports',
		'substitution-requests',
		'compliance-dashboard',
		'compliance-violations',
		'compliance-reports',
		'settings',
		'manager-dashboard',
		'manager-time-entries',
		'manager-absences',
		'manager-month-closures',
		'admin-dashboard',
		'admin-users',
		'admin-user-detail',
		'admin-notifications',
		'admin-overtime-settings',
		'admin-license',
		'admin-settings',
		'admin-support-us',
		'admin-overtime-payouts',
		'admin-overtime-payout-audit',
		'admin-tariff-rules',
		'admin-vacation-layers',
		'admin-vacation-rules',
		'admin-teams',
		'admin-holidays',
		'admin-audit-log',
		'admin-working-time-models',
		'admin-kiosk',
	];

	/**
	 * Attach SETTINGS-PAGES-STANDARD chip bar payload for policy-cluster pages.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	protected function withPolicyPagesNav(array $params, string $pageId): array
	{
		$catalog = new AdminPolicyPagesCatalog();
		$section = $catalog->sectionForPageId($pageId);
		if ($section === null) {
			return $params;
		}
		$params['policyPages'] = $catalog->chipBarPayload($this->l10n, $this->urlGenerator, $section);
		// SETTINGS-PAGES-STANDARD §7: App → Policy settings (link) → section title
		$params['breadcrumbSection'] = '';
		$params['breadcrumbParent'] = [
			'label' => $this->l10n->t('Policy settings'),
			'url' => $catalog->url($this->urlGenerator, $catalog->defaultSection()),
		];
		return $params;
	}

	/**
	 * @param array<string, mixed> $navFlags
	 * @return array<string, mixed>
	 */
	protected function buildShellParams(
		string $pageId,
		string $pageTitle,
		string $pageHelp,
		array $navFlags = [],
		?string $breadcrumbSection = null,
		string $shellWidth = 'standard',
	): array {
		$userId = $this->getShellUserId();
		$roleLabel = $this->resolveRoleLabel($userId);
		$clientHints = $this->localeFormat->clientHints();
		$shellWidth = in_array($shellWidth, ['standard', 'wide', 'constrained', 'minimal'], true) ? $shellWidth : 'standard';
		if ($shellWidth === 'standard' && in_array($pageId, self::CONSTRAINED_SHELL_PAGE_IDS, true)) {
			$shellWidth = 'constrained';
		} elseif ($shellWidth === 'standard' && in_array($pageId, self::WIDE_SHELL_PAGE_IDS, true)) {
			$shellWidth = 'wide';
		}

		$params = array_merge($navFlags, [
			'pageId' => $pageId,
			'pageTitle' => $pageTitle,
			'pageHelp' => $pageHelp,
			'shellWidth' => $shellWidth,
			'breadcrumbSection' => $breadcrumbSection,
			'roleLabel' => $roleLabel,
			'roleSlug' => $this->roleSlugFromLabel($roleLabel),
			'clientHints' => $clientHints,
			'localeFormat' => $this->localeFormat,
			'urls' => $this->buildShellUrls(),
			'urlGenerator' => $this->urlGenerator,
			'l' => $this->l10n,
			'pendingCorrectionCount' => $navFlags['pendingCorrectionCount'] ?? 0,
		]);

		return $this->withPolicyPagesNav($params, $pageId);
	}

	/**
	 * @param list<string> $extraStyles
	 * @param list<string> $extraScripts
	 */
	protected function registerFrontEndAssets(string $pageScript, ?string $pageStyle = null, array $extraStyles = [], array $extraScripts = []): void
	{
		FrontEndAssetService::registerPage($pageScript, $pageStyle, $extraStyles, $extraScripts);
	}

	/**
	 * Merge shell params with page-specific data for TemplateResponse.
	 *
	 * @param array<string, mixed> $navFlags
	 * @param array<string, mixed> $pageData
	 * @return array<string, mixed>
	 */
	protected function mergeShellPageParams(
		string $pageId,
		string $pageTitle,
		string $pageHelp,
		array $navFlags,
		array $pageData = [],
		?string $breadcrumbSection = null,
		string $shellWidth = 'standard',
	): array {
		return $this->buildShellParams(
			$pageId,
			$pageTitle,
			$pageHelp,
			$navFlags,
			$breadcrumbSection,
			$shellWidth,
		) + $pageData;
	}

	/**
	 * @return array<string, string>
	 */
	protected function buildShellUrls(): array
	{
		$g = $this->urlGenerator;

		return [
			'dashboard' => $g->linkToRoute('arbeitszeitcheck.page.dashboard'),
			'timeEntries' => $g->linkToRoute('arbeitszeitcheck.page.timeEntries'),
			'absences' => $g->linkToRoute('arbeitszeitcheck.page.absences'),
			'calendar' => $g->linkToRoute('arbeitszeitcheck.page.calendar'),
			'timeline' => $g->linkToRoute('arbeitszeitcheck.page.timeline'),
			'settings' => $g->linkToRoute('arbeitszeitcheck.page.settings'),
			'getTheApp' => $g->linkToRoute('arbeitszeitcheck.page.getTheApp'),
			'reports' => $g->linkToRoute('arbeitszeitcheck.page.reports'),
			'complianceDashboard' => $g->linkToRoute('arbeitszeitcheck.compliance.dashboard'),
			'complianceViolations' => $g->linkToRoute('arbeitszeitcheck.compliance.violations'),
			'complianceReports' => $g->linkToRoute('arbeitszeitcheck.compliance.reports'),
			'managerDashboard' => $g->linkToRoute('arbeitszeitcheck.manager.dashboard'),
			'managerTimeEntries' => $g->linkToRoute('arbeitszeitcheck.manager.employeeTimeEntriesPage'),
			'managerAbsences' => $g->linkToRoute('arbeitszeitcheck.manager.employeeAbsencesPage'),
			'managerMonthClosures' => $g->linkToRoute('arbeitszeitcheck.manager.monthClosuresPage'),
			'substitutionRequests' => $g->linkToRoute('arbeitszeitcheck.substitute.index'),
			'adminDashboard' => $g->linkToRoute('arbeitszeitcheck.admin.dashboard'),
			'adminNotifications' => $g->linkToRoute('arbeitszeitcheck.admin.notifications'),
			'adminOvertimeSettings' => $g->linkToRoute('arbeitszeitcheck.admin.overtimeSettings'),
			'adminOvertimePayouts' => $g->linkToRoute('arbeitszeitcheck.overtime_payout.index'),
			'adminOvertimePayoutAudit' => $g->linkToRoute('arbeitszeitcheck.overtime_payout.auditIndex'),
			'adminUsers' => $g->linkToRoute('arbeitszeitcheck.admin.users'),
			'adminWorkingTimeModels' => $g->linkToRoute('arbeitszeitcheck.admin.workingTimeModels'),
			'adminTariffRules' => $g->linkToRoute('arbeitszeitcheck.admin.tariffRuleSets'),
			'adminHolidays' => $g->linkToRoute('arbeitszeitcheck.admin.holidays'),
			'adminTeams' => $g->linkToRoute('arbeitszeitcheck.admin.teams'),
			'adminVacationLayers' => $g->linkToRoute('arbeitszeitcheck.admin.vacationLayers'),
			'adminAuditLog' => $g->linkToRoute('arbeitszeitcheck.admin.auditLog'),
			'adminSettings' => $g->linkToRoute('arbeitszeitcheck.admin.settings'),
			'adminSupportUs' => $g->linkToRoute('arbeitszeitcheck.admin.supportUs'),
			'gdprExport' => $g->linkToRoute('arbeitszeitcheck.gdpr.export'),
			'gdprDelete' => $g->linkToRoute('arbeitszeitcheck.gdpr.delete'),
			'home' => $g->linkToDefaultPageUrl(),
		];
	}

	protected function resolveRoleLabel(string $userId): string
	{
		if ($this->permissionService->isAdmin($userId)) {
			return $this->l10n->t('Administrator');
		}
		if ($this->permissionService->canAccessManagerDashboard($userId)) {
			return $this->l10n->t('Manager');
		}

		return $this->l10n->t('Employee');
	}

	private function roleSlugFromLabel(string $roleLabel): string
	{
		$admin = $this->l10n->t('Administrator');
		$manager = $this->l10n->t('Manager');
		if ($roleLabel === $admin) {
			return 'administrator';
		}
		if ($roleLabel === $manager) {
			return 'manager';
		}

		return 'employee';
	}

	private function getShellUserId(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}

	/**
	 * @param array<string, mixed> $navFlags
	 * @return array<string, mixed>
	 */
	protected function buildTimeEntriesShellParams(string $mode, array $navFlags = []): array
	{
		$titles = [
			'create' => [
				$this->l10n->t('Record working time'),
				$this->l10n->t('Date, working hours, optional breaks, and a short note. Compliance is checked while you type.'),
			],
			'edit' => [
				$this->l10n->t('Edit time entry'),
				$this->l10n->t('Change date, times, breaks, or note for this entry. Only entries you are allowed to edit appear here.'),
			],
			'list' => [
				$this->l10n->t('Time Entries'),
				$this->l10n->t('Manage your working time records'),
			],
		];
		[$title, $help] = $titles[$mode] ?? $titles['list'];

		return $this->buildShellParams('time-entries', $title, $help, $navFlags);
	}

	/**
	 * @param array<string, mixed> $navFlags
	 * @return array<string, mixed>
	 */
	protected function buildAbsencesShellParams(string $mode, array $navFlags = []): array
	{
		$titles = [
			'create' => [
				$this->l10n->t('Request time off'),
				$this->l10n->t('Fill in the type, dates, and optional reason and substitute.'),
			],
			'edit' => [
				$this->l10n->t('Edit absence request'),
				$this->l10n->t('Change details before your manager approves the request.'),
			],
			'view' => [
				$this->l10n->t('Absence Details'),
				$this->l10n->t('Review status, history, and available actions for this absence.'),
			],
			'list' => [
				$this->l10n->t('Absences'),
				$this->l10n->t('View and manage your absence requests'),
			],
		];
		[$title, $help] = $titles[$mode] ?? $titles['list'];

		return $this->buildShellParams('absences', $title, $help, $navFlags);
	}
}
