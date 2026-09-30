<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\MissingTimeEntryJob;
use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Db\AbsenceMapper;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Service\HolidayService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MissingTimeEntryJobTest extends TestCase
{
	private const USER_ID = 'user1';

	/** @var TimeEntryMapper&MockObject */
	private $timeEntryMapper;
	/** @var AbsenceMapper&MockObject */
	private $absenceMapper;
	/** @var HolidayService&MockObject */
	private $holidayService;
	/** @var NotificationService&MockObject */
	private $notificationService;
	/** @var IUserManager&MockObject */
	private $userManager;
	/** @var IConfig&MockObject */
	private $config;
	/** @var LoggerInterface&MockObject */
	private $logger;
	/** @var MissingTimeEntryJob */
	private $job;

	protected function setUp(): void
	{
		parent::setUp();
		$this->timeEntryMapper = $this->createMock(TimeEntryMapper::class);
		$this->absenceMapper = $this->createMock(AbsenceMapper::class);
		$this->holidayService = $this->createMock(HolidayService::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('isUserAllowedByAccessGroups')->willReturn(true);

		$this->job = new MissingTimeEntryJob(
			$this->createMock(ITimeFactory::class),
			$this->timeEntryMapper,
			$this->absenceMapper,
			$this->holidayService,
			$this->notificationService,
			$this->userManager,
			$this->config,
			$this->logger,
			$permissionService
		);
	}

	public function testRunReturnsEarlyOutsideTargetHour(): void
	{
		$previousTz = $this->setTimezoneWithHourNotNine();
		try {
			$this->userManager->expects($this->never())->method('callForAllUsers');

			$this->invokeRun();
		} finally {
			date_default_timezone_set($previousTz);
		}
	}

	public function testRunIteratesUsersDuringTargetHour(): void
	{
		$previousTz = $this->setTimezoneWithHourNine();
		try {
			$user = $this->createMock(\OCP\IUser::class);
			$user->method('getUID')->willReturn('u1');
			$user->method('isEnabled')->willReturn(true);

			$this->userManager->expects($this->once())->method('callForAllUsers')
				->willReturnCallback(static function (callable $cb) use ($user): void {
					$cb($user);
				});
			$this->notificationService->method('shouldSendMissingClockInReminder')->willReturn(false);

			$this->invokeRun();
			$this->addToAssertionCount(1);
		} finally {
			date_default_timezone_set($previousTz);
		}
	}

	public function testRunSendsAlertForMissingYesterdayEntry(): void
	{
		$previousTz = $this->setTimezoneWithHourNineAndWeekdayYesterday();
		try {
			$user = $this->createMock(\OCP\IUser::class);
			$user->method('getUID')->willReturn('u1');
			$user->method('isEnabled')->willReturn(true);

			$this->userManager->method('callForAllUsers')
				->willReturnCallback(static function (callable $cb) use ($user): void {
					$cb($user);
				});
			$this->notificationService->method('shouldSendMissingClockInReminder')->willReturn(true);
			$this->holidayService->method('isHolidayForUser')->willReturn(false);
			$this->absenceMapper->method('findByUserAndDateRange')->willReturn([]);
			$this->timeEntryMapper->method('findByUserAndDateRange')->willReturn([]);
			$this->config->method('getUserValue')->willReturn('');
			$this->config->expects($this->once())->method('setUserValue')
				->with('u1', 'arbeitszeitcheck', 'last_missing_clock_in_reminder_date', self::isType('string'));
			$this->notificationService->expects($this->once())->method('notifyMissingTimeEntry')
				->with('u1', self::isType('string'));
			$this->logger->expects($this->atLeastOnce())->method('info');

			$this->invokeRun();
		} finally {
			date_default_timezone_set($previousTz);
		}
	}

	public function testIsNonWorkingDayReturnsFalseForRegularWorkday(): void
	{
		$date = new \DateTime('2026-04-15 00:00:00'); // Wednesday
		$this->holidayService->expects($this->once())
			->method('isHolidayForUser')
			->willReturn(false);
		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([]);

		$this->assertFalse($this->invokeIsNonWorkingDay(self::USER_ID, $date));
	}

	public function testIsNonWorkingDayReturnsTrueWhenHoliday(): void
	{
		$date = new \DateTime('2026-04-15 00:00:00'); // Wednesday
		$this->holidayService->expects($this->once())
			->method('isHolidayForUser')
			->willReturn(true);
		$this->absenceMapper->expects($this->never())->method('findByUserAndDateRange');

		$this->assertTrue($this->invokeIsNonWorkingDay(self::USER_ID, $date));
	}

	public function testIsNonWorkingDayReturnsTrueWhenApprovedAbsenceExists(): void
	{
		$date = new \DateTime('2026-04-15 00:00:00'); // Wednesday
		$absence = new Absence();
		$absence->setStatus(Absence::STATUS_APPROVED);

		$this->holidayService->method('isHolidayForUser')->willReturn(false);
		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([$absence]);

		$this->assertTrue($this->invokeIsNonWorkingDay(self::USER_ID, $date));
	}

	public function testProcessUserForDateSkipsWhenDeduped(): void
	{
		$yesterday = new \DateTime('2026-04-15 00:00:00');
		$today = new \DateTime('2026-04-16 00:00:00');
		$dateKey = $yesterday->format('Y-m-d');

		$this->notificationService->expects($this->once())
			->method('shouldSendMissingClockInReminder')
			->with(self::USER_ID)
			->willReturn(true);
		$this->holidayService->expects($this->once())
			->method('isHolidayForUser')
			->willReturn(false);
		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([]);
		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([]);

		$this->config->expects($this->once())
			->method('getUserValue')
			->with(self::USER_ID, 'arbeitszeitcheck', 'last_missing_clock_in_reminder_date', '')
			->willReturn($dateKey);
		$this->config->expects($this->never())->method('setUserValue');
		$this->notificationService->expects($this->never())->method('notifyMissingTimeEntry');

		$this->assertFalse($this->invokeProcessUserForDate(self::USER_ID, $yesterday, $today));
	}

	public function testProcessUserForDateSendsWhenNotDeduped(): void
	{
		$yesterday = new \DateTime('2026-04-15 00:00:00');
		$today = new \DateTime('2026-04-16 00:00:00');
		$dateKey = $yesterday->format('Y-m-d');

		$this->notificationService->expects($this->once())
			->method('shouldSendMissingClockInReminder')
			->with(self::USER_ID)
			->willReturn(true);
		$this->holidayService->expects($this->once())
			->method('isHolidayForUser')
			->willReturn(false);
		$this->absenceMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([]);
		$this->timeEntryMapper->expects($this->once())
			->method('findByUserAndDateRange')
			->willReturn([]);

		$this->config->expects($this->once())
			->method('getUserValue')
			->willReturn('');
		$this->notificationService->expects($this->once())
			->method('notifyMissingTimeEntry')
			->with(self::USER_ID, $dateKey);
		$this->config->expects($this->once())
			->method('setUserValue')
			->with(self::USER_ID, 'arbeitszeitcheck', 'last_missing_clock_in_reminder_date', $dateKey);

		$this->assertTrue($this->invokeProcessUserForDate(self::USER_ID, $yesterday, $today));
	}

	private function invokeRun(): void
	{
		$reflection = new \ReflectionClass($this->job);
		$runMethod = $reflection->getMethod('run');
		$runMethod->setAccessible(true);
		$runMethod->invoke($this->job, null);
	}

	private function invokeIsNonWorkingDay(string $userId, \DateTimeInterface $date): bool
	{
		$reflection = new \ReflectionClass($this->job);
		$method = $reflection->getMethod('isNonWorkingDay');
		$method->setAccessible(true);
		return $method->invoke($this->job, $userId, $date);
	}

	private function invokeProcessUserForDate(string $userId, \DateTimeInterface $yesterday, \DateTimeInterface $today): bool
	{
		$reflection = new \ReflectionClass($this->job);
		$method = $reflection->getMethod('processUserForDate');
		$method->setAccessible(true);
		return $method->invoke($this->job, $userId, $yesterday, $today);
	}

	/**
	 * The alert path only fires when "yesterday" was a working day — pick a
	 * timezone where the wall clock is 9 AM *and* yesterday was Mon–Fri.
	 */
	private function setTimezoneWithHourNineAndWeekdayYesterday(): string
	{
		$previousTz = date_default_timezone_get();
		foreach (\DateTimeZone::listIdentifiers() as $timezone) {
			date_default_timezone_set($timezone);
			$yesterday = new \DateTime('-1 day');
			if ((int)date('G') === 9 && (int)$yesterday->format('N') <= 5) {
				return $previousTz;
			}
		}
		self::markTestSkipped('No timezone currently has 9 AM on a working-day boundary');
		return $previousTz;
	}

	private function setTimezoneWithHourNine(): string
	{
		$previousTz = date_default_timezone_get();
		foreach (\DateTimeZone::listIdentifiers() as $timezone) {
			date_default_timezone_set($timezone);
			if ((int)date('G') === 9) {
				return $previousTz;
			}
		}
		self::markTestSkipped('No timezone currently has wall clock at 9 AM');
		return $previousTz;
	}

	private function setTimezoneWithHourNotNine(): string
	{
		$previousTz = date_default_timezone_get();
		foreach (\DateTimeZone::listIdentifiers() as $timezone) {
			date_default_timezone_set($timezone);
			if ((int)date('G') !== 9) {
				return $previousTz;
			}
		}

		date_default_timezone_set($previousTz);
		self::fail('Could not set timezone with local hour != 9.');
	}
}

