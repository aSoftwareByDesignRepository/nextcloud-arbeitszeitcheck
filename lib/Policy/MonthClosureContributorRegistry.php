<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Policy;

final class MonthClosureContributorRegistry
{
	private array $contributors = [];

	public function register(MonthClosureContributor $contributor): void
	{
		$id = $contributor->id();
		if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $id) !== 1 || isset($this->contributors[$id])) {
			throw new \InvalidArgumentException('Invalid or duplicate month-closure contributor id.');
		}
		$this->contributors[$id] = $contributor;
		ksort($this->contributors, SORT_STRING);
	}

	public function all(): array
	{
		return array_values($this->contributors);
	}

	public function ids(): array
	{
		return array_keys($this->contributors);
	}
}
