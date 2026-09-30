<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\ManagerPendingApprovalMailService;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\TeamResolverService;
use OCA\ArbeitszeitCheck\Service\TimeCaptureMethodService;
use OCA\ArbeitszeitCheck\Util\AbsenceWorkingDaysResolver;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Broadcast coverage for the thin notify* methods: each builds a fluent
 * INotification for the right user and hands it to the notification manager.
 * Gate branches (feature flags, manager resolution) are asserted separately.
 */
final class NotificationServiceBroadcastTest extends TestCase
{
	private INotificationManager&MockObject $notificationManager;
	private IL10N&MockObject $l10n;
	private UserSettingsMapper&MockObject $userSettingsMapper;
	private IUserManager&MockObject $userManager;
	private IConfig&MockObject $config;
	private TeamResolverService&MockObject $teamResolver;
	private ManagerPendingApprovalMailService&MockObject $managerPendingMail;
	private NotificationService $service;

	/** @var array<string,string> */
	private array $appValues = [];
	/** @var list<string> */
	private array $managerIds = ['manager1'];
	/** @var array<string,string> keyed by setting key */
	private array $userStringSettings = ['manager_id' => 'legacy-mgr'];
	private ?IUser $managerUser = null;

	protected function setUp(): void
	{
		parent::setUp();

		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->userSettingsMapper = $this->createMock(UserSettingsMapper::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->config = $this->createMock(IConfig::class);
		$this->teamResolver = $this->createMock(TeamResolverService::class);
		$this->managerPendingMail = $this->createMock(ManagerPendingApprovalMailService::class);
		$workingDaysResolver = $this->createMock(AbsenceWorkingDaysResolver::class);
		$timeCaptureMethodService = $this->createMock(TimeCaptureMethodService::class);

		$this->config->method('getAppValue')->willReturnCallback(
			fn ($app, $key, $default = '') => $this->appValues[$key] ?? $default
		);
		$this->l10n->method('t')->willReturnCallback(
			static fn (string $text, array $params = []) => empty($params) ? $text : vsprintf($text, $params)
		);
		$this->teamResolver->method('getManagerIdsForEmployee')->willReturnCallback(
			fn () => $this->managerIds
		);
		$this->userSettingsMapper->method('getStringSetting')->willReturnCallback(
			fn ($uid, $key, $default = '') => $this->userStringSettings[$key] ?? $default
		);
		$this->userManager->method('get')->willReturnCallback(function ($uid) {
			if ($this->managerUser !== null) {
				return $this->managerUser;
			}
			$u = $this->createMock(IUser::class);
			$u->method('isEnabled')->willReturn(true);
			$u->method('getUID')->willReturn($uid);
			return $u;
		});
		$workingDaysResolver->method('resolveFromNotificationParameters')->willReturnCallback(
			static fn (array $p): float => is_numeric($p['days'] ?? null) ? (float)$p['days'] : 0.0
		);

		$this->service = new NotificationService(
			$this->notificationManager,
			$this->l10n,
			$this->userSettingsMapper,
			$this->userManager,
			$this->config,
			$workingDaysResolver,
			$timeCaptureMethodService,
			$this->teamResolver,
			$this->managerPendingMail,
		);
	}

	private function fluentNotification(): INotification&MockObject
	{
		$n = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setMessage', 'setLink', 'setIcon'] as $m) {
			if (method_exists($n, $m)) {
				$n->method($m)->willReturnSelf();
			}
		}
		$this->notificationManager->method('createNotification')->willReturn($n);
		return $n;
	}

	private function expectsNotify(int $times = 1): void
	{
		$this->notificationManager->expects($this->exactly($times))->method('notify');
	}

	public function testNotifyComplianceViolationSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifyComplianceViolation('alice', ['rule' => 'max_daily', 'value' => 10.5]);
	}

	public function testNotifyComplianceViolationRespectsFeatureFlag(): void
	{
		$this->appValues['enable_violation_notifications'] = '0';
		$this->notificationManager->expects($this->never())->method('notify');
		$this->service->notifyComplianceViolation('alice', ['rule' => 'max_daily']);
	}

	public function testNotifySubstitutionRequestSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifySubstitutionRequest('sub', 'alice', [
			'id' => 3, 'type' => 'vacation', 'start_date' => '2025-02-01', 'end_date' => '2025-02-05', 'days' => 5,
		]);
	}

	public function testNotifySubstituteApprovedSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifySubstituteApproved('alice', 'sub', [
			'id' => 3, 'start_date' => '2025-02-01', 'end_date' => '2025-02-05',
		]);
	}

	public function testNotifySubstituteDeclinedSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifySubstituteDeclined('alice', 'sub', [
			'id' => 3, 'start_date' => '2025-02-01', 'end_date' => '2025-02-05',
		], 'no capacity');
	}

	public function testNotifyAbsenceRejectedSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifyAbsenceRejected('alice', [
			'id' => 3, 'type' => 'vacation', 'start_date' => '2025-02-01', 'end_date' => '2025-02-05',
		], 'staffing');
	}

	public function testNotifyClockOutReminderSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifyClockOutReminder('alice', ['id' => 7, 'start_time' => '08:00', 'hours_worked' => 9.5]);
	}

	public function testNotifyBreakReminderSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifyBreakReminder('alice', ['id' => 7, 'start_time' => '08:00', 'hours_worked' => 6.5]);
	}

	public function testNotifyMissingTimeEntrySends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifyMissingTimeEntry('alice', '2025-02-03');
	}

	public function testNotifyOvertimeWarningSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifyOvertimeWarning('alice', ['period' => '2025-01', 'overtime_hours' => 12.0, 'limit' => 10.0]);
	}

	public function testNotifyOvertimeTrafficLightSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifyOvertimeTrafficLight('alice', ['state' => 'red', 'direction' => 'over', 'level' => 'red', 'balance' => 45.0]);
	}

	public function testNotifyOvertimePayoutSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifyOvertimePayout('alice', [
			'calendar_year' => 2025, 'calendar_month' => 1, 'hours_paid' => 8.0, 'effective_balance_after' => 4.0, 'id' => 9,
		]);
	}

	public function testNotifyTimeEntryCorrectionApprovedSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifyTimeEntryCorrectionApproved('alice', ['id' => 7, 'date' => '2025-02-01', 'changes' => ['end_time']]);
	}

	public function testNotifyManagerWorkingTimeWarningSendsPerManager(): void
	{
		$this->fluentNotification();
		$this->expectsNotify(1);
		$this->service->notifyManagerWorkingTimeWarning('alice', 'daily_max', ['date' => '2025-02-01', 'message' => 'x', 'current_value' => 11, 'limit' => 10]);
	}

	public function testNotifyManagerWorkingTimeWarningSilentWithoutManager(): void
	{
		$this->managerIds = [];
		$this->userStringSettings = [];
		$this->notificationManager->expects($this->never())->method('notify');
		$this->service->notifyManagerWorkingTimeWarning('alice', 'daily_max', []);
	}

	public function testNotifyTimeEntryCorrectedByManagerSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifyTimeEntryCorrectedByManager('alice', ['id' => 7, 'startTime' => '2025-02-01 08:00'], 'typo');
	}

	public function testNotifyTimeEntryCorrectionRejectedSends(): void
	{
		$this->fluentNotification();
		$this->expectsNotify();
		$this->service->notifyTimeEntryCorrectionRejected('alice', ['id' => 7, 'date' => '2025-02-01'], 'incomplete');
	}

	public function testMarkNotificationProcessed(): void
	{
		$n = $this->fluentNotification();
		$this->notificationManager->expects($this->once())->method('markProcessed')->with($n);
		$this->service->markNotificationProcessed('alice', 'time_entry', '7');
	}
}
