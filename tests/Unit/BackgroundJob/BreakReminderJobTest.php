<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\BreakReminderJob;
use OCA\ArbeitszeitCheck\Db\TimeEntryMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\TimeTrackingService;
use OCA\ArbeitszeitCheck\Support\LaborLawProfileFactory;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class BreakReminderJobTest extends TestCase
{
	/** @return array{0:string,1:bool} timezone + whether inside business hours */
	private function pickTimezone(bool $wantBusinessHours): array
	{
		$candidates = [
			'UTC',
			'Europe/Berlin',
			'America/New_York',
			'America/Los_Angeles',
			'Pacific/Honolulu',
			'Asia/Tokyo',
			'Asia/Dhaka',
			'Pacific/Auckland',
			'Atlantic/Azores',
			'Pacific/Kiritimati',
			'America/Adak',
			'Asia/Kathmandu',
		];
		foreach ($candidates as $tz) {
			$hour = (int)(new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('G');
			$inside = $hour >= 6 && $hour < 22;
			if ($inside === $wantBusinessHours) {
				return [$tz, $inside];
			}
		}
		$this->markTestSkipped('No timezone places wall clock in desired business-hours window');
	}

	public function testRunReturnsOutsideBusinessHoursWithoutScanningUsers(): void
	{
		[$tz] = $this->pickTimezone(false);
		$previousTz = date_default_timezone_get();
		date_default_timezone_set($tz);
		try {
			$userManager = $this->createMock(IUserManager::class);
			$userManager->expects($this->never())->method('callForAllUsers');

			$job = new BreakReminderJob(
				$this->createMock(ITimeFactory::class),
				$this->createMock(TimeEntryMapper::class),
				$this->createMock(UserSettingsMapper::class),
				$this->createMock(NotificationService::class),
				$userManager,
				$this->createMock(IConfig::class),
				$this->createMock(LoggerInterface::class),
				$this->createMock(PermissionService::class),
				$this->createMock(TimeTrackingService::class),
				$this->createMock(LaborLawProfileFactory::class),
			);

			$ref = new ReflectionClass($job);
			$method = $ref->getMethod('run');
			$method->setAccessible(true);
			$method->invoke($job, null);
			$this->assertTrue(true);
		} finally {
			date_default_timezone_set($previousTz);
		}
	}

	public function testRunInvokesDuringBusinessHours(): void
	{
		[$tz] = $this->pickTimezone(true);
		$previousTz = date_default_timezone_get();
		date_default_timezone_set($tz);
		try {
			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->atLeastOnce())->method('info');

			$userManager = $this->createMock(IUserManager::class);
			$userManager->expects($this->once())->method('callForAllUsers');

			$job = new BreakReminderJob(
				$this->createMock(ITimeFactory::class),
				$this->createMock(TimeEntryMapper::class),
				$this->createMock(UserSettingsMapper::class),
				$this->createMock(NotificationService::class),
				$userManager,
				$this->createMock(IConfig::class),
				$logger,
				$this->createMock(PermissionService::class),
				$this->createMock(TimeTrackingService::class),
				$this->createMock(LaborLawProfileFactory::class),
			);

			$ref = new ReflectionClass($job);
			$method = $ref->getMethod('run');
			$method->setAccessible(true);
			$method->invoke($job, null);
		} finally {
			date_default_timezone_set($previousTz);
		}
	}

	/**
	 * Personal setting dependency: notifications_enabled=off suppresses break reminders.
	 */
	public function testRunSkipsNotifyWhenNotificationsDisabled(): void
	{
		[$tz] = $this->pickTimezone(true);
		$previousTz = date_default_timezone_get();
		date_default_timezone_set($tz);
		try {
			$user = $this->createMock(\OCP\IUser::class);
			$user->method('getUID')->willReturn('u1');
			$user->method('isEnabled')->willReturn(true);

			$userManager = $this->createMock(IUserManager::class);
			$userManager->method('callForAllUsers')->willReturnCallback(
				static function (callable $cb) use ($user): void {
					$cb($user);
				}
			);

			$permission = $this->createMock(PermissionService::class);
			$permission->method('isUserAllowedByAccessGroups')->willReturn(true);

			$timeTracking = $this->createMock(TimeTrackingService::class);
			$timeTracking->method('enforceBreakAutoFallbackForUser')->willReturn(null);
			$timeTracking->method('enforceDailyMaximumForUser')->willReturn(null);

			$settings = $this->createMock(UserSettingsMapper::class);
			$settings->expects($this->once())
				->method('getBooleanSetting')
				->with('u1', 'notifications_enabled', true)
				->willReturn(false);

			$notifications = $this->createMock(NotificationService::class);
			$notifications->expects($this->never())->method('notifyBreakReminder');

			$mapper = $this->createMock(TimeEntryMapper::class);
			$mapper->method('findOnBreakByUser')->willReturn(null);
			$mapper->expects($this->never())->method('findActiveByUser');

			$job = new BreakReminderJob(
				$this->createMock(ITimeFactory::class),
				$mapper,
				$settings,
				$notifications,
				$userManager,
				$this->createMock(IConfig::class),
				$this->createMock(LoggerInterface::class),
				$permission,
				$timeTracking,
				$this->createMock(LaborLawProfileFactory::class),
			);

			$ref = new ReflectionClass($job);
			$method = $ref->getMethod('run');
			$method->setAccessible(true);
			$method->invoke($job, null);
		} finally {
			date_default_timezone_set($previousTz);
		}
	}

	/**
	 * Personal setting dependency: break_reminders_enabled=off suppresses notifyBreakReminder.
	 */
	public function testRunSkipsNotifyWhenBreakRemindersDisabled(): void
	{
		[$tz] = $this->pickTimezone(true);
		$previousTz = date_default_timezone_get();
		date_default_timezone_set($tz);
		try {
			$user = $this->createMock(\OCP\IUser::class);
			$user->method('getUID')->willReturn('u1');
			$user->method('isEnabled')->willReturn(true);

			$userManager = $this->createMock(IUserManager::class);
			$userManager->method('callForAllUsers')->willReturnCallback(
				static function (callable $cb) use ($user): void {
					$cb($user);
				}
			);

			$permission = $this->createMock(PermissionService::class);
			$permission->method('isUserAllowedByAccessGroups')->willReturn(true);

			$timeTracking = $this->createMock(TimeTrackingService::class);
			$timeTracking->method('enforceBreakAutoFallbackForUser')->willReturn(null);
			$timeTracking->method('enforceDailyMaximumForUser')->willReturn(null);

			$settings = $this->createMock(UserSettingsMapper::class);
			$settings->method('getBooleanSetting')->willReturnCallback(
				static function (string $userId, string $key, bool $default) {
					unset($userId, $default);
					return match ($key) {
						'notifications_enabled' => true,
						'break_reminders_enabled' => false,
						default => true,
					};
				}
			);

			$notifications = $this->createMock(NotificationService::class);
			$notifications->expects($this->never())->method('notifyBreakReminder');

			$mapper = $this->createMock(TimeEntryMapper::class);
			$mapper->method('findOnBreakByUser')->willReturn(null);
			$mapper->expects($this->never())->method('findActiveByUser');

			$job = new BreakReminderJob(
				$this->createMock(ITimeFactory::class),
				$mapper,
				$settings,
				$notifications,
				$userManager,
				$this->createMock(IConfig::class),
				$this->createMock(LoggerInterface::class),
				$permission,
				$timeTracking,
				$this->createMock(LaborLawProfileFactory::class),
			);

			$ref = new ReflectionClass($job);
			$method = $ref->getMethod('run');
			$method->setAccessible(true);
			$method->invoke($job, null);
		} finally {
			date_default_timezone_set($previousTz);
		}
	}

	/**
	 * Personal setting dependency ON: both notification prefs enabled → reminder fires.
	 */
	public function testRunNotifiesWhenBreakReminderPrefsEnabled(): void
	{
		[$tz] = $this->pickTimezone(true);
		$previousTz = date_default_timezone_get();
		date_default_timezone_set($tz);
		try {
			$user = $this->createMock(\OCP\IUser::class);
			$user->method('getUID')->willReturn('u1');
			$user->method('isEnabled')->willReturn(true);

			$userManager = $this->createMock(IUserManager::class);
			$userManager->method('callForAllUsers')->willReturnCallback(
				static function (callable $cb) use ($user): void {
					$cb($user);
				}
			);

			$permission = $this->createMock(PermissionService::class);
			$permission->method('isUserAllowedByAccessGroups')->willReturn(true);

			$timeTracking = $this->createMock(TimeTrackingService::class);
			$timeTracking->method('enforceBreakAutoFallbackForUser')->willReturn(null);
			$timeTracking->method('enforceDailyMaximumForUser')->willReturn(null);
			$timeTracking->method('getTodayHours')->willReturn(6.5);

			$settings = $this->createMock(UserSettingsMapper::class);
			$settings->method('getBooleanSetting')->willReturn(true);

			$entry = new \OCA\ArbeitszeitCheck\Db\TimeEntry();
			$entry->setId(99);

			$mapper = $this->createMock(TimeEntryMapper::class);
			$mapper->method('findOnBreakByUser')->willReturn(null);
			$mapper->method('findActiveByUser')->willReturn($entry);

			$lawConfig = $this->createMock(IConfig::class);
			$lawConfig->method('getAppValue')->willReturnCallback(
				static fn ($app, $key, $default) => $key === 'country' ? 'DE' : $default
			);
			$law = new LaborLawProfileFactory($lawConfig, null);

			$config = $this->createMock(IConfig::class);
			$config->method('getUserValue')->willReturn('0');
			$config->expects($this->once())->method('setUserValue');

			$notifications = $this->createMock(NotificationService::class);
			$notifications->expects($this->once())
				->method('notifyBreakReminder')
				->with('u1', $this->callback(static function (array $payload): bool {
					return ($payload['id'] ?? null) === 99
						&& ($payload['required_break_minutes'] ?? null) === 30;
				}));

			$job = new BreakReminderJob(
				$this->createMock(ITimeFactory::class),
				$mapper,
				$settings,
				$notifications,
				$userManager,
				$config,
				$this->createMock(LoggerInterface::class),
				$permission,
				$timeTracking,
				$law,
			);

			$ref = new ReflectionClass($job);
			$method = $ref->getMethod('run');
			$method->setAccessible(true);
			$method->invoke($job, null);
		} finally {
			date_default_timezone_set($previousTz);
		}
	}
}
