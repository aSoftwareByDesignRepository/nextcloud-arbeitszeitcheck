<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Service\AbsenceNotificationMailService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;

class AbsenceNotificationMailServiceTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		if (!interface_exists(IMailer::class) || !interface_exists(IMessage::class)) {
			$this->markTestSkipped('Nextcloud OCP mail interfaces are not available in this isolated PHPUnit runtime.');
		}
	}

	public function testSendHrOfficeNotificationSendsToNormalizedRecipients(): void
	{
		$mailer = $this->createMock(IMailer::class);
		$config = $this->createMock(IConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$userManager = $this->createMock(IUserManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$message = $this->createMock(IMessage::class);

		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params));
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.local/link');
		$mailer->method('validateMailAddress')->willReturnCallback(static fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false);
		$mailer->method('createMessage')->willReturn($message);
		$mailer->expects($this->exactly(2))->method('send');

		$config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_HR_NOTIFICATIONS_ENABLED) {
					return '1';
				}
				if ($key === Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS) {
					return 'hr@example.com, HR@example.com, ops@example.com,invalid';
				}
				if ($key === Constants::CONFIG_HR_NOTIFICATION_MATRIX_V1) {
					return '{"vacation":{"request_created":true}}';
				}
				return $default;
			});

		$employee = $this->createMock(IUser::class);
		$employee->method('getDisplayName')->willReturn('Max Mustermann');
		$userManager->method('get')->willReturn($employee);

		$absence = new Absence();
		$absence->setUserId('employee1');
		$absence->setType('vacation');
		$absence->setStartDate(new \DateTime('2026-05-01'));
		$absence->setEndDate(new \DateTime('2026-05-03'));
		$absence->setDays(3.0);

		$service = new AbsenceNotificationMailService(
			$mailer,
			$config,
			$l10n,
			$userManager,
			$urlGenerator,
			$teamResolver
		);

		$service->sendHrOfficeNotification($absence, 'request_created', 'manager1');
	}

	public function testSendHrOfficeNotificationFormatsHalfDayWithoutIntCast(): void
	{
		$mailer = $this->createMock(IMailer::class);
		$config = $this->createMock(IConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$userManager = $this->createMock(IUserManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$message = $this->createMock(IMessage::class);

		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params));
		$l10n->method('n')->willReturnCallback(static function (string $singular, string $plural, int $count, array $params = []): string {
			return str_replace('%n', (string)$count, $count === 1 ? $singular : $plural);
		});
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://example.local/link');
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturn($message);

		$captured = [];
		$message->method('setPlainBody')->willReturnCallback(static function (string $body) use (&$captured, $message) {
			$captured[] = $body;
			return $message;
		});
		$mailer->expects($this->once())->method('send');

		$config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_HR_NOTIFICATIONS_ENABLED) {
					return '1';
				}
				if ($key === Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS) {
					return 'hr@example.com';
				}
				if ($key === Constants::CONFIG_HR_NOTIFICATION_MATRIX_V1) {
					return '{"vacation":{"request_created":true}}';
				}
				return $default;
			});

		$employee = $this->createMock(IUser::class);
		$employee->method('getDisplayName')->willReturn('Max Mustermann');
		$userManager->method('get')->willReturn($employee);

		$absence = new Absence();
		$absence->setUserId('employee1');
		$absence->setType('vacation');
		$absence->setStartDate(new \DateTime('2026-08-12'));
		$absence->setEndDate(new \DateTime('2026-08-12'));
		$absence->setDays(0.5);

		$service = new AbsenceNotificationMailService(
			$mailer,
			$config,
			$l10n,
			$userManager,
			$urlGenerator,
			$teamResolver
		);

		$service->sendHrOfficeNotification($absence, 'request_created', 'manager1');
		$this->assertNotEmpty($captured);
		$this->assertStringContainsString('0.5', $captured[0]);
		$this->assertStringNotContainsString("Days: 0\n", $captured[0]);
	}

	public function testSendHrOfficeNotificationSkipsWhenMatrixDisabled(): void
	{
		$mailer = $this->createMock(IMailer::class);
		$config = $this->createMock(IConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$userManager = $this->createMock(IUserManager::class);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$teamResolver = $this->createMock(TeamResolverService::class);

		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => $params === [] ? $text : vsprintf($text, $params));
		$config->method('getAppValue')
			->willReturnCallback(function (string $app, string $key, string $default = ''): string {
				if ($key === Constants::CONFIG_HR_NOTIFICATIONS_ENABLED) {
					return '1';
				}
				if ($key === Constants::CONFIG_HR_NOTIFICATION_RECIPIENTS) {
					return 'hr@example.com';
				}
				if ($key === Constants::CONFIG_HR_NOTIFICATION_MATRIX_V1) {
					return '{"vacation":{"request_created":false}}';
				}
				return $default;
			});

		$mailer->expects($this->never())->method('send');
		$mailer->expects($this->never())->method('createMessage');

		$absence = new Absence();
		$absence->setUserId('employee1');
		$absence->setType('vacation');
		$absence->setStartDate(new \DateTime('2026-05-01'));
		$absence->setEndDate(new \DateTime('2026-05-03'));
		$absence->setDays(3.0);

		$service = new AbsenceNotificationMailService(
			$mailer,
			$config,
			$l10n,
			$userManager,
			$urlGenerator,
			$teamResolver
		);

		$service->sendHrOfficeNotification($absence, 'request_created');
	}

	private function substituteFixture(array $configFlags = [], array $users = []): array
	{
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturnCallback(
			static fn (string $e) => filter_var($e, FILTER_VALIDATE_EMAIL) !== false
		);
		$message = $this->createMock(IMessage::class);
		$mailer->method('createMessage')->willReturn($message);
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => $configFlags[$key] ?? $default
		);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $t, array $p = []) => $p === [] ? $t : vsprintf($t, $p)
		);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			static fn (string $uid) => $users[$uid] ?? null
		);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://x.test/l');
		$teamResolver = $this->createMock(TeamResolverService::class);
		$service = new AbsenceNotificationMailService(
			$mailer, $config, $l10n, $userManager, $urlGenerator, $teamResolver
		);
		return [$service, $mailer, $teamResolver];
	}

	private function mkUser(string $email = 'u@x.de', bool $enabled = true): \OCP\IUser
	{
		$u = $this->createMock(IUser::class);
		$u->method('getEMailAddress')->willReturn($email);
		$u->method('isEnabled')->willReturn($enabled);
		$u->method('getDisplayName')->willReturn('Some User');
		return $u;
	}

	private function mkAbsenceWithSubstitute(): Absence
	{
		$absence = new Absence();
		$absence->setUserId('employee1');
		$absence->setSubstituteUserId('sub1');
		$absence->setType('vacation');
		$absence->setStartDate(new \DateTime('2026-05-01'));
		$absence->setEndDate(new \DateTime('2026-05-03'));
		$absence->setDays(3.0);
		return $absence;
	}

	public function testSendSubstitutionRequestToSubstituteSendsMail(): void
	{
		[$service, $mailer] = $this->substituteFixture(
			['send_email_substitution_request' => '1'],
			['sub1' => $this->mkUser('sub@x.de'), 'employee1' => $this->mkUser('e@x.de')]
		);
		$mailer->expects($this->once())->method('send');
		$service->sendSubstitutionRequestToSubstitute($this->mkAbsenceWithSubstitute());
	}

	public function testSendSubstitutionRequestSkipsWhenDisabledOrNoValidMail(): void
	{
		// disabled flag -> no send
		[$svcOff, $mailerOff] = $this->substituteFixture(
			['send_email_substitution_request' => '0'],
			['sub1' => $this->mkUser()]
		);
		$mailerOff->expects($this->never())->method('send');
		$svcOff->sendSubstitutionRequestToSubstitute($this->mkAbsenceWithSubstitute());

		// substitute has no usable email -> no send
		[$svcNoMail, $mailerNoMail] = $this->substituteFixture(
			['send_email_substitution_request' => '1'],
			['sub1' => $this->mkUser(''), 'employee1' => $this->mkUser()]
		);
		$mailerNoMail->expects($this->never())->method('send');
		$svcNoMail->sendSubstitutionRequestToSubstitute($this->mkAbsenceWithSubstitute());
	}

	public function testSendSubstituteApprovedToEmployeeSendsMail(): void
	{
		[$service, $mailer] = $this->substituteFixture(
			['send_email_substitute_approved_to_employee' => '1'],
			['employee1' => $this->mkUser('e@x.de'), 'sub1' => $this->mkUser('s@x.de')]
		);
		$mailer->expects($this->once())->method('send');
		$service->sendSubstituteApprovedToEmployee($this->mkAbsenceWithSubstitute());
	}

	public function testSendSubstituteApprovedToManagersSkipsInactiveAndDuplicates(): void
	{
		[$service, $mailer, $teamResolver] = $this->substituteFixture(
			['send_email_substitute_approved_to_manager' => '1'],
			[
				'employee1' => $this->mkUser('e@x.de'),
				'sub1' => $this->mkUser('s@x.de'),
				'mgr1' => $this->mkUser('m1@x.de'),
				'mgr-gone' => null, // filtered via get() returning null
				'mgr-disabled' => $this->mkUser('d@x.de', false),
			]
		);
		$teamResolver->method('getManagerIdsForEmployee')
			->willReturn(['mgr1', 'mgr1', 'mgr-disabled', 'mgr-gone']);
		// only mgr1 is enabled + valid -> exactly one mail
		$mailer->expects($this->once())->method('send');
		$service->sendSubstituteApprovedToManagers($this->mkAbsenceWithSubstitute());
	}
}