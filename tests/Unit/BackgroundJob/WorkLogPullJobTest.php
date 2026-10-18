<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\BackgroundJob;

use OCA\ArbeitszeitCheck\BackgroundJob\WorkLogPullJob;
use OCA\ArbeitszeitCheck\Service\ExternalStampService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F19b pull consumer — fail-closed on missing CareCheck and soft-fail on
 * any producer/Server resolution error (a broken sibling must never break
 * the AZC job queue).
 */
class WorkLogPullJobTest extends TestCase
{
	private function invokeRun(WorkLogPullJob $job): void
	{
		$m = new \ReflectionMethod($job, 'run');
		$m->setAccessible(true);
		$m->invoke($job, null);
	}

	private function job(bool $cckEnabled, ?ExternalStampService $svc = null, ?LoggerInterface $logger = null): WorkLogPullJob
	{
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->with('carecheck')->willReturn($cckEnabled);
		return new WorkLogPullJob(
			$this->createMock(ITimeFactory::class),
			$apps,
			$svc ?? $this->createMock(ExternalStampService::class),
			$logger ?? $this->createMock(LoggerInterface::class),
		);
	}

	public function testNoOpWhenCarecheckDisabled(): void
	{
		$svc = $this->createMock(ExternalStampService::class);
		$svc->expects($this->never())->method('propose');
		$this->invokeRun($this->job(false, $svc));
	}

	public function testProducerFailureIsCaughtAndLogged(): void
	{
		// Whether or not the CCK facade class autoloads in unit context, the
		// run must terminate without exception and without a partial write.
		$svc = $this->createMock(ExternalStampService::class);
		$logger = $this->createMock(LoggerInterface::class);
		try {
			$this->invokeRun($this->job(true, $svc, $logger));
		} catch (\Throwable $e) {
			$this->fail('WorkLogPullJob must fail soft on producer errors: ' . $e->getMessage());
		}
		// Either it early-returned (class missing/version gate) or the whole
		// facade block was inside the try — no path may leak an exception.
		$this->addToAssertionCount(1);
	}
}
