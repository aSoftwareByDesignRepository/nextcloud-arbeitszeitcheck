<?php

declare(strict_types=1);

/**
 * Unit tests for HealthController
 *
 * This controller is tested via integration tests (see tests/Integration/ApiTest.php).
 * 
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;

/**
 * Class HealthControllerTest
 * 
 * See tests/Integration/ApiTest.php for comprehensive health check tests.
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
