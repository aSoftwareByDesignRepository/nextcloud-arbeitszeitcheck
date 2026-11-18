<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Support\Personalnummer;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class PersonalnummerTest extends TestCase
{
	private function configFor(array $byUser): IConfig
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			static function (string $uid, string $app, string $key, $default = '') use ($byUser) {
				if ($app === 'arbeitszeitcheck' && $key === Constants::USER_DATEV_PERSONALNUMMER) {
					return $byUser[$uid] ?? $default;
				}
				return is_string($default) ? $default : (string)$default;
			}
		);
		return $config;
	}

	public function testForUserReadsStoredValue(): void
	{
		$config = $this->configFor(['alice' => '42']);
		$this->assertSame('42', Personalnummer::forUser($config, 'alice'));
	}

	public function testForUserDefaultsEmptyWhenUnset(): void
	{
		$config = $this->configFor([]);
		$this->assertSame('', Personalnummer::forUser($config, 'nobody'));
	}

	public function testMapForUsersDeduplicatesAndPreservesMissing(): void
	{
		$config = $this->configFor(['alice' => '7', 'bob' => '0815']);
		$map = Personalnummer::mapForUsers($config, ['alice', 'bob', 'alice', 'carol']);
		$this->assertSame(['alice' => '7', 'bob' => '0815', 'carol' => ''], $map);
	}
}
