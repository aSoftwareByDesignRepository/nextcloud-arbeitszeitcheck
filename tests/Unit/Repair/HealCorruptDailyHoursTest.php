<?php

declare(strict_types=1);

/**
 * Coverage for the corrupt-daily_hours healer (customer incident
 * 2026-11-19: daily_hours=78 turned a +133 h balance into "≈ 1
 * Abbummeltag"). Missing table → quiet no-op; corrupt rows → derived
 * weekly/5 or the 8.0 column default; on-demand zero rows untouched;
 * audit row only when something actually changed.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Repair;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Repair\HealCorruptDailyHours;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class HealCorruptDailyHoursTest extends TestCase
{
	private function result(array $rows): IResult
	{
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		return $result;
	}

	public function testMissingTableIsQuietNoOp(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(false);
		$db->expects($this->never())->method('executeQuery');

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->never())->method('logAction');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		(new HealCorruptDailyHours($db, $audit))->run($output);
	}

	public function testNoCorruptRowsIsQuietNoOp(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('executeQuery')->willReturn($this->result([]));
		$db->expects($this->never())->method('executeStatement');

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->never())->method('logAction');

		(new HealCorruptDailyHours($db, $audit))->run($this->createMock(IOutput::class));
	}

	public function testSelectPredicateCoversEveryCorruptShape(): void
	{
		// Assertions inside a willReturnCallback would be swallowed by
		// run()'s catch(\Throwable) — capture the SQL and assert afterwards.
		$sql = null;
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->expects($this->once())->method('executeQuery')->willReturnCallback(
			function (string $query) use (&$sql): IResult {
				$sql = $query;
				return $this->result([]);
			}
		);

		(new HealCorruptDailyHours($db, $this->createMock(AuditLogMapper::class)))
			->run($this->createMock(IOutput::class));

		// > 24 is the proven corruption; <= 0 is only poison when the
		// contract is NOT on-demand (a zero Soll is legal there).
		$this->assertIsString($sql);
		$this->assertStringContainsString('`daily_hours` > 24', $sql);
		$this->assertStringContainsString('`daily_hours` <= 0', $sql);
		$this->assertStringContainsString("`type` <> 'on_demand'", $sql);
	}

	public function testHealsFromWeeklyContractAndWritesAudit(): void
	{
		$updates = [];
		$updateSql = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('executeQuery')->willReturn($this->result([
			['id' => 7, 'daily_hours' => 78.0, 'weekly_hours' => 39.0],
			['id' => 9, 'daily_hours' => 0.0, 'weekly_hours' => 0.0],
		]));
		$db->expects($this->exactly(2))->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params) use (&$updates, &$updateSql): int {
				$updateSql[] = $sql;
				$updates[$params[1]] = $params[0];
				return 1;
			}
		);

		$logged = null;
		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction')->willReturnCallback(
			function (...$args) use (&$logged): \OCA\ArbeitszeitCheck\Db\AuditLog {
				$logged = $args;
				return new \OCA\ArbeitszeitCheck\Db\AuditLog();
			}
		);

		$infos = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(static function (string $m) use (&$infos): void {
			$infos[] = $m;
		});

		(new HealCorruptDailyHours($db, $audit))->run($output);

		foreach ($updateSql as $sql) {
			$this->assertStringContainsString('SET `daily_hours` = ?', $sql);
		}
		// #7: the reported case — 39 h week over 5 days heals 78 → 7.8,
		// which is exactly the divisor the comp-day estimate derives.
		$this->assertSame(7.8, $updates[7]);
		// #9: no derivable weekly → schema default, never a second corrupt value.
		$this->assertSame(8.0, $updates[9]);
		$this->assertCount(2, $infos);

		$this->assertIsArray($logged);
		$this->assertSame('system', $logged[0]);
		$this->assertSame('daily_hours_healed', $logged[1]);
		$this->assertSame('working_time_model', $logged[2]);
		$new = $logged[5];
		$this->assertSame(2, $new['total']);
		$this->assertSame(78.0, $new['healed'][0]['old']);
		$this->assertSame(7.8, $new['healed'][0]['new']);
	}

	public function testDerivedAboveBoundFallsBackToDefault(): void
	{
		$updates = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('executeQuery')->willReturn($this->result([
			['id' => 3, 'daily_hours' => 160.0, 'weekly_hours' => 200.0],
		]));
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params) use (&$updates): int {
				$updates[$params[1]] = $params[0];
				return 1;
			}
		);

		(new HealCorruptDailyHours($db, $this->createMock(AuditLogMapper::class)))
			->run($this->createMock(IOutput::class));

		// weekly/5 = 40 is still impossible — the healer must never trade
		// one corrupt bound for another.
		$this->assertSame(8.0, $updates[3]);
	}

	public function testQueryFailureWarnsButNeverFatals(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('executeQuery')->willThrowException(new \RuntimeException('db gone'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		(new HealCorruptDailyHours($db, $this->createMock(AuditLogMapper::class)))->run($output);
	}
}
