<?php

declare(strict_types=1);

/**
 * Resolve the admin-configured minute step for time pickers.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCP\IConfig;

final class TimePickerMinuteStep
{
	/** @var list<int> */
	public const ALLOWED = [1, 5, 10, 15];

	public static function resolve(?IConfig $config = null): int
	{
		$fallback = Constants::TIME_PICKER_MINUTE_STEP;
		if ($config === null) {
			return self::normalize($fallback);
		}
		$raw = (int)$config->getAppValue(
			'arbeitszeitcheck',
			Constants::CONFIG_TIME_PICKER_MINUTE_STEP,
			(string)$fallback
		);

		return self::normalize($raw);
	}

	public static function normalize(int $step): int
	{
		if (in_array($step, self::ALLOWED, true)) {
			return $step;
		}

		return Constants::TIME_PICKER_MINUTE_STEP;
	}
}
