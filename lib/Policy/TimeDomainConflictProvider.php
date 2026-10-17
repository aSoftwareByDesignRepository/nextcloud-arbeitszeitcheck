<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

interface TimeDomainConflictProvider
{
	public function id(): string;
	public function conflicts(string $userId, string $from, string $to): array;
}
