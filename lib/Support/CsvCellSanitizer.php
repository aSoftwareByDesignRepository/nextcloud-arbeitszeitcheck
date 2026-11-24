<?php

declare(strict_types=1);

/**
 * Spreadsheet formula-injection guard for every CSV cell this app emits.
 * A leading = + - @ (after whitespace) or control character would make
 * Excel/LibreOffice evaluate the cell — prefixing a single quote renders
 * it as inert text. Shared by all export surfaces.
 */

namespace OCA\ArbeitszeitCheck\Support;

final class CsvCellSanitizer
{
	public static function sanitize(string $value): string
	{
		if ($value === '') {
			return $value;
		}
		if (preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1) {
			return "'" . $value;
		}
		$first = $value[0];
		if ($first === "\t" || $first === "\r" || $first === "\n") {
			return "'" . $value;
		}
		return $value;
	}
}
