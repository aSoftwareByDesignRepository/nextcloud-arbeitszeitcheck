<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\OvertimePayout;
use OCA\ArbeitszeitCheck\Db\OvertimePayoutMapper;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\NotificationService;
use OCA\ArbeitszeitCheck\Service\OvertimeBankService;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutMailService;
use OCA\ArbeitszeitCheck\Service\OvertimePayoutService;
use OCA\ArbeitszeitCheck\Service\PermissionService;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class OvertimePayoutServiceTest extends TestCase
{
	private function createService(
		OvertimeBankService $bank,
		OvertimePayoutMapper $payoutMapper,
		?AuditLogMapper $audit = null,
		?IUserManager $userManager = null,
	): OvertimePayoutService {
		return new OvertimePayoutService(
			$bank,
			$payoutMapper,
			$audit ?? $this->createMock(AuditLogMapper::class),
			$userManager ?? $this->createMock(IUserManager::class),
			$this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(PermissionService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(OvertimePayoutMailService::class),
			$this->createMock(IConfig::class),
		);
	}

	public function testProcessPayoutSkipsWhenAlreadyPaid(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);

		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('existsForUserAndMonth')->willReturn(true);

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$service = $this->createService($bank, $payoutMapper, null, $userManager);

		$result = $service->processPayout('user1', 2020, 6, 'admin', false);
		$this->assertSame('skipped_already_paid', $result['action']);
	}

	public function testProcessPayoutNotifiesDisabledAndNotifyFailureArms(): void
	{
		foreach ([
			// [notify_flag, notifyThrows, expectNotifyCalled]
			['0', false, false],  // in-app notify disabled -> notification skipped, mail still sent
			['1', true, true],    // notify enabled but throws -> caught, payout + mail proceed
		] as [$flag, $notifyThrows, $expectNotify]) {
			$bank = $this->createMock(OvertimeBankService::class);
			$bank->method('isEnabled')->willReturn(true);
			$bank->method('getMonthEndSnapshot')->willReturn([
				'raw_balance' => 110.0,
				'effective_balance' => 110.0,
				'payout_eligible_hours' => 10.0,
				'bank_max_hours' => 100.0,
				'total_payouts_before_month' => 0.0,
			]);
			$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
			$payoutMapper->method('existsForUserAndMonth')->willReturn(false);
			$payoutMapper->method('insertPayout')->willReturnCallback(static function ($e) {
				$e->setId(1);
				return $e;
			});
			$user = $this->createMock(IUser::class);
			$userManager = $this->createMock(IUserManager::class);
			$userManager->method('get')->willReturn($user);
			$audit = $this->createMock(AuditLogMapper::class);
			$audit->method('logAction');

			$notify = $this->createMock(NotificationService::class);
			$notify->expects($expectNotify ? $this->once() : $this->never())
				->method('notifyOvertimePayout');
			if ($notifyThrows) {
				$notify->method('notifyOvertimePayout')
					->willThrowException(new \RuntimeException('notify broken'));
			}

			$mail = $this->createMock(OvertimePayoutMailService::class);
			$mail->expects($this->once())->method('sendEmployeePayoutConfirmation');

			$config = $this->createMock(IConfig::class);
			$config->method('getAppValue')->willReturnCallback(
				static fn (string $app, string $key, string $default) => $key === Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_IN_APP ? $flag : $default
			);

			$service = new OvertimePayoutService(
				$bank, $payoutMapper, $audit, $userManager,
				$this->createMock(UserOvertimeSettingsService::class),
				$this->createMock(PermissionService::class),
				$notify, $mail, $config,
			);

			$result = $service->processPayout('user1', 2020, 6, 'admin', false);
			$this->assertSame('paid', $result['action']);
		}
	}

	public function testProcessPayoutRecordsEligibleHours(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);
		$bank->method('getMonthEndSnapshot')->willReturn([
			'raw_balance' => 110.0,
			'effective_balance' => 110.0,
			'payout_eligible_hours' => 10.0,
			'bank_max_hours' => 100.0,
			'total_payouts_before_month' => 0.0,
		]);

		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('existsForUserAndMonth')->willReturn(false);
		$saved = new OvertimePayout();
		$saved->setId(1);
		$saved->setCalendarYear(2020);
		$saved->setCalendarMonth(6);
		$saved->setHoursPaid(10.0);
		$saved->setEffectiveBalanceBefore(110.0);
		$saved->setEffectiveBalanceAfter(100.0);
		$payoutMapper->expects($this->once())->method('insertPayout')->willReturn($saved);

		$user = $this->createMock(IUser::class);
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturn($user);

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction');

		$notify = $this->createMock(NotificationService::class);
		$notify->expects($this->once())->method('notifyOvertimePayout');

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function (string $app, string $key, string $default) {
			if ($key === Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_IN_APP) {
				return '1';
			}

			return $default;
		});

		$service = new OvertimePayoutService(
			$bank,
			$payoutMapper,
			$audit,
			$userManager,
			$this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(PermissionService::class),
			$notify,
			$this->createMock(OvertimePayoutMailService::class),
			$config,
		);

		$result = $service->processPayout('user1', 2020, 6, 'admin', false);
		$this->assertSame('paid', $result['action']);
		$this->assertSame(10.0, $result['payout']['hours_paid']);
	}

	public function testProcessPayoutRejectsFutureMonth(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);

		$service = $this->createService(
			$bank,
			$this->createMock(OvertimePayoutMapper::class),
		);

		$futureMonth = (int)(new \DateTime('+2 months'))->format('n');
		$futureYear = (int)(new \DateTime('+2 months'))->format('Y');

		$this->expectException(\InvalidArgumentException::class);
		$service->processPayout('user1', $futureYear, $futureMonth, 'admin', false);
	}

	private function enabledUser(string $uid, string $displayName = ''): IUser
	{
		$u = $this->createMock(IUser::class);
		$u->method('isEnabled')->willReturn(true);
		$u->method('getUID')->willReturn($uid);
		$u->method('getDisplayName')->willReturn($displayName !== '' ? $displayName : 'DN ' . $uid);
		return $u;
	}

	public function testListMonthOverviewBuildsPaidAndPendingItems(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);
		$bank->method('getMonthEndSnapshot')->willReturn([
			'raw_balance' => 120.0,
			'effective_balance' => 120.0,
			'payout_eligible_hours' => 20.0,
			'bank_max_hours' => 100.0,
		]);

		$paid = new OvertimePayout();
		$paid->setId(7);
		$paid->setUserId('paid_user');
		$paid->setHoursPaid(5.0);
		$paid->setEffectiveBalanceBefore(105.0);
		$paid->setEffectiveBalanceAfter(100.0);
		$paid->setRawBalanceBefore(105.0);
		$paid->setBankMaxHours(100.0);
		$paid->setProcessedBy('admin');
		$paid->setCreatedAt(new \DateTime('2020-06-30 10:00:00'));

		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('findByYearAndMonth')->willReturn([$paid]);

		$users = [
			'pending_user' => $this->enabledUser('pending_user'),
			'paid_user' => $this->enabledUser('paid_user'),
			// disallowed and disabled users are filtered out of payroll scope
			'disallowed' => $this->enabledUser('disallowed'),
		];
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForAllUsers')->willReturnCallback(
			static function (callable $cb) use ($users): void {
				foreach ($users as $u) {
					$cb($u);
				}
			}
		);
		$userManager->method('get')->willReturnCallback(
			static fn (string $uid) => $users[$uid] ?? null
		);

		$perm = $this->createMock(PermissionService::class);
		$perm->method('isUserAllowedByAccessGroups')->willReturnCallback(
			static fn (string $uid) => $uid !== 'disallowed'
		);

		$service = new OvertimePayoutService(
			$bank, $payoutMapper, $this->createMock(AuditLogMapper::class),
			$userManager, $this->createMock(UserOvertimeSettingsService::class),
			$perm, $this->createMock(NotificationService::class),
			$this->createMock(OvertimePayoutMailService::class),
			$this->createMock(IConfig::class),
		);

		$out = $service->listMonthOverview(2020, 6);

		$this->assertSame(2, $out['meta']['total_users_in_scope']);
		$this->assertCount(2, $out['items']);
		$this->assertSame(1, $out['summary']['pending_count']);
		$this->assertSame(1, $out['summary']['paid_count']);
		$this->assertSame(20.0, $out['summary']['pending_hours']);
		// pending sorts before paid
		$this->assertSame('pending', $out['items'][0]['status']);
		$this->assertSame('pending_user', $out['items'][0]['user_id']);
		$this->assertSame('paid', $out['items'][1]['status']);
		$this->assertSame(7, $out['items'][1]['payout_id']);
	}

	public function testListPayoutHistoryForUserMapsPeriod(): void
	{
		$entity = new OvertimePayout();
		$entity->setId(3);
		$entity->setUserId('u1');
		$entity->setCalendarYear(2026);
		$entity->setCalendarMonth(2);
		$entity->setHoursPaid(4.5);

		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('findByUser')->willReturn([$entity]);
		$payoutMapper->method('countByUser')->willReturn(1);

		$service = $this->createService($this->createMock(OvertimeBankService::class), $payoutMapper);
		$out = $service->listPayoutHistoryForUser('u1');

		$this->assertSame(1, $out['total']);
		$this->assertSame('2026-02', $out['items'][0]['period']);
		$this->assertSame(4.5, $out['items'][0]['hours_paid']);
	}

	public function testBuildPayrollCsvEscapesAndSkipsNoneRows(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);
		$bank->method('getMonthEndSnapshot')->willReturn([
			'raw_balance' => 120.0,
			'effective_balance' => 120.0,
			'payout_eligible_hours' => 20.0,
			'bank_max_hours' => 100.0,
		]);

		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('findByYearAndMonth')->willReturn([]);

		// display name with ';' exercises quoting; '=cmd' exercises formula neutralization
		$evil = $this->enabledUser('evil', "=cmd|' /C calc';quoted");
		$plain = $this->enabledUser('plain', 'Plain Name');
		$users = ['evil' => $evil, 'plain' => $plain];
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForAllUsers')->willReturnCallback(
			static function (callable $cb) use ($users): void {
				foreach ($users as $u) {
					$cb($u);
				}
			}
		);
		$userManager->method('get')->willReturnCallback(static fn (string $u) => $users[$u] ?? null);

		$perm = $this->createMock(PermissionService::class);
		$perm->method('isUserAllowedByAccessGroups')->willReturn(true);

		$service = new OvertimePayoutService(
			$bank, $payoutMapper, $this->createMock(AuditLogMapper::class),
			$userManager, $this->createMock(UserOvertimeSettingsService::class),
			$perm, $this->createMock(NotificationService::class),
			$this->createMock(OvertimePayoutMailService::class),
			$this->createMock(IConfig::class),
		);

		$csv = $service->buildPayrollCsv(2020, 6);
		$this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
		$this->assertStringContainsString('user_id;display_name;status', $csv);
		// formula-injection neutralized with a leading quote
		$this->assertStringContainsString("'=cmd", $csv);
		// semicolon in name -> field is quoted
		$this->assertMatchesRegularExpression('/"[^"]*;[^"]*"/', $csv);
	}

	public function testProcessBulkPayoutsCountsMixedOutcomes(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);
		$bank->method('getMonthEndSnapshot')->willReturnCallback(
			static fn (string $uid) => $uid === 'zero_user'
				? ['raw_balance' => 5.0, 'effective_balance' => 5.0, 'payout_eligible_hours' => 0.0, 'bank_max_hours' => 100.0]
				: ['raw_balance' => 110.0, 'effective_balance' => 110.0, 'payout_eligible_hours' => 10.0, 'bank_max_hours' => 100.0]
		);

		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('existsForUserAndMonth')->willReturn(false);
		$payoutMapper->method('insertPayout')->willReturnCallback(static function (OvertimePayout $e) {
			$e->setId(9);
			return $e;
		});

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('get')->willReturnCallback(
			fn (string $uid) => $uid === 'ghost' ? null : $this->enabledUser($uid)
		);

		$service = new OvertimePayoutService(
			$bank, $payoutMapper, $this->createMock(AuditLogMapper::class),
			$userManager, $this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(PermissionService::class),
			$this->createMock(NotificationService::class),
			$this->createMock(OvertimePayoutMailService::class),
			$this->createMock(IConfig::class),
		);

		$out = $service->processBulkPayouts(2020, 6, 'admin', ['pay_user', 'zero_user', 'ghost'], false);

		$this->assertSame(1, $out['processed']);
		$this->assertSame(1, $out['skipped']);
		$this->assertSame(1, $out['errors']);
		$this->assertCount(3, $out['results']);
	}

	public function testPayoutNotificationRespectsInAppFlagAndMissingUser(): void
	{
		$bank = $this->createMock(OvertimeBankService::class);
		$bank->method('isEnabled')->willReturn(true);
		$bank->method('getMonthEndSnapshot')->willReturn([
			'raw_balance' => 110.0, 'effective_balance' => 110.0,
			'payout_eligible_hours' => 10.0, 'bank_max_hours' => 100.0,
		]);
		$payoutMapper = $this->createMock(OvertimePayoutMapper::class);
		$payoutMapper->method('existsForUserAndMonth')->willReturn(false);
		$payoutMapper->method('insertPayout')->willReturnCallback(static function (OvertimePayout $e) {
			$e->setId(1);
			return $e;
		});

		$userManager = $this->createMock(IUserManager::class);
		// payout proceeds for 'user1' but notify path finds no user -> mail skipped
		$calls = 0;
		$userManager->method('get')->willReturnCallback(
			function () use (&$calls) { return $calls++ === 0 ? $this->enabledUser('user1') : null; }
		);

		$notify = $this->createMock(NotificationService::class);
		$notify->expects($this->never())->method('notifyOvertimePayout');
		$mail = $this->createMock(OvertimePayoutMailService::class);
		$mail->expects($this->never())->method('sendEmployeePayoutConfirmation');

		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static fn ($app, $key, $default = '') => $key === Constants::CONFIG_OVERTIME_PAYOUT_NOTIFY_IN_APP ? '0' : $default
		);

		$service = new OvertimePayoutService(
			$bank, $payoutMapper, $this->createMock(AuditLogMapper::class),
			$userManager, $this->createMock(UserOvertimeSettingsService::class),
			$this->createMock(PermissionService::class), $notify, $mail, $config,
		);

		$result = $service->processPayout('user1', 2020, 6, 'admin', false);
		$this->assertSame('paid', $result['action']);
	}
}
