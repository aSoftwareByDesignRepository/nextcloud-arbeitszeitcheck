<?php

declare(strict_types=1);

/**
 * Pure-static helpers for naive `DATETIME` / `TIMESTAMP` columns in `at_*` tables.
 *
 * Prefer {@see TimeZoneService} for any code path that has access to the DI
 * container. This class exists for contexts where DI is not available:
 *
 *  - {@see \OCP\AppFramework\Db\Entity} hydration (e.g.
 *    {@see \OCA\ArbeitszeitCheck\Db\TimeEntry}).
 *  - Pure {@see \OCP\Migration\IRepairStep} migration steps that construct
 *    the helper themselves.
 *  - Static factory methods that cannot be refactored to receive
 *    {@see \OCP\IConfig}.
 *
 * **Storage contract:** values persisted to naive SQL are the civil
 * `Y-m-d H:i:s` digits of an instant expressed in the configured organisation
 * timezone ({@see \OCA\ArbeitszeitCheck\Constants::CONFIG_APP_TIMEZONE},
 * default `Europe/Berlin`). See {@see TimeZoneService} for the full contract.
 *
 * @copyright Copyright (c) 2026, Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;

final class AppLocalNaiveDateTimeNormalizer
{
	/**
	 * Re-bind a {@see \DateTime} loaded from a naive SQL datetime to the
	 * given storage timezone. Returns the input unchanged when the wall
	 * clock cannot be parsed.
	 */
	public static function interpretSqlNaiveAsAppTimezone(\DateTime $value, \DateTimeZone $storageTz): \DateTime
	{
		$wall = $value->format('Y-m-d H:i:s');
		$parsed = \DateTime::createFromFormat('!Y-m-d H:i:s', $wall, $storageTz);
		if ($parsed === false) {
			return $value;
		}
		return $parsed;
	}

	/**
	 * Normalise naive SQL datetime columns in a raw associative row to
	 * ISO-8601 with offset. Used by callers that bypass entity hydration
	 * (e.g. `$queryBuilder->executeQuery()->fetchAll()`).
	 *
	 * @param array<string, mixed> $row
	 * @param list<string>         $columns Optional list of columns to convert.
	 *                                      Defaults to the standard `at_entries` set.
	 * @return array<string, mixed>
	 */
	public static function normalizeAtEntryDatetimeStringsInRow(
		array $row,
		\DateTimeZone $storageTz,
		array $columns = ['start_time', 'end_time', 'break_start_time', 'break_end_time', 'created_at', 'updated_at', 'approved_at']
	): array {
		foreach ($columns as $col) {
			if (!\array_key_exists($col, $row)) {
				continue;
			}
			$v = $row[$col];
			if (!\is_string($v) || $v === '') {
				continue;
			}
			$parsed = \DateTime::createFromFormat('!Y-m-d H:i:s', $v, $storageTz);
			if ($parsed !== false) {
				$row[$col] = $parsed->format(\DateTimeInterface::ATOM);
			}
		}
		return $row;
	}

	/**
	 * Parse a datetime string from HTTP/JSON clients.
	 *
	 *  - If PHP detects an explicit zone in the string (e.g. `+02:00`, `Z`,
	 *    `Europe/Berlin`), the INSTANT is honoured and the result is expressed
	 *    in $naiveInThisZoneIfUnset — the storage zone. Returning the raw
	 *    foreign-offset object instead would make `set*Time()` persist that
	 *    foreign wall clock into the naive column and rehydrate shifted.
	 *  - Otherwise the value is treated as a wall clock in
	 *    $naiveInThisZoneIfUnset.
	 *
	 * @throws \InvalidArgumentException when the value cannot be parsed.
	 */
	public static function parseFlexibleDateTime(string $value, \DateTimeZone $naiveInThisZoneIfUnset): \DateTime
	{
		$value = trim($value);
		if ($value === '') {
			throw new \InvalidArgumentException('Empty datetime value');
		}
		$info = date_parse($value);
		if (($info['error_count'] ?? 0) > 0) {
			throw new \InvalidArgumentException('Invalid datetime syntax');
		}
		$explicit = (($info['is_localtime'] ?? false) === true);
		if ($explicit) {
			return (new \DateTime($value))->setTimezone($naiveInThisZoneIfUnset);
		}
		return new \DateTime($value, $naiveInThisZoneIfUnset);
	}

	/**
	 * Resolve the configured storage timezone from app config.
	 *
	 * Prefer {@see TimeZoneService::storageTimeZone()} in DI-enabled code
	 * (services / controllers / mappers). This static helper is kept for
	 * entity hydration and migration steps where DI is not available.
	 */
	public static function appStorageTimeZoneFromConfig(\OCP\IConfig $config): \DateTimeZone
	{
		$name = (string)$config->getAppValue('arbeitszeitcheck', Constants::CONFIG_APP_TIMEZONE, TimeZoneService::DEFAULT_STORAGE_TZ);
		try {
			return new \DateTimeZone($name);
		} catch (\Throwable $e) {
			\OCP\Log\logger('arbeitszeitcheck')->warning(
				'Invalid app timezone in config; falling back to ' . TimeZoneService::DEFAULT_STORAGE_TZ . ': ' . $name,
				['exception' => $e]
			);
			return new \DateTimeZone(TimeZoneService::DEFAULT_STORAGE_TZ);
		}
	}

	/**
	 * Mutable "now" in the configured storage timezone.
	 *
	 * Prefer {@see TimeZoneService::nowInStorage()} in DI-enabled code.
	 */
	public static function nowMutableInAppStorage(\OCP\IConfig $config): \DateTime
	{
		return new \DateTime('now', self::appStorageTimeZoneFromConfig($config));
	}
}
