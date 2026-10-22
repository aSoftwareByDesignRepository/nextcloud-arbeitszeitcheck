<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\MonthClosureAutoFinalizeJob;
use OCA\ArbeitszeitCheck\Service\MonthClosureService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class MonthClosureAutoFinalizeJobTest extends TestCase
{
	public function testRunInvokesAutomaticFinalizeForAllUsers(): void
	{
		$today = new \DateTimeImmutable('2026-09-08T02:00:00Z');
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(\DateTime::createFromImmutable($today));

		$monthClosure = $this->createMock(MonthClosureService::class);
		$monthClosure->expects($this->once())
			->method('runAutomaticFinalizeForAllUsers')
			->with($this->callback(static function ($dt) use ($today): bool {
				return $dt instanceof \DateTimeInterface
					&& $dt->format('Y-m-d') === $today->format('Y-m-d');
			}))
			->willReturn([
				'finalized' => 1,
				'pending_correction' => 0,
				'errors' => 0,
			]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info')
			->with('Month closure auto-finalize job finished', $this->arrayHasKey('finalized'));

		$job = new MonthClosureAutoFinalizeJob($time, $monthClosure, $logger);

		$ref = new ReflectionClass($job);
		$method = $ref->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}
}
