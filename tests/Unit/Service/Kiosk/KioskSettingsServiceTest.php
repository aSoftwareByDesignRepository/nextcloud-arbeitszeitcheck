<?php

declare(strict_types=1);

/**
 * Unit tests for KioskSettingsService — CONFIG_KIOSK_ENABLED and
 * CONFIG_KIOSK_RFID_SALT wiring (Atlas settings-matrix proof).
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service\Kiosk;

use OCA\ArbeitszeitCheck\AppInfo\Application;
use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskSettingsService;
use OCP\IConfig;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

class KioskSettingsServiceTest extends TestCase
{
	private function make(array $appValues = [], ?ILockingProvider $locking = null): KioskSettingsService
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, string $default = '') use ($appValues): string {
				self::assertSame(Application::APP_ID, $app);
				return array_key_exists($key, $appValues) ? (string)$appValues[$key] : $default;
			}
		);
		return new KioskSettingsService(
			$config,
			$locking ?? $this->createMock(ILockingProvider::class),
		);
	}

	public function testKioskDisabledByDefault(): void
	{
		$this->assertFalse($this->make()->isKioskEnabled());
	}

	public function testKioskEnabledHonoursConfigFlag(): void
	{
		$this->assertTrue($this->make([Constants::CONFIG_KIOSK_ENABLED => '1'])->isKioskEnabled());
		$this->assertFalse($this->make([Constants::CONFIG_KIOSK_ENABLED => 'yes'])->isKioskEnabled());
	}

	public function testRfidSaltReturnsExistingWithoutLock(): void
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->never())->method('acquireLock');
		$service = $this->make([Constants::CONFIG_KIOSK_RFID_SALT => 'deadbeef'], $locking);
		$this->assertSame('deadbeef', $service->getRfidSalt());
	}

	public function testRfidSaltBootstrapGeneratesHexUnderExclusiveLock(): void
	{
		$locking = $this->createMock(ILockingProvider::class);
		$locking->expects($this->once())->method('acquireLock')
			->with('arbeitszeitcheck/kiosk_rfid_salt', ILockingProvider::LOCK_EXCLUSIVE, $this->anything());
		$locking->expects($this->once())->method('releaseLock')
			->with('arbeitszeitcheck/kiosk_rfid_salt', ILockingProvider::LOCK_EXCLUSIVE);

		$written = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturn('');
		$config->expects($this->once())->method('setAppValue')
			->with(Application::APP_ID, Constants::CONFIG_KIOSK_RFID_SALT, $this->callback(
				static fn (string $v): bool => strlen($v) === 64 && ctype_xdigit($v)
			))
			->willReturnCallback(static function (string $app, string $key, string $value) use (&$written): void {
				$written[$key] = $value;
			});

		$service = new KioskSettingsService($config, $locking);
		$salt = $service->getRfidSalt();

		$this->assertSame(64, strlen($salt));
		$this->assertSame($salt, $written[Constants::CONFIG_KIOSK_RFID_SALT] ?? null);
	}

	public function testSetKioskEnabledWritesFlag(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->expects(self::once())
			->method('setAppValue')
			->with(Application::APP_ID, Constants::CONFIG_KIOSK_ENABLED, '1');
		$service = new KioskSettingsService($config, $this->createMock(ILockingProvider::class));
		$service->setKioskEnabled(true);
	}

	public function testIsUserKioskAllowed(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static fn (string $uid) => $uid === 'alice' ? '1' : '0'
		);
		$service = new KioskSettingsService($config, $this->createMock(ILockingProvider::class));
		self::assertTrue($service->isUserKioskAllowed('alice'));
		self::assertFalse($service->isUserKioskAllowed('bob'));
		self::assertFalse($service->isUserKioskAllowed(''));
	}

	public function testRfidLookupHashNormalizesAndHmacs(): void
	{
		$service = $this->make([Constants::CONFIG_KIOSK_RFID_SALT => str_repeat('ab', 16)]);
		$hash = $service->rfidLookupHash(' AA:bb:cc ');
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
		// normalization: separators/case-insensitive uid produce identical hash
		self::assertSame($hash, $service->rfidLookupHash('aabbcc'));
		self::assertNotSame($hash, $service->rfidLookupHash('ddeeff'));
	}
}