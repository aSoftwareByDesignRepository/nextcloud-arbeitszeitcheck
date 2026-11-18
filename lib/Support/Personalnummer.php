<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCP\IConfig;

/**
 * Payroll personnel number (Personalnummer) lookup for export surfaces.
 *
 * The stored value is the per-user IConfig value `datev_personalnummer`
 * (admin-maintained, validated 1–8 digits at the write path). Export readers
 * must NOT re-validate — legacy/unvalidated values pass through verbatim so a
 * payroll export never silently drops a row (see cecilienstift pack spec §9.2).
 */
final class Personalnummer
{
	private function __construct()
	{
	}

	public static function forUser(IConfig $config, string $userId): string
	{
		return (string)$config->getUserValue(
			$userId,
			'arbeitszeitcheck',
			Constants::USER_DATEV_PERSONALNUMMER,
			''
		);
	}

	/**
	 * One lookup per distinct uid (no N+1 per export row — callers share the map).
	 *
	 * @param list<string> $userIds
	 * @return array<string, string> uid → personalnummer ('' when unset)
	 */
	public static function mapForUsers(IConfig $config, array $userIds): array
	{
		$map = [];
		foreach ($userIds as $userId) {
			if (!array_key_exists($userId, $map)) {
				$map[$userId] = self::forUser($config, $userId);
			}
		}
		return $map;
	}
}
