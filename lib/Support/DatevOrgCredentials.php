<?php

declare(strict_types=1);

/**
 * Validates DATEV organisation credentials and base Lohnarten.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

/**
 * Empty values mean DATEV is unused. Partial fill (only Berater or only Mandant)
 * is rejected so admins cannot save a half-configured export that always fails.
 */
final class DatevOrgCredentials
{
	public const ERR_BERATER = 'DATEV_BERATER_INVALID';
	public const ERR_MANDANT = 'DATEV_MANDANT_INVALID';
	public const ERR_PAIR = 'DATEV_CREDENTIALS_INCOMPLETE';
	public const ERR_LOHNART = 'DATEV_LOHNART_INVALID';
	public const ERR_PERSONAL = 'DATEV_PERSONALNUMMER_INVALID';

	/**
	 * @param mixed $berater
	 * @param mixed $mandant
	 * @return list<string>
	 */
	public static function validatePair(mixed $berater, mixed $mandant): array
	{
		$b = self::digitsOnly($berater);
		$m = self::digitsOnly($mandant);
		$errors = [];
		if ($b !== '' && !preg_match('/^\d{1,7}$/', $b)) {
			$errors[] = self::ERR_BERATER;
		}
		if ($m !== '' && !preg_match('/^\d{1,5}$/', $m)) {
			$errors[] = self::ERR_MANDANT;
		}
		if (($b === '') xor ($m === '')) {
			$errors[] = self::ERR_PAIR;
		}

		return array_values(array_unique($errors));
	}

	/**
	 * @param mixed $code
	 * @return list<string>
	 */
	public static function validateLohnart(mixed $code, bool $allowEmpty = true): array
	{
		$s = self::digitsOnly($code);
		if ($s === '') {
			return $allowEmpty ? [] : [self::ERR_LOHNART];
		}
		if (!preg_match('/^[1-9]\d{0,3}$/', $s)) {
			return [self::ERR_LOHNART];
		}

		return [];
	}

	/**
	 * @param mixed $code
	 * @return list<string>
	 */
	public static function validatePersonalnummer(mixed $code, bool $allowEmpty = true): array
	{
		$s = self::digitsOnly($code);
		if ($s === '') {
			return $allowEmpty ? [] : [self::ERR_PERSONAL];
		}
		// DATEV Personalnummer: up to 8 digits (padded on export).
		if (!preg_match('/^\d{1,8}$/', $s)) {
			return [self::ERR_PERSONAL];
		}

		return [];
	}

	public static function normalizeDigits(mixed $raw): string
	{
		return self::digitsOnly($raw);
	}

	/**
	 * Strip spaces; reject if any non-digit remains after trim of surrounding space.
	 */
	private static function digitsOnly(mixed $raw): string
	{
		if ($raw === null) {
			return '';
		}
		if (!is_string($raw) && !is_int($raw)) {
			return '';
		}
		$s = preg_replace('/\s+/', '', trim((string)$raw)) ?? '';
		if ($s === '') {
			return '';
		}
		// Keep only digits for length checks; non-digit content fails validate* via pattern.
		if (!ctype_digit($s)) {
			// Return raw stripped string so validators can reject (not silently drop).
			return $s;
		}

		return $s;
	}
}
