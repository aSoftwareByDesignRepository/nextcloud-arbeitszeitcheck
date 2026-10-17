<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeMatrix;
use PHPUnit\Framework\TestCase;

final class LegacyUpgradeMatrixTest extends TestCase
{
	private string $path;

	protected function setUp(): void
	{
		$this->path = dirname(__DIR__, 2) . '/fixtures/legacy-upgrade-matrix.json';
	}

	public function testCommittedMatrixCoversCurrentProductionSourceAndDatabases(): void
	{
		$matrix = LegacyUpgradeMatrix::load($this->path);

		self::assertSame(['mysql', 'pgsql'], $matrix['databases']);
		self::assertSame(['nextcloud:32', 'nextcloud:35'], $matrix['serverImages']);
		self::assertSame(['v1.8.0'], $matrix['sourceTags']);
	}

	public function testRejectsDuplicateTags(): void
	{
		$this->expectException(\RuntimeException::class);
		LegacyUpgradeMatrix::load($this->fixture(['v1.1.1', 'v1.1.1']));
	}

	public function testRejectsLexicallySortedButSemanticallyWrongTags(): void
	{
		$this->expectException(\RuntimeException::class);
		LegacyUpgradeMatrix::load($this->fixture(['v1.1.10', 'v1.1.2']));
	}

	public function testRejectsMissingDatabase(): void
	{
		$this->expectException(\RuntimeException::class);
		LegacyUpgradeMatrix::load($this->fixture(['v1.1.1'], ['mysql']));
	}

	public function testRejectsMalformedTag(): void
	{
		$this->expectException(\RuntimeException::class);
		LegacyUpgradeMatrix::load($this->fixture(['release-1.1.1']));
	}

	public function testRejectsMissingRuntimeBoundary(): void
	{
		$data = json_decode(file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
		$data['serverImages'] = ['nextcloud:35'];
		$path = tempnam(sys_get_temp_dir(), 'azc-matrix-');
		self::assertNotFalse($path);
		file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR));
		$this->expectException(\RuntimeException::class);
		LegacyUpgradeMatrix::load($path);
	}

	private function fixture(array $tags, array $databases = ['mysql', 'pgsql']): string
	{
		$path = tempnam(sys_get_temp_dir(), 'azc-matrix-');
		self::assertNotFalse($path);
		file_put_contents($path, json_encode([
			'format' => 1,
			'databases' => $databases,
			'serverImages' => ['nextcloud:32', 'nextcloud:35'],
			'sourceTags' => $tags,
		], JSON_THROW_ON_ERROR));
		return $path;
	}
}
