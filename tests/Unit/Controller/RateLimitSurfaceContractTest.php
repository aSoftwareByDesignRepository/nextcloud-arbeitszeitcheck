<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Controller;

use OCA\ArbeitszeitCheck\Controller\AdminController;
use OCA\ArbeitszeitCheck\Controller\KioskController;
use OCA\ArbeitszeitCheck\Controller\PageController;
use OCA\ArbeitszeitCheck\Controller\TimeTrackingController;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Contract: expensive / auth-sensitive surfaces carry UserRateLimit or BruteForceProtection.
 */
class RateLimitSurfaceContractTest extends TestCase
{
	/**
	 * @param class-string $class
	 */
	private function assertUserRateLimit(string $class, string $method, int $limit, int $period): void
	{
		$ref = new ReflectionMethod($class, $method);
		$attrs = $ref->getAttributes(UserRateLimit::class);
		$this->assertNotEmpty($attrs, "{$class}::{$method} must have UserRateLimit");
		/** @var UserRateLimit $instance */
		$instance = $attrs[0]->newInstance();
		$this->assertSame($limit, $instance->getLimit(), "{$class}::{$method} limit");
		$this->assertSame($period, $instance->getPeriod(), "{$class}::{$method} period");
	}

	/**
	 * @param class-string $class
	 */
	private function assertBruteForce(string $class, string $method): void
	{
		$ref = new ReflectionMethod($class, $method);
		$attrs = $ref->getAttributes(BruteForceProtection::class);
		$this->assertNotEmpty($attrs, "{$class}::{$method} must have BruteForceProtection");
	}

	public function testClockMutationsAreRateLimitedAndBruteForceProtected(): void
	{
		foreach (['clockIn', 'clockOut', 'startBreak', 'endBreak', 'enforceDailyMaximum'] as $method) {
			$this->assertUserRateLimit(TimeTrackingController::class, $method, 20, 60);
			$this->assertBruteForce(TimeTrackingController::class, $method);
		}
	}

	public function testOvertimeBalancePdfIsRateLimited(): void
	{
		$this->assertUserRateLimit(PageController::class, 'overtimeBalancePdf', 30, 60);
	}

	public function testAdminProfilePutIsRateLimited(): void
	{
		$this->assertUserRateLimit(AdminController::class, 'updateUserProfile', 20, 60);
	}

	public function testKioskIdentifyAndPairAreBruteForceProtected(): void
	{
		$this->assertBruteForce(KioskController::class, 'pair');
		$this->assertBruteForce(KioskController::class, 'identify');
	}
}
