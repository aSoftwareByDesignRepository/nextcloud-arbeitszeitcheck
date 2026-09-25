<?php

declare(strict_types=1);

/**
 * AZG §11-style break split validation (and sum-only fallback for DE/CH).
 *
 * Austrian law (AZG §11 Abs. 1) allows, without Betriebsvereinbarung:
 *  - one continuous break of the required length, or
 *  - two portions of at least 15 minutes each, or
 *  - three portions of at least 10 minutes each.
 * Other splits need a works agreement — those are NOT accepted here (fail closed).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class BreakSplitValidator
{
	/**
	 * @param list<int> $portionMinutes Non-overlapping break lengths in whole minutes
	 * @param list<list<int>>|null $allowedPatterns null = sum-only (DE/CH)
	 */
	public static function meetsRequirement(
		array $portionMinutes,
		int $requiredMinutes,
		?array $allowedPatterns,
	): bool {
		if ($requiredMinutes <= 0) {
			return true;
		}

		$total = 0;
		foreach ($portionMinutes as $minutes) {
			$total += max(0, (int)$minutes);
		}
		if ($total < $requiredMinutes) {
			return false;
		}

		// Sum-only regimes (ArbZG / ArG in v1): total minutes are enough.
		if ($allowedPatterns === null) {
			return true;
		}

		// Continuous break covering the requirement.
		foreach ($portionMinutes as $minutes) {
			if ((int)$minutes >= $requiredMinutes) {
				return true;
			}
		}

		foreach ($allowedPatterns as $pattern) {
			if (!is_array($pattern) || $pattern === []) {
				continue;
			}
			if (self::portionsMatchPattern($portionMinutes, $pattern)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param list<int> $portionMinutes
	 * @param list<int> $patternMins
	 */
	private static function portionsMatchPattern(array $portionMinutes, array $patternMins): bool
	{
		if (count($portionMinutes) !== count($patternMins)) {
			return false;
		}

		$sortedPortions = array_map(static fn ($m): int => (int)$m, $portionMinutes);
		$sortedPattern = array_map(static fn ($m): int => (int)$m, $patternMins);
		rsort($sortedPortions, SORT_NUMERIC);
		rsort($sortedPattern, SORT_NUMERIC);

		foreach ($sortedPattern as $i => $need) {
			if ($sortedPortions[$i] < $need) {
				return false;
			}
		}

		return true;
	}
}
