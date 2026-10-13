<?php

declare(strict_types=1);

/**
 * Distinct-day counter for work-location reporting (GH #43).
 *
 * A calendar day counts toward a location when at least one entry starting
 * that day carries it. Mixed days (e.g. office morning, home afternoon)
 * appear under both — documented in the report UI. The day boundary is the
 * organisation timezone ({@see \OCA\ArbeitszeitCheck\Service\TimeZoneService}),
 * matching the report period boundaries fed into the same reports.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Db\TimeEntry;

final class WorkLocationDayCounter
{
	private function __construct()
	{
	}

	/**
	 * @param iterable<TimeEntry> $entries
	 * @return array{home: int, external: int} distinct calendar-day counts
	 */
	public static function countDays(iterable $entries, \DateTimeZone $tz): array
	{
		$days = [
			WorkLocation::HOME => [],
			WorkLocation::EXTERNAL => [],
		];

		foreach ($entries as $entry) {
			$location = $entry->getWorkLocation();
			if ($location !== WorkLocation::HOME && $location !== WorkLocation::EXTERNAL) {
				continue;
			}
			$start = $entry->getStartTime();
			if ($start === null) {
				continue;
			}
			$day = (clone $start)->setTimezone($tz)->format('Y-m-d');
			$days[$location][$day] = true;
		}

		return [
			'home' => count($days[WorkLocation::HOME]),
			'external' => count($days[WorkLocation::EXTERNAL]),
		];
	}
}
