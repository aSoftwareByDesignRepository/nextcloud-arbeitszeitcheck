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
use OCP\Lock\ILockingProvider;
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
				$this->createMock(ILockingProvider::class),
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
				$this->createMock(ILockingProvider::class),
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
				$this->createMock(ILockingProvider::class),
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
				$this->createMock(ILockingProvider::class),
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

			// Inert resolver built from this test's own config — the job's lazy
			// Server::get fallback would otherwise bind the LIVE dev database
			// (any saved org tier would leak in and change the expectation).
			$dateTimeZone = $this->createMock(\OCP\IDateTimeZone::class);
			$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
			$userSession = $this->createMock(\OCP\IUserSession::class);
			$userSession->method('getUser')->willReturn(null);
			$resolver = new \OCA\ArbeitszeitCheck\Support\BreakPolicyResolver(
				$lawConfig,
				$law,
				$this->createMock(UserSettingsMapper::class),
				$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$this->createMock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$mapper,
				new \OCA\ArbeitszeitCheck\Service\TimeZoneService(
					$lawConfig, $dateTimeZone, $userSession, new \Psr\Log\NullLogger()
				),
				$this->createMock(\OCP\IL10N::class),
			);

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
				$this->createMock(ILockingProvider::class),
				$resolver,
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
	 * Org policy tier (>6h → 60min) must tighten the reminder's required
	 * break minutes — the resolver funnel is the single source of truth.
	 */
	public function testRunUsesResolverOrgTierOverLaw(): void
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

			$policyConfig = $this->createMock(IConfig::class);
			$policyConfig->method('getAppValue')->willReturnCallback(
				static fn ($app, $key, $default) => match ($key) {
					'country' => 'DE',
					'app_timezone' => 'Europe/Berlin',
					\OCA\ArbeitszeitCheck\Constants::CONFIG_BREAK_POLICY_JSON => json_encode([
						'enforced' => true,
						'tiers' => [['afterHours' => 6, 'breakMinutes' => 60]],
					]),
					default => $default,
				}
			);
			$policyConfig->method('getUserValue')->willReturn('');

			$dateTimeZone = $this->createMock(\OCP\IDateTimeZone::class);
			$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
			$userSession = $this->createMock(\OCP\IUserSession::class);
			$userSession->method('getUser')->willReturn(null);
			$timeZoneService = new \OCA\ArbeitszeitCheck\Service\TimeZoneService(
				$policyConfig, $dateTimeZone, $userSession, new \Psr\Log\NullLogger()
			);

			$resolver = new \OCA\ArbeitszeitCheck\Support\BreakPolicyResolver(
				$policyConfig,
				new LaborLawProfileFactory($policyConfig, null),
				$this->createMock(UserSettingsMapper::class),
				$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$this->createMock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$mapper,
				$timeZoneService,
				$this->createMock(\OCP\IL10N::class),
			);

			$config = $this->createMock(IConfig::class);
			$config->method('getUserValue')->willReturn('0');
			$config->expects($this->once())->method('setUserValue');

			$notifications = $this->createMock(NotificationService::class);
			$notifications->expects($this->once())
				->method('notifyBreakReminder')
				->with('u1', $this->callback(static function (array $payload): bool {
					return ($payload['required_break_minutes'] ?? null) === 60;
				}));

			// Law profile would say 30min for 6.5h — asserting 60 proves the
			// resolver path (not the law fallback) drove the reminder.
			$lawProfileFactory = $this->createMock(LaborLawProfileFactory::class);
			$lawProfileFactory->expects($this->never())->method('getProfile');

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
				$lawProfileFactory,
				$this->createMock(ILockingProvider::class),
				$resolver,
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
	 * When the resolver throws, the job must fail open to the law profile —
	 * a reminder must never crash the whole background pass.
	 */
	public function testRunFallsBackToLawProfileWhenResolverThrows(): void
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

			// BreakPolicyResolver is final — build a real one whose internal
			// law-profile factory throws, so requiredBreakMinutes() propagates
			// and the job's fail-open fallback must kick in.
			$throwingLaw = $this->createMock(LaborLawProfileFactory::class);
			$throwingLaw->method('getProfile')->willThrowException(new \RuntimeException('db gone'));

			$tzConfig = $this->createMock(IConfig::class);
			$tzConfig->method('getAppValue')->willReturnCallback(
				static fn ($app, $key, $default) => $key === 'app_timezone' ? 'Europe/Berlin' : $default
			);
			$dateTimeZone = $this->createMock(\OCP\IDateTimeZone::class);
			$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
			$userSession = $this->createMock(\OCP\IUserSession::class);
			$userSession->method('getUser')->willReturn(null);

			$resolver = new \OCA\ArbeitszeitCheck\Support\BreakPolicyResolver(
				$tzConfig,
				$throwingLaw,
				$this->createMock(UserSettingsMapper::class),
				$this->createMock(\OCA\ArbeitszeitCheck\Db\UserWorkingTimeModelMapper::class),
				$this->createMock(\OCA\ArbeitszeitCheck\Db\WorkingTimeModelMapper::class),
				$mapper,
				new \OCA\ArbeitszeitCheck\Service\TimeZoneService(
					$tzConfig, $dateTimeZone, $userSession, new \Psr\Log\NullLogger()
				),
				$this->createMock(\OCP\IL10N::class),
			);

			$lawConfig = $this->createMock(IConfig::class);
			$lawConfig->method('getAppValue')->willReturnCallback(
				static fn ($app, $key, $default) => $key === 'country' ? 'DE' : $default
			);
			$law = new LaborLawProfileFactory($lawConfig, null);

			$logger = $this->createMock(LoggerInterface::class);
			$logger->expects($this->atLeastOnce())->method('warning');

			$config = $this->createMock(IConfig::class);
			$config->method('getUserValue')->willReturn('0');
			$config->expects($this->once())->method('setUserValue');

			$notifications = $this->createMock(NotificationService::class);
			$notifications->expects($this->once())
				->method('notifyBreakReminder')
				->with('u1', $this->callback(static function (array $payload): bool {
					return ($payload['required_break_minutes'] ?? null) === 30;
				}));

			$job = new BreakReminderJob(
				$this->createMock(ITimeFactory::class),
				$mapper,
				$settings,
				$notifications,
				$userManager,
				$config,
				$logger,
				$permission,
				$timeTracking,
				$law,
				$this->createMock(ILockingProvider::class),
				$resolver,
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
