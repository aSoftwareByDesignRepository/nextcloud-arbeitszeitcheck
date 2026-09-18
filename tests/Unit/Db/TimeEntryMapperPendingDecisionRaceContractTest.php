<?php

declare(strict_types=1);

/**
 * Source contract: pending-approval decisions must be status-guarded writes.
 *
 * @copyright Copyright (c) 2026, Alexander Mäule / Software by Design
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use PHPUnit\Framework\TestCase;

class TimeEntryMapperPendingDecisionRaceContractTest extends TestCase
{
	public function testUpdateIfPendingApprovalGuardsOnPendingStatus(): void
	{
		$src = file_get_contents(__DIR__ . '/../../../lib/Db/TimeEntryMapper.php');
		$this->assertNotFalse($src);

		$start = strpos($src, 'function updateIfPendingApproval');
		$this->assertNotFalse($start, 'missing updateIfPendingApproval');
		$next = strpos($src, "\n\tpublic function ", $start + 10);
		$chunk = $next === false ? substr($src, $start) : substr($src, $start, $next - $start);

		$this->assertStringContainsString('STATUS_PENDING_APPROVAL', $chunk);
		$this->assertStringContainsString('andWhere', $chunk);
		$this->assertStringContainsString('executeStatement() === 1', $chunk);
	}
}
