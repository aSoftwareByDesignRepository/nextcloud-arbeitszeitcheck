<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\UserSettingsMapper;
use OCA\ArbeitszeitCheck\Service\UserEmploymentSettingsService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserEmploymentSettingsServiceTest extends TestCase
{
	private UserSettingsMapper&MockObject $settings;
	private AuditLogMapper&MockObject $audit;
	private UserEmploymentSettingsService $service;

	/** @var array<string, string> in-memory settings store keyed by setting key */
	private array $store = [];

	protected function setUp(): void
	{
		parent::setUp();
		$this->settings = $this->createMock(UserSettingsMapper::class);
		$this->audit = $this->createMock(AuditLogMapper::class);

		$this->settings->method('getStringSetting')
			->willReturnCallback(function (string $uid, string $key, string $default = ''): string {
				return $this->store[$key] ?? $default;
			});

		$this->service = new UserEmploymentSettingsService($this->settings, $this->audit);
	}

	public function testGetReturnsNullWhenUnset(): void
	{
		$this->assertNull($this->service->getEmploymentStart('alice'));
		$this->assertNull($this->service->getEmploymentEnd('alice'));
	}

	public function testGetParsesStoredDate(): void
	{
		$this->store[Constants::SETTING_EMPLOYMENT_START] = '2026-05-01';

		$start = $this->service->getEmploymentStart('alice');
		$this->assertInstanceOf(\DateTimeImmutable::class, $start);
		$this->assertSame('2026-05-01', $start->format('Y-m-d'));
		$this->assertSame('00:00:00', $start->format('H:i:s'));
	}

	public function testGetReturnsNullForCorruptStoredValue(): void
	{
		$this->store[Constants::SETTING_EMPLOYMENT_START] = 'not-a-date';
		$this->assertNull($this->service->getEmploymentStart('alice'));
	}

	public function testSetEmploymentStartPersistsAndAudits(): void
	{
		$this->settings->expects($this->once())
			->method('setSetting')
			->with('alice', Constants::SETTING_EMPLOYMENT_START, '2026-05-01');
		$this->audit->expects($this->once())
			->method('logAction')
			->with(
				'alice',
				'user_employment_start_updated',
				'user',
				null,
				$this->anything(),
				$this->anything(),
				'admin'
			);

		$this->service->setEmploymentStart('alice', new \DateTimeImmutable('2026-05-01'), 'admin');
	}

	public function testSetEmploymentStartNoopWhenUnchanged(): void
	{
		$this->store[Constants::SETTING_EMPLOYMENT_START] = '2026-05-01';
		$this->settings->expects($this->never())->method('setSetting');
		$this->settings->expects($this->never())->method('deleteSetting');
		$this->audit->expects($this->never())->method('logAction');

		$this->service->setEmploymentStart('alice', new \DateTimeImmutable('2026-05-01'), 'admin');
	}

	public function testSetEmploymentStartNullClearsSetting(): void
	{
		$this->store[Constants::SETTING_EMPLOYMENT_START] = '2026-05-01';
		$this->settings->expects($this->once())
			->method('deleteSetting')
			->with('alice', Constants::SETTING_EMPLOYMENT_START);
		$this->settings->expects($this->never())->method('setSetting');
		$this->audit->expects($this->once())->method('logAction');

		$this->service->setEmploymentStart('alice', null, 'admin');
	}

	public function testSetEmploymentStartAfterExistingEndThrows(): void
	{
		$this->store[Constants::SETTING_EMPLOYMENT_END] = '2026-03-01';
		$this->settings->expects($this->never())->method('setSetting');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setEmploymentStart('alice', new \DateTimeImmutable('2026-08-01'), 'admin');
	}

	public function testSetEmploymentEndBeforeExistingStartThrows(): void
	{
		$this->store[Constants::SETTING_EMPLOYMENT_START] = '2026-05-01';
		$this->settings->expects($this->never())->method('setSetting');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setEmploymentEnd('alice', new \DateTimeImmutable('2026-01-01'), 'admin');
	}

	public function testSetEmploymentEndPersistsAndClears(): void
	{
		$this->store[Constants::SETTING_EMPLOYMENT_START] = '2026-05-01';

		$this->settings->expects($this->once())
			->method('setSetting')
			->with('alice', Constants::SETTING_EMPLOYMENT_END, '2026-12-31')
			->willReturnCallback(function () {
				$this->store[Constants::SETTING_EMPLOYMENT_END] = '2026-12-31';
				$row = new \OCA\ArbeitszeitCheck\Db\UserSetting();
				$row->setUserId('alice');
				$row->setSettingKey(Constants::SETTING_EMPLOYMENT_END);
				$row->setSettingValue('2026-12-31');
				return $row;
			});
		$this->settings->expects($this->once())
			->method('deleteSetting')
			->with('alice', Constants::SETTING_EMPLOYMENT_END)
			->willReturnCallback(function () {
				unset($this->store[Constants::SETTING_EMPLOYMENT_END]);
			});
		$this->audit->expects($this->exactly(2))->method('logAction')
			->with('alice', 'user_employment_end_updated', 'user');

		$this->service->setEmploymentEnd('alice', new \DateTimeImmutable('2026-12-31'), 'admin');
		$this->assertSame('2026-12-31', $this->service->getEmploymentEnd('alice')?->format('Y-m-d'));

		// null clears the stored end date
		$this->service->setEmploymentEnd('alice', null, 'admin');
		$this->assertNull($this->service->getEmploymentEnd('alice'));
	}

	public function testSetEmploymentPeriodRejectsInvertedRange(): void
	{
		$this->settings->expects($this->never())->method('setSetting');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->setEmploymentPeriod(
			'alice',
			new \DateTimeImmutable('2026-08-01'),
			new \DateTimeImmutable('2026-03-01'),
			'admin'
		);
	}

	public function testSetEmploymentPeriodWritesBothEndpoints(): void
	{
		$writes = [];
		$this->settings->method('setSetting')
			->willReturnCallback(function (string $uid, string $key, ?string $value) use (&$writes) {
				$writes[$key] = $value;
				return $this->createMock(\OCA\ArbeitszeitCheck\Db\UserSetting::class);
			});

		$this->service->setEmploymentPeriod(
			'alice',
			new \DateTimeImmutable('2026-05-01'),
			new \DateTimeImmutable('2026-11-30'),
			'admin'
		);

		$this->assertSame('2026-05-01', $writes[Constants::SETTING_EMPLOYMENT_START] ?? null);
		$this->assertSame('2026-11-30', $writes[Constants::SETTING_EMPLOYMENT_END] ?? null);
	}
}
