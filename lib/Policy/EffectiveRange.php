<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

final readonly class EffectiveRange
{
	public function __construct(
		public \DateTimeImmutable $from,
		public ?\DateTimeImmutable $to = null,
	) {
		if ($to !== null && $to < $from) {
			throw new \InvalidArgumentException('Effective range end precedes start.');
		}
	}

	public function contains(\DateTimeInterface $date): bool
	{
		$value = $date->format('Y-m-d');
		return $value >= $this->from->format('Y-m-d')
			&& ($this->to === null || $value <= $this->to->format('Y-m-d'));
	}

	public function overlaps(self $other): bool
	{
		return ($this->to === null || $other->from <= $this->to)
			&& ($other->to === null || $this->from <= $other->to);
	}
}
