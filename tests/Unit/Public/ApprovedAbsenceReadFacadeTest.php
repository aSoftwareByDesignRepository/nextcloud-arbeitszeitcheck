<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Public;

use OCA\ArbeitszeitCheck\Db\Absence;
use OCA\ArbeitszeitCheck\Public\ApprovedAbsenceReadFacade;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * F19 — approved-absence facade: status-only wire shape (never type /
 * reason / comment), overlap-range filtering, fail-closed.
 */
class ApprovedAbsenceReadFacadeTest extends TestCase
{
	private function dbWithRows(array $rows): IDBConnection
	{
		$result = $this->createMock(IResult::class);
		$result->method('iterateAssociative')->willReturnCallback(
			static fn () => (function () use ($rows) { yield from $rows; })()
		);

		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'lte', 'gte'] as $m) {
			$expr->method($m)->willReturn('expr');
		}

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('p');
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy'] as $m) {
			$qb->method($m)->willReturnSelf();
		}
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function facade(IDBConnection $db): ApprovedAbsenceReadFacade
	{
		return new ApprovedAbsenceReadFacade($db, $this->createMock(LoggerInterface::class));
	}

	public function testContractShape(): void
	{
		$this->assertSame(1, ApprovedAbsenceReadFacade::FACADE_VERSION);
		$this->assertTrue(method_exists(ApprovedAbsenceReadFacade::class, 'listApprovedForRange'));
	}

	public function testRowsCarryStatusOnly(): void
	{
		$out = $this->facade($this->dbWithRows([
			['user_id' => 'alice', 'start_date' => '2026-10-05', 'end_date' => '2026-10-09'],
		]))->listApprovedForRange('2026-10-01', '2026-10-31');
		$this->assertCount(1, $out);
		$this->assertSame([
			'userId' => 'alice',
			'startDate' => '2026-10-05',
			'endDate' => '2026-10-09',
			'status' => Absence::STATUS_APPROVED,
		], $out[0]);
		// privacy boundary: no type/reason key may exist on the wire shape
		$this->assertArrayNotHasKey('type', $out[0]);
		$this->assertArrayNotHasKey('reason', $out[0]);
		$this->assertArrayNotHasKey('approverComment', $out[0]);
	}

	public function testBadRangeYieldsEmpty(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('getQueryBuilder');
		$f = $this->facade($db);
		$this->assertSame([], $f->listApprovedForRange('not-a-date', '2026-10-31'));
		$this->assertSame([], $f->listApprovedForRange('2026-10-31', '2026-10-01'));
		$this->assertSame([], $f->listApprovedForRange('2026-10-01', 'garbage'));
	}

	public function testFailsClosedOnDbError(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new \RuntimeException('boom'));
		$this->assertSame([], $this->facade($db)->listApprovedForRange('2026-10-01', '2026-10-31'));
	}
}
