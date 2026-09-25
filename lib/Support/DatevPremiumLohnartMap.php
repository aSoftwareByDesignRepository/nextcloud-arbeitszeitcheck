<?php

declare(strict_types=1);

/**
 * Validates DATEV Lohnart mapping for premium (Zuschlag) buckets.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

/**
 * Additive payroll codes only — empty code means "do not export this bucket".
 */
final class DatevPremiumLohnartMap
{
	public const ERR_NOT_ARRAY = 'DATEV_PREMIUM_MAP_NOT_ARRAY';
	public const ERR_INVALID_ID = 'DATEV_PREMIUM_MAP_ID';
	public const ERR_INVALID_CODE = 'DATEV_PREMIUM_MAP_CODE';

	/**
	 * Allowed starter / UI category ids (extensible: unknown ids still accepted if well-formed).
	 *
	 * @var list<string>
	 */
	public const KNOWN_CATEGORY_IDS = ['overtime_base', 'sunday', 'saturday', 'night'];

	/**
	 * @param mixed $raw
	 * @return list<string> error codes (empty = ok)
	 */
	public static function validate(mixed $raw): array
	{
		if (!is_array($raw)) {
			return [self::ERR_NOT_ARRAY];
		}
		$errors = [];
		foreach ($raw as $id => $code) {
			$idStr = is_string($id) ? trim($id) : '';
			if ($idStr === '' || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', $idStr)) {
				$errors[] = self::ERR_INVALID_ID;
				continue;
			}
			$codeStr = is_string($code) || is_int($code) ? trim((string)$code) : '';
			if ($codeStr === '') {
				continue;
			}
			if (!preg_match('/^[1-9]\d{0,3}$/', $codeStr)) {
				$errors[] = self::ERR_INVALID_CODE;
			}
		}

		return array_values(array_unique($errors));
	}

	/**
	 * @param mixed $raw
	 * @return array<string, string> id => code (empty codes omitted)
	 */
	public static function normalize(mixed $raw): array
	{
		if (!is_array($raw) || self::validate($raw) !== []) {
			return [];
		}
		$out = [];
		foreach ($raw as $id => $code) {
			$idStr = trim((string)$id);
			$codeStr = trim((string)$code);
			if ($idStr === '' || $codeStr === '') {
				continue;
			}
			$out[$idStr] = $codeStr;
		}

		return $out;
	}

	/**
	 * Decode stored JSON app config.
	 *
	 * @return array<string, string>
	 */
	public static function fromJson(string $json): array
	{
		if ($json === '') {
			return [];
		}
		try {
			$decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return [];
		}

		return self::normalize($decoded);
	}

	/**
	 * @param array<string, string> $map
	 */
	public static function toJson(array $map): string
	{
		$normalized = self::normalize($map);
		if ($normalized === []) {
			return '';
		}

		return json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
	}
}
