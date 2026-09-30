<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Service\AbsenceIcalMailService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IAttachment;
use OCP\Mail\IMessage;
use OCP\Mail\IMailer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AbsenceIcalMailServiceTest extends TestCase
{
	private IMailer&MockObject $mailer;
	private IConfig&MockObject $config;
	private IUserManager&MockObject $userManager;
	private TeamResolverService&MockObject $teamResolver;
	private AbsenceIcalMailService $service;

	/** @var array<string,string> */
	private array $appValues = [];
	/** @var list<IMessage&MockObject> */
	private array $messages = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->mailer = $this->createMock(IMailer::class);
		$this->config = $this->createMock(IConfig::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $t, array $p = []) => $t . '|' . implode(',', $p)
		);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->teamResolver = $this->createMock(TeamResolverService::class);

		$this->appValues = [
			'send_ical_notification' => '1',
			'send_ical_to_substitute' => '1',
			'send_ical_to_managers' => '1',
		];
		$this->config->method('getAppValue')->willReturnCallback(
			fn ($app, $key, $default = '') => $this->appValues[$key] ?? $default
		);
		$this->config->method('getSystemValue')->willReturnCallback(
			static fn ($key, $default = '') => $key === 'mail_from_address' ? 'noreply' : $default
		);

		$this->mailer->method('validateMailAddress')->willReturnCallback(
			static fn (string $a) => str_contains($a, '@')
		);
		$this->messages = [];
		$this->mailer->method('createMessage')->willReturnCallback(function () {
			$m = $this->createMock(IMessage::class);
			$this->messages[] = $m;
			return $m;
		});
		$this->mailer->method('createAttachment')->willReturn($this->createMock(IAttachment::class));

		$this->service = new AbsenceIcalMailService(
			$this->mailer, $this->config, $l10n, $this->userManager, $this->teamResolver
		);
	}

	private function absence(string $status = Absence::STATUS_APPROVED, string $type = Absence::TYPE_VACATION): Absence
	{
		$a = new Absence();
		$a->setUserId('alice');
		$a->setType($type);
		$a->setStatus($status);
		$a->setStartDate(new \DateTime('2026-04-10'));
		$a->setEndDate(new \DateTime('2026-04-12'));
		$a->setSubstituteUserId('bob');
		return $a;
	}

	private function user(string $uid, string $email, bool $enabled = true): IUser&MockObject
	{
		$u = $this->createMock(IUser::class);
		$u->method('isEnabled')->willReturn($enabled);
		$u->method('getEMailAddress')->willReturn($email);
		$u->method('getDisplayName')->willReturn('DN ' . $uid);
		return $u;
	}

	public function testSendIcalForApprovedAbsenceSendsToAllRecipientsWithDedup(): void
	{
		$this->teamResolver->method('getManagerIdsForEmployee')->willReturn(['boss']);
		$this->userManager->method('get')->willReturnMap([
			['alice', $this->user('alice', 'alice@x.de')],
			['bob', $this->user('bob', 'bob@x.de')],
			// duplicate email → dedup prevents a second mail
			['boss', $this->user('boss', 'alice@x.de')],
		]);

		$subjects = [];
		$this->mailer->expects($this->exactly(2))->method('send')
			->willReturnCallback(function (IMessage $m) { });

		$this->service->sendIcalForApprovedAbsence($this->absence());

		$this->assertCount(2, $this->messages);
	}

	public function testSendIcalSkipsNonApprovedAndSickLeaveAndDisabledConfig(): void
	{
		$this->mailer->expects($this->never())->method('send');

		$this->service->sendIcalForApprovedAbsence($this->absence(Absence::STATUS_PENDING));
		$this->service->sendIcalForApprovedAbsence($this->absence(Absence::STATUS_APPROVED, Absence::TYPE_SICK_LEAVE));
		$this->appValues['send_ical_notification'] = '0';
		$this->service->sendIcalForApprovedAbsence($this->absence());
	}

	public function testSendIcalToSubstituteOnSubstitutionApproval(): void
	{
		$this->userManager->method('get')->willReturnMap([
			['alice', $this->user('alice', 'alice@x.de')],
			['bob', $this->user('bob', 'bob@x.de')],
		]);
		$this->mailer->expects($this->once())->method('send');

		$this->service->sendIcalToSubstituteOnSubstitutionApproval(
			$this->absence(Absence::STATUS_PENDING)
		);
	}

	public function testSendIcalToSubstituteSkipsWhenFlagOffOrNoSubstitute(): void
	{
		$this->mailer->expects($this->never())->method('send');

		$this->appValues['send_ical_to_substitute'] = '0';
		$this->service->sendIcalToSubstituteOnSubstitutionApproval(
			$this->absence(Absence::STATUS_PENDING)
		);

		$this->appValues['send_ical_to_substitute'] = '1';
		$a = $this->absence(Absence::STATUS_PENDING);
		$a->setSubstituteUserId(null);
		$this->service->sendIcalToSubstituteOnSubstitutionApproval($a);
	}
}
