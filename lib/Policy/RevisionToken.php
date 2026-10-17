<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

final readonly class RevisionToken
{
	public function __construct(public int $revision)
	{
		if ($revision < 1) {
			throw new \InvalidArgumentException('Revision token must be positive.');
		}
	}

	public static function parse(mixed $value): self
	{
		if (is_int($value)) {
			return new self($value);
		}
		if (!is_string($value) || preg_match('/^[1-9]\d*$/D', $value) !== 1) {
			throw new \InvalidArgumentException('Revision token is required.');
		}
		return new self((int)$value);
	}

	public function assertMatches(int $current): void
	{
		if ($current !== $this->revision) {
			throw new \DomainException('REVISION_CONFLICT');
		}
	}
}
