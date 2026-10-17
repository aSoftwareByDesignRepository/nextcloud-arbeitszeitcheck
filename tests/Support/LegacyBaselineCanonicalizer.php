<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Support;

final class LegacyBaselineCanonicalizer
{
	public static function encode(array $baseline): string
	{
		return json_encode(self::normalize($baseline), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	public static function hash(array $baseline): string
	{
		return hash('sha256', self::encode($baseline));
	}

	private static function normalize(array $value): array
	{
		if (!array_is_list($value)) {
			ksort($value, SORT_STRING);
		}

		foreach ($value as $key => $item) {
			if (is_array($item)) {
				$value[$key] = self::normalize($item);
			}
		}

		return $value;
	}
}
