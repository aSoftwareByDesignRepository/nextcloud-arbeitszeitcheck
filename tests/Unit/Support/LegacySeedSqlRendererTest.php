<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Tests\Support\LegacySeedSqlRenderer;
use PHPUnit\Framework\TestCase;

final class LegacySeedSqlRendererTest extends TestCase
{
	public function testRendersReferencedInsertsAndUpdateForMysql(): void
	{
		$rendered = LegacySeedSqlRenderer::render(self::compiled(), self::schema(), 'mysql');
		$sql = array_column($rendered['statements'], 'sql');
		$this->assertStringContainsString('INSERT INTO `oc_at_teams`', $sql[0]);
		$this->assertStringContainsString('900001', $sql[0]);
		$this->assertStringContainsString('VALUES (900002, 900001,', $sql[1]);
		$this->assertStringContainsString('UPDATE `oc_at_absences`', $sql[3]);
		$this->assertStringContainsString('WHERE `id` = 900003', $sql[3]);
		$this->assertSame('team:therapy', array_search(900001, $rendered['references'], true));
		$this->assertCount(1, $rendered['external']);
	}

	public function testRendersPostgresSequencesAndBooleanLiterals(): void
	{
		$rendered = LegacySeedSqlRenderer::render(self::compiled(), self::schema(), 'pgsql');
		$sql = array_column($rendered['statements'], 'sql');
		$this->assertStringContainsString('TRUE', $sql[0]);
		$this->assertContains(
			"SELECT CASE WHEN pg_get_serial_sequence('oc_at_teams', 'id') IS NOT NULL THEN setval(pg_get_serial_sequence('oc_at_teams', 'id'), (SELECT COALESCE(MAX(id), 900001) FROM \"oc_at_teams\"), true) ELSE NULL END",
			$sql
		);
		$this->assertNotSame($rendered['hash'], '');
	}

	public function testRejectsUnknownColumnsInsteadOfDroppingFixtureData(): void
	{
		$compiled = self::compiled();
		$compiled['operations'][0]['data']['fantasy_column'] = 'x';
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('unknown columns');
		LegacySeedSqlRenderer::render($compiled, self::schema(), 'mysql');
	}

	public function testRejectsUnresolvedReferences(): void
	{
		$compiled = self::compiled();
		$compiled['operations'][1]['data']['team_id'] = 'ref:team:missing';
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('cannot be resolved');
		LegacySeedSqlRenderer::render($compiled, self::schema(), 'mysql');
	}

	public function testRejectsMissingRequiredColumns(): void
	{
		$compiled = self::compiled();
		unset($compiled['operations'][0]['data']['name']);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('omits required column');
		LegacySeedSqlRenderer::render($compiled, self::schema(), 'mysql');
	}

	public function testRendersLegacyUtcStorageWithoutChangingCalendarDates(): void
	{
		$compiled = self::compiled();
		$compiled['capabilities'] = ['localDatetimeStorage' => false];
		$rendered = LegacySeedSqlRenderer::render($compiled, self::schema(), 'mysql');
		$sql = array_column($rendered['statements'], 'sql');
		$this->assertStringContainsString("'2026-01-20 11:00:00'", $sql[0]);
		$this->assertStringContainsString("'2026-02-02'", $sql[2]);
	}

	private static function compiled(): array
	{
		return [
			'clock' => '2026-01-20T12:00:00+01:00',
			'operations' => [
				['key' => 'team:therapy', 'target' => 'at_teams', 'operation' => 'insert', 'data' => ['name' => 'therapy', 'sort_order' => 0, 'created_at' => '2026-01-20T12:00:00+01:00', 'active' => true]],
				['key' => 'member:therapy', 'target' => 'at_team_members', 'operation' => 'insert', 'data' => ['team_id' => 'ref:team:therapy', 'user_id' => 'azc_employee_days']],
				['key' => 'absence:vacation', 'target' => 'at_absences', 'operation' => 'insert', 'data' => ['user_id' => 'azc_employee_days', 'type' => 'vacation', 'status' => 'approved', 'start_date' => '2026-02-02', 'end_date' => '2026-02-03', 'days' => 2.0, 'reason' => 'fixture', 'created_at' => '2026-01-20T12:00:00+01:00', 'updated_at' => '2026-01-20T12:00:00+01:00']],
				['key' => 'substitute:vacation', 'target' => 'at_absences', 'operation' => 'update', 'data' => ['id' => 'ref:absence:vacation', 'substitute_user_id' => 'azc_manager']],
				['key' => 'mail', 'target' => 'external.mailhog', 'operation' => 'emit', 'data' => []],
			],
		];
	}

	private static function schema(): array
	{
		return [
			'at_teams' => ['columns' => [
				'id' => self::column('bigint', auto: true),
				'name' => self::column('varchar'),
				'sort_order' => self::column('integer', default: 0),
				'created_at' => self::column('datetime'),
				'active' => self::column('boolean', nullable: true),
			]],
			'at_team_members' => ['columns' => [
				'id' => self::column('bigint', auto: true),
				'team_id' => self::column('bigint'),
				'user_id' => self::column('varchar'),
			]],
			'at_absences' => ['columns' => [
				'id' => self::column('bigint', auto: true),
				'user_id' => self::column('varchar'),
				'type' => self::column('varchar'),
				'status' => self::column('varchar'),
				'start_date' => self::column('date'),
				'end_date' => self::column('date'),
				'days' => self::column('double', nullable: true),
				'reason' => self::column('text', nullable: true),
				'substitute_user_id' => self::column('varchar', nullable: true),
				'created_at' => self::column('datetime'),
				'updated_at' => self::column('datetime'),
			]],
		];
	}

	private static function column(string $type, bool $nullable = false, bool $auto = false, mixed $default = null): array
	{
		return ['type' => $type, 'nullable' => $nullable, 'auto' => $auto, 'default' => $default];
	}
}
