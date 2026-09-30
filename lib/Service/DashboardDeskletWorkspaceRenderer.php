<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;

/**
 * Renders the dashboard desklet workspace partial via the legacy Nextcloud template API.
 *
 * This widget runs inside DashboardController::index(), i.e. mid-request before
 * the host page renders. Any truthy renderAs value would wrap the partial in
 * OC\TemplateLayout, whose constructor resolves Util::getScripts() and marks
 * already-registered apps as visited — leaking their scripts ahead of
 * core/js/main in the final <head> (dead Dashboard, "OC is not defined").
 * RENDER_AS_BLANK is the empty string: fetchPage() then returns only the
 * partial markup and never touches the page asset pipeline.
 */
class DashboardDeskletWorkspaceRenderer
{
	/**
	 * @param array<string, mixed> $config
	 */
	public function render(array $config, IL10N $l10n): string
	{
		$template = new \OCP\Template(
			Application::APP_ID,
			'partials/dashboard-desklet-workspace',
			TemplateResponse::RENDER_AS_BLANK,
		);
		$template->assign('deskletConfig', $config);
		$template->assign('l', $l10n);

		return $template->fetchPage();
	}
}
