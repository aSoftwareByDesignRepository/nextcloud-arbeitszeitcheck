<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Support\OfflineStampSkewPolicy;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class OfflineStampSkewPolicyTest extends TestCase
{
	public function testFromConfigReadsAndClamps(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $app, string $key, $default = '') {
				if ($app === 'arbeitszeitcheck' && $key === Constants::CONFIG_OFFLINE_STAMP_MAX_PAST_HOURS) {
					return '48';
				}
				return is_string($default) ? $default : (string)$default;
			}
		);
		$this->assertSame(48, OfflineStampSkewPolicy::fromConfig($config));
		$this->assertSame(72, OfflineStampSkewPolicy::fromAppConfigString('900'));
		$this->assertSame(24, OfflineStampSkewPolicy::fromAppConfigString('0'));
	}
}
