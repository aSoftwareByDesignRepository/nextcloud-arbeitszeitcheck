<?php

declare(strict_types=1);

/**
 * Canonical people-search query reading for admin/manager/license/kiosk APIs.
 *
 * Kraft 1.7.10: teams bulk search sent `q=` while getUsersForPicker only read
 * `search=` → empty suggestions with HTTP 200. Prefer `search`, accept `q`.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCP\IRequest;

final class PeopleSearchQuery
{
	/**
	 * Read the search term from a request.
	 *
	 * Order: explicit $fromRoute (AppFramework method arg) → `search` → `q`.
	 */
	public static function fromRequest(IRequest $request, ?string $fromRoute = null): string
	{
		if ($fromRoute !== null) {
			$trimmed = trim($fromRoute);
			if ($trimmed !== '') {
				return $trimmed;
			}
		}

		$search = trim((string)($request->getParam('search') ?? ''));
		if ($search !== '') {
			return $search;
		}

		return trim((string)($request->getParam('q') ?? ''));
	}

	/**
	 * Clamp length for directory searches (DoS / DB safety).
	 */
	public static function clamp(string $term, int $maxLength): string
	{
		$maxLength = max(1, $maxLength);
		if (mb_strlen($term) <= $maxLength) {
			return $term;
		}
		return mb_substr($term, 0, $maxLength);
	}
}
