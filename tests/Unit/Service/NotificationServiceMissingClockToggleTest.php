<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Util\AbsenceWorkingDaysResolver;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;

/**
 * Explicit on-path for personal-missing_clock_in_reminders_enabled dependency toggle.
 * (Off-path covered in NotificationServiceTest::testShouldSendMissingClockInReminderUsesUserSettingWhenGlobalEnabled.)
 */
class NotificationServiceMissingClockToggleTest extends TestCase
{
	public function testShouldSendMissingClockInReminderReturnsTrueWhenUserToggleOn(): void
	{
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->with('user1')->willReturn($user);

		$capture = $this->createMock(TimeCaptureMethodService::class);
		$capture->method('isClockStampingEnabled')->with('user1')->willReturn(true);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with('arbeitszeitcheck', NotificationService::CONFIG_MISSING_CLOCK_IN_REMINDERS_ENABLED, '1')
			->willReturn('1');

		$settings = $this->createMock(UserSettingsMapper::class);
		$settings->expects($this->once())
			->method('getBooleanSetting')
			->with('user1', NotificationService::USER_SETTING_MISSING_CLOCK_IN_REMINDERS_ENABLED, true)
			->willReturn(true);

		$service = new NotificationService(
			$this->createMock(INotificationManager::class),
			$this->createMock(IL10N::class),
			$settings,
			$userManager,
			$config,
			$this->createMock(AbsenceWorkingDaysResolver::class),
			$capture,
		);

		$this->assertTrue($service->shouldSendMissingClockInReminder('user1'));
	}
}
