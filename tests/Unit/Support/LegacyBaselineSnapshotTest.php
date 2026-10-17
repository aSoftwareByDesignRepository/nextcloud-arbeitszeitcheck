<?php

declare(strict_types=1);

namespace OCA\ArbeitszeitCheck\Tests\Unit\Support;

use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineManifest;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineSnapshot;
use PHPUnit\Framework\TestCase;

final class LegacyBaselineSnapshotTest extends TestCase
{
	private const MANIFEST = __DIR__ . '/../../fixtures/legacy-baseline-manifest.json';

	public function testCaptureProducesHashesForEveryCategory(): void
	{
		$manifest = LegacyBaselineManifest::load(self::MANIFEST);
		$snapshot = LegacyBaselineSnapshot::capture($manifest, $this->results($manifest), 'days');
		$this->assertSame(1, $snapshot['format']);
		$this->assertSame(array_keys($snapshot['payloads']), array_keys($snapshot['hashes']));
		$this->assertCount(23, $snapshot['payloads']);
		$this->assertCount(3, $snapshot['payloads']['query_latency']);
		$this->assertSame(4, $snapshot['payloads']['query_latency'][0]['metrics']['queryCount']);
	}

	public function testCaptureRejectsMissingProbeResult(): void
	{
		$manifest = LegacyBaselineManifest::load(self::MANIFEST);
		$results = $this->results($manifest);
		array_pop($results);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('one result for every manifest probe');
		LegacyBaselineSnapshot::capture($manifest, $results, 'days');
	}

	public function testCaptureRejectsFailedProbe(): void
	{
		$manifest = LegacyBaselineManifest::load(self::MANIFEST);
		$results = $this->results($manifest);
		$results[3]['status'] = 403;
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('cannot be captured as a passing baseline');
		LegacyBaselineSnapshot::capture($manifest, $results, 'days');
	}

	public function testCaptureRejectsMissingVariant(): void
	{
		$manifest = LegacyBaselineManifest::load(self::MANIFEST);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('non-empty variant');
		LegacyBaselineSnapshot::capture($manifest, $this->results($manifest), '');
	}

	public function testCompareRejectsDifferentVacationModes(): void
	{
		$manifest = LegacyBaselineManifest::load(self::MANIFEST);
		$expected = LegacyBaselineSnapshot::capture($manifest, $this->results($manifest), 'days');
		$actual = LegacyBaselineSnapshot::capture($manifest, $this->results($manifest), 'hours');
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('incompatible');
		LegacyBaselineSnapshot::compare($expected, $actual);
	}

	public function testExcludedExecutionMetadataDoesNotChangeSnapshot(): void
	{
		$manifest = LegacyBaselineManifest::load(self::MANIFEST);
		$before = $this->results($manifest);
		$after = $this->results($manifest);
		$before[0]['payload']['execution'] = ['requestId' => 'req-before', 'startedAt' => '2026-01-01T00:00:00Z'];
		$after[0]['payload']['execution'] = ['requestId' => 'req-after', 'startedAt' => '2026-01-02T00:00:00Z'];
		$expected = LegacyBaselineSnapshot::capture($manifest, $before, 'days');
		$actual = LegacyBaselineSnapshot::capture($manifest, $after, 'days');
		$this->assertTrue(LegacyBaselineSnapshot::compare($expected, $actual)['equal']);
		$this->assertSame($expected['hashes'], $actual['hashes']);
	}

	public function testCompareDetectsBusinessPayloadChangeAndAddedCategory(): void
	{
		$manifest = LegacyBaselineManifest::load(self::MANIFEST);
		$expected = LegacyBaselineSnapshot::capture($manifest, $this->results($manifest), 'days');
		$actual = LegacyBaselineSnapshot::capture($manifest, $this->results($manifest), 'days');
		$actual['payloads']['absences'][0]['payload']['value'] = 'changed';
		$actual['payloads']['unexpected_surface'] = [];
		$comparison = LegacyBaselineSnapshot::compare($expected, $actual);
		$this->assertFalse($comparison['equal']);
		$this->assertSame(['absences', 'unexpected_surface'], $comparison['mismatchedCategories']);
	}

	public function testLatencyProbeRequiresMetrics(): void
	{
		$manifest = LegacyBaselineManifest::load(self::MANIFEST);
		$results = $this->results($manifest);
		unset($results[array_key_last($results)]['metrics']);
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('lacks query and duration metrics');
		LegacyBaselineSnapshot::capture($manifest, $results, 'days');
	}

	private function results(array $manifest): array
	{
		return array_map(static function (array $endpoint): array {
			$result = [
				'status' => 200,
				'payload' => [
					'operation' => $endpoint['operation'],
					'actor' => $endpoint['actor'],
					'value' => 'stable',
				],
			];
			if ($endpoint['category'] === 'query_latency') {
				$result['metrics'] = ['queryCount' => 4, 'durationMs' => 12.5];
			}
			return $result;
		}, $manifest['endpoints']);
	}
}
