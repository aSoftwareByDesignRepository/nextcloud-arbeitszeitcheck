<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Db\BusinessTripRuleSet;
use OCA\ArbeitszeitCheck\Exception\BusinessTripException;

/**
 * Declarative rule-set config validator (spec §9.4).
 *
 * The schema is a closed allowlist — no conditions, variables, or operators
 * beyond it ever reach the calculation engine. All factors are integer
 * basis points (10000 = 100 %); floats are rejected.
 */
final class BusinessTripRuleConfigValidator
{
	public const SCHEMA_VERSION = 1;

	public const DAY_BASES = ['trip_presence', 'travel_duration', 'actual_work', 'active_plus_work', 'classified_total'];
	public const OPERATIONS = ['minimum', 'fixed', 'maximum', 'additive'];
	public const COMPARE_AGAINST = ['actual_work_minutes', 'actual_accountable_minutes', 'zero'];
	public const EXPORT_MODES = ['account_credit', 'compliance', 'actual_work', 'separate_factors'];
	public const ROUNDING_MODES = ['nearest', 'floor', 'ceil'];
	public const ROUNDING_INCREMENTS = [1, 5, 6, 10, 15, 30, 60];
	public const DAY_KIND_KEYS = ['first', 'middle', 'last', 'single'];
	public const FACTOR_KEYS = [
		'actualWorkFactorBps',
		'activeTravelFactorBps',
		'passiveTravelFactorBps',
		'workDuringTravelFactorBps',
	];
	public const MAX_THRESHOLDS = 32;
	public const MAX_MINUTES = 1440;

