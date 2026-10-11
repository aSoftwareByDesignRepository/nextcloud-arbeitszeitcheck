<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Templates;

use PHPUnit\Framework\TestCase;

class DashboardVacationHoursLayoutContractTest extends TestCase
{
	public function testDashboardTemplateIsVacationUnitAware(): void
	{
		$src = file_get_contents(dirname(__DIR__, 2) . '/../templates/dashboard.php');
		$this->assertNotFalse($src);
		$this->assertStringContainsString('data-vacation-unit=', $src);
		$this->assertStringContainsString('id="dashboard-vacation-remaining-value"', $src);
		$this->assertStringContainsString('Remaining vacation hours', $src);
		$this->assertStringContainsString('dashboard-stat-card__value--hero', $src);
	}
}
