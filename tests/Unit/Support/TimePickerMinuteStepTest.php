<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Support\TimePickerMinuteStep;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;

class TimePickerMinuteStepTest extends TestCase
{
	public function testNormalizeAcceptsAllowedSteps(): void
	{
		foreach ([1, 5, 10, 15] as $step) {
			$this->assertSame($step, TimePickerMinuteStep::normalize($step));
		}
	}

	public function testNormalizeRejectsUnknown(): void
	{
		$this->assertSame(Constants::TIME_PICKER_MINUTE_STEP, TimePickerMinuteStep::normalize(7));
		$this->assertSame(Constants::TIME_PICKER_MINUTE_STEP, TimePickerMinuteStep::normalize(0));
	}

	public function testResolveReadsAppConfig(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())
			->method('getAppValue')
			->with('arbeitszeitcheck', Constants::CONFIG_TIME_PICKER_MINUTE_STEP, (string)Constants::TIME_PICKER_MINUTE_STEP)
			->willReturn('5');
		$this->assertSame(5, TimePickerMinuteStep::resolve($config));
	}
}
