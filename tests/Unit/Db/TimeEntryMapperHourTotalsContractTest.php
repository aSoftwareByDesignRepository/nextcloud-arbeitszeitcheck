<?php

declare(strict_types=1);

/**
 * Source contract: hour-total helpers must not count pending four-eyes entries.
 *
 * @copyright Copyright (c) 2026, Alexander Mäule / Software by Design
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

class TimeEntryMapperHourTotalsContractTest extends TestCase
{
	public function testCompletedOnlyHourTotalsExcludePendingApproval(): void
	{
		$src = file_get_contents(__DIR__ . '/../../../lib/Db/TimeEntryMapper.php');
		$this->assertNotFalse($src);

		foreach (['getTotalHoursByUserAndDateRange', 'getTotalBreakHoursByUserAndDateRange'] as $method) {
			$start = strpos($src, "function {$method}");
			$this->assertNotFalse($start, "missing {$method}");
			$next = strpos($src, "\n\tpublic function ", $start + 10);
			$chunk = $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
			$this->assertStringContainsString('STATUS_COMPLETED', $chunk);
			$this->assertStringNotContainsString('STATUS_PENDING_APPROVAL', $chunk,
				"{$method} must not count pending four-eyes entries toward totals");
		}
	}
}
