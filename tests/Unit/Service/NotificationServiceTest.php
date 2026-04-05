<?php

declare(strict_types=1);

/**
 * Unit tests for NotificationService
 *
 * @copyright Copyright (c) 2024
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase
{
	/** @var INotificationManager|MockObject */
	private $notificationManager;

	/** @var IL10N|MockObject */
	private $l10n;

	/** @var UserSettingsMapper|MockObject */
	private $userSettingsMapper;

	/** @var IUserManager|MockObject */
	private $userManager;

	/** @var IConfig|MockObject */
	private $config;

	/** @var NotificationService */
	private $service;

	protected function setUp(): void
	{
		parent::setUp();

		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default = '') => $default);

		// Simple translation passthrough
		$this->l10n->method('t')
			->willReturnCallback(static function (string $text, array $params = []) {
				if (!empty($params)) {
					return vsprintf($text, $params);
				}
				return $text;
			});

		$this->service = new NotificationService(
			$this->notificationManager,
			$this->l10n,
			$this->userSettingsMapper,
			$this->userManager,
			$this->config
		);
	}

	public function testNotifyTimeEntryCorrectionRequestedWithoutManagerDoesNothing(): void
	{
		$userId = 'user1';
		$timeEntryData = [
			'id' => 10,
			'date' => '2024-01-10'
		];
		$justification = 'Correction reason';

		$this->userSettingsMapper->expects($this->once())
			->method('getStringSetting')
			->with($userId, 'manager_id', '')
			->willReturn('');

		$this->userManager->expects($this->never())
			->method('get');

		$this->notificationManager->expects($this->never())
			->method('createNotification');

		$this->notificationManager->expects($this->never())
			->method('notify');

		$this->service->notifyTimeEntryCorrectionRequested($userId, $timeEntryData, $justification);
	}

	public function testNotifyTimeEntryCorrectionRequestedWithDisabledManagerDoesNothing(): void
	{
		$userId = 'user1';
		$timeEntryData = [
			'id' => 11,
			'date' => '2024-01-11'
		];
		$justification = 'Another reason';

		$this->userSettingsMapper->expects($this->once())
			->method('getStringSetting')
			->with($userId, 'manager_id', '')
			->willReturn('manager1');

		$manager = $this->createMock(IUser::class);
		$manager->method('isEnabled')->willReturn(false);

		$this->userManager->expects($this->once())
			->method('get')
			->with('manager1')
			->willReturn($manager);

		$this->notificationManager->expects($this->never())
			->method('createNotification');

		$this->notificationManager->expects($this->never())
			->method('notify');

		$this->service->notifyTimeEntryCorrectionRequested($userId, $timeEntryData, $justification);
	}

	public function testNotifyTimeEntryCorrectionRequestedWithValidManagerSendsNotification(): void
	{
		$userId = 'user1';
		$managerId = 'manager1';
		$timeEntryData = [
			'id' => 12,
			'date' => '2024-01-12'
		];
		$justification = 'Fix wrong end time';

		$this->userSettingsMapper->expects($this->once())
			->method('getStringSetting')
			->with($userId, 'manager_id', '')
			->willReturn($managerId);

		$manager = $this->createMock(IUser::class);
		$manager->method('isEnabled')->willReturn(true);
		$manager->method('getUID')->willReturn($managerId);

		$this->userManager->expects($this->once())
			->method('get')
			->with($managerId)
			->willReturn($manager);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$notification->method('setMessage')->willReturnSelf();

		$this->notificationManager->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		// Ensure notification is sent to the manager
		$notification->expects($this->once())
			->method('setUser')
			->with($managerId)
			->willReturnSelf();

		$this->notificationManager->expects($this->once())
			->method('notify')
			->with($notification);

		$this->service->notifyTimeEntryCorrectionRequested($userId, $timeEntryData, $justification);
	}
}

