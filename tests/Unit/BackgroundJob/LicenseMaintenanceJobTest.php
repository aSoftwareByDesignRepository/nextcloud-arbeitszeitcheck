<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\LicenseMaintenanceJob;
use OCA\ArbeitszeitCheck\Service\LicenseEnforcementService;
use OCA\ArbeitszeitCheck\Service\LicenseService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class LicenseMaintenanceJobTest extends TestCase
{
	private LicenseMaintenanceJob $job;

	public function testSkipsWhenNoStoredLicense(): void
	{
		$license = $this->createMock(LicenseService::class);
		$license->method('hasStoredLicense')->willReturn(false);

		$enforcement = $this->createMock(LicenseEnforcementService::class);
		$enforcement->expects(self::never())->method('enforceCurrentLimits');

		$this->job = new LicenseMaintenanceJob(
			$this->createMock(ITimeFactory::class),
			$license,
			$enforcement,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ILockingProvider::class),
		);

		$this->invokeRun();
	}

	public function testEnforcesLimitsWhenLicenseExists(): void
	{
		$license = $this->createMock(LicenseService::class);
		$license->method('hasStoredLicense')->willReturn(true);
		$license->method('isStoredLicenseExpired')->willReturn(false);

		$enforcement = $this->createMock(LicenseEnforcementService::class);
		$enforcement->expects(self::once())
			->method('enforceCurrentLimits')
			->willReturn(['mobileSeatsRemoved' => 1, 'terminalsRevoked' => 0]);

		$this->job = new LicenseMaintenanceJob(
			$this->createMock(ITimeFactory::class),
			$license,
			$enforcement,
			$this->createMock(LoggerInterface::class),
			$this->createMock(ILockingProvider::class),
		);

		$this->invokeRun();
	}

	private function invokeRun(): void
	{
		$reflection = new \ReflectionClass($this->job);
		$runMethod = $reflection->getMethod('run');
		$runMethod->setAccessible(true);
		$runMethod->invoke($this->job, null);
	}
}
