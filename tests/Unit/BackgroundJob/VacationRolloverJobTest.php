<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\VacationRolloverJob;
use OCA\ArbeitszeitCheck\Service\VacationRolloverService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class VacationRolloverJobTest extends TestCase
{
	public function testRunInvokesRolloverServiceForAllUsers(): void
	{
		$rollover = $this->createMock(VacationRolloverService::class);
		$rollover->expects($this->once())
			->method('runForAllUsers')
			->with(null, false, false, false)
			->willReturn([
				'applied' => 1,
				'skipped' => 0,
				'errors' => 0,
			]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with('Vacation rollover job finished', $this->arrayHasKey('applied'));

		$job = new VacationRolloverJob(
			$this->createMock(ITimeFactory::class),
			$rollover,
			$logger,
		);

		$ref = new ReflectionClass($job);
		$method = $ref->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}
}
