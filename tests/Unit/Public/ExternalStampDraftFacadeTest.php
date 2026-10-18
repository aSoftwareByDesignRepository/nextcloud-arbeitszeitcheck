<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Public;

use OCA\ArbeitszeitCheck\Db\ExternalStamp;
use OCA\ArbeitszeitCheck\Public\ExternalStampDraftFacade;
use OCA\ArbeitszeitCheck\Service\ExternalStampService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F16 — pending-stamp write facade: additive wire contract, null passthrough
 * for rejected proposals, and fail-closed on service exceptions (a broken
 * sibling call must never propagate into the producer).
 */
class ExternalStampDraftFacadeTest extends TestCase
{
	private function stamp(int $id, string $status): ExternalStamp
	{
		$s = new ExternalStamp();
		$s->setId($id);
		$s->setStatus($status);
		return $s;
	}

	public function testProposeReturnsWireShapeOnCreate(): void
	{
		$service = $this->createMock(ExternalStampService::class);
		$service->expects($this->once())
			->method('propose')
			->with('worker1', 1700000000, null, 'tourcheck', 'round:42:start')
			->willReturn(['stamp' => $this->stamp(7, ExternalStamp::STATUS_PENDING), 'created' => true]);

		$facade = new ExternalStampDraftFacade($service, $this->createMock(LoggerInterface::class));
		$this->assertSame(
			['id' => 7, 'status' => 'pending', 'created' => true],
			$facade->propose('worker1', 1700000000, null, 'tourcheck', 'round:42:start'),
		);
	}

	public function testProposeReplayReturnsStoredIdAndCreatedFalse(): void
	{
		$service = $this->createMock(ExternalStampService::class);
		$service->method('propose')
			->willReturn(['stamp' => $this->stamp(7, ExternalStamp::STATUS_CONFIRMED), 'created' => false]);

		$facade = new ExternalStampDraftFacade($service, $this->createMock(LoggerInterface::class));
		$out = $facade->propose('worker1', 1700000000, null, 'tourcheck', 'round:42:start');
		$this->assertSame(7, $out['id']);
		$this->assertSame('confirmed', $out['status']);
		$this->assertFalse($out['created']);
	}

	public function testProposePassesThroughNullForRejectedInput(): void
	{
		$service = $this->createMock(ExternalStampService::class);
		$service->method('propose')->willReturn(null);

		$facade = new ExternalStampDraftFacade($service, $this->createMock(LoggerInterface::class));
		$this->assertNull($facade->propose('ghost', 1700000000, null, 'tourcheck', 'round:42:start'));
	}

	public function testProposeFailsClosedOnServiceException(): void
	{
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('info');

		$service = $this->createMock(ExternalStampService::class);
		$service->method('propose')->willThrowException(new \RuntimeException('db gone'));

		$facade = new ExternalStampDraftFacade($service, $logger);
		$this->assertNull($facade->propose('worker1', 1700000000, null, 'tourcheck', 'round:42:start'));
	}

	public function testProposePassesSegmentEndThrough(): void
	{
		$service = $this->createMock(ExternalStampService::class);
		$service->expects($this->once())
			->method('propose')
			->with('worker1', 1700000000, 1700003600, 'carecheck', 'worklog:9')
			->willReturn(['stamp' => $this->stamp(3, ExternalStamp::STATUS_PENDING), 'created' => true]);

		$facade = new ExternalStampDraftFacade($service, $this->createMock(LoggerInterface::class));
		$this->assertSame(
			['id' => 3, 'status' => 'pending', 'created' => true],
			$facade->propose('worker1', 1700000000, 1700003600, 'carecheck', 'worklog:9'),
		);
	}
}
