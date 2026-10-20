<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Tests\Support\LegacyRuntimeProfileMap;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeMatrix;
use PHPUnit\Framework\TestCase;

final class LegacyRuntimeProfileMapTest extends TestCase
{
	private const MAP = __DIR__ . '/../../fixtures/legacy-runtime-profiles.json';
	private const MATRIX = __DIR__ . '/../../fixtures/legacy-upgrade-matrix.json';

	public function testMapsCurrentProductionSourceToCompatibleServerImage(): void
	{
		$map = self::map();
		$this->assertSame('nextcloud:35', $map->profileForTag('v1.8.0')['serverImage']);
		$this->assertCount(1, $map->profiles());
	}

	public function testRejectsProfileOutsideSharedCompatibilityRange(): void
	{
		$data = self::data();
		$data['profiles'][0]['serverVersion'] = '36';
		$data['profiles'][0]['serverImage'] = 'nextcloud:36';
		$this->expectMapFailure($data, 'unsupported server image');
	}

	public function testRejectsImageThatDoesNotMatchServerVersion(): void
	{
		$data = self::data();
		$data['profiles'][0]['serverImage'] = 'nextcloud:34';
		$this->expectMapFailure($data, 'unsupported server image');
	}

	public function testRejectsDuplicateTag(): void
	{
		$data = self::data();
		$data['profiles'][0]['tags'][] = 'v1.8.0';
		$this->expectMapFailure($data, 'missing or duplicate source tag');
	}

	public function testRejectsUncoveredTag(): void
	{
		$data = self::data();
		array_pop($data['profiles'][0]['tags']);
		$this->expectMapFailure($data, 'malformed or selects an unsupported server image');
	}

	public function testRejectsTagsNotCoveringTheMatrix(): void
	{
		// Well-formed profile whose tag list does not match the
		// authoritative matrix — must hit the coverage guard, not an
		// earlier structural check.
		$data = self::data();
		$data['profiles'][0]['tags'] = ['other-tag'];
		$this->expectMapFailure($data, 'authoritative upgrade matrix');
	}

	private static function map(): LegacyRuntimeProfileMap
	{
		return LegacyRuntimeProfileMap::load(self::MAP, LegacyUpgradeMatrix::load(self::MATRIX));
	}

	private static function data(): array
	{
		return json_decode(file_get_contents(self::MAP), true, 512, JSON_THROW_ON_ERROR);
	}

	private function expectMapFailure(array $data, string $message): void
	{
		$path = tempnam(sys_get_temp_dir(), 'azc-runtime-map-');
		file_put_contents($path, json_encode($data));
		try {
			$this->expectException(\RuntimeException::class);
			$this->expectExceptionMessage($message);
			LegacyRuntimeProfileMap::load($path, LegacyUpgradeMatrix::load(self::MATRIX));
		} finally {
			unlink($path);
		}
	}
}
