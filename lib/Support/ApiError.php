<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Canonical API error envelopes.
 *
 * API-security rule §1: every ID-bearing route collapses "missing" and "not
 * accessible" into the SAME payload — per-entity messages are an existence
 * oracle. `fields` must serialize as a JSON object (`{}`), never `[]`.
 */
final class ApiError
{
	public static function notFound(): JSONResponse
	{
		return new JSONResponse([
			'success' => false,
			'error' => 'NOT_FOUND',
			'message' => 'Not found.',
			'fields' => new \stdClass(),
		], Http::STATUS_NOT_FOUND);
	}
}
