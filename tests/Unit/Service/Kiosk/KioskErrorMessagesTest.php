<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use OCA\ArbeitszeitCheck\Service\Kiosk\KioskErrorMessages;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskException;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

final class KioskErrorMessagesTest extends TestCase
{
	private function messages(): KioskErrorMessages
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, $params = []) => $params === []
				? $text
				: str_replace('{code}', (string)($params['code'] ?? ''), $text)
		);
		return new KioskErrorMessages($l10n);
	}

	public function testResolvePrefersDetailForBusinessRejections(): void
	{
		$svc = $this->messages();
		// detail-bearing codes surface the translated exception message
		$e = new KioskException('KIOSK_REST_PERIOD_REQUIRED', 'rest until 06:12');
		self::assertSame('rest until 06:12', $svc->resolve($e));

		$e2 = new KioskException('KIOSK_ACTION_REJECTED', 'rule x failed');
		self::assertSame('rule x failed', $svc->resolve($e2));

		$e3 = new KioskException('KIOSK_DAILY_HOURS_LIMIT', '10h reached');
		self::assertSame('10h reached', $svc->resolve($e3));
	}

	public function testResolveFallsBackToCatalogMessage(): void
	{
		$svc = $this->messages();
		// no detail message -> catalog copy for the code
		$e = new KioskException('KIOSK_SESSION_USED');
		self::assertSame('This kiosk session was already used. Identify again.', $svc->resolve($e));

		// detail that merely echoes the code -> still the catalog copy
		$e2 = new KioskException('PIN_LOCKED', 'PIN_LOCKED');
		self::assertSame('PIN is temporarily locked. Try again later.', $svc->resolve($e2));
	}

	public function testMessageCatalogArmsAndDefault(): void
	{
		$svc = $this->messages();
		self::assertStringContainsString('Terminal license', $svc->message('TERMINAL_LICENSE_REQUIRED'));
		self::assertStringContainsString('terminal license slots', $svc->message('TERMINAL_DEVICE_LIMIT_REACHED'));
		self::assertStringContainsString('Pairing code', $svc->message('PAIRING_CODE_INVALID'));
		self::assertStringContainsString('not allowed to use the kiosk', $svc->message('KIOSK_USER_NOT_ALLOWED'));
		self::assertStringContainsString('Clock in/out is not enabled', $svc->message('KIOSK_CLOCK_STAMPING_DISABLED'));
		self::assertStringContainsString('went wrong on the server', $svc->message('KIOSK_INTERNAL_ERROR'));
		self::assertStringContainsString('Too many attempts', $svc->message('KIOSK_RATE_LIMITED'));
		self::assertStringContainsString('already assigned', $svc->message('KIOSK_RFID_ALREADY_ASSIGNED'));
		self::assertStringContainsString('could not be read', $svc->message('KIOSK_RFID_INVALID'));
		self::assertStringContainsString('Credential not found', $svc->message('KIOSK_CREDENTIAL_NOT_FOUND'));
		self::assertStringContainsString('Badge not recognized', $svc->message('KIOSK_CREDENTIAL_UNKNOWN'));
		self::assertStringContainsString('Terminal not found', $svc->message('KIOSK_TERMINAL_NOT_FOUND'));
		self::assertStringContainsString('paired (active) tablet', $svc->message('KIOSK_TERMINAL_NOT_ACTIVE'));
		self::assertStringContainsString('no longer authorized', $svc->message('KIOSK_TERMINAL_UNAUTHORIZED'));
		self::assertStringContainsString('No badge scan is waiting', $svc->message('ENROLLMENT_NOT_ACTIVE'));
		self::assertStringContainsString('in progress', $svc->message('ENROLLMENT_ACTIVE'));
		self::assertStringContainsString('Cancel scan', $svc->message('KIOSK_BUSY'));
		self::assertStringContainsString('could not be saved', $svc->message('KIOSK_SCAN_FAILED'));
		self::assertStringContainsString('too large', $svc->message('KIOSK_IMPORT_TOO_LARGE'));
		self::assertStringContainsString('already used', $svc->message('KIOSK_SESSION_USED'));
		self::assertStringContainsString('Session expired', $svc->message('KIOSK_SESSION_INVALID'));
		self::assertStringContainsString('not allowed in the current state', $svc->message('KIOSK_ACTION_INVALID'));
		self::assertStringContainsString('could not be completed', $svc->message('KIOSK_ACTION_REJECTED'));
		self::assertStringContainsString('already clocked in', $svc->message('KIOSK_ALREADY_CLOCKED_IN'));
		self::assertStringContainsString('not clocked in', $svc->message('KIOSK_NOT_CLOCKED_IN'));
		self::assertStringContainsString('End your break', $svc->message('KIOSK_ON_BREAK_END_FIRST'));
		self::assertStringContainsString('break has already started', $svc->message('KIOSK_BREAK_ALREADY_STARTED'));
		self::assertStringContainsString('not on break', $svc->message('KIOSK_NOT_ON_BREAK'));
		self::assertStringContainsString('daily working hours', $svc->message('KIOSK_DAILY_HOURS_LIMIT'));
		self::assertStringContainsString('rest period', $svc->message('KIOSK_REST_PERIOD_REQUIRED'));
		self::assertStringContainsString('Kiosk mode is disabled', $svc->message('KIOSK_DISABLED'));
		self::assertStringContainsString('PIN is incorrect', $svc->message('PIN_INVALID'));
		self::assertStringContainsString('PIN is temporarily locked', $svc->message('PIN_LOCKED'));
		self::assertStringContainsString('month is finalized', $svc->message('MONTH_FINALIZED'));
		self::assertStringContainsString('Employee not found', $svc->message('KIOSK_USER_NOT_FOUND'));
		// default arm interpolates the code
		self::assertStringContainsString('MYSTERY_CODE', $svc->message('MYSTERY_CODE'));
		self::assertStringContainsString('unknown', $svc->message(''));
	}
}
