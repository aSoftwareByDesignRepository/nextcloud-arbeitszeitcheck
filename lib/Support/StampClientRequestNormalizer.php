<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Exception\StampReplayException;

final class StampClientRequestNormalizer
{
	public static function normalize(?string $raw): ?string
	{
		if ($raw === null) {
			return null;
		}
		$id = trim($raw);
		if ($id === '') {
			return null;
		}
		if (strlen($id) > 64 || preg_match('/^[A-Za-z0-9._:-]+$/', $id) !== 1) {
			throw new StampReplayException('STAMP_CLIENT_REQUEST_ID_INVALID');
		}
		return $id;
	}
}
