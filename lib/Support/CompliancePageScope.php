<?php

declare(strict_types=1);

/**
 * Who a compliance page may show.
 *
 * @copyright Copyright (c) 2026, Alexander Mäule <info@software-by-design.de>
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Support;

/**
 * Resolves the compliance page to the signed-in user, or to one employee
 * the viewer is already allowed to see. Anything else is a hard denial.
 */
final class CompliancePageScope {
	public const DENIED = 'compliance-access-denied';

	/**
	 * @param callable(string, string): bool $canView
	 * @return array{targetUserId: string, filterUserId: ?string}
	 */
	public static function resolve(string $currentUserId, mixed $requestedUserId, callable $canView): array {
		$requested = is_string($requestedUserId) ? trim($requestedUserId) : '';
		if ($requested === '' || $requested === $currentUserId) {
			return [
				'targetUserId' => $currentUserId,
				'filterUserId' => null,
			];
		}
		if ($canView($currentUserId, $requested) !== true) {
			throw new \InvalidArgumentException(self::DENIED);
		}

		return [
			'targetUserId' => $requested,
			'filterUserId' => $requested,
		];
	}
}
