<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Constants;
use OCA\ArbeitszeitCheck\Support\TimePickerMinutes;
use PHPUnit\Framework\TestCase;

class TimePickerMinutesTest extends TestCase
{
	public function testDefaultStepIsOneMinute(): void
	{
		$opts = TimePickerMinutes::options();
		$this->assertSame('00', $opts[0]);
		$this->assertSame('01', $opts[1]);
		$this->assertSame('59', $opts[array_key_last($opts)]);
		$this->assertCount(60, $opts);
		$this->assertSame(Constants::TIME_PICKER_MINUTE_STEP, 1);
	}

	public function testFiveMinuteStepStillAvailable(): void
	{
		$opts = TimePickerMinutes::options(null, 5);
		$this->assertSame('00', $opts[0]);
		$this->assertSame('05', $opts[1]);
		$this->assertSame('55', $opts[array_key_last($opts)]);
		$this->assertCount(12, $opts);
	}

	public function testPreservesOddSelectedMinuteOnCoarseStep(): void
	{
		$opts = TimePickerMinutes::options('07', 5);
		$this->assertContains('07', $opts);
		$this->assertContains('05', $opts);
		$this->assertContains('10', $opts);
	}

	public function testIgnoresInvalidSelectedMinute(): void
	{
		$opts = TimePickerMinutes::options('99', 5);
		$this->assertNotContains('99', $opts);
		$this->assertCount(12, $opts);
	}
}
