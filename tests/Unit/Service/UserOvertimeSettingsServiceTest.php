<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserOvertimeYearBalance;
use OCA\ArbeitszeitCheck\Db\UserOvertimeYearBalanceMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\UserOvertimeSettingsService;
use PHPUnit\Framework\TestCase;

class UserOvertimeSettingsServiceTest extends TestCase
{
	public function testSetTrackingFromAuditsWithNullEntityId(): void
	{
		$userSettings = $this->createMock(UserSettingsMapper::class);
		$userSettings->method('getStringSetting')
			->with('alice', Constants::SETTING_OVERTIME_TRACKING_FROM, '')
			->willReturn('');
		$userSettings->expects($this->once())
			->method('setSetting')
			->with('alice', Constants::SETTING_OVERTIME_TRACKING_FROM, '2025-06-01');

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())
			->method('logAction')
			->with(
				'alice',
				'user_overtime_tracking_from_updated',
				'user',
				null,
				['tracking_from' => null],
				['tracking_from' => '2025-06-01'],
				'admin'
			);

		$service = new UserOvertimeSettingsService(
			$userSettings,
			$this->createMock(UserOvertimeYearBalanceMapper::class),
			$audit,
		);

		$service->setTrackingFrom(
			'alice',
			new \DateTimeImmutable('2025-06-01'),
			'admin'
		);
	}

	public function testSetOpeningBalanceAuditsWithNullEntityId(): void
	{
		$balanceMapper = $this->createMock(UserOvertimeYearBalanceMapper::class);
		$balanceMapper->method('getOpeningBalanceHours')->willReturn(0.0);
		$entity = new UserOvertimeYearBalance();
		$entity->setOpeningBalanceHours(12.5);
		$balanceMapper->method('upsert')->willReturn($entity);

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())
			->method('logAction')
			->with(
				'alice',
				'user_overtime_opening_balance_updated',
				'user',
				null,
				['year' => 2026, 'opening_balance_hours' => 0.0],
				['year' => 2026, 'opening_balance_hours' => 12.5],
				'admin'
			);

		$service = new UserOvertimeSettingsService(
			$this->createMock(UserSettingsMapper::class),
			$balanceMapper,
			$audit,
		);

		$service->setOpeningBalance('alice', 2026, 12.5, 'admin');
	}

	public function testResolveEffectiveYearStartClampsToTrackingFrom(): void
	{
		$userSettings = $this->createMock(UserSettingsMapper::class);
		$userSettings->method('getStringSetting')->willReturnCallback(
			static fn ($u, $k, $d = '') => '2025-06-15'
		);
		$service = new UserOvertimeSettingsService(
			$userSettings,
			$this->createMock(UserOvertimeYearBalanceMapper::class),
			$this->createMock(AuditLogMapper::class),
		);
		// tracking start mid-year -> effective start is the tracking date
		$this->assertSame('2025-06-15', $service->resolveEffectiveYearStart('alice', 2025)->format('Y-m-d'));
		// later year -> plain Jan 1
		$this->assertSame('2026-01-01', $service->resolveEffectiveYearStart('alice', 2026)->format('Y-m-d'));

		// no tracking date -> year start
		$userSettings2 = $this->createMock(UserSettingsMapper::class);
		$userSettings2->method('getStringSetting')->willReturn('');
		$service2 = new UserOvertimeSettingsService(
			$userSettings2,
			$this->createMock(UserOvertimeYearBalanceMapper::class),
			$this->createMock(AuditLogMapper::class),
		);
		$this->assertSame('2025-01-01', $service2->resolveEffectiveYearStart('alice', 2025)->format('Y-m-d'));
		$this->assertFalse($service2->hasTrackingFrom('alice'));
		$this->assertTrue($service->hasTrackingFrom('alice'));
	}

	public function testCountAndListUsersWithTrackingFromDelegate(): void
	{
		$userSettings = $this->createMock(UserSettingsMapper::class);
		$userSettings->method('countDistinctUsersWithNonEmptySetting')
			->with(Constants::SETTING_OVERTIME_TRACKING_FROM)->willReturn(7);
		$userSettings->method('findUserIdsWithNonEmptySetting')
			->with(Constants::SETTING_OVERTIME_TRACKING_FROM)->willReturn(['alice', 'bob']);
		$service = new UserOvertimeSettingsService(
			$userSettings,
			$this->createMock(UserOvertimeYearBalanceMapper::class),
			$this->createMock(AuditLogMapper::class),
		);
		$this->assertSame(7, $service->countUsersWithTrackingFrom());
		$this->assertSame(['alice', 'bob'], $service->listUserIdsWithTrackingFrom());
	}
}