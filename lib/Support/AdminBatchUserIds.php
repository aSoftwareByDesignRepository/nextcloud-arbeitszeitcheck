<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Constants;

/**
 * Normalize admin bulk userId payloads: trim, drop empties, unique, enforce MAX_BATCH_USERS.
 */
final class AdminBatchUserIds
{
	/**
	 * @param mixed $raw
	 * @return array{ok: true, userIds: list<string>}|array{ok: false, error: string, httpStatus: int}
	 */
	public static function normalize(mixed $raw, int $max = Constants::MAX_BATCH_USERS): array
	{
		if (!is_array($raw)) {
			return [
				'ok' => false,
				'error' => 'user_ids_required',
				'httpStatus' => 400,
			];
		}

		$seen = [];
		$userIds = [];
		foreach ($raw as $item) {
			if (!is_string($item) && !is_int($item)) {
				continue;
			}
			$id = trim((string)$item);
			if ($id === '' || isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$userIds[] = $id;
		}

		if ($userIds === []) {
			return [
				'ok' => false,
				'error' => 'user_ids_required',
				'httpStatus' => 400,
			];
		}

		if (count($userIds) > $max) {
			return [
				'ok' => false,
				'error' => 'batch_too_large',
				'httpStatus' => 400,
			];
		}

		return [
			'ok' => true,
			'userIds' => $userIds,
		];
	}
}
