<?php

declare(strict_types=1);

/**
 * Canonical allowlist for per-entry work location marking (GH #43).
 *
 * `office` | `home` | `external`; NULL on a row means "not recorded"
 * (legacy entries predating the column). Every trust boundary that accepts
 * a location must run it through {@see normalize()} — never trust caller
 * strings.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

final class WorkLocation
{
	public const OFFICE = 'office';
	public const HOME = 'home';
	public const EXTERNAL = 'external';

	/** Default applied when neither the request nor the user setting provides one. */
	public const FALLBACK = self::OFFICE;

	/** @var list<string> */
	public const ALL = [
		self::OFFICE,
		self::HOME,
		self::EXTERNAL,
	];

	private function __construct()
	{
	}

	public static function isValid(string $value): bool
	{
		if (in_array($value, self::ALL, true)) {
			return true;
		}
		// Catalog-defined codes are valid only while the feature is enabled —
		// flag-off keeps the byte-identical static allowlist so upgraded and
		// rolled-back installations can never write a code their build does
		// not understand.
		try {
			if (!\OCP\Server::get(\OCA\ArbeitszeitCheck\Service\WorkforceSuiteConfig::class)
				->isEnabled('work_location_catalog_enabled')) {
				return false;
			}
			return \OCP\Server::get(\OCA\ArbeitszeitCheck\Service\WorkLocationCatalog::class)
				->isKnown($value);
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * Normalize untrusted input to a canonical location or null.
	 *
	 * `null`/`''` mean "not provided / clear". Any other non-allowlist value
	 * throws — callers at request boundaries must treat that as a 400, not
	 * silently drop it (silent drops hide client bugs and audit gaps).
	 *
	 * @param mixed $raw
	 * @throws \InvalidArgumentException when $raw is a non-empty invalid value
	 */
	public static function normalize(mixed $raw): ?string
	{
		if ($raw === null || $raw === '') {
			return null;
		}
		if (!is_string($raw)) {
			throw new \InvalidArgumentException('Invalid work location');
		}
		$value = strtolower(trim($raw));
		if ($value === '') {
			return null;
		}
		if (!self::isValid($value)) {
			throw new \InvalidArgumentException('Invalid work location');
		}
		return $value;
	}

	/**
	 * l10n key suffix for a location (caller translates via the app's catalog).
	 *
	 * @return string|null `home`|`external`|`office`, or null when unset
	 */
	public static function labelKey(?string $value): ?string
	{
		return ($value !== null && self::isValid($value)) ? $value : null;
	}
}
