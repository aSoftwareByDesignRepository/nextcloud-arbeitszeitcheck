<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Service\OvertimeNotificationMailService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class OvertimeNotificationMailServiceTest extends TestCase
{
	private function service(IMailer $mailer, ?IConfig $config = null, ?IUserManager $users = null): OvertimeNotificationMailService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $t, $p = []) => is_array($p) && $p !== [] ? vsprintf($t, $p) : $t
		);
		return new OvertimeNotificationMailService(
			$mailer,
			$config ?? $this->createMock(IConfig::class),
			$l10n,
			$users ?? $this->createMock(IUserManager::class),
			new NullLogger(),
		);
	}

	public function testSendSkipsInvalidRecipientsAndDedupes(): void
	{
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(
			static fn (string $a) => str_contains($a, '@')
		);
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setTo')->with(['boss@x.de' => 'boss@x.de']);
		$mailer->method('createMessage')->willReturn($message);
		$template = $this->createMock(IEMailTemplate::class);
		$mailer->method('createEMailTemplate')->willReturn($template);
		$mailer->expects($this->once())->method('send')->with($message);

		$this->service($mailer)->sendTrafficLightNotification(
			[' BOSS@x.de ', 'boss@x.de', 'not-an-email', ''],
			['user_id' => 'alice', 'state' => 'red', 'direction' => 'up', 'level' => 'high', 'balance' => 12.345]
		);
	}

	public function testSendDoesNothingWhenAllRecipientsInvalid(): void
	{
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(false);
		$mailer->expects($this->never())->method('createMessage');
		$this->service($mailer)->sendTrafficLightNotification(['bad'], []);
	}

	public function testSendSetsFromAddressAndDisplayName(): void
	{
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setFrom')
			->with(['noreply@corp.example' => 'Time Corp']);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('createEMailTemplate')->willReturn($this->createMock(IEMailTemplate::class));
		$mailer->expects($this->once())->method('send');

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static fn (string $k, $d = '') => match ($k) {
			'mail_from_address' => 'noreply',
			'mail_domain' => 'corp.example',
			'mail_from_name' => 'Time Corp',
			default => $d,
		});
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn('Alice A.');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('alice')->willReturn($user);

		$this->service($mailer, $config, $users)->sendTrafficLightNotification(
			['boss@x.de'],
			['user_id' => 'alice', 'state' => 'red']
		);
	}

	public function testSetFromAppliedWhenConfigured(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValue')->willReturnCallback(static fn (string $k, $d = '') => match ($k) {
			'mail_from_address' => 'noreply',
			'mail_domain' => 'corp.example',
			'mail_from_name' => 'AZC Mailer',
			default => $d,
		});
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setFrom')
			->with(['noreply@corp.example' => 'AZC Mailer']);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('createEMailTemplate')->willReturn($this->createMock(IEMailTemplate::class));

		$this->service($mailer, $config)->sendTrafficLightNotification(
			['boss@x.de'],
			['user_id' => 'alice', 'state' => 'red', 'direction' => 'up', 'level' => 'high', 'balance' => 1.0]
		);
	}

	public function testSendFailurePerRecipientIsLoggedNotThrown(): void
	{
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$message = $this->createMock(IMessage::class);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->method('createEMailTemplate')->willReturn($this->createMock(IEMailTemplate::class));
		// first send throws, second succeeds — service continues
		$mailer->expects($this->exactly(2))->method('send')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new \RuntimeException('smtp down')),
				null
			);

		$this->service($mailer)->sendTrafficLightNotification(
			['a@x.de', 'b@x.de'],
			['user_id' => 'alice', 'state' => 'red', 'direction' => 'up', 'level' => 'high', 'balance' => 1.0]
		);
	}

	public function testRecipientCapAtMax(): void
	{
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->method('createEMailTemplate')->willReturn($this->createMock(IEMailTemplate::class));
		// MAX_RECIPIENTS = 20 — 25 submitted, capped at 20
		$mailer->expects($this->exactly(20))->method('send');

		$this->service($mailer)->sendTrafficLightNotification(
			array_map(static fn (int $i) => "u{$i}@x.de", range(1, 25)),
			['user_id' => 'u', 'state' => 'red', 'direction' => 'up', 'level' => 'high', 'balance' => 1.0]
		);
	}
}