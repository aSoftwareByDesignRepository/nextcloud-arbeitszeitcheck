<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\KioskMaintenanceJob;
use OCA\ArbeitszeitCheck\Db\KioskEnrollmentMapper;
use OCA\ArbeitszeitCheck\Service\Kiosk\KioskTerminalService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class KioskMaintenanceJobTest extends TestCase
{
	public function testRunExpiresTerminalsAndEnrollments(): void
	{
		$time = $this->createMock(ITimeFactory::class);
		$now = new \DateTimeImmutable('2026-09-08T12:00:00Z');
		$time->method('getDateTime')->willReturn(\DateTime::createFromImmutable($now));

		$terminals = $this->createMock(KioskTerminalService::class);
		$terminals->expects($this->once())->method('expireStalePendingTerminals')->willReturn(2);

		$enrollments = $this->createMock(KioskEnrollmentMapper::class);
		$enrollments->expects($this->once())->method('deleteExpiredIncomplete')->willReturn(1);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('info');

		$job = new KioskMaintenanceJob($time, $terminals, $enrollments, $logger);
		$ref = new ReflectionClass($job);
		$method = $ref->getMethod('run');
		$method->setAccessible(true);
		$method->invoke($job, null);
	}
}
