<?php

declare(strict_types=1);

/**
 * Org premium (Zuschlag) policy — hours + % only, no currency.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

/**
 * Immutable validated premium policy (BANSS Phase D).
 */
final class PremiumPolicy
{
	public const VERSION = 1;

	public const STACKING_MAX_SINGLE = 'max_single_rate';
	public const STACKING_ADDITIVE = 'additive_rates';
	public const STACKING_TAGGED = 'tagged_multi';

	public const APPLIES_WEEKDAY = 'weekday';
	public const APPLIES_TIME_WINDOW = 'time_window';
	public const APPLIES_OVERTIME = 'hours_above_daily_or_weekly_threshold';

	/**
	 * @param list<array{
	 *   id: string,
	 *   label: string,
	 *   rate: float,
	 *   enabled: bool,
	 *   applies_to: string,
	 *   weekdays?: list<string>,
	 *   window_start?: string,
	 *   window_end?: string,
	 *   threshold_ref?: string
	 * }> $categories
	 */
	private function __construct(
		private readonly string $stacking,
		private readonly array $categories,
		private readonly string $holidayPolicy,
		private readonly int $version,
	) {
	}

	/**
	 * Strict-bool parse — a "false"/"0" string must never enable a category
	 * (fail closed = disabled).
	 */
	private static function truthyFlag(mixed $value): bool
	{
		return $value === true || $value === 1 || $value === '1'
			|| (is_string($value) && strtolower(trim($value)) === 'true');
	}

	/**
	 * @param array<string, mixed> $raw
	 * @return list<string> error codes
	 */
	public static function validate(array $raw): array
	{
		$errors = [];
		$stacking = (string)($raw['stacking'] ?? self::STACKING_MAX_SINGLE);
		if (!in_array($stacking, [self::STACKING_MAX_SINGLE, self::STACKING_ADDITIVE, self::STACKING_TAGGED], true)) {
			$errors[] = 'PREMIUM_STACKING_INVALID';
		}
		$cats = $raw['categories'] ?? null;
		if (!is_array($cats)) {
			return array_merge($errors, ['PREMIUM_CATEGORIES_MISSING']);
		}
		$ids = [];
		foreach ($cats as $cat) {
			if (!is_array($cat)) {
				$errors[] = 'PREMIUM_CATEGORY_INVALID';
				continue;
			}
			$id = trim((string)($cat['id'] ?? ''));
			if ($id === '' || isset($ids[$id])) {
				$errors[] = 'PREMIUM_CATEGORY_ID';
				continue;
			}
			$ids[$id] = true;
			$rate = (float)($cat['rate'] ?? -1);
			if (!is_finite($rate) || $rate < 0.0 || $rate > 3.0) {
				$errors[] = 'PREMIUM_RATE_INVALID';
			}
			// enabled: only real bools or canonical form-encoded values —
			// anything else is rejected instead of !empty()-smuggled to true.
			if (array_key_exists('enabled', $cat)
				&& !is_bool($cat['enabled'])
				&& !in_array($cat['enabled'], [0, 1, '0', '1', 'true', 'false'], true)) {
				$errors[] = 'PREMIUM_ENABLED_INVALID';
			}
			$applies = (string)($cat['applies_to'] ?? '');
			if ($applies === self::APPLIES_WEEKDAY) {
				$days = $cat['weekdays'] ?? [];
				if (!is_array($days) || $days === []) {
					$errors[] = 'PREMIUM_WEEKDAYS_MISSING';
				}
			} elseif ($applies === self::APPLIES_TIME_WINDOW) {
				$ws = (string)($cat['window_start'] ?? '');
				$we = (string)($cat['window_end'] ?? '');
				if (self::parseHm($ws) === null || self::parseHm($we) === null) {
					$errors[] = 'PREMIUM_WINDOW_INVALID';
				}
			} elseif ($applies !== self::APPLIES_OVERTIME) {
				$errors[] = 'PREMIUM_APPLIES_INVALID';
			}
		}

		return array_values(array_unique($errors));
	}

