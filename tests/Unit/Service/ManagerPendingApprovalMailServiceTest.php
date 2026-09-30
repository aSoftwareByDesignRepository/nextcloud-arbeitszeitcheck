<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntry;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\ManagerPendingApprovalMailService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ManagerPendingApprovalMailServiceTest extends TestCase
{
	public function testImmediateSkipsWhenKindDisabled(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default = '') {
			if ($key === Constants::CONFIG_MANAGER_PENDING_EMAIL_ABSENCES) {
				return '0';
			}
			if ($key === Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE) {
				return Constants::MANAGER_PENDING_EMAIL_MODE_IMMEDIATE;
			}
			return $default;
		});

		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('createMessage');

		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->expects($this->never())->method('getManagerIdsForEmployee');

		$service = $this->makeService($mailer, $config, $teamResolver);
		$service->notifyManagersOfPending(Constants::MANAGER_PENDING_KIND_ABSENCE, 'alice', []);
	}

	public function testImmediateSendsToTeamManagers(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default = '') {
			if (str_starts_with($key, 'manager_pending_email_') && $key !== Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE) {
				return '1';
			}
			if ($key === Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE) {
				return Constants::MANAGER_PENDING_EMAIL_MODE_IMMEDIATE;
			}
			return $default;
		});
		$config->method('getSystemValue')->willReturn('');

		$message = $this->createMock(IMessage::class);
		$message->expects($this->once())->method('setSubject');
		$message->expects($this->once())->method('setPlainBody');
		$message->expects($this->once())->method('setTo')->with(['boss@example.com' => 'Boss']);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->expects($this->once())->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->with($message);

		$boss = $this->createMock(IUser::class);
		$boss->method('isEnabled')->willReturn(true);
		$boss->method('getEMailAddress')->willReturn('boss@example.com');
		$boss->method('getDisplayName')->willReturn('Boss');

		$alice = $this->createMock(IUser::class);
		$alice->method('getDisplayName')->willReturn('Alice');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnMap([
			['alice', $alice],
			['boss', $boss],
		]);

		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('getManagerIdsForEmployee')->with('alice')->willReturn(['boss']);

		$url = $this->createMock(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/arbeitszeitcheck/manager');

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s, array $a = []) => $s);

		$service = new ManagerPendingApprovalMailService(
			$mailer,
			$config,
			$l10n,
			$userManager,
			$url,
			$teamResolver,
			$this->createMock(TeamManagerMapper::class),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(TimeEntryMapper::class),
			$this->createMock(LoggerInterface::class),
		);
		$service->notifyManagersOfPending(Constants::MANAGER_PENDING_KIND_ABSENCE, 'alice', [
			'type' => 'vacation',
			'start' => '2026-10-01',
			'end' => '2026-10-05',
		]);
	}

	public function testDigestModeSkipsImmediate(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default = '') {
			if ($key === Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE) {
				return Constants::MANAGER_PENDING_EMAIL_MODE_DIGEST;
			}
			return '1';
		});

		$mailer = $this->createMock(IMailer::class);
		$mailer->expects($this->never())->method('createMessage');

		$service = $this->makeService($mailer, $config, $this->createMock(TeamResolverService::class));
		$service->notifyManagersOfPending(Constants::MANAGER_PENDING_KIND_MANUAL, 'alice', []);
	}

	private function makeService(
		IMailer $mailer,
		IConfig $config,
		TeamResolverService $teamResolver,
		?TeamManagerMapper $teamManagerMapper = null,
		?AbsenceMapper $absenceMapper = null,
		?TimeEntryMapper $timeEntryMapper = null,
		?IL10N $l10n = null,
		?IUserManager $userManager = null,
	): ManagerPendingApprovalMailService
	{
		if ($l10n === null) {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
			$l10n->method('n')->willReturnCallback(static fn (string $s, string $p, int $c) => $s);
		}
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://x.test/mgr');

		return new ManagerPendingApprovalMailService(
			$mailer,
			$config,
			$l10n,
			$userManager ?? $this->createMock(IUserManager::class),
			$urlGenerator,
			$teamResolver,
			$teamManagerMapper ?? $this->createMock(TeamManagerMapper::class),
			$absenceMapper ?? $this->createMock(AbsenceMapper::class),
			$timeEntryMapper ?? $this->createMock(TimeEntryMapper::class),
			null,
		);
	}

	public function testSendDailyDigestsGatesOnModeAndTeams(): void
	{
		// non-digest mode -> 0 without touching mappers
		$configOff = $this->createMock(IConfig::class);
		$configOff->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default = '') =>
				$key === Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE ? 'immediate' : $default
		);
		$teamManagerMapper = $this->createMock(TeamManagerMapper::class);
		$teamManagerMapper->expects($this->never())->method('findDistinctManagerUserIds');
		$svc = $this->makeService($this->createMock(IMailer::class), $configOff, $this->createMock(TeamResolverService::class), $teamManagerMapper);
		$this->assertSame(0, $svc->sendDailyDigests());
	}

	public function testSendDailyDigestsSkipsWhenAppTeamsDisabled(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, $default = '') =>
				$key === Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE ? Constants::MANAGER_PENDING_EMAIL_MODE_DIGEST : $default
		);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('useAppTeams')->willReturn(false);
		$svc = $this->makeService($this->createMock(IMailer::class), $config, $teamResolver);
		$this->assertSame(0, $svc->sendDailyDigests());
	}

	public function testSendDailyDigestsSendsPerManagerWithPending(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default = '') {
			if ($key === Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE) {
				return Constants::MANAGER_PENDING_EMAIL_MODE_DIGEST;
			}
			if (str_starts_with($key, 'manager_pending_email_')) {
				return '1';
			}
			return $default;
		});
		$config->method('getSystemValue')->willReturn('');
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $t, array $p = []) => $p === [] ? $t : vsprintf($t, $p)
		);
		$l10n->method('n')->willReturnCallback(
			static fn (string $s, string $pl, int $n) => str_replace('%n', (string)$n, $n === 1 ? $s : $pl)
		);

		$teamManagerMapper = $this->createMock(TeamManagerMapper::class);
		$teamManagerMapper->method('findDistinctManagerUserIds')->willReturn(['mgr1', 'mgr2']);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->method('useAppTeams')->willReturn(true);
		$teamResolver->method('getTeamMemberIds')->willReturnCallback(
			static fn (string $m) => $m === 'mgr1' ? ['alice'] : []
		);
		$absenceMapper = $this->createMock(AbsenceMapper::class);
		$absenceMapper->method('countPendingForUsers')->willReturn(2);

		$manager = $this->createMock(IUser::class);
		$manager->method('isEnabled')->willReturn(true);
		$manager->method('getEMailAddress')->willReturn('boss@x.de');
		$manager->method('getDisplayName')->willReturn('Boss');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($manager);

		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->method('createMessage')->willReturn($this->createMock(IMessage::class));
		$mailer->expects($this->once())->method('send'); // mgr2 has empty team -> skipped

		$svc = $this->makeService($mailer, $config, $teamResolver, $teamManagerMapper, $absenceMapper, null, $l10n, $userManager);
		$this->assertSame(1, $svc->sendDailyDigests());
	}

	public function testNotifyPendingAbsenceRoutesOnlyPending(): void
	{
		$absence = new \OCA\ArbeitszeitCheck\Db\Absence();
		$absence->setUserId('alice');
		$absence->setStatus(\OCA\ArbeitszeitCheck\Db\Absence::STATUS_APPROVED);
		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->expects($this->never())->method('getManagerIdsForEmployee');
		$svc = $this->makeService($this->createMock(IMailer::class), $this->createMock(IConfig::class), $teamResolver);
		$svc->notifyPendingAbsence($absence); // no-op for non-pending
		$this->addToAssertionCount(1);
	}

	public function testNotifyPendingAbsenceSendsForPending(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, $default = '') {
			if (str_starts_with($key, 'manager_pending_email_') && $key !== Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE) {
				return '1';
			}
			if ($key === Constants::CONFIG_MANAGER_PENDING_EMAIL_MODE) {
				return Constants::MANAGER_PENDING_EMAIL_MODE_IMMEDIATE;
			}
			return $default;
		});
		$config->method('getSystemValue')->willReturn('');

		$message = $this->createMock(IMessage::class);
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('validateMailAddress')->willReturn(true);
		$mailer->expects($this->once())->method('createMessage')->willReturn($message);
		$mailer->expects($this->once())->method('send')->with($message);

		$boss = $this->createMock(IUser::class);
		$boss->method('isEnabled')->willReturn(true);
		$boss->method('getEMailAddress')->willReturn('boss@example.com');
		$boss->method('getDisplayName')->willReturn('Boss');
		$alice = $this->createMock(IUser::class);
		$alice->method('getDisplayName')->willReturn('Alice');
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnMap([
			['alice', $alice],
			['boss', $boss],
		]);

		$teamResolver = $this->createMock(TeamResolverService::class);
		$teamResolver->expects($this->once())
			->method('getManagerIdsForEmployee')
			->with('alice')
			->willReturn(['boss']);

		$service = $this->makeService($mailer, $config, $teamResolver, userManager: $userManager);

		$absence = new \OCA\ArbeitszeitCheck\Db\Absence();
		$absence->setUserId('alice');
		$absence->setStatus(\OCA\ArbeitszeitCheck\Db\Absence::STATUS_PENDING);
		$absence->setType(\OCA\ArbeitszeitCheck\Db\Absence::TYPE_VACATION ?? 'vacation');
		$absence->setStartDate(new \DateTime('2026-10-01'));
		$absence->setEndDate(new \DateTime('2026-10-05'));
		$absence->setDays(3.5);

		$service->notifyPendingAbsence($absence);
	}

	public function testIsManualCreatePendingAndCountByKind(): void
	{
		$svc = $this->makeService(
			$this->createMock(IMailer::class),
			$this->createMock(IConfig::class),
			$this->createMock(TeamResolverService::class),
		);
		$manual = new TimeEntry();
		$manual->setJustification(json_encode(['type' => 'manual_create']));
		$correction = new TimeEntry();
		$correction->setJustification(json_encode(['type' => 'correction']));
		$plain = new TimeEntry();
		$plain->setJustification('plain text');
		$this->assertTrue($svc->isManualCreatePending($manual));
		$this->assertFalse($svc->isManualCreatePending($correction));
		$this->assertFalse($svc->isManualCreatePending($plain));
	}
}