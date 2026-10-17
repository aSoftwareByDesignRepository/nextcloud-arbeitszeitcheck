<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Tests\Support\LegacySchemaFamilyMap;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeMatrix;
use PHPUnit\Framework\TestCase;

final class LegacySchemaFamilyMapTest extends TestCase
{
	private const MAP = __DIR__ . '/../../fixtures/legacy-schema-families.json';
	private const MATRIX = __DIR__ . '/../../fixtures/legacy-upgrade-matrix.json';

	public function testMapsCurrentProductionSourceSchema(): void
	{
		$map = self::map();
		$this->assertCount(1, $map->families());
		$this->assertSame(1046, $map->familyForTag('v1.8.0')['maxMigration']);
		$this->assertTrue($map->capabilitiesForTag('v1.8.0')['monthClosures']);
		$this->assertTrue($map->capabilitiesForTag('v1.8.0')['mobileKioskIdempotency']);
		$this->assertFalse($map->capabilitiesForTag('v1.8.0')['workLocation']);
	}

	public function testRejectsUncoveredTag(): void
	{
		$data = self::data();
		unset($data['families'][0]['tags'][0]);
		$this->expectMapFailure($data, 'malformed');
	}

	public function testRejectsDuplicateTagAcrossFamilies(): void
	{
		$data = self::data();
		$data['families'][0]['tags'][] = 'v1.8.0';
		$this->expectMapFailure($data, 'missing or duplicate source tag');
	}

	public function testRejectsUnsortedMigrationSet(): void
	{
		$data = self::data();
		$data['families'][0]['migrationVersions'] = array_reverse($data['families'][0]['migrationVersions']);
		$this->expectMapFailure($data, 'strictly ordered');
	}

	public function testRejectsMigrationBoundsThatDoNotMatchSet(): void
	{
		$data = self::data();
		$data['families'][0]['maxMigration']--;
		$this->expectMapFailure($data, 'strictly ordered');
	}

	private static function map(): LegacySchemaFamilyMap
	{
		return LegacySchemaFamilyMap::load(self::MAP, LegacyUpgradeMatrix::load(self::MATRIX));
	}

	private static function data(): array
	{
		return json_decode(file_get_contents(self::MAP), true, 512, JSON_THROW_ON_ERROR);
	}

	private function expectMapFailure(array $data, string $message): void
	{
		$path = tempnam(sys_get_temp_dir(), 'azc-schema-map-');
		file_put_contents($path, json_encode($data));
		try {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage($message);
			LegacySchemaFamilyMap::load($path, LegacyUpgradeMatrix::load(self::MATRIX));
		} finally {
			unlink($path);
		}
	}
}
