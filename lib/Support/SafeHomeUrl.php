<?php

declare(strict_types=1);

/**
 * Resolve a safe “go home” URL for access-denied pages.
 *
 * NC35 (and some PHPUnit boots) can throw from linkToDefaultPageUrl() when the
 * default navigation entry lacks href — that must never turn a 403 into a 500.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCP\IURLGenerator;

final class SafeHomeUrl
{
	public static function resolve(IURLGenerator $urlGenerator, string $fallback = '/'): string
	{
		try {
			$url = $urlGenerator->linkToDefaultPageUrl();
			if (is_string($url) && trim($url) !== '') {
				return $url;
			}
		} catch (\Throwable) {
			// Fall through to absolute web root fallback.
		}

		try {
			$absolute = $urlGenerator->getAbsoluteURL('/');
			if (is_string($absolute) && trim($absolute) !== '') {
				return $absolute;
			}
		} catch (\Throwable) {
			// Last resort relative root.
		}

		return $fallback;
	}
}
