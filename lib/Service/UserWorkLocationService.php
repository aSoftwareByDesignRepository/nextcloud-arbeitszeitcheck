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

final class UserWorkLocationService
{
	public function __construct(
		private readonly UserSettingsMapper $userSettingsMapper,
		private readonly AuditLogMapper $auditLogMapper,
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
		$new = WorkLocation::normalize($value);
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
	 * Location for a newly created entry: explicit value → employee default →
	 * {@see WorkLocation::FALLBACK}. Always returns a concrete location.
	 *
	 * @param mixed $explicit Raw caller value; invalid values throw.
	 * @throws \InvalidArgumentException
	 */
	public function resolveForNewEntry(string $userId, mixed $explicit): string
	{
		return WorkLocation::normalize($explicit)
			?? $this->getDefault($userId)
			?? WorkLocation::FALLBACK;
	}
}
