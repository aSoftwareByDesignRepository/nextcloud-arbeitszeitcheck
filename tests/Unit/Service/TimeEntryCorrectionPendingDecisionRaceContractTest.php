<?php

declare(strict_types=1);

/**
 * Source contract: correction approve/reject must use the atomic pending guard.
 *
 * @copyright Copyright (c) 2026, Alexander Mäule / Software by Design
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;

class TimeEntryCorrectionPendingDecisionRaceContractTest extends TestCase
{
	public function testPersistPendingDecisionUsesStatusGuardedMapper(): void
	{
		$src = file_get_contents(__DIR__ . '/../../../lib/Service/TimeEntryCorrectionService.php');
		$this->assertNotFalse($src);

		$this->assertStringContainsString('updateIfPendingApproval', $src);
		$this->assertStringContainsString('ConcurrentDecisionException', $src);
		$this->assertStringContainsString('function persistPendingDecision', $src);

		$start = strpos($src, 'function persistPendingDecision');
		$this->assertNotFalse($start);
		$next = strpos($src, "\n\tpublic function ", $start + 10);
		$chunk = $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
		$this->assertStringContainsString('updateIfPendingApproval', $chunk);
		$this->assertStringNotContainsString('->update($entry)', $chunk);
	}
}
