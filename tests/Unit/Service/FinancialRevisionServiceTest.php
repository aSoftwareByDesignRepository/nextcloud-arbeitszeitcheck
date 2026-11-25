<?php

declare(strict_types=1);

/**
 * Unit tests for FinancialRevisionService — the per-year revision authority
 * behind §9.6 needs_review detection on year-end reset items.
 *
 * @copyright Copyright (c) 2026, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Service;

use OCA\ArbeitszeitCheck\Db\BalanceRevisionMapper;
use OCA\ArbeitszeitCheck\Db\YearResetItemMapper;
use OCA\ArbeitszeitCheck\Service\FinancialRevisionService;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class FinancialRevisionServiceTest extends TestCase
{
	private FinancialRevisionService $service;
	private $balanceRevisionMapper;
	private $itemMapper;
	private $db;

	protected function setUp(): void
	{
		parent::setUp();
		$this->balanceRevisionMapper = $this->createMock(BalanceRevisionMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = new FinancialRevisionService($this->balanceRevisionMapper, $this->db);
		$this->itemMapper = $this->createMock(YearResetItemMapper::class);
		$prop = new \ReflectionProperty(FinancialRevisionService::class, 'itemMapper');
		$prop->setValue($this->service, $this->itemMapper);
	}

	/** Stub the epoch query path: select revision → one row or none. */
	private function stubEpochQuery(array $revisionsByCall = []): void
	{
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('x = y');
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('?');
		$result = $this->createMock(IResult::class);
		$i = 0;
		$revisionsByCall = $revisionsByCall === [] ? [0] : $revisionsByCall;
		$result->method('fetch')->willReturnCallback(
			static function () use (&$i, $revisionsByCall) {
				$r = $revisionsByCall[$i] ?? end($revisionsByCall);
				$i++;
				return ['revision' => $r];
			}
		);
		$qb->method('executeQuery')->willReturn($result);
		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	public function testBumpForWriteBumpsEachYearAndReturnsMax(): void
	{
		$this->stubEpochQuery([0, 0, 0, 0, 0, 0, 0, 0, 0, 0]);
		$this->balanceRevisionMapper->expects($this->exactly(2))
			->method('bump')
			->willReturnMap([['u1', 2025, 4], ['u1', 2026, 7]]);
		$this->itemMapper->expects($this->exactly(2))
			->method('flagStaleForUser');

		$this->assertSame(7, $this->service->bumpForWrite('u1', [2025, 2026]));
	}

	public function testBumpForWriteFlagsStaleWithCurrentSourceToken(): void
	{
		$this->stubEpochQuery([3, 0, 0, 0, 0]);
		$this->balanceRevisionMapper->method('bump')->willReturn(2);
		$this->balanceRevisionMapper->method('current')->with('u1', 2025)->willReturn(2);
		$this->itemMapper->expects($this->once())
			->method('flagStaleForUser')
			->with('u1', 2025, 'u2.p3.c0.h0.m0');

		$this->service->bumpForWrite('u1', [2025]);
	}

	public function testBumpForWriteEmptyYearsIsNoOp(): void
	{
		$this->balanceRevisionMapper->expects($this->never())->method('bump');
		$this->itemMapper->expects($this->never())->method('flagStaleForUser');
		$this->assertSame(0, $this->service->bumpForWrite('u1', []));
	}

	public function testBumpForWriteRangeClampsToThirtyYears(): void
	{
		$currentYear = (int)(new \DateTime('now'))->format('Y');
		$this->stubEpochQuery(array_fill(0, 200, 0));
		$calls = 0;
		$this->balanceRevisionMapper->method('bump')
			->willReturnCallback(function () use (&$calls) {
				$calls++;
				return 1;
			});
		// From 2000 to current year: span must be capped at 31 years.
		$this->service->bumpForWriteRange('u1', 2000, $currentYear);
		$this->assertLessThanOrEqual(31, $calls);
		$this->assertGreaterThan(0, $calls);
	}

	public function testBumpForWriteRangeFutureClampsToCurrentYear(): void
	{
		$currentYear = (int)(new \DateTime('now'))->format('Y');
		$this->stubEpochQuery(array_fill(0, 200, 0));
		$years = [];
		$this->balanceRevisionMapper->method('bump')
			->willReturnCallback(function (string $u, int $y) use (&$years) {
				$years[] = $y;
				return 1;
			});
		$this->service->bumpForWriteRange('u1', $currentYear, $currentYear + 10);
		$this->assertSame([$currentYear], $years);
	}

	public function testBumpForWriteRangeEmptyAfterClampSkipsFlagging(): void
	{
		// to < from after clamping must not bump and must not flag.
		$this->itemMapper->expects($this->never())->method('flagStaleForUser');
		$this->balanceRevisionMapper->expects($this->never())->method('bump');
		$this->service->bumpForWriteRange('u1', 1900, 1901);
	}

	public function testBumpForDateUsesYearOfDate(): void
	{
		$this->stubEpochQuery([0, 0, 0, 0, 0]);
		$this->balanceRevisionMapper->expects($this->once())
			->method('bump')
			->with('u1', 2024)
			->willReturn(9);
		$this->assertSame(9, $this->service->bumpForDate('u1', new \DateTime('2024-06-15')));
	}

	public function testSourceRevisionShape(): void
	{
		$this->stubEpochQuery([5, 6, 7, 8]);
		$this->balanceRevisionMapper->method('current')->with('u1', 2025)->willReturn(3);
		$this->assertSame('u3.p5.c6.h7.m8', $this->service->sourceRevision('u1', 2025));
	}

	public function testIsStaleDetectsTokenDrift(): void
	{
		$this->stubEpochQuery([0, 0, 0, 0, 0, 0, 0, 0]);
		$this->balanceRevisionMapper->method('current')->willReturn(1);
		$current = $this->service->sourceRevision('u1', 2025);
		$this->assertFalse($this->service->isStale($current, 'u1', 2025));
		$this->assertTrue($this->service->isStale('u9.p0.c0.h0.m0', 'u1', 2025));
		$this->assertTrue($this->service->isStale('', 'u1', 2025));
	}
}
