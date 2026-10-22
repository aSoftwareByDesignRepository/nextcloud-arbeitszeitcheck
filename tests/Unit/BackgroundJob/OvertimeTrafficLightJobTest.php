<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\OvertimeTrafficLightJob;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\OvertimeDisplayService;
use OCA\ArbeitszeitCheck\Service\OvertimeNotificationMailService;
use OCA\ArbeitszeitCheck\Service\OvertimeTrafficLightService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class OvertimeTrafficLightJobTest extends TestCase
{
	public function testRunReturnsEarlyWhenTrafficLightDisabled(): void
	{
		$trafficLight = $this->createMock(OvertimeTrafficLightService::class);
		$trafficLight->expects($this->once())->method('isEnabled')->willReturn(false);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects($this->never())->method('callForAllUsers');

		$job = new OvertimeTrafficLightJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(OvertimeDisplayService::class),
			$trafficLight,
			$this->createMock(NotificationService::class),
			$this->createMock(OvertimeNotificationMailService::class),
			$userManager,
			$this->createMock(IConfig::class),
			$this->createMock(PermissionService::class),
			$this->createMock(LoggerInterface::class),
		);

		$ref = new ReflectionClass($job);
		$method = $ref->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	public function testRunScansUsersWhenEnabled(): void
	{
		$trafficLight = $this->createMock(OvertimeTrafficLightService::class);
		$trafficLight->expects($this->once())->method('isEnabled')->willReturn(true);
		$trafficLight->method('getThresholds')->willReturn([
			'yellow' => 10.0,
			'red' => 20.0,
		]);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');

		$userManager = $this->createMock(IUserManager::class);
		$userManager->expects($this->once())->method('callForAllUsers');

		$job = new OvertimeTrafficLightJob(
			$this->createMock(ITimeFactory::class),
			$this->createMock(OvertimeDisplayService::class),
			$trafficLight,
			$this->createMock(NotificationService::class),
			$this->createMock(OvertimeNotificationMailService::class),
			$userManager,
			$config,
			$this->createMock(PermissionService::class),
			$this->createMock(LoggerInterface::class),
		);

		$ref = new ReflectionClass($job);
		$method = $ref->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	public function testRunNotifiesOnRedEscalationWithinMatrix(): void
	{
		$trafficLight = $this->createMock(OvertimeTrafficLightService::class);
		$trafficLight->method('isEnabled')->willReturn(true);
		$trafficLight->method('getThresholds')->willReturn(['yellow' => 10.0, 'red' => 20.0]);
		$trafficLight->method('classify')->willReturn([
			'state' => 'red',
			'direction' => 'over',
			'level' => 'red',
		]);

		$user = $this->createMock(\OCP\IUser::class);
		$user->method('getUID')->willReturn('u1');
		$user->method('isEnabled')->willReturn(true);

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForAllUsers')
			->willReturnCallback(static function (callable $cb) use ($user): void {
				$cb($user);
			});

		$permission = $this->createMock(PermissionService::class);
		$permission->method('isUserAllowedByAccessGroups')->willReturn(true);

		$balance = $this->createMock(OvertimeDisplayService::class);
		$balance->method('getYearToDateBalanceForTrafficLight')->willReturn(25.0);

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				Constants::CONFIG_OVERTIME_NOTIFICATION_MATRIX_V1 => '{"over":{"red":true}}',
				Constants::CONFIG_OVERTIME_NOTIFICATION_RECIPIENTS => 'admin@example.org',
				default => $default,
			},
		);
		$config->method('getUserValue')->willReturn('green');
		$config->expects($this->once())->method('setUserValue')
			->with('u1', 'arbeitszeitcheck', 'overtime_traffic_light_last_state', 'red');

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->once())->method('notifyOvertimeTrafficLight')
			->with('u1', self::callback(static fn (array $p): bool => $p['state'] === 'red'));

		$mail = $this->createMock(OvertimeNotificationMailService::class);
		$mail->expects($this->once())->method('sendTrafficLightNotification')
			->with(['admin@example.org'], self::anything());

		$job = new OvertimeTrafficLightJob(
			$this->createMock(ITimeFactory::class),
			$balance,
			$trafficLight,
			$notifications,
			$mail,
			$userManager,
			$config,
			$permission,
			$this->createMock(LoggerInterface::class),
		);

		$method = (new ReflectionClass($job))->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}
}
