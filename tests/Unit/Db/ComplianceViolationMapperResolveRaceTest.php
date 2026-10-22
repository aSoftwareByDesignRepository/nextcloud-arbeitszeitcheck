<?php

declare(strict_types=1);

/**
 * ComplianceViolationMapper::resolveViolation race contract.
 *
 * The resolve write must be a compare-and-set: UPDATE ... WHERE id = ? AND
 * resolved = 0. A concurrent resolver that already flipped the row leaves
 * zero affected rows; the loser must get ConcurrentDecisionException (HTTP
 * 409 upstream) instead of silently overwriting resolved_by/resolved_at
 * attribution. An unresolved row that still reports zero affected rows is a
 * driver-level anomaly and must surface loudly.
 *
 * @copyright Copyright (c) 2026, Alexander Mäule / Software by Design
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Db;

use OCA\ArbeitszeitCheck\Db\ComplianceViolationMapper;
use OCA\ArbeitszeitCheck\Exception\ConcurrentDecisionException;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ComplianceViolationMapperResolveRaceTest extends TestCase
{
	private function qbWithRow(int $affected, ?array $row): IQueryBuilder
	{
		$qb = $this->createMock(IQueryBuilder::class);
		foreach ([
			'select', 'from', 'where', 'andWhere', 'update', 'set',
			'setMaxResults', 'setFirstResult', 'orderBy',
		] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('func')->willReturn($this->createMock(IFunctionBuilder::class));
		$qb->method('createNamedParameter')->willReturn(':p');
		$qb->method('createParameter')->willReturn(':p');
		$qb->method('executeStatement')->willReturn($affected);

		$result = $this->createMock(IResult::class);
		$result->method('fetchAssociative')->willReturnOnConsecutiveCalls($row ?? false, false);
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	private function dbForQbs(array $qbs): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$i = 0;
		$db->method('getQueryBuilder')->willReturnCallback(function () use ($qbs, &$i): IQueryBuilder {
			return $qbs[min($i++, count($qbs) - 1)];
		});
		return $db;
	}

	public function testSuccessfulCasReturnsRefreshedEntity(): void
	{
		$row = ['id' => 5, 'user_id' => 'alice', 'resolved' => 1, 'resolved_by' => 'manager1'];
		$mapper = new ComplianceViolationMapper($this->dbForQbs([
			$this->qbWithRow(1, $row),
			$this->qbWithRow(1, $row),
		]));
		$violation = $mapper->resolveViolation(5, 'manager1');
		$this->assertTrue($violation->getResolved());
		$this->assertSame('manager1', $violation->getResolvedBy());
	}

	public function testLostRaceThrowsConcurrentDecision(): void
	{
		$row = ['id' => 5, 'user_id' => 'alice', 'resolved' => 1, 'resolved_by' => 'other-manager'];
		$mapper = new ComplianceViolationMapper($this->dbForQbs([
			$this->qbWithRow(0, $row),
			$this->qbWithRow(0, $row),
		]));
		$this->expectException(ConcurrentDecisionException::class);
		$mapper->resolveViolation(5, 'manager1');
	}

	public function testZeroAffectedOnUnresolvedRowIsAnomaly(): void
	{
		$row = ['id' => 5, 'user_id' => 'alice', 'resolved' => 0];
		$mapper = new ComplianceViolationMapper($this->dbForQbs([
			$this->qbWithRow(0, $row),
			$this->qbWithRow(0, $row),
		]));
		$this->expectException(\RuntimeException::class);
		$mapper->resolveViolation(5, 'manager1');
	}

	public function testUpdateIsStatusGuardedInSource(): void
	{
		$src = file_get_contents(__DIR__ . '/../../../lib/Db/ComplianceViolationMapper.php');
		$this->assertNotFalse($src);
		$start = strpos($src, 'function resolveViolation');
		$this->assertNotFalse($start, 'missing resolveViolation');
		$next = strpos($src, "\n\tpublic function ", $start + 10);
		$chunk = $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
		$this->assertStringContainsString("'resolved'", $chunk);
		$this->assertStringContainsString('andWhere', $chunk);
		$this->assertStringContainsString('executeStatement() === 0', $chunk);
	}
}
