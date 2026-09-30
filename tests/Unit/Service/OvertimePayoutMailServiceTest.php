<?php

declare(strict_types=1);

/**
 * Unit tests for OvertimePayoutMailService — CONFIG_OVERTIME_PAYOUT_NOTIFY_EMAIL
 * opt-out gate (Atlas settings-matrix proof).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutMailService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\Mail\IMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OvertimePayoutMailServiceTest extends TestCase
{
	private function make(array $appValues = [], ?IMailer $mailer = null): OvertimePayoutMailService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($appValues): string {
				self::assertSame('arbeitszeitcheck', $app);
				return array_key_exists($key, $appValues) ? (string)$appValues[$key] : $default;
			}
		);
		$config->method('getSystemValue')->willReturnCallback(
			static fn (string $key, $default = '') => (string)$default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf(str_replace('%1$s', '%s', $text), $params)
		);
		return new OvertimePayoutMailService(
			$mailer ?? $this->createMock(IMailer::class),
			$config,
			$l10n,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testNotifyEmailOptOutSkipsMailer(): void
	{
		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('send');
		$mailer->expects($this->never())->method('createMessage');

		$service = $this->make([Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_EMAIL => '0'], $mailer);
		$user = $this->createMock(IUser::class);

		$this->assertFalse($service->sendEmployeePayoutConfirmation($user, ['calendar_year' => 2026, 'calendar_month' => 9]));
	}

	public function testNotifyEmailEnabledSendsMessage(): void
	{
		$message = $this->createMock(\OCP\Mail\IMessage::class);
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->with($message);

		$service = $this->make([Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_EMAIL => '1'], $mailer);
		$user = $this->createMock(IUser::class);
		$user->method('getEMailAddress')->willReturn('alice@example.com');
		$user->method('getDisplayName')->willReturn('Alice');
		$user->method('getUID')->willReturn('alice');

		$this->assertTrue($service->sendEmployeePayoutConfirmation($user, [
			'calendar_year' => 2026, 'calendar_month' => 9, 'hours_paid' => 4.5, 'id' => 7,
		]));
	}
}
