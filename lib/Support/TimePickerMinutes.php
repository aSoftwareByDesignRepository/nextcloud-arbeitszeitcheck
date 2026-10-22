<?php

declare(strict_types=1);

/**
 * Minute options for time pickers (5-minute steps + preserve odd values on edit).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Constants;

final class TimePickerMinutes
{
	/**
	 * Zero-padded minute values for &lt;select&gt; options.
	 *
	 * Always includes every step (default 5). When $selectedMinute is a valid
	 * 00–59 value not on the step grid (e.g. "07" from an older entry), it is
	 * inserted so the current value remains selectable without forcing a change.
	 *
	 * @return list<string>
	 */
	public static function options(?string $selectedMinute = null, int $step = Constants::TIME_PICKER_MINUTE_STEP): array
	{
		$step = max(1, min(30, $step));
		$out = [];
		for ($m = 0; $m < 60; $m += $step) {
			$out[] = sprintf('%02d', $m);
		}

		if ($selectedMinute === null || $selectedMinute === '') {
			return $out;
		}

		if (!preg_match('/^\d{1,2}$/', $selectedMinute)) {
			return $out;
		}
		$n = (int)$selectedMinute;
		if ($n < 0 || $n > 59) {
			return $out;
		}
		$padded = sprintf('%02d', $n);
		if (!in_array($padded, $out, true)) {
			$out[] = $padded;
			sort($out, SORT_STRING);
		}

		return $out;
	}
}
