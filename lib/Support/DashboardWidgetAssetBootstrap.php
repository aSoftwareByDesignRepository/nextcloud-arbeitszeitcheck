<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCP\Util;

/**
 * Idempotent script/style registration for the NC home dashboard desklet.
 *
 * Nextcloud may inject widget assets more than once when several desklets load;
 * PHP dedupe is per-request only, so companion JS modules must also be safe to
 * re-execute. This class avoids duplicate Util::addScript calls in one request.
 */
final class DashboardWidgetAssetBootstrap {
	private static bool $deskletAssetsRegistered = false;

	public static function registerDeskletAssets(): void {
		if (self::$deskletAssetsRegistered) {
			return;
		}
		self::$deskletAssetsRegistered = true;

		// l10n-boot defines __azcBootL10n and must precede the l10n/<lang>.js the
		// first non-l10n addScript() injects; its own path contains 'l10n' so it
		// triggers no injection itself.
		Util::addScript(Application::APP_ID, 'common/l10n-boot');
		Util::addScript(Application::APP_ID, 'common/catalog');
		Util::addScript(Application::APP_ID, 'common/api');
		Util::addScript(Application::APP_ID, 'common/desklet-actions');
		Util::addScript(Application::APP_ID, 'dashboard-widgets');
		Util::addStyle(Application::APP_ID, 'dashboard-widgets');
	}
}
