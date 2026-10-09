<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Service\Kiosk;

use OCP\AppFramework\Http;

/**
 * Single source of truth for kiosk API HTTP status codes.
 * Controller and middleware must stay in lockstep — never duplicate this match.
 */
final class KioskHttp
{
	public static function statusForCode(string $code): int
	{
		return match ($code) {
			'TERMINAL_LICENSE_REQUIRED' => Http::STATUS_PAYMENT_REQUIRED,
			'TERMINAL_DEVICE_LIMIT_REACHED',
			'KIOSK_USER_NOT_ALLOWED',
			'KIOSK_CLOCK_STAMPING_DISABLED' => Http::STATUS_FORBIDDEN,
			'KIOSK_RFID_ALREADY_ASSIGNED',
			'ENROLLMENT_ACTIVE',
			'KIOSK_BUSY',
			'KIOSK_ALREADY_CLOCKED_IN',
			'KIOSK_ON_BREAK_END_FIRST',
			'KIOSK_BREAK_ALREADY_STARTED' => Http::STATUS_CONFLICT,
			'ENROLLMENT_NOT_ACTIVE',
			'KIOSK_CREDENTIAL_NOT_FOUND',
			'KIOSK_TERMINAL_NOT_FOUND',
			'KIOSK_USER_NOT_FOUND',
			'PAIRING_CODE_INVALID',
			'KIOSK_CREDENTIAL_UNKNOWN',
			'KIOSK_DISABLED' => Http::STATUS_NOT_FOUND,
			'PIN_INVALID',
			'PIN_LOCKED',
			'KIOSK_SESSION_INVALID',
			'KIOSK_TERMINAL_UNAUTHORIZED' => Http::STATUS_UNAUTHORIZED,
			'KIOSK_RATE_LIMITED' => Http::STATUS_TOO_MANY_REQUESTS,
			'KIOSK_INTERNAL_ERROR' => Http::STATUS_INTERNAL_SERVER_ERROR,
			default => Http::STATUS_BAD_REQUEST,
		};
	}

	/** Soft auth failures that feed Nextcloud's bruteforce throttler. */
	public static function shouldRegisterBruteForceAttempt(string $code): bool
	{
		return in_array($code, [
			'PIN_INVALID',
			'PIN_LOCKED',
			'KIOSK_CREDENTIAL_UNKNOWN',
			'PAIRING_CODE_INVALID',
		], true);
	}
}
