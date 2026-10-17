<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

final readonly class IdempotencyKey
{
	public function __construct(public string $value)
	{
		if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $value) !== 1) {
			throw new \InvalidArgumentException('Invalid idempotency key.');
		}
	}

	public static function parse(mixed $value): self
	{
		if (!is_string($value)) {
			throw new \InvalidArgumentException('Idempotency key is required.');
		}
		return new self(trim($value));
	}

	public function payloadHash(array $payload): string
	{
		self::sortRecursive($payload);
		return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
	}

	private static function sortRecursive(array &$value): void
	{
		if (!array_is_list($value)) {
			ksort($value, SORT_STRING);
		}
		foreach ($value as &$item) {
			if (is_array($item)) {
				self::sortRecursive($item);
			}
		}
	}
}
