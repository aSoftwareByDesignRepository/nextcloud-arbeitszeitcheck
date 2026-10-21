<?php

declare(strict_types=1);

/**
 * Allowlist for the absence create-form query prefill.
 *
 * The "report sick today" link only sets type=sick_leave. The same gate
 * accepts any creatable type so a bookmark cannot inject a value the
 * dropdown itself would refuse. Unknown, inactive, and non-string values
 * prefill nothing.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Util;

use OCA\ArbeitszeitCheck\Support\AbsenceTypeCatalog;

final class AbsenceCreateQueryPrefill
{
	public static function type(mixed $raw): string
	{
		if (!is_string($raw)) {
			return '';
		}
		$type = trim($raw);
		if ($type === '') {
			return '';
		}

		return AbsenceTypeCatalog::isCreatable($type) ? $type : '';
	}
}