	/**
	 * @param array<string, mixed> $raw
	 */
	public static function fromValidated(array $raw): self
	{
		$stacking = (string)($raw['stacking'] ?? self::STACKING_MAX_SINGLE);
		if (!in_array($stacking, [self::STACKING_MAX_SINGLE, self::STACKING_ADDITIVE, self::STACKING_TAGGED], true)) {
			$stacking = self::STACKING_MAX_SINGLE;
		}
		$holiday = (string)($raw['holiday_policy'] ?? 'treat_as_sunday');
		if ($holiday !== 'treat_as_sunday' && $holiday !== 'ignore') {
			$holiday = 'treat_as_sunday';
		}
		$normalized = [];
		foreach ((array)($raw['categories'] ?? []) as $cat) {
			if (!is_array($cat)) {
				continue;
			}
			$id = trim((string)($cat['id'] ?? ''));
			if ($id === '') {
				continue;
			}
			$row = [
				'id' => $id,
				'label' => trim((string)($cat['label'] ?? $id)),
				'rate' => round(max(0.0, min(3.0, (float)($cat['rate'] ?? 0))), 4),
				'enabled' => !array_key_exists('enabled', $cat) || self::truthyFlag($cat['enabled']),
				'applies_to' => (string)($cat['applies_to'] ?? ''),
			];
			if ($row['applies_to'] === self::APPLIES_WEEKDAY) {
				$days = [];
				foreach ((array)($cat['weekdays'] ?? []) as $d) {
					$d = strtolower(substr((string)$d, 0, 3));
					if (in_array($d, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true)) {
						$days[] = $d;
					}
				}
				$row['weekdays'] = array_values(array_unique($days));
			} elseif ($row['applies_to'] === self::APPLIES_TIME_WINDOW) {
				$row['window_start'] = (string)$cat['window_start'];
				$row['window_end'] = (string)$cat['window_end'];
			} elseif ($row['applies_to'] === self::APPLIES_OVERTIME) {
				$row['threshold_ref'] = (string)($cat['threshold_ref'] ?? 'model_net_daily');
			} else {
				continue;
			}
			$normalized[] = $row;
		}

		return new self($stacking, $normalized, $holiday, self::VERSION);
	}

	public static function tryFromArray(?array $raw): ?self
	{
		if ($raw === null || $raw === []) {
			return null;
		}
		if (self::validate($raw) !== []) {
			return null;
		}

		return self::fromValidated($raw);
	}

	/**
	 * AT starter preset (BANSS-oriented rates) — editable after load.
	 *
	 * @return array<string, mixed>
	 */
	public static function atStarterPreset(): array
	{
		return [
			'version' => self::VERSION,
			'currency_mode' => 'hours_only',
			'stacking' => self::STACKING_MAX_SINGLE,
			'holiday_policy' => 'treat_as_sunday',
			'categories' => [
				[
					'id' => 'overtime_base',
					'label' => 'Overtime above daily target',
					'rate' => 0.50,
					'enabled' => true,
					'applies_to' => self::APPLIES_OVERTIME,
					'threshold_ref' => 'model_net_daily',
				],
				[
					'id' => 'sunday',
					'label' => 'Sunday',
					'rate' => 1.00,
					'enabled' => true,
					'applies_to' => self::APPLIES_WEEKDAY,
					'weekdays' => ['sun'],
				],
				[
					'id' => 'saturday',
					'label' => 'Saturday',
					'rate' => 0.50,
					'enabled' => true,
					'applies_to' => self::APPLIES_WEEKDAY,
					'weekdays' => ['sat'],
				],
				[
					'id' => 'night',
					'label' => 'Night',
					'rate' => 0.50,
					'enabled' => true,
					'applies_to' => self::APPLIES_TIME_WINDOW,
					'window_start' => '22:00',
					'window_end' => '05:00',
				],
			],
		];
	}

	/**
	 * DE Tarif-oriented starter (night often 23–6).
	 *
	 * @return array<string, mixed>
	 */
	public static function deTariffStarterPreset(): array
	{
		$p = self::atStarterPreset();
		foreach ($p['categories'] as &$cat) {
			if (($cat['id'] ?? '') === 'night') {
				$cat['window_start'] = '23:00';
				$cat['window_end'] = '06:00';
				$cat['label'] = 'Night (23:00–06:00)';
			}
			if (($cat['id'] ?? '') === 'sunday') {
				$cat['rate'] = 1.00;
			}
		}
		unset($cat);

		return $p;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function blankPreset(): array
	{
		return [
			'version' => self::VERSION,
			'currency_mode' => 'hours_only',
			'stacking' => self::STACKING_MAX_SINGLE,
			'holiday_policy' => 'treat_as_sunday',
			'categories' => [],
		];
	}

	public function getStacking(): string
	{
		return $this->stacking;
	}

	public function getHolidayPolicy(): string
	{
		return $this->holidayPolicy;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getCategories(): array
	{
		return $this->categories;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public function getEnabledCategories(): array
	{
		return array_values(array_filter(
			$this->categories,
			static fn (array $c): bool => !empty($c['enabled'])
		));
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array
	{
		return [
			'version' => $this->version,
			'currency_mode' => 'hours_only',
			'stacking' => $this->stacking,
			'holiday_policy' => $this->holidayPolicy,
			'categories' => $this->categories,
		];
	}

	public static function parseHm(string $hm): ?int
	{
		$hm = trim($hm);
		if (!preg_match('/^(\d{1,2}):(\d{2})$/', $hm, $m)) {
			return null;
		}
		$h = (int)$m[1];
		$min = (int)$m[2];
		if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
			return null;
		}

		return $h * 60 + $min;
	}
}
