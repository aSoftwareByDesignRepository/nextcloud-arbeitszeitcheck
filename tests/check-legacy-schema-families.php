<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineCanonicalizer;
use OCA\ArbeitszeitCheck\Tests\Support\LegacySchemaFamilyMap;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeMatrix;

$root = dirname(__DIR__);
require_once $root . '/tests/Support/LegacyBaselineCanonicalizer.php';
require_once $root . '/tests/Support/LegacyUpgradeMatrix.php';
require_once $root . '/tests/Support/LegacySchemaFamilyMap.php';

try {
	$matrix = LegacyUpgradeMatrix::load($root . '/tests/fixtures/legacy-upgrade-matrix.json');
	$committed = json_decode((string)file_get_contents($root . '/tests/fixtures/legacy-schema-families.json'), true, 512, JSON_THROW_ON_ERROR);
	LegacySchemaFamilyMap::load($root . '/tests/fixtures/legacy-schema-families.json', $matrix);

	$families = [];
	foreach ($matrix['sourceTags'] as $tag) {
		$process = proc_open(
			['git', 'ls-tree', '-r', '--name-only', $tag, 'lib/Migration'],
			[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
			$pipes,
			$root,
		);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not inspect migration tree for ' . $tag);
		}
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (proc_close($process) !== 0) {
			throw new RuntimeException('Could not inspect migration tree for ' . $tag . ': ' . trim((string)$stderr));
		}
		$versions = [];
		foreach (preg_split('/\R/', trim((string)$stdout)) ?: [] as $path) {
			if (preg_match('/Version(\d+)Date/', $path, $match) === 1) {
				$versions[] = (int)$match[1];
			}
		}
		$versions = array_values(array_unique($versions));
		sort($versions, SORT_NUMERIC);
		$key = implode(',', $versions);
		$families[$key]['tags'][] = $tag;
		$families[$key]['migrationVersions'] = $versions;
	}

	$actual = ['format' => 1, 'families' => []];
	foreach (array_values($families) as $family) {
		$actual['families'][] = [
			'key' => $family['tags'][0] . '-to-' . $family['tags'][count($family['tags']) - 1],
			'tags' => $family['tags'],
			'migrationVersions' => $family['migrationVersions'],
			'minMigration' => min($family['migrationVersions']),
			'maxMigration' => max($family['migrationVersions']),
		];
	}

	if (LegacyBaselineCanonicalizer::encode($committed) !== LegacyBaselineCanonicalizer::encode($actual)) {
		throw new RuntimeException('Legacy schema family map drift detected. Regenerate and review the fixture before changing upgrade code.');
	}
	printf("Legacy schema families OK: %d tags, %d families.\n", count($matrix['sourceTags']), count($actual['families']));
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
