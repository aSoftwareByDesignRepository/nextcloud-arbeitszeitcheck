<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Support;

use OCA\ArbeitszeitCheck\Exception\AccessDeniedException;
use OCA\ArbeitszeitCheck\Exception\NotAuthenticatedException;
use OCP\IL10N;

/**
 * Locale-stable Throwable → HTTP classification.
 *
 * The app once derived error status by strpos()-matching English phrase
 * fragments in $e->getMessage(). That silently breaks whenever the throw
 * is localized at throw time — a German "Zugriff verweigert." misses the
 * 'Access denied' needle and collapses to a 500 where the caller deserved
 * a 403. Status must come from the exception TYPE:
 *
 *   NotAuthenticatedException → 401
 *   AccessDeniedException     → 403
 *   BusinessRuleException     → 400 (user-safe message, catalog-boundary —
 *                                 checked by callers via instanceof)
 *
 * The classifier helpers keep a residual text fallback for legacy plain
 * \Exception throws on both conventions: the English source phrase AND its
 * rendering under the caller's locale are both tried, so an unconverted
 * throw still classifies correctly no matter which language it carries.
 */
final class ExceptionHttpStatus
{
	/**
	 * @param IL10N|null $l10n caller-scoped l10n for the residual
	 *                         localized-twin phrase check
	 */
	public static function isUnauthenticated(\Throwable $e, ?IL10N $l10n = null): bool
	{
		if ($e instanceof NotAuthenticatedException) {
			return true;
		}
		return self::messageMatches($e, 'User not authenticated', $l10n);
	}

	public static function isAccessDenied(\Throwable $e, ?IL10N $l10n = null): bool
	{
		if ($e instanceof AccessDeniedException) {
			return true;
		}
		return self::messageMatches($e, 'Access denied', $l10n);
	}

	/**
	 * True when a legacy plain-\Exception message contains the msgid or its
	 * current-locale rendering. Matching the translated twin keeps the
	 * residual path correct for localized-at-throw strings that predate the
	 * typed exceptions; msgids without a translation collapse to the
	 * English needle (t() pass-through), so the check stays idempotent.
	 */
	private static function messageMatches(\Throwable $e, string $msgid, ?IL10N $l10n): bool
	{
		$raw = (string)$e->getMessage();
		if ($raw === '') {
			return false;
		}
		if (stripos($raw, $msgid) !== false) {
			return true;
		}
		if ($l10n !== null) {
			try {
				$twin = $l10n->t($msgid);
			} catch (\Throwable) {
				return false;
			}
			if ($twin !== '' && $twin !== $msgid && stripos($raw, $twin) !== false) {
				return true;
			}
		}
		return false;
	}
}
