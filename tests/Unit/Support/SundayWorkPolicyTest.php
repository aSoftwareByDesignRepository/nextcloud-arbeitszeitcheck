<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Support\SundayWorkPolicy;
use OCA\ArbeitszeitCheck\Support\WeekdaySchedule;
use PHPUnit\Framework\TestCase;

class SundayWorkPolicyTest extends TestCase
{
	public function testLegacyAbsentMeansNotAllowed(): void
	{
		$this->assertFalse(SundayWorkPolicy::isAllowed(null));
		$this->assertFalse(SundayWorkPolicy::isAllowed([]));
		$this->assertFalse(SundayWorkPolicy::isAllowed(['break_policy' => 'flex']));
	}

	public function testExplicitFlagAllows(): void
	{
		$this->assertTrue(SundayWorkPolicy::isAllowed([SundayWorkPolicy::KEY => true]));
		$this->assertTrue(SundayWorkPolicy::isAllowed([SundayWorkPolicy::KEY => 1]));
	}

	public function testWeekdayScheduleSundayWorkAllows(): void
	{
		$raw = WeekdaySchedule::banssPreset();
		$raw['days']['sun'] = [
			'work' => true,
			'start' => '08:00',
			'end' => '16:00',
			'breaks' => [],
		];
		$this->assertTrue(SundayWorkPolicy::isAllowed([
			WeekdaySchedule::KEY => $raw,
		]));
	}

	public function testMergeIntoBreakRulesIsLegacySafe(): void
	{
		$with = SundayWorkPolicy::mergeIntoBreakRules(['x' => 1], true);
		$this->assertTrue($with[SundayWorkPolicy::KEY]);
		$this->assertSame(1, $with['x']);

		$without = SundayWorkPolicy::mergeIntoBreakRules($with, false);
		$this->assertArrayNotHasKey(SundayWorkPolicy::KEY, $without);
		$this->assertSame(1, $without['x']);
	}
}
