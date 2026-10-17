<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineManifest;
use PHPUnit\Framework\TestCase;

final class LegacyBaselineManifestTest extends TestCase
{
	private const MANIFEST = __DIR__ . '/../../fixtures/legacy-baseline-manifest.json';

	public function testManifestCoversEveryRequiredBaselineDomain(): void
	{
		$manifest = LegacyBaselineManifest::load(self::MANIFEST);
		$this->assertSame('legacy-baseline-scenario.json', $manifest['scenario']);
		$this->assertCount(23, $manifest['categories']);
		$this->assertCount(30, $manifest['endpoints']);
		$this->assertContains('exports_datev', $manifest['comparisons']['requiredHashPayloads']);
		$this->assertContains('execution.requestId', $manifest['comparisons']['exclude']);
	}

	public function testRejectsReorderedCategories(): void
	{
		$manifest = json_decode(file_get_contents(self::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
		[$manifest['categories'][0], $manifest['categories'][1]] = [$manifest['categories'][1], $manifest['categories'][0]];
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('contract order');
		$this->load($manifest);
	}

	public function testRejectsMissingHashProtectedDomain(): void
	{
		$manifest = json_decode(file_get_contents(self::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
		unset($manifest['comparisons']['requiredHashPayloads'][array_search('absences', $manifest['comparisons']['requiredHashPayloads'], true)]);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('omits required hash payloads: absences');
		$this->load($manifest);
	}

	public function testRejectsUnknownCategory(): void
	{
		$manifest = json_decode(file_get_contents(self::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
		$manifest['endpoints'][0]['category'] = 'unbounded_endpoint';
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('endpoint probe is malformed');
		$this->load($manifest);
	}

	public function testRejectsDuplicateProbe(): void
	{
		$manifest = json_decode(file_get_contents(self::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
		$manifest['endpoints'][] = $manifest['endpoints'][0];
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('Duplicate legacy baseline endpoint probe');
		$this->load($manifest);
	}

	public function testRejectsCategoryWithoutProbe(): void
	{
		$manifest = json_decode(file_get_contents(self::MANIFEST), true, 512, JSON_THROW_ON_ERROR);
		$manifest['endpoints'] = array_values(array_filter(
			$manifest['endpoints'],
			static fn (array $endpoint): bool => $endpoint['category'] !== 'mobile_offline_replay'
		));
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('lacks an executable probe');
		$this->load($manifest);
	}

	private function load(array $manifest): array
	{
		$path = tempnam(sys_get_temp_dir(), 'azc-manifest-');
		file_put_contents($path, json_encode($manifest));
		try {
			return LegacyBaselineManifest::load($path);
		} finally {
			unlink($path);
		}
	}
}
