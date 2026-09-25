<?php

declare(strict_types=1);

/**
 * Minute-resolution premium classifier (hours + %, no currency).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

/**
 * Pure classifier: work intervals → premium buckets. Does not touch Saldo.
 */
final class PremiumSurchargeClassifier
{
	private const DAY_KEYS = [
		1 => 'mon',
		2 => 'tue',
		3 => 'wed',
		4 => 'thu',
		5 => 'fri',
		6 => 'sat',
		7 => 'sun',
	];

	/**
	 * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $workIntervals
	 * @param callable(\DateTimeImmutable): bool $isHolidayFullDay
	 * @param callable(\DateTimeImmutable): float $dailySollHours  net daily target for that calendar day
	 * @return array{
	 *   stacking: string,
	 *   buckets: list<array{id: string, label: string, hours: float, rate: float, valued_hours: float}>,
	 *   total_classified_hours: float,
	 *   total_valued_hours: float
	 * }
	 */
	public function classify(
		array $workIntervals,
		PremiumPolicy $policy,
		callable $isHolidayFullDay,
		callable $dailySollHours,
	): array {
		$categories = $policy->getEnabledCategories();
		$bucketSeconds = [];
		$meta = [];
		foreach ($categories as $cat) {
			$id = (string)$cat['id'];
			$bucketSeconds[$id] = 0;
			$meta[$id] = [
				'label' => (string)$cat['label'],
				'rate' => (float)$cat['rate'],
			];
		}

		$sorted = $workIntervals;
		usort($sorted, static function (array $a, array $b): int {
			return $a[0] <=> $b[0];
		});

		$dayProgress = [];
		// Wall-clock minutes that matched ≥1 category (never double-count overlaps).
		$uniqueClassifiedSeconds = 0;
		$stacking = $policy->getStacking();
		foreach ($sorted as [$start, $end]) {
			$t = $start->getTimestamp();
			$endTs = $end->getTimestamp();
			// Align to whole minutes for stable classification.
			$t -= ($t % 60);
			$endTs -= ($endTs % 60);
			for (; $t < $endTs; $t += 60) {
				$moment = (new \DateTimeImmutable('@' . $t))->setTimezone($start->getTimezone());
				$dayKey = $moment->format('Y-m-d');
				$progressBefore = $dayProgress[$dayKey] ?? 0;
				$dayProgress[$dayKey] = $progressBefore + 60;

				$soll = max(0.0, (float)$dailySollHours($moment->setTime(0, 0, 0)));
				$sollSeconds = (int)round($soll * 3600);
				$isOt = $progressBefore >= $sollSeconds;

				$isHoliday = $isHolidayFullDay($moment->setTime(0, 0, 0));
				$matches = $this->matchingCategories(
					$moment,
					$categories,
					$isHoliday,
					$isOt,
					$policy->getHolidayPolicy()
				);
				if ($matches === []) {
					continue;
				}

				$uniqueClassifiedSeconds += 60;

				if ($stacking === PremiumPolicy::STACKING_MAX_SINGLE) {
					$best = null;
					$bestRate = -1.0;
					foreach ($matches as $cat) {
						$rate = (float)$cat['rate'];
						if ($rate > $bestRate) {
							$bestRate = $rate;
							$best = $cat;
						}
					}
					if ($best !== null) {
						$bucketSeconds[(string)$best['id']] += 60;
					}
				} else {
					// additive_rates + tagged_multi: attribute the minute to every match.
					// Valued totals differ below (additive sums rates; tagged does not).
					foreach ($matches as $cat) {
						$bucketSeconds[(string)$cat['id']] += 60;
					}
				}
			}
		}

		$buckets = [];
		$totalValued = 0.0;
		foreach ($bucketSeconds as $id => $seconds) {
			if ($seconds <= 0) {
				continue;
			}
			$hours = round($seconds / 3600.0, 4);
			$rate = (float)$meta[$id]['rate'];
			$valued = round($hours * $rate, 4);
			$buckets[] = [
				'id' => $id,
				'label' => (string)$meta[$id]['label'],
				'hours' => $hours,
				'rate' => $rate,
				// Per-bucket valued_hours always shown; stacked money total below.
				'valued_hours' => $valued,
			];
			if ($stacking === PremiumPolicy::STACKING_ADDITIVE
				|| $stacking === PremiumPolicy::STACKING_MAX_SINGLE) {
				$totalValued += $valued;
			}
			// tagged_multi: report tags without summing money (NN / §9.3).
		}

		usort($buckets, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));

		return [
			'stacking' => $stacking,
			'buckets' => $buckets,
			'total_classified_hours' => round($uniqueClassifiedSeconds / 3600.0, 4),
			'total_valued_hours' => round($totalValued, 4),
		];
	}

	/**
	 * @param list<array<string, mixed>> $categories
	 * @return list<array<string, mixed>>
	 */
	private function matchingCategories(
		\DateTimeImmutable $moment,
		array $categories,
		bool $isHoliday,
		bool $isOvertimeMinute,
		string $holidayPolicy,
	): array {
		$dayKey = self::DAY_KEYS[(int)$moment->format('N')] ?? 'mon';
		$minuteOfDay = ((int)$moment->format('G')) * 60 + (int)$moment->format('i');
		$out = [];
		foreach ($categories as $cat) {
			$applies = (string)$cat['applies_to'];
			if ($applies === PremiumPolicy::APPLIES_OVERTIME) {
				if ($isOvertimeMinute) {
					$out[] = $cat;
				}
				continue;
			}
			if ($applies === PremiumPolicy::APPLIES_WEEKDAY) {
				$days = (array)($cat['weekdays'] ?? []);
				$matchDay = in_array($dayKey, $days, true);
				if (!$matchDay && $holidayPolicy === 'treat_as_sunday' && $isHoliday && in_array('sun', $days, true)) {
					$matchDay = true;
				}
				if ($matchDay) {
					$out[] = $cat;
				}
				continue;
			}
			if ($applies === PremiumPolicy::APPLIES_TIME_WINDOW) {
				$ws = PremiumPolicy::parseHm((string)($cat['window_start'] ?? ''));
				$we = PremiumPolicy::parseHm((string)($cat['window_end'] ?? ''));
				if ($ws === null || $we === null) {
					continue;
				}
				if ($this->minuteInWindow($minuteOfDay, $ws, $we)) {
					$out[] = $cat;
				}
			}
		}

		return $out;
	}

	private function minuteInWindow(int $minuteOfDay, int $start, int $end): bool
	{
		if ($start === $end) {
			return true;
		}
		if ($start < $end) {
			return $minuteOfDay >= $start && $minuteOfDay < $end;
		}
		// Wraps midnight, e.g. 22:00–05:00
		return $minuteOfDay >= $start || $minuteOfDay < $end;
	}
}
