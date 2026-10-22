<?php

declare(strict_types=1);

/**
 * Whether a working-time model permits Sunday work without compliance warnings.
 *
 * Legacy-safe: absent / false keeps ArbZG §9 Sunday warnings. Opt-in via
 * break_rules.allow_sunday_work or weekday_schedule.days.sun.work.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class SundayWorkPolicy
{
	public const KEY = 'allow_sunday_work';

	/**
	 * @param array<string, mixed>|null $breakRules
	 */
	public static function isAllowed(?array $breakRules): bool
	{
		if ($breakRules === null || $breakRules === []) {
			return false;
		}
		if (!empty($breakRules[self::KEY])) {
			return true;
		}
		$schedule = WeekdaySchedule::tryFromBreakRules($breakRules);
		if ($schedule !== null && $schedule->isWorkDay('sun')) {
			return true;
		}

		return false;
	}

	/**
	 * @param array<string, mixed>|null $breakRules
	 * @return array<string, mixed>
	 */
	public static function mergeIntoBreakRules(?array $breakRules, bool $allowed): array
	{
		$base = is_array($breakRules) ? $breakRules : [];
		if ($allowed) {
			$base[self::KEY] = true;
		} else {
			unset($base[self::KEY]);
		}

		return $base;
	}
}
