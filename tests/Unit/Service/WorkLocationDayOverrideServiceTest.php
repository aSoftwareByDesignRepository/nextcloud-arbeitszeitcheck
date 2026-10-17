<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Db\WorkLocationDayOverride;
use OCA\ArbeitszeitCheck\Db\WorkLocationDayOverrideMapper;
use OCA\ArbeitszeitCheck\Exception\WorkLocationDayConflictException;
use OCA\ArbeitszeitCheck\Service\WorkLocationCatalog;
use OCA\ArbeitszeitCheck\Service\WorkLocationDayOverrideService;
use OCP\DB\Exception as DbException;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class WorkLocationDayOverrideServiceTest extends TestCase
{
	private WorkLocationDayOverrideMapper $mapper;
	private WorkLocationCatalog $catalog;
	private AuditLogMapper $audit;
	private ILockingProvider $locking;
	private WorkLocationDayOverrideService $service;

	/** @var array<string, WorkLocationDayOverride> key: user|date */
	private array $store = [];

	protected function setUp(): void
	{
		$this->store = [];
		$this->mapper = $this->createMock(WorkLocationDayOverrideMapper::class);
		$this->mapper->method('findForUserDate')->willReturnCallback(
			fn (string $u, string $d) => $this->store["$u|$d"] ?? null
		);
		$this->mapper->method('insert')->willReturnCallback(function (WorkLocationDayOverride $row) {
			$key = $row->getUserId() . '|' . $row->getLocalDate()->format('Y-m-d');
			if (isset($this->store[$key])) {
				throw new DbException('duplicate key');
			}
			$this->store[$key] = $row;
			return $row;
		});
		$this->mapper->method('update')->willReturnCallback(function (WorkLocationDayOverride $row) {
			$this->store[$row->getUserId() . '|' . $row->getLocalDate()->format('Y-m-d')] = $row;
			return $row;
		});
		$this->mapper->method('deleteForUserDate')->willReturnCallback(function (string $u, string $d) {
			unset($this->store["$u|$d"]);
		});

		$defMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper::class);
		$defMapper->method('findAllIndexed')->willReturn([]);
		$this->catalog = new WorkLocationCatalog($defMapper, new \Psr\Log\NullLogger());

		$this->audit = $this->createMock(AuditLogMapper::class);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->service = new WorkLocationDayOverrideService($this->mapper, $this->catalog, $this->audit, $this->locking);
	}

	private function today(): string
	{
		return (new \DateTime('today'))->format('Y-m-d');
	}

	public function testCreateSetsRevisionOneAndAudits(): void
	{
		$this->audit->expects(self::once())->method('logAction')
			->with('u1', 'work_location_day_set', 'work_location_day', null,
				self::callback(fn ($old) => $old['location_code'] === null),
				self::callback(fn ($new) => $new['location_code'] === 'home' && $new['revision'] === 1));
		$result = $this->service->setOverride('u1', $this->today(), 'home', null, 'web', 'u1');
		self::assertSame(['locationCode' => 'home', 'revision' => 1], $result);
	}

	public function testUpdateRequiresMatchingRevision(): void
	{
		$this->service->setOverride('u1', $this->today(), 'home', null, 'web', 'u1');
		$result = $this->service->setOverride('u1', $this->today(), 'external', '1', 'mobile', 'u1');
		self::assertSame(['locationCode' => 'external', 'revision' => 2], $result);
	}

	public function testMissingRevisionOnExistingRowConflicts(): void
	{
		$this->service->setOverride('u1', $this->today(), 'home', null, 'web', 'u1');
		$this->expectException(WorkLocationDayConflictException::class);
		$this->service->setOverride('u1', $this->today(), 'external', null, 'web', 'u1');
	}

	public function testStaleRevisionConflictsWithoutWrite(): void
	{
		$this->service->setOverride('u1', $this->today(), 'home', null, 'web', 'u1');
		$this->mapper->expects(self::never())->method('update');
		try {
			$this->service->setOverride('u1', $this->today(), 'external', '99', 'web', 'u1');
			self::fail('expected conflict');
		} catch (WorkLocationDayConflictException $e) {
			self::assertSame('REVISION_CONFLICT', $e->getReasonCode());
		}
	}

	public function testMalformedRevisionTokenConflicts(): void
	{
		$this->expectException(WorkLocationDayConflictException::class);
		$this->service->setOverride('u1', $this->today(), 'home', '2.9', 'web', 'u1');
	}

	public function testNonZeroExpectationOnMissingRowConflicts(): void
	{
		$this->expectException(WorkLocationDayConflictException::class);
		$this->service->setOverride('u1', $this->today(), 'home', 5, 'web', 'u1');
	}

	public function testClearRemovesRowWithMatchingRevision(): void
	{
		$this->service->setOverride('u1', $this->today(), 'home', null, 'web', 'u1');
		$result = $this->service->setOverride('u1', $this->today(), null, '1', 'web', 'u1');
		self::assertSame(['locationCode' => null, 'revision' => 0], $result);
		self::assertNull($this->service->get('u1', $this->today()));
	}

	public function testClearOnMissingRowIsNoop(): void
	{
		$result = $this->service->setOverride('u1', $this->today(), null, null, 'web', 'u1');
		self::assertSame(['locationCode' => null, 'revision' => 0], $result);
		self::assertArrayNotHasKey('u1|' . $this->today(), $this->store);
	}

	public function testPastDateRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setOverride('u1', '2020-01-01', 'home', null, 'web', 'u1');
	}

	public function testDateBeyondWindowRejected(): void
	{
		$far = (new \DateTime('today'))->modify('+' . (WorkLocationDayOverrideService::FUTURE_WINDOW_DAYS + 1) . ' days')->format('Y-m-d');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setOverride('u1', $far, 'home', null, 'web', 'u1');
	}

	public function testBoundaryFutureDateAccepted(): void
	{
		$max = (new \DateTime('today'))->modify('+' . WorkLocationDayOverrideService::FUTURE_WINDOW_DAYS . ' days')->format('Y-m-d');
		$result = $this->service->setOverride('u1', $max, 'home', null, 'web', 'u1');
		self::assertSame('home', $result['locationCode']);
	}

	public function testUnknownCodeRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setOverride('u1', $this->today(), 'custom_ghost', null, 'web', 'u1');
	}

	public function testInvalidSourceRejected(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		$this->service->setOverride('u1', $this->today(), 'home', null, 'kiosk', 'u1');
	}

	public function testEffectiveCodeSkipsInactive(): void
	{
		// Write a real override first, then replace the catalog with one that
		// marks the code inactive — the override row stays readable but no
		// longer wins the resolution chain.
		$this->service->setOverride('u1', $this->today(), 'home', null, 'web', 'u1');
		$defMapper = $this->createMock(\OCA\ArbeitszeitCheck\Db\WorkLocationDefinitionMapper::class);
		$home = new \OCA\ArbeitszeitCheck\Db\WorkLocationDefinition();
		$home->setCode('home');
		$home->setBaseLabel('Home');
		$home->setReportingCategory('home');
		$home->setActive(false);
		$defMapper->method('findAllIndexed')->willReturn(['home' => $home]);
		$catalog = new WorkLocationCatalog($defMapper, new \Psr\Log\NullLogger());
		$service = new WorkLocationDayOverrideService($this->mapper, $catalog, $this->audit, $this->locking);
		self::assertNull($service->effectiveCodeFor('u1', $this->today()));
		// Row itself stays readable — history is never reinterpreted away.
		self::assertSame('home', $service->get('u1', $this->today())['locationCode']);
	}

	public function testLockIsHeldAcrossReadAndWrite(): void
	{
		$events = [];
		$this->locking->method('acquireLock')->willReturnCallback(function () use (&$events) {
			$events[] = 'acquire';
		});
		$this->locking->method('releaseLock')->willReturnCallback(function () use (&$events) {
			$events[] = 'release';
		});
		$this->service->setOverride('u1', $this->today(), 'home', null, 'web', 'u1');
		self::assertSame(['acquire', 'release'], $events);
	}

	public function testLockReleasedOnConflict(): void
	{
		$releases = 0;
		$this->locking->method('releaseLock')->willReturnCallback(function () use (&$releases) {
			$releases++;
		});
		$this->service->setOverride('u1', $this->today(), 'home', null, 'web', 'u1');
		self::assertSame(1, $releases);
		try {
			$this->service->setOverride('u1', $this->today(), 'external', null, 'web', 'u1');
			self::fail('expected conflict');
		} catch (WorkLocationDayConflictException) {
		}
		self::assertSame(2, $releases);
	}
}
