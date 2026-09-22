<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TeamManagerMapper;
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

	private function makeService(IMailer $mailer, IConfig $config, TeamResolverService $teamResolver): ManagerPendingApprovalMailService
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $s) => $s);
		$l10n->method('n')->willReturnCallback(static fn (string $s, string $p, int $c) => $s);

		return new ManagerPendingApprovalMailService(
			$mailer,
			$config,
			$l10n,
			$this->createMock(IUserManager::class),
			$this->createMock(IURLGenerator::class),
			$teamResolver,
			$this->createMock(TeamManagerMapper::class),
			$this->createMock(AbsenceMapper::class),
			$this->createMock(TimeEntryMapper::class),
			null,
		);
	}
}
