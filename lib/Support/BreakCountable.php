<?php

declare(strict_types=1);

/**
 * Countable break floor for DACH profiles (DE/CH: 15 min, AT: 10 min).
 *
 * Write paths and duration math must use the labour-law profile floor so
 * AZG §11 3×10 portions are kept and counted. Callers that omit the
 * minutes fall back to the historic German 15-minute floor (never laxer).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Db\TimeEntry;

final class BreakCountable
{
	public const DEFAULT_MIN_MINUTES = 15;

	public static function minMinutes(?int $minBreakMinutes = null): int
	{
		if ($minBreakMinutes === null || $minBreakMinutes <= 0) {
			return self::DEFAULT_MIN_MINUTES;
		}

		return $minBreakMinutes;
	}

	public static function minSeconds(?int $minBreakMinutes = null): int
	{
		return self::minMinutes($minBreakMinutes) * 60;
	}

	public static function stampEntry(TimeEntry $entry, LaborLawProfile $profile): void
	{
		$entry->setCountableMinBreakMinutes($profile->minBreakMinutes);
	}

	public static function stampEntryForUser(TimeEntry $entry, LaborLawProfileFactory $factory): void
	{
		$userId = $entry->getUserId();
		self::stampEntry($entry, $factory->getProfile(is_string($userId) ? $userId : null));
	}
}
