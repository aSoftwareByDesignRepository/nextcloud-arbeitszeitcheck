<?php

declare(strict_types=1);

/**
 * Coverage for the dangling-tariff-reference cleanup repair step:
 * missing rule-set table → quiet no-op; per-table NULL-out → audit row with
 * per-table counts; zero dangling → no audit; one broken table → isolated,
 * remaining tables still cleaned.
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

namespace OCA\ArbeitszeitCheck\Tests\Unit\Repair;

use OCA\ArbeitszeitCheck\Db\AuditLogMapper;
use OCA\ArbeitszeitCheck\Repair\CleanDanglingTariffRuleSetRefs;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class CleanDanglingTariffRuleSetRefsTest extends TestCase
{
	private function db(array $tableExists, ?callable $executeStatement = null): IDBConnection
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(
			static fn (string $table): bool => $tableExists[$table] ?? true
		);
		if ($executeStatement !== null) {
			$db->method('executeStatement')->willReturnCallback($executeStatement);
		}
		return $db;
	}

	public function testMissingRuleSetTableIsQuietNoOp(): void
	{
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(
			static fn (string $table): bool => $table !== 'at_tariff_rule_sets'
		);
		$db->expects($this->never())->method('executeStatement');

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->never())->method('logAction');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		(new CleanDanglingTariffRuleSetRefs($db, $audit))->run($output);
	}

	public function testCleansDanglingRefsAndWritesOneAuditRow(): void
	{
		$db = $this->db([], function (string $sql): int {
			$this->assertStringContainsString('NOT IN (SELECT `id` FROM `*PREFIX*at_tariff_rule_sets`)', $sql);
			if (str_contains($sql, 'at_org_vacation_defaults')) {
				return 2;
			}
			if (str_contains($sql, 'at_user_vacation_policies')) {
				return 3;
			}
			return 0;
		});

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction')
			->with(
				'system',
				'tariff_rule_set_refs_cleaned',
				'tariff_rule_set',
				null,
				null,
				$this->callback(static function (?array $newValues): bool {
					return $newValues !== null
						&& ($newValues['total'] ?? null) === 5
						&& ($newValues['cleaned']['at_org_vacation_defaults'] ?? null) === 2
						&& ($newValues['cleaned']['at_user_vacation_policies'] ?? null) === 3;
				}),
				'system'
			);

		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->willReturnCallback(static function (string $m) use (&$messages): void {
				$messages[] = $m;
			});

		(new CleanDanglingTariffRuleSetRefs($db, $audit))->run($output);

		$this->assertStringContainsString('5', $messages[0]);
	}

	public function testNoDanglingRefsIsQuietNoOp(): void
	{
		$db = $this->db([], static fn (string $sql): int => 0);

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->never())->method('logAction');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->never())->method('info');

		(new CleanDanglingTariffRuleSetRefs($db, $audit))->run($output);
	}

	public function testMissingLayerTableIsSkipped(): void
	{
		$db = $this->db(['at_se_policy' => false], function (string $sql): int {
			$this->assertStringNotContainsString('at_se_policy', $sql);
			return str_contains($sql, 'at_team_vacation_policies') ? 1 : 0;
		});

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction')
			->with(
				'system',
				'tariff_rule_set_refs_cleaned',
				'tariff_rule_set',
				null,
				null,
				$this->callback(static fn (?array $nv): bool => ($nv['total'] ?? null) === 1),
				'system'
			);

		(new CleanDanglingTariffRuleSetRefs($db, $audit))->run($this->createMock(IOutput::class));
	}

	public function testBrokenTableIsIsolatedAndRestStillCleaned(): void
	{
		$db = $this->db([], function (string $sql): int {
			if (str_contains($sql, 'at_model_vacation_defaults')) {
				throw new \RuntimeException('boom');
			}
			return str_contains($sql, 'at_org_vacation_defaults') ? 4 : 0;
		});

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->expects($this->once())->method('logAction')
			->with(
				'system',
				'tariff_rule_set_refs_cleaned',
				'tariff_rule_set',
				null,
				null,
				$this->callback(static fn (?array $nv): bool => ($nv['total'] ?? null) === 4),
				'system'
			);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');
		$output->expects($this->never())->method('warning');

		(new CleanDanglingTariffRuleSetRefs($db, $audit))->run($output);
	}

	public function testAuditWriteFailureDoesNotBlockInfo(): void
	{
		$db = $this->db([], static fn (string $sql): int => str_contains($sql, 'at_org_vacation_defaults') ? 1 : 0);

		$audit = $this->createMock(AuditLogMapper::class);
		$audit->method('logAction')->willThrowException(new \RuntimeException('audit down'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');
		$output->expects($this->never())->method('warning');

		(new CleanDanglingTariffRuleSetRefs($db, $audit))->run($output);
	}
}
