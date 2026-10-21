<?php

declare(strict_types=1);

/**
 * Calendar-day anchor for a half-day vacation deep link.
 *
 * The absences list no longer offers this as a button. Weekends are skipped so
 * a half-vacation URL does not land on a day that always fails
 * VAC_HALF_DAY_NON_WORKING. Public holidays are still validated on submit.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Util;

final class HalfDayVacationShortcut
{
	/**
	 * Prefer today when it is Mon–Fri; otherwise the next Monday.
	 */
	public static function anchorDate(\DateTimeImmutable $today): \DateTimeImmutable
	{
		$d = $today->setTime(0, 0, 0);
		$n = (int)$d->format('N');
		if ($n >= 6) {
			return $d->modify('next monday');
		}

		return $d;
	}

	public static function isSameCalendarDay(\DateTimeImmutable $a, \DateTimeImmutable $b): bool
	{
		return $a->format('Y-m-d') === $b->setTime(0, 0, 0)->format('Y-m-d');
	}
}
