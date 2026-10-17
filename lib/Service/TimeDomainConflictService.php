<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Policy\TimeDomainConflictProvider;

final class TimeDomainConflictService
{
	private array $providers = [];

	public function __construct(private readonly WorkforceSuiteConfig $config)
	{
	}

	public function register(TimeDomainConflictProvider $provider): void
	{
		$id = $provider->id();
		if (preg_match('/^[a-z][a-z0-9_]{1,63}$/D', $id) !== 1 || isset($this->providers[$id])) {
			throw new \InvalidArgumentException('Invalid or duplicate conflict provider id.');
		}
		$this->providers[$id] = $provider;
		ksort($this->providers, SORT_STRING);
	}

	public function check(string $userId, string $from, string $to): array
	{
		if (!$this->hasActiveDomain()) {
			return [];
		}
		if (trim($userId) === '' || !$this->isValidDate($from) || !$this->isValidDate($to) || $to < $from) {
			throw new \InvalidArgumentException('Invalid conflict-check range.');
		}
		$conflicts = [];
		foreach ($this->providers as $provider) {
			foreach ($provider->conflicts($userId, $from, $to) as $conflict) {
				if (!is_array($conflict)
					|| !is_string($conflict['code'] ?? null)
					|| preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $conflict['code']) !== 1
					|| !is_string($conflict['from'] ?? null)
					|| !is_string($conflict['to'] ?? null)) {
					throw new \UnexpectedValueException('Conflict provider returned a non-public payload.');
				}
				$conflicts[] = [
					'domain' => $provider->id(),
					'code' => $conflict['code'],
					'from' => $conflict['from'],
					'to' => $conflict['to'],
				];
			}
		}
		return $conflicts;
	}

	private function hasActiveDomain(): bool
	{
		return $this->config->isEnabled('special_entitlements_enabled')
			|| $this->config->isEnabled('business_trips_enabled');
	}

	private function isValidDate(string $date): bool
	{
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		return $parsed !== false && $parsed->format('Y-m-d') === $date;
	}
}
