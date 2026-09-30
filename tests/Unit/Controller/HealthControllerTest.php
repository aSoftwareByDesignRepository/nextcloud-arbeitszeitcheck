<?php

declare(strict_types=1);

/**
 * Unit tests for HealthController
 *
 * Note: HealthController uses \OC::$server directly, making it difficult to unit test.
 * This controller is better tested via integration tests (see tests/integration/ApiTest.php).
 * 
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\HealthController;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCA\ArbeitszeitCheck\Service\ProjectCheckIntegrationService;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Class HealthControllerTest
 * 
 * HealthController is tested via integration tests due to its dependency on \OC::$server.
 * See tests/integration/ApiTest.php for comprehensive health check tests.
 */
class HealthControllerTest extends TestCase
{
	/**
	 * Placeholder test to ensure test file exists
	 * Actual testing is done in integration tests
	 */
	public function testHealthControllerExists(): void
	{
		$this->assertTrue(class_exists(HealthController::class));
	}

	private function controller(IDBConnection $db, ?ComplianceService $compliance = null, ?ProjectCheckIntegrationService $pc = null): HealthController
	{
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$l10n->method('n')->willReturnArgument(0);
		return new HealthController(
			'arbeitszeitcheck',
			$this->createMock(IRequest::class),
			$compliance ?? $this->createMock(ComplianceService::class),
			$pc ?? $this->createMock(ProjectCheckIntegrationService::class),
			$db,
			$l10n,
		);
	}

	public function testCheckHealthyWhenSchemaReadyAndQueryWorks(): void
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn('1');
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('executeQuery')->willReturn($result);

		$compliance = $this->createMock(ComplianceService::class);
		$compliance->method('isGermanPublicHoliday')->willReturn(true);
		$pc = $this->createMock(ProjectCheckIntegrationService::class);
		$pc->method('isProjectCheckAvailable')->willReturn(false);

		$r = $this->controller($db, $compliance, $pc)->check();
		$d = $r->getData();
		$this->assertSame('healthy', $d['status']);
		$this->assertSame('healthy', $d['services']['database']['status']);
		$this->assertSame('healthy', $d['services']['compliance']['status']);
		$this->assertArrayHasKey('projectcheck_integration', $d['services']);
	}

	public function testCheckReportsMissingTables(): void
	{
		$db = $this->createMock(IDBConnection::class);
		// every table missing -> schema not ready, no SELECT attempted
		$db->method('tableExists')->willReturn(false);
		$db->expects($this->never())->method('executeQuery');

		$r = $this->controller($db)->check();
		$d = $r->getData();
		$this->assertSame('degraded', $d['status']);
		$this->assertSame('unhealthy', $d['services']['database']['status']);
		$this->assertGreaterThan(0, $d['services']['database']['missing_tables']);
	}

	public function testCheckDegradedWhenComplianceFails(): void
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn('1');
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('executeQuery')->willReturn($result);

		$compliance = $this->createMock(ComplianceService::class);
		$compliance->method('isGermanPublicHoliday')->willThrowException(new \RuntimeException('boom'));

		$r = $this->controller($db, $compliance)->check();
		$d = $r->getData();
		$this->assertSame('degraded', $d['status']);
		$this->assertSame('unhealthy', $d['services']['compliance']['status']);
		// raw exception message must not leak to the public endpoint
		$this->assertStringNotContainsString('boom', json_encode($d));
	}
}
