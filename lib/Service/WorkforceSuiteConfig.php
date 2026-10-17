<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service;

use OCP\IConfig;

final class WorkforceSuiteConfig
{
	public const APP_ID = 'arbeitszeitcheck';
	public const INSTALL_STATE_KEY = 'workforce_suite_install_state';
	public const STATE_FRESH = 'fresh';
	public const STATE_UPGRADED_LEGACY = 'upgraded_legacy';
	public const STATE_PARTIALLY_MIGRATED = 'partially_migrated';
	public const STATE_MIGRATED = 'migrated';

	public const FEATURE_DEFAULTS = [
		'policy_cohorts_enabled' => false,
		'work_location_catalog_enabled' => false,
		'special_entitlements_enabled' => false,
		'business_trips_enabled' => false,
		'business_trip_credit_enabled' => false,
		'legacy_home_absence_create_enabled' => true,
		'legacy_trip_absence_create_enabled' => true,
	];

	private const STATES = [
		self::STATE_FRESH,
		self::STATE_UPGRADED_LEGACY,
		self::STATE_PARTIALLY_MIGRATED,
		self::STATE_MIGRATED,
	];

	public function __construct(private readonly IConfig $config)
	{
	}

	public function initialize(string $state): void
	{
		$this->assertState($state);
		if ($this->config->getAppValue(self::APP_ID, self::INSTALL_STATE_KEY, '') === '') {
			$this->config->setAppValue(self::APP_ID, self::INSTALL_STATE_KEY, $state);
		}
		foreach (self::FEATURE_DEFAULTS as $key => $enabled) {
			if ($this->config->getAppValue(self::APP_ID, $key, '') === '') {
				$this->config->setAppValue(self::APP_ID, $key, $enabled ? '1' : '0');
			}
		}
	}

	public function installState(): string
	{
		$state = $this->config->getAppValue(self::APP_ID, self::INSTALL_STATE_KEY, '');
		return in_array($state, self::STATES, true) ? $state : self::STATE_UPGRADED_LEGACY;
	}

	public function setInstallState(string $state): void
	{
		$this->assertState($state);
		$current = $this->installState();
		$allowed = match ($current) {
			self::STATE_FRESH, self::STATE_UPGRADED_LEGACY => [self::STATE_PARTIALLY_MIGRATED, self::STATE_MIGRATED],
			self::STATE_PARTIALLY_MIGRATED => [self::STATE_MIGRATED],
			self::STATE_MIGRATED => [],
		};
		if ($state !== $current && !in_array($state, $allowed, true)) {
			throw new \DomainException('Invalid workforce suite install-state transition.');
		}
		$this->config->setAppValue(self::APP_ID, self::INSTALL_STATE_KEY, $state);
	}

	public function isEnabled(string $key): bool
	{
		if (!array_key_exists($key, self::FEATURE_DEFAULTS)) {
			throw new \InvalidArgumentException('Unknown workforce suite feature flag.');
		}
		$value = $this->config->getAppValue(self::APP_ID, $key, self::FEATURE_DEFAULTS[$key] ? '1' : '0');
		return $value === '1' || $value === 'true';
	}

	public function flags(): array
	{
		$flags = [];
		foreach (self::FEATURE_DEFAULTS as $key => $_) {
			$flags[$key] = $this->isEnabled($key);
		}
		return $flags;
	}

	public function updateFlags(array $changes): void
	{
		$current = $this->flags();
		foreach ($changes as $key => $value) {
			if (!array_key_exists($key, self::FEATURE_DEFAULTS) || !is_bool($value)) {
				throw new \InvalidArgumentException('Invalid workforce suite feature flag update.');
			}
			$current[$key] = $value;
		}
		$blockers = $this->blockers($current);
		if ($blockers !== []) {
			throw new \DomainException(implode(',', $blockers));
		}
		foreach ($changes as $key => $value) {
			$this->config->setAppValue(self::APP_ID, $key, $value ? '1' : '0');
		}
	}

	public function blockers(array $flags): array
	{
		$blockers = [];
		if (($flags['special_entitlements_enabled'] ?? false) && !($flags['policy_cohorts_enabled'] ?? false)) {
			$blockers[] = 'SPECIAL_ENTITLEMENTS_REQUIRE_POLICY_COHORTS';
		}
		if (($flags['business_trip_credit_enabled'] ?? false) && !($flags['business_trips_enabled'] ?? false)) {
			$blockers[] = 'BUSINESS_TRIP_CREDIT_REQUIRES_BUSINESS_TRIPS';
		}
		return $blockers;
	}

	private function assertState(string $state): void
	{
		if (!in_array($state, self::STATES, true)) {
			throw new \InvalidArgumentException('Unknown workforce suite install state.');
		}
	}
}
