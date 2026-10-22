<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\HoursDisplay;
use PHPUnit\Framework\TestCase;

class HoursDisplayTest extends TestCase
{
	public function testNormalizeDefaultsToDecimal(): void
	{
		$this->assertSame(HoursDisplay::MODE_DECIMAL, HoursDisplay::normalize(null));
		$this->assertSame(HoursDisplay::MODE_DECIMAL, HoursDisplay::normalize(''));
		$this->assertSame(HoursDisplay::MODE_DECIMAL, HoursDisplay::normalize('bogus'));
		$this->assertSame(HoursDisplay::MODE_HOURS_MINUTES, HoursDisplay::normalize('hours_minutes'));
	}

	public function testFormatDecimalLegacy(): void
	{
		$this->assertSame('0', HoursDisplay::format(0.0, HoursDisplay::MODE_DECIMAL));
		$this->assertSame('5.5', HoursDisplay::format(5.5, HoursDisplay::MODE_DECIMAL));
		$this->assertSame('5.25', HoursDisplay::format(5.25, HoursDisplay::MODE_DECIMAL));
		$this->assertSame('8', HoursDisplay::format(8.0, HoursDisplay::MODE_DECIMAL));
	}

	public function testFormatHoursMinutes(): void
	{
		$this->assertSame('5h 30', HoursDisplay::format(5.5, HoursDisplay::MODE_HOURS_MINUTES));
		$this->assertSame('0h', HoursDisplay::format(0.0, HoursDisplay::MODE_HOURS_MINUTES));
		$this->assertSame('8h', HoursDisplay::format(8.0, HoursDisplay::MODE_HOURS_MINUTES));
		$this->assertSame('1h 01', HoursDisplay::format(1 + 1 / 60, HoursDisplay::MODE_HOURS_MINUTES));
	}
}