	/** @return array<string,mixed> the validated config (unchanged, normalized whitespace-free). */
	public function validate(mixed $raw): array
	{
		if (is_string($raw)) {
			$decoded = json_decode($raw, true);
			if (!is_array($decoded)) {
				throw new BusinessTripException('RULE_SCHEMA_INVALID', 'The rule configuration is not valid JSON.', 400, ['config' => 'invalid_json']);
			}
			$raw = $decoded;
		}
		if (!is_array($raw) || array_is_list($raw)) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'The rule configuration must be an object.', 400, ['config' => 'not_object']);
		}

		$allowed = [
			'schemaVersion', 'evaluationTimezone', 'dayBasis', 'dayKinds', 'thresholds',
			'operation', 'compareAgainst', 'accountTreatment', 'rounding',
			'dailyTargetCapMinutes', 'export', 'requiresClassification', 'legalReview',
			'activeTravelLegallyReleased', 'passiveTravelLegallyReleased',
		];
		foreach (array_keys($raw) as $key) {
			if (!in_array($key, $allowed, true)) {
				throw new BusinessTripException('RULE_SCHEMA_INVALID', 'Unknown rule field: ' . $key, 400, ['config' => 'unknown_field']);
			}
		}

		if (($raw['schemaVersion'] ?? self::SCHEMA_VERSION) !== self::SCHEMA_VERSION) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'Unsupported rule schema version.', 400, ['schemaVersion' => 'unsupported']);
		}

		$operation = $raw['operation'] ?? null;
		if (!is_string($operation) || !in_array($operation, self::OPERATIONS, true)) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'operation must be one of minimum|fixed|maximum|additive.', 400, ['operation' => 'invalid']);
		}

		$dayBasis = $raw['dayBasis'] ?? null;
		if (!is_string($dayBasis) || !in_array($dayBasis, self::DAY_BASES, true)) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'dayBasis must be one of trip_presence|travel_duration|actual_work|active_plus_work|classified_total.', 400, ['dayBasis' => 'invalid']);
		}

		if (isset($raw['compareAgainst']) && !in_array($raw['compareAgainst'], self::COMPARE_AGAINST, true)) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'compareAgainst is invalid.', 400, ['compareAgainst' => 'invalid']);
		}

		if (isset($raw['evaluationTimezone'])
			&& !in_array($raw['evaluationTimezone'], ['organization', 'origin', 'destination'], true)) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'evaluationTimezone is invalid.', 400, ['evaluationTimezone' => 'invalid']);
		}

		// dayKinds: each of first/middle/last/single must point at a known basis.
		if (isset($raw['dayKinds'])) {
			if (!is_array($raw['dayKinds']) || array_is_list($raw['dayKinds'])) {
				throw new BusinessTripException('RULE_SCHEMA_INVALID', 'dayKinds must be an object.', 400, ['dayKinds' => 'invalid']);
			}
			foreach ($raw['dayKinds'] as $kind => $basis) {
				if (!in_array($kind, self::DAY_KIND_KEYS, true) || !in_array($basis, self::DAY_BASES, true)) {
					throw new BusinessTripException('RULE_SCHEMA_INVALID', 'dayKinds contains an invalid entry.', 400, ['dayKinds' => 'invalid']);
				}
			}
		}

		// Thresholds: sorted, first fromMinutes=0, strictly increasing, target 0..1440.
		$thresholds = $raw['thresholds'] ?? null;
		if (!is_array($thresholds) || !array_is_list($thresholds) || $thresholds === []) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'thresholds must be a non-empty list.', 400, ['thresholds' => 'required']);
		}
		if (count($thresholds) > self::MAX_THRESHOLDS) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'At most ' . self::MAX_THRESHOLDS . ' thresholds are allowed.', 400, ['thresholds' => 'too_many']);
		}
		$prev = -1;
		foreach ($thresholds as $i => $threshold) {
			if (!is_array($threshold)
				|| !array_key_exists('fromMinutes', $threshold)
				|| !array_key_exists('targetMinutes', $threshold)
				|| !is_int($threshold['fromMinutes'])
				|| !is_int($threshold['targetMinutes'])
			) {
				throw new BusinessTripException('RULE_SCHEMA_INVALID', 'Each threshold needs integer fromMinutes and targetMinutes.', 400, ['thresholds' => 'invalid_shape']);
			}
			$from = $threshold['fromMinutes'];
			$target = $threshold['targetMinutes'];
			if ($i === 0 && $from !== 0) {
				throw new BusinessTripException('RULE_SCHEMA_INVALID', 'The first threshold must start at fromMinutes=0.', 400, ['thresholds' => 'first_not_zero']);
			}
			if ($from <= $prev || $from < 0 || $from > self::MAX_MINUTES) {
				throw new BusinessTripException('RULE_SCHEMA_INVALID', 'thresholds must be strictly increasing and within 0..1440.', 400, ['thresholds' => 'not_increasing']);
			}
			if ($target < 0 || $target > self::MAX_MINUTES) {
				throw new BusinessTripException('RULE_SCHEMA_INVALID', 'targetMinutes must stay within 0..1440.', 400, ['thresholds' => 'target_out_of_range']);
			}
			$prev = $from;
		}

		// Factor blocks: integer basis points 0..10000, no floats.
		foreach (['accountTreatment', 'export'] as $block) {
			if (!isset($raw[$block])) {
				continue;
			}
			if (!is_array($raw[$block]) || array_is_list($raw[$block])) {
				throw new BusinessTripException('RULE_SCHEMA_INVALID', $block . ' must be an object.', 400, [$block => 'invalid']);
			}
			if ($block === 'export') {
				$mode = $raw[$block]['mode'] ?? null;
				if (!is_string($mode) || !in_array($mode, self::EXPORT_MODES, true)) {
					throw new BusinessTripException('RULE_SCHEMA_INVALID', 'export.mode is invalid.', 400, ['export' => 'invalid_mode']);
				}
				foreach (['activeTravelFactorBps', 'passiveTravelFactorBps', 'workDuringTravelFactorBps'] as $key) {
					if (isset($raw[$block][$key])) {
						$this->assertBps($raw[$block][$key], 'export.' . $key);
					}
				}
				continue;
			}
			foreach (self::FACTOR_KEYS as $key) {
				if (!array_key_exists($key, $raw[$block])) {
					throw new BusinessTripException('RULE_SCHEMA_INVALID', 'accountTreatment is missing ' . $key . '.', 400, [$block => 'missing_factor']);
				}
				$this->assertBps($raw[$block][$key], 'accountTreatment.' . $key);
			}
		}

		if (isset($raw['rounding'])) {
			$r = $raw['rounding'];
			if (!is_array($r) || array_is_list($r)) {
				throw new BusinessTripException('RULE_SCHEMA_INVALID', 'rounding must be an object.', 400, ['rounding' => 'invalid']);
			}
			foreach (['inputIncrement', 'resultIncrement'] as $inc) {
				if (isset($r[$inc]) && (!is_int($r[$inc]) || !in_array($r[$inc], self::ROUNDING_INCREMENTS, true))) {
					throw new BusinessTripException('RULE_SCHEMA_INVALID', 'rounding.' . $inc . ' must be one of 1|5|6|10|15|30|60.', 400, ['rounding' => 'invalid_increment']);
				}
			}
			if (isset($r['mode']) && !in_array($r['mode'], self::ROUNDING_MODES, true)) {
				throw new BusinessTripException('RULE_SCHEMA_INVALID', 'rounding.mode must be nearest|floor|ceil.', 400, ['rounding' => 'invalid_mode']);
			}
		}

		if (isset($raw['dailyTargetCapMinutes'])
			&& (!is_int($raw['dailyTargetCapMinutes']) || $raw['dailyTargetCapMinutes'] < 0 || $raw['dailyTargetCapMinutes'] > self::MAX_MINUTES)) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'dailyTargetCapMinutes must stay within 0..1440.', 400, ['dailyTargetCapMinutes' => 'out_of_range']);
		}

		// Legal review metadata is mandatory for built-in jurisdictions (§10.5).
		if (isset($raw['legalReview']) && !is_array($raw['legalReview'])) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'legalReview must be an object.', 400, ['legalReview' => 'invalid']);
		}
		if (isset($raw['requiresClassification']) && !is_bool($raw['requiresClassification'])) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', 'requiresClassification must be boolean.', 400, ['requiresClassification' => 'invalid']);
		}
		foreach (['activeTravelLegallyReleased', 'passiveTravelLegallyReleased'] as $flagKey) {
			if (isset($raw[$flagKey]) && !is_bool($raw[$flagKey])) {
				throw new BusinessTripException('RULE_SCHEMA_INVALID', $flagKey . ' must be boolean.', 400, [$flagKey => 'invalid']);
			}
		}

		return $raw;
	}

	private function assertBps(mixed $value, string $field): void
	{
		if (!is_int($value) || $value < 0 || $value > 10000) {
			throw new BusinessTripException('RULE_SCHEMA_INVALID', $field . ' must be an integer basis-point value 0..10000.', 400, [$field => 'invalid_bps']);
		}
	}

	/** Canonical hash over the validated config (spec: config_hash per version). */
	public function configHash(array $config): string
	{
		return hash('sha256', json_encode($this->canonicalize($config), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/** Deterministic key order so identical configs hash identically. */
	private function canonicalize(mixed $value): mixed
	{
		if (!is_array($value)) {
			return $value;
		}
		if (array_is_list($value)) {
			return array_map([$this, 'canonicalize'], $value);
		}
		ksort($value);
		$out = [];
		foreach ($value as $k => $v) {
			$out[$k] = $this->canonicalize($v);
		}
		return $out;
	}
}
