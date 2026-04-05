<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants as AppConstants;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceCalendarMapper;
use OCA\ArbeitszeitCheck\Service\AbsenceCalendarSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Calendar\ICreateFromString;
use OCP\Calendar\IManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

class AbsenceCalendarSyncServiceTest extends TestCase
{
	private function buildL10nMocks(): array
	{
		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn ($s) => $s);
		$factory = $this->createMock(IFactory::class);
		$factory->method('getUserLanguage')->with($user)->willReturn('en');
		$factory->method('get')->with('arbeitszeitcheck', 'en', null)->willReturn($l10n);

		return [$factory, $userManager];
	}

	public function testSkipsWhenAppConfigDisabled(): void
	{
		$im = $this->createMock(IManager::class);
		$im->expects($this->never())->method('getCalendarsForPrincipal');

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with('arbeitszeitcheck', AppConstants::CONFIG_CALENDAR_SYNC_ABSENCES_ENABLED, '1')
			->willReturn('0');

		$mapper = $this->createMock(AbsenceCalendarMapper::class);
		$mapper->expects($this->never())->method('insert');

		[$factory, $userManager] = $this->buildL10nMocks();

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);
		$time->method('getDateTime')->willReturn(new \DateTime());

		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturn('http://localhost/');

		$svc = new AbsenceCalendarSyncService(
			$im,
			$mapper,
			$factory,
			$userManager,
			$time,
			$url,
			$config,
			null
		);

		$a = new Absence();
		$a->setId(1);
		$a->setUserId('u1');
		$a->setType(Absence::TYPE_VACATION);
		$a->setStartDate(new \DateTime('2025-01-02'));
		$a->setEndDate(new \DateTime('2025-01-03'));
		$a->setStatus(Absence::STATUS_APPROVED);

		$svc->syncApprovedAbsence($a);
	}

	public function testCreatesEventWhenEnabled(): void
	{
		$cal = $this->createMock(ICreateFromString::class);
		$cal->method('getPermissions')->willReturn(31);
		$cal->method('isDeleted')->willReturn(false);
		$cal->method('getKey')->willReturn('42');
		$cal->expects($this->once())->method('createFromString')->with(
			'arbeitszeitcheck-absence-5.ics',
			$this->stringContains('BEGIN:VCALENDAR')
		);

		$im = $this->createMock(IManager::class);
		$im->method('isEnabled')->willReturn(true);
		// Only the app-owned absence calendar may receive events (never arbitrary user calendars).
		$im->method('getCalendarsForPrincipal')->willReturnCallback(function (string $principal, array $uris = []) use ($cal) {
			if ($uris === [AppConstants::CALENDAR_URI_ABSENCES]) {
				return [$cal];
			}
			return [];
		});

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with('arbeitszeitcheck', AppConstants::CONFIG_CALENDAR_SYNC_ABSENCES_ENABLED, '1')
			->willReturn('1');

		$mapper = $this->createMock(AbsenceCalendarMapper::class);
		$mapper->method('findByAbsenceIdOrNull')->willReturn(null);
		$mapper->expects($this->once())->method('insert');

		[$factory, $userManager] = $this->buildL10nMocks();

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);
		$time->method('getDateTime')->willReturn(new \DateTime());

		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturn('http://localhost/');

		$svc = new AbsenceCalendarSyncService(
			$im,
			$mapper,
			$factory,
			$userManager,
			$time,
			$url,
			$config,
			null
		);

		$a = new Absence();
		$a->setId(5);
		$a->setUserId('u1');
		$a->setType(Absence::TYPE_VACATION);
		$a->setStartDate(new \DateTime('2025-06-01'));
		$a->setEndDate(new \DateTime('2025-06-02'));
		$a->setStatus(Absence::STATUS_APPROVED);

		$svc->syncApprovedAbsence($a);
	}

	public function testDoesNotWriteToArbitraryCalendarWhenDedicatedMissing(): void
	{
		$im = $this->createMock(IManager::class);
		$im->method('isEnabled')->willReturn(true);
		$im->method('getCalendarsForPrincipal')->willReturn([]);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with('arbeitszeitcheck', AppConstants::CONFIG_CALENDAR_SYNC_ABSENCES_ENABLED, '1')
			->willReturn('1');

		$mapper = $this->createMock(AbsenceCalendarMapper::class);
		$mapper->method('findByAbsenceIdOrNull')->willReturn(null);
		$mapper->expects($this->never())->method('insert');

		[$factory, $userManager] = $this->buildL10nMocks();

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1000000);
		$time->method('getDateTime')->willReturn(new \DateTime());

		$url = $this->createMock(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturn('http://localhost/');

		$svc = new AbsenceCalendarSyncService(
			$im,
			$mapper,
			$factory,
			$userManager,
			$time,
			$url,
			$config,
			null
		);

		$a = new Absence();
		$a->setId(9);
		$a->setUserId('u1');
		$a->setType(Absence::TYPE_VACATION);
		$a->setStartDate(new \DateTime('2025-06-01'));
		$a->setEndDate(new \DateTime('2025-06-02'));
		$a->setStatus(Absence::STATUS_APPROVED);

		$svc->syncApprovedAbsence($a);
	}
}
