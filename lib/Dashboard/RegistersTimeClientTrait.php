<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Dashboard;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Support\TimeClientBootstrap;
use OCP\Util;

/**
 * Dashboard widgets render outside app templates; styles (and optional time
 * client scripts) must be registered in {@see load()} themselves.
 *
 * API-only widgets should call {@see registerDeskletStylesForWidget()} only.
 * Widgets that mount desklet JS must call {@see registerTimeClientForWidget()}
 * which uses regular Util::addScript (never addInitScript) so l10n/*.js does
 * not execute before window.OC on `/apps/dashboard`.
 */
trait RegistersTimeClientTrait {
	private function registerTimeClientForWidget(TimeClientBootstrap $timeClientBootstrap, bool $withUtils = true): void {
		$timeClientBootstrap->register($withUtils);
	}

	private function registerDeskletStylesForWidget(): void {
		Util::addStyle(Application::APP_ID, 'desklet-nextcloud');
	}
}
