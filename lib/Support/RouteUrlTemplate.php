<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCP\IURLGenerator;

/**
 * Builds client-consumed route URL templates with a literal sentinel segment
 * (e.g. /apps/<app>/api/time-entries/__ID__/request-correction).
 *
 * linkToRoute() validates pinned \d+ requirements at generation time and would
 * reject the sentinel, so the URL is generated with a numeric placeholder
 * segment that is rewritten afterwards. linkTo() must NOT be used for route
 * templates: it only prefixes /apps/<app>/ for files that physically exist in
 * the app directory and silently returns WEBROOT/<app>/<file> otherwise.
 */
final class RouteUrlTemplate {
	/**
	 * @param string $route route name (e.g. arbeitszeitcheck.time_entry.apiUpdate)
	 * @param string $param pinned route parameter name (e.g. id, absenceId)
	 * @param string $sentinel literal segment the client replaces (e.g. __ID__)
	 * @param string|int $placeholder generation-time value satisfying the
	 *        route's requirement — numeric for `\d+` params, a valid enum
	 *        member for constrained params (e.g. `DE` for DE|AT|CH|custom)
	 */
	public static function for(IURLGenerator $urlGenerator, string $route, string $param = 'id', string $sentinel = '__ID__', string|int $placeholder = 0): string {
		$url = $urlGenerator->linkToRoute($route, [$param => $placeholder]);
		$template = preg_replace('#/' . preg_quote((string)$placeholder, '#') . '(?=/|$)#', '/' . $sentinel, $url, 1, $count);
		if ($template === null || $count !== 1) {
			throw new \RuntimeException('Route URL template generation failed for ' . $route);
		}
		return $template;
	}
}
