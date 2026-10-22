<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCP\IConfig;

/**
 * Admin-configurable past skew for offline stamp replay (`occurredAt`).
 *
 * Default 24h keeps the fail-closed posture for device-clock abuse; hard ceiling
 * 72h covers weekend outages without becoming open-ended offline payroll.
 */
final class OfflineStampSkewPolicy
{
	public const DEFAULT_PAST_HOURS = 24;
	public const MIN_PAST_HOURS = 24;
	public const MAX_PAST_HOURS = 72;
	public const MAX_FUTURE_SECONDS = 300;

	/** @var list<int> */
	public const PRESET_PAST_HOURS = [24, 48, 72];

	public static function normalizePastHours(int $hours): int
	{
		return max(self::MIN_PAST_HOURS, min(self::MAX_PAST_HOURS, $hours));
	}

	public static function isAllowedPastHours(int $hours): bool
	{
		return $hours >= self::MIN_PAST_HOURS && $hours <= self::MAX_PAST_HOURS;
	}

	public static function pastSeconds(int $hours): int
	{
		return self::normalizePastHours($hours) * 3600;
	}

	/**
	 * Read effective past-hours from app config.
	 *
	 * Writers MUST use typed string APIs ({@see \OCP\IAppConfig::setValueString}
	 * / AppFramework {@code setAppValueString}). Untyped
	 * {@see \OCP\IConfig::setAppValue} fatals on Nextcloud 34+ after the key
	 * exists as a string-typed value.
	 */
	public static function fromConfig(IConfig $config): int
	{
		$raw = (int)$config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_OFFLINE_STAMP_MAX_PAST_HOURS,
			(string)self::DEFAULT_PAST_HOURS,
		);
		return self::normalizePastHours($raw);
	}

	public static function fromAppConfigString(string $raw): int
	{
		return self::normalizePastHours((int)$raw);
	}
}
