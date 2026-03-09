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
		$this->assertTrue(class_exists(\OCA\ArbeitszeitCheck\Controller\HealthController::class));
	}
}
