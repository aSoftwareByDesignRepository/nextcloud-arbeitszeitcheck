<?php

declare(strict_types=1);

/**
 * Display format for duration hours (decimal vs hours+minutes).
 *
 * Legacy default is decimal ("5.5") so existing screens stay unchanged until
 * the user opts into hours+minutes ("5h 30").
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class HoursDisplay
{
	public const MODE_DECIMAL = 'decimal';

	public const MODE_HOURS_MINUTES = 'hours_minutes';

	/** User setting key (at_user_settings). */
	public const USER_SETTING_KEY = 'hours_display';

	/**
	 * @return list<string>
	 */
	public static function modes(): array
	{
		return [self::MODE_DECIMAL, self::MODE_HOURS_MINUTES];
	}

	public static function normalize(?string $mode): string
	{
		$mode = is_string($mode) ? trim($mode) : '';
		if ($mode === self::MODE_HOURS_MINUTES) {
			return self::MODE_HOURS_MINUTES;
		}

		return self::MODE_DECIMAL;
	}

	/**
	 * Format a non-negative hour quantity for UI display.
	 */
	public static function format(float $hours, ?string $mode = null, string $decimalSeparator = '.'): string
	{
		$mode = self::normalize($mode);
		$safe = is_finite($hours) ? max(0.0, $hours) : 0.0;

		if ($mode === self::MODE_HOURS_MINUTES) {
			$totalMinutes = (int)round($safe * 60.0);
			$h = intdiv($totalMinutes, 60);
			$m = $totalMinutes % 60;
			if ($m === 0) {
				return $h . 'h';
			}

			return $h . 'h ' . sprintf('%02d', $m);
		}

		$rounded = round($safe, 2);
		if (abs($rounded - round($rounded)) < 0.001) {
			return (string)(int)round($rounded);
		}
		$formatted = number_format($rounded, 2, '.', '');
		$formatted = rtrim(rtrim($formatted, '0'), '.');
		if ($decimalSeparator !== '.') {
			$formatted = str_replace('.', $decimalSeparator, $formatted);
		}

		return $formatted;
	}
}
