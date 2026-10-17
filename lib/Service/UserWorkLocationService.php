<?php

declare(strict_types=1);

/**
 * Per-employee default work location (GH #43).
 *
 * Stored as a plain user setting (see {@see UserEmploymentSettingsService}
 * for the established pattern). The default is resolved server-side on every
 * new time entry — web stamp, kiosk, mobile replay and manual entry all
 * funnel through {@see resolveForNewEntry()} — so a remote worker's stamps
 * are marked correctly without per-device configuration.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Support\WorkLocation;
use OCP\Server;

final class UserWorkLocationService
{
	public function __construct(
		private readonly UserSettingsMapper $userSettingsMapper,
		private readonly AuditLogMapper $auditLogMapper,
		private readonly ?WorkforceSuiteConfig $suiteConfig = null,
		private readonly ?WorkLocationCatalog $catalog = null,
		private readonly ?WorkLocationDayOverrideService $dayOverrideService = null,
		private readonly ?WorkLocationDefaultResolver $defaultResolver = null,
	) {
	}

	/**
	 * The employee's configured default location, or null when unset/invalid.
	 * A corrupt stored value reads as unset — never trust it blindly.
	 */
	public function getDefault(string $userId): ?string
	{
		$raw = $this->userSettingsMapper->getStringSetting(
			$userId,
			Constants::SETTING_WORK_LOCATION_DEFAULT,
			''
		);
		return $raw !== '' && WorkLocation::isValid($raw) ? $raw : null;
	}

	/**
	 * Persist the employee's default location. Null clears it (next entry
	 * resolves to {@see WorkLocation::FALLBACK}).
	 *
	 * @throws \InvalidArgumentException on a non-empty invalid value
	 */
	public function setDefault(string $userId, ?string $value, string $actorUserId): void
	{
		$new = $this->suiteConfig()->isEnabled('work_location_catalog_enabled')
			? $this->catalog()->normalize($value)
			: WorkLocation::normalize($value);
		$old = $this->getDefault($userId);
		if ($old === $new) {
			return;
		}
		if ($new === null) {
			$this->userSettingsMapper->deleteSetting($userId, Constants::SETTING_WORK_LOCATION_DEFAULT);
		} else {
			$this->userSettingsMapper->setSetting($userId, Constants::SETTING_WORK_LOCATION_DEFAULT, $new);
		}
		$this->auditLogMapper->logAction(
			$userId,
			'user_work_location_default_updated',
			'user',
			null,
			[Constants::SETTING_WORK_LOCATION_DEFAULT => $old],
			[Constants::SETTING_WORK_LOCATION_DEFAULT => $new],
			$actorUserId
		);
	}

	/**
	 * Location for a newly created entry. Chain (spec §9.5):
	 *
	 *   explicit → day override → policy chain (user→team→model→org)
	 *   → legacy user setting → {@see WorkLocation::FALLBACK}
	 *
	 * When `work_location_catalog_enabled` is off the chain collapses to the
	 * pre-suite behavior: static built-in allowlist → user setting → office.
	 * This keeps upgraded installations bit-identical and guarantees that a
	 * code rolled back to the old app version can never meet a custom catalog
	 * code it could not write.
	 *
	 * @param mixed $explicit Raw caller value; invalid values throw.
	 * @throws \InvalidArgumentException
	 */
	public function resolveForNewEntry(string $userId, mixed $explicit, ?\DateTimeInterface $localDate = null): string
	{
		if (!$this->suiteConfig()->isEnabled('work_location_catalog_enabled')) {
			return WorkLocation::normalize($explicit)
				?? $this->getDefault($userId)
				?? WorkLocation::FALLBACK;
		}

		$date = $localDate ?? new \DateTime();
		$dateStr = $date->format('Y-m-d');

		$normalizedExplicit = $this->catalog()->normalize($explicit);
		if ($normalizedExplicit !== null) {
			if (!$this->catalog()->isCreatable($normalizedExplicit)) {
				throw new \InvalidArgumentException('Work location is inactive');
			}
			return $normalizedExplicit;
		}

		return $this->dayOverrideService()->effectiveCodeFor($userId, $dateStr)
			?? $this->defaultResolver()->resolve($userId, $date)['code']
			?? $this->legacyDefaultCode($userId)
			?? WorkLocation::FALLBACK;
	}

	/**
	 * Effective location for the dashboard "Heute" chip: full chain including
	 * day override and policies, without writing anything. Returns the code
	 * plus a machine-readable source for UI/audit.
	 *
	 * @return array{code:string,source:string,policyId:int|null}
	 */
	public function effectiveToday(string $userId): array
	{
		$date = new \DateTime();
		if (!$this->suiteConfig()->isEnabled('work_location_catalog_enabled')) {
			return ['code' => $this->getDefault($userId) ?? WorkLocation::FALLBACK, 'source' => 'legacy_default', 'policyId' => null];
		}
		$dateStr = $date->format('Y-m-d');
		$override = $this->dayOverrideService()->effectiveCodeFor($userId, $dateStr);
		if ($override !== null) {
			return ['code' => $override, 'source' => 'day_override', 'policyId' => null];
		}
		$resolved = $this->defaultResolver()->resolve($userId, $date);
		if ($resolved !== null) {
			return ['code' => $resolved['code'], 'source' => $resolved['source'], 'policyId' => $resolved['policyId']];
		}
		$legacy = $this->legacyDefaultCode($userId);
		if ($legacy !== null) {
			return ['code' => $legacy, 'source' => 'legacy_default', 'policyId' => null];
		}
		return ['code' => WorkLocation::FALLBACK, 'source' => 'fallback', 'policyId' => null];
	}

	/**
	 * Options for create/edit selects: catalog-backed once the feature is
	 * enabled (creatable codes only, server-localized labels), the static
	 * built-ins otherwise. The stored entry value itself is never filtered
	 * out here — callers append it as a synthetic option when it is no
	 * longer creatable, so historical marks stay renderable.
	 *
	 * @return list<array{code: string, label: string}>|null `null` while the
	 *         catalog feature is disabled so callers keep their byte-identical
	 *         built-in fallback.
	 */
	public function selectableOptions(?string $locale = null): ?array
	{
		if (!$this->suiteConfig()->isEnabled('work_location_catalog_enabled')) {
			return null;
		}
		$options = [];
		foreach (WorkLocation::ALL as $builtin) {
			if ($this->catalog()->isCreatable($builtin)) {
				$options[] = ['code' => $builtin, 'label' => $this->catalog()->labelFor($builtin, $locale)];
			}
		}
		foreach ($this->catalog()->active() as $code => $def) {
			if (in_array($code, WorkLocation::ALL, true) || !$this->catalog()->isCreatable($code)) {
				continue;
			}
			$options[] = ['code' => $code, 'label' => $this->catalog()->labelFor($code, $locale)];
		}
		return $options;
	}

	/**
	 * Legacy user setting read through the catalog (custom codes are valid
	 * once the catalog is enabled). Returns null when unset/unknown.
	 */
	private function legacyDefaultCode(string $userId): ?string
	{
		$raw = $this->userSettingsMapper->getStringSetting(
			$userId,
			Constants::SETTING_WORK_LOCATION_DEFAULT,
			''
		);
		if ($raw === '') {
			return null;
		}
		$code = strtolower(trim($raw));
		return ($code !== '' && $this->catalog()->isCreatable($code)) ? $code : null;
	}

	private function suiteConfig(): WorkforceSuiteConfig
	{
		return $this->suiteConfig ?? Server::get(WorkforceSuiteConfig::class);
	}

	private function catalog(): WorkLocationCatalog
	{
		return $this->catalog ?? Server::get(WorkLocationCatalog::class);
	}

	private function dayOverrideService(): WorkLocationDayOverrideService
	{
		return $this->dayOverrideService ?? Server::get(WorkLocationDayOverrideService::class);
	}

	private function defaultResolver(): WorkLocationDefaultResolver
	{
		return $this->defaultResolver ?? Server::get(WorkLocationDefaultResolver::class);
	}
}
