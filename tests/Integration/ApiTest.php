<?php

declare(strict_types=1);

/**
 * Integration tests for API endpoints
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Integration;

use OCP\AppFramework\Http;
use Test\TestCase;

/**
 * Class ApiTest
 */
class ApiTest extends TestCase {

	/**
	 * Test health check endpoint
	 */
	public function testHealthCheck(): void {
		// Get the health controller from DI container
		$healthController = \OC::$server->query(\OCA\ArbeitszeitCheck\Controller\HealthController::class);
		$this->assertInstanceOf(\OCA\ArbeitszeitCheck\Controller\HealthController::class, $healthController);

		// Call the actual check method
		$response = $healthController->check();

		// Verify response is JSONResponse
		$this->assertInstanceOf(\OCP\AppFramework\Http\JSONResponse::class, $response);

		// Get response data
		$data = $response->getData();
		$this->assertIsArray($data);

		// Verify required fields exist
		$this->assertArrayHasKey('status', $data);
		$this->assertArrayHasKey('timestamp', $data);
		$this->assertArrayHasKey('services', $data);
		$this->assertArrayHasKey('version', $data);
		$this->assertArrayHasKey('nextcloud_version', $data);

		// Verify status is valid
		$this->assertContains($data['status'], ['healthy', 'degraded', 'unhealthy']);

		// Verify services structure
		$this->assertIsArray($data['services']);
		$this->assertArrayHasKey('database', $data['services']);
		$this->assertArrayHasKey('compliance', $data['services']);
		$this->assertArrayHasKey('projectcheck_integration', $data['services']);

		// Verify each service has status and message
		foreach ($data['services'] as $serviceName => $serviceData) {
			$this->assertIsArray($serviceData, "Service $serviceName should be an array");
			$this->assertArrayHasKey('status', $serviceData, "Service $serviceName should have status");
			$this->assertArrayHasKey('message', $serviceData, "Service $serviceName should have message");
			$this->assertContains($serviceData['status'], ['healthy', 'unhealthy'], "Service $serviceName status should be valid");
		}

		// In containerized/dev environments DB health depends on configured driver/extensions.
		// Assert the contract: database/compliance report a valid status string.
		$this->assertContains($data['services']['database']['status'], ['healthy', 'unhealthy'], 'Database status should be valid');
		$this->assertContains($data['services']['compliance']['status'], ['healthy', 'unhealthy'], 'Compliance status should be valid');
	}

	/**
	 * Test that health check returns proper HTTP status codes
	 */
	public function testHealthCheckHttpStatus(): void {
		$healthController = \OC::$server->query(\OCA\ArbeitszeitCheck\Controller\HealthController::class);
		$response = $healthController->check();

		// When healthy, should return 200 OK
		$data = $response->getData();
		if ($data['status'] === 'healthy' || $data['status'] === 'degraded') {
			$this->assertEquals(Http::STATUS_OK, $response->getStatus());
		} else {
			$this->assertEquals(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		}
	}

	/**
	 * Test that health check response includes timestamp
	 */
	public function testHealthCheckTimestamp(): void {
		$healthController = \OC::$server->query(\OCA\ArbeitszeitCheck\Controller\HealthController::class);
		$response = $healthController->check();
		$data = $response->getData();

		$this->assertArrayHasKey('timestamp', $data);
		$this->assertIsInt($data['timestamp']);
		$this->assertGreaterThan(0, $data['timestamp']);

		// Timestamp should be recent (within last minute)
		$now = time();
		$this->assertLessThanOrEqual($now, $data['timestamp']);
		$this->assertGreaterThan($now - 60, $data['timestamp']);
	}

	/**
	 * Test that health check response includes version information
	 */
	public function testHealthCheckVersion(): void {
		$healthController = \OC::$server->query(\OCA\ArbeitszeitCheck\Controller\HealthController::class);
		$response = $healthController->check();
		$data = $response->getData();

		$this->assertArrayHasKey('version', $data);
		$this->assertIsString($data['version']);
		$this->assertNotEmpty($data['version']);

		$this->assertArrayHasKey('nextcloud_version', $data);
		$this->assertIsString($data['nextcloud_version']);
		$this->assertNotEmpty($data['nextcloud_version']);
	}
}