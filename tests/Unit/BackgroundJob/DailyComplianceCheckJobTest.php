<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\DailyComplianceCheckJob;
use OCA\ArbeitszeitCheck\Service\ComplianceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class DailyComplianceCheckJobTest extends TestCase
{
	public function testRunSkipsWhenAutoComplianceDisabled(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with('arbeitszeitcheck', 'auto_compliance_check', '1')
			->willReturn('0');

		$compliance = $this->createMock(ComplianceService::class);
		$compliance->expects($this->never())->method('runDailyComplianceCheck');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with('Daily compliance check skipped (disabled in settings)');

		$job = new DailyComplianceCheckJob(
			$this->createMock(ITimeFactory::class),
			$compliance,
			$config,
			$logger,
		);

		$ref = new ReflectionClass($job);
		$method = $ref->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}

	public function testRunInvokesComplianceServiceWhenEnabled(): void
	{
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')
			->with('arbeitszeitcheck', 'auto_compliance_check', '1')
			->willReturn('1');

		$compliance = $this->createMock(ComplianceService::class);
		$compliance->expects($this->once())
			->method('runDailyComplianceCheck')
			->willReturn([
				'users_checked' => 2,
				'violations_found' => 0,
				'check_date' => '2026-09-08',
			]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->atLeastOnce())->method('info');

		$job = new DailyComplianceCheckJob(
			$this->createMock(ITimeFactory::class),
			$compliance,
			$config,
			$logger,
		);

		$ref = new ReflectionClass($job);
		$method = $ref->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}
}
