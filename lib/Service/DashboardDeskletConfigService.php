<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Util\TemplateL10n;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * JSON config for the dashboard desklet workspace (NC home + optional app embed).
 */
class DashboardDeskletConfigService
{
	public function __construct(
		private readonly IURLGenerator $urlGenerator,
		private readonly PermissionService $permissionService,
		private readonly IL10N $l10n,
		private readonly IAppManager $appManager,
		private readonly ProjectCheckIntegrationService $projectCheckIntegration,
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function buildForUser(string $userId): array
	{
		$this->ensureAppRoutesRegistered();

		$isManager = $this->permissionService->canAccessManagerDashboard($userId);
		$isAdmin = $this->permissionService->isAdmin($userId);
		$linkingEnabled = $this->projectCheckIntegration->isLinkingEnabledForUser($userId);
		$projectCheckAvailable = $this->projectCheckIntegration->isProjectCheckAvailable();
		$projects = $linkingEnabled
			? $this->projectCheckIntegration->getAvailableProjects($userId)
			: [];

		return [
			'employeeDataUrl' => $this->deskletRouteUrl('arbeitszeitcheck.dashboard_widget.employeeData', '/api/dashboard-widget/employee'),
			'managerDataUrl' => $this->deskletRouteUrl('arbeitszeitcheck.dashboard_widget.managerData', '/api/dashboard-widget/manager'),
			'adminDataUrl' => $this->deskletRouteUrl('arbeitszeitcheck.dashboard_widget.adminData', '/api/dashboard-widget/admin'),
			'clockInUrl' => $this->deskletRouteUrl('arbeitszeitcheck.dashboard_widget.clockIn', '/api/dashboard-widget/clock/in'),
			'startBreakUrl' => $this->deskletRouteUrl('arbeitszeitcheck.dashboard_widget.startBreak', '/api/dashboard-widget/break/start'),
			'endBreakUrl' => $this->deskletRouteUrl('arbeitszeitcheck.dashboard_widget.endBreak', '/api/dashboard-widget/break/end'),
			'clockOutUrl' => $this->deskletRouteUrl('arbeitszeitcheck.dashboard_widget.clockOut', '/api/dashboard-widget/clock/out'),
			'dashboardUrl' => $this->deskletRouteUrl('arbeitszeitcheck.page.dashboard', '/dashboard'),
			'timeEntriesUrl' => $this->deskletRouteUrl('arbeitszeitcheck.page.timeEntries', '/time-entries'),
			'isManager' => $isManager,
			'isAdmin' => $isAdmin,
			'projectCheck' => [
				'available' => $projectCheckAvailable,
				'linkingEnabled' => $linkingEnabled,
				'projects' => $projects,
			],
			'l10n' => $this->buildL10n(),
		];
	}

	/**
	 * NC home dashboard loads widget assets before app routes are registered;
	 * linkToRoute() then returns "" and the desklet fetch fails.
	 */
	private function ensureAppRoutesRegistered(): void
	{
		if (!$this->appManager->isAppLoaded(Application::APP_ID)) {
			$this->appManager->loadApp(Application::APP_ID);
		}
		\OCP\Server::get(\OCP\Route\IRouter::class)->loadRoutes(Application::APP_ID);
	}

	/**
	 * Resolve a desklet API/page URL. Falls back to a fixed app path when the
	 * router has not registered routes yet (NC home dashboard lazy widget load).
	 */
	private function deskletRouteUrl(string $routeName, string $appRelativePath): string
	{
		$url = $this->urlGenerator->linkToRoute($routeName);
		if ($url !== '') {
			return $url;
		}

		\OCP\Log\logger(Application::APP_ID)->warning('Desklet route URL fallback', [
			'route' => $routeName,
			'path' => $appRelativePath,
		]);

		return $this->appWebPathPrefix() . $appRelativePath;
	}

	private function appWebPathPrefix(): string
	{
		$webPath = $this->appManager->getAppWebPath(Application::APP_ID);
		if (!is_string($webPath) || $webPath === '') {
			$webPath = '/apps/' . Application::APP_ID;
		}
		if (!str_starts_with($webPath, '/')) {
			$webPath = '/' . $webPath;
		}

		return '/index.php' . rtrim($webPath, '/');
	}

	/**
	 * @return array<string, string>
	 */
	private function buildL10n(): array
	{
		$l = $this->l10n;

		return [
			'working' => TemplateL10n::translate($l, 'Working'),
			'onBreak' => TemplateL10n::translate($l, 'On Break'),
			'paused' => TemplateL10n::translate($l, 'Paused'),
			'clockedOut' => TemplateL10n::translate($l, 'Clocked Out'),
			'statusLine' => TemplateL10n::translate($l, 'Status: %1$s'),
			'sessionSince' => TemplateL10n::translate($l, 'Since %1$s'),
			'workedToday' => TemplateL10n::translate($l, 'Worked today'),
			'sessionDuration' => TemplateL10n::translate($l, 'Session'),
			'clockIn' => TemplateL10n::translate($l, 'Clock In'),
			'startBreak' => TemplateL10n::translate($l, 'Start Break'),
			'endBreak' => TemplateL10n::translate($l, 'End Break'),
			'clockOut' => TemplateL10n::translate($l, 'Clock Out'),
			'openDashboard' => TemplateL10n::translate($l, 'Open full dashboard'),
			'openTimeEntries' => TemplateL10n::translate($l, 'Open time entries'),
			'teamOverview' => TemplateL10n::translate($l, 'Team overview'),
			'companyOverview' => TemplateL10n::translate($l, 'Company overview'),
			'lastUpdated' => TemplateL10n::translate($l, 'Last updated: %1$s'),
			'actionFailed' => TemplateL10n::translate($l, 'Action failed'),
			'actionDone' => TemplateL10n::translate($l, '%1$s successful'),
			'networkError' => TemplateL10n::translate($l, 'Could not load status. Please check your connection.'),
			'statusLoadError' => TemplateL10n::translate($l, 'Could not load status. Try again.'),
			'tryAgain' => TemplateL10n::translate($l, 'Try again'),
			'loadingStatus' => TemplateL10n::translate($l, 'Loading...'),
			'sessionExpired' => TemplateL10n::translate($l, 'Your session has expired. Please refresh the page and try again.'),
			'noTeamMembers' => TemplateL10n::translate($l, 'No team members found.'),
			'noUsersFound' => TemplateL10n::translate($l, 'No users found.'),
			'peopleRow' => TemplateL10n::translate($l, '%1$s: %2$s (%3$s h)'),
			'stampingDisabledTitle' => TemplateL10n::translate($l, 'Clock in/out is turned off for you'),
			'stampingDisabledBodyManual' => TemplateL10n::translate($l, 'Add your hours under Time entries in the app.'),
			'stampingDisabledBody' => TemplateL10n::translate($l, 'Contact HR if you need to record time.'),
			'stampingDisabledPausedBody' => TemplateL10n::translate($l, 'Finish the paused session on the dashboard, or contact your administrator.'),
			'deskletTitle' => TemplateL10n::translate($l, 'Quick time tracking'),
			'deskletLead' => TemplateL10n::translate($l, 'Clock in, take a break, or clock out from here.'),
			'projectLabel' => TemplateL10n::translate($l, 'Project'),
			'projectHelp' => TemplateL10n::translate($l, 'Optional. Link these hours to a ProjectCheck project, or leave “No project”.'),
			'projectNone' => TemplateL10n::translate($l, 'No project'),
			'projectLinkingOffTitle' => TemplateL10n::translate($l, 'ProjectCheck linking is turned off'),
			'projectLinkingOffBody' => TemplateL10n::translate($l, 'An administrator must enable the ProjectCheck connection in Global settings before you can link hours to a project.'),
			'dailyMaximumTitle' => TemplateL10n::translate($l, 'Daily maximum reached'),
			'dailyMaximumBody' => TemplateL10n::translate($l, 'You already reached today’s working-time limit. Clock-in is available again tomorrow.'),
		];
	}
}
