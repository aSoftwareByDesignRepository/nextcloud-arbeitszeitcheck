<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Contract: manager dashboard uses SQL counts for full team (not first-10 loop for pending).
 */
final class ManagerDashboardPendingCountsContractTest extends TestCase
{
	public function testDashboardComputesPendingViaMapperCounts(): void
	{
		$src = (string)file_get_contents(__DIR__ . '/../../../lib/Controller/ManagerController.php');
		$dashboardPos = strpos($src, 'function dashboard');
		$this->assertNotFalse($dashboardPos);
		$chunk = substr($src, $dashboardPos, 3500);

		$this->assertStringContainsString('countPendingForUsers', $chunk);
		$this->assertStringContainsString('countPendingApprovalForUsers', $chunk);
		$this->assertStringContainsString("'pending_time_entries'", $chunk);
		$this->assertStringNotContainsString("\$teamStats['pending_absences'] += count(\$pendingAbsences)", $chunk);
	}

	public function testTemplateHasPendingTimeEntriesTile(): void
	{
		$tpl = (string)file_get_contents(__DIR__ . '/../../../templates/manager-dashboard.php');
		$this->assertStringContainsString('pending_time_entries', $tpl);
		$this->assertStringContainsString('data-manager-pending-tab="time-entries"', $tpl);
		$this->assertStringContainsString('Pending time entries', $tpl);
	}
}
