<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Tests\Support\LegacyBaselineCanonicalizer;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyRuntimeProfileMap;
use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeMatrix;

$root = dirname(__DIR__);
require_once $root . '/tests/Support/LegacyBaselineCanonicalizer.php';
require_once $root . '/tests/Support/LegacyUpgradeMatrix.php';
require_once $root . '/tests/Support/LegacyRuntimeProfileMap.php';

try {
	$matrix = LegacyUpgradeMatrix::load($root . '/tests/fixtures/legacy-upgrade-matrix.json');
	$committed = json_decode((string)file_get_contents($root . '/tests/fixtures/legacy-runtime-profiles.json'), true, 512, JSON_THROW_ON_ERROR);
	LegacyRuntimeProfileMap::load($root . '/tests/fixtures/legacy-runtime-profiles.json', $matrix);
	$targetMax = (int)($committed['targetNextcloud']['max'] ?? 0);

	$profiles = [];
	foreach ($matrix['sourceTags'] as $tag) {
		$process = proc_open(
			['git', 'show', $tag . ':appinfo/info.xml'],
			[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
			$pipes,
			$root,
		);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not inspect app metadata for ' . $tag);
		}
		fclose($pipes[0]);
		$xml = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (proc_close($process) !== 0) {
			throw new RuntimeException('Could not inspect app metadata for ' . $tag . ': ' . trim((string)$stderr));
		}
		if (preg_match('/<dependencies>(.*?)<\/dependencies>/s', (string)$xml, $block) !== 1
			|| preg_match('/<nextcloud[^>]*min-version=["\']([^"\']+)["\'][^>]*max-version=["\']([^"\']+)["\']/', $block[1], $match) !== 1) {
			throw new RuntimeException('Missing Nextcloud dependency range in ' . $tag);
		}
		// PHP bounds are only enforced when declared inside <dependencies>;
		// Nextcloud treats php max-version as inclusive (8.4 allows PHP 8.4).
		$phpMax = null;
		if (preg_match('/<php[^>]*max-version=["\']([^"\']+)["\']/', $block[1], $phpMatch) === 1) {
			$phpMax = $phpMatch[1];
		}
		$group = $match[1] . ':' . $match[2] . ':php<=' . ($phpMax ?? 'none');
		$profiles[$group]['sourceMin'] = $match[1];
		$profiles[$group]['sourceMax'] = $match[2];
		$profiles[$group]['phpMax'] = $phpMax;
		$profiles[$group]['tags'][] = $tag;
	}

	// PHP shipped by each pinned server image (verified via docker run php -v).
	$serverPhp = [32 => '8.3', 33 => '8.4', 34 => '8.5', 35 => '8.5'];
	$actual = ['format' => 1, 'targetNextcloud' => $committed['targetNextcloud'], 'profiles' => []];
	foreach (array_values($profiles) as $profile) {
		$server = null;
		for ($candidate = min((int)$profile['sourceMax'], $targetMax); $candidate >= max((int)$profile['sourceMin'], (int)$committed['targetNextcloud']['min']); $candidate--) {
			$php = $serverPhp[$candidate] ?? null;
			if ($php === null) {
				continue;
			}
			if ($profile['phpMax'] === null || version_compare($php, $profile['phpMax'], '<=')) {
				$server = $candidate;
				break;
			}
		}
		if ($server === null) {
			throw new RuntimeException('No pinned server image satisfies the runtime constraints for tags ' . implode(',', $profile['tags']));
		}
		$actual['profiles'][] = [
			'key' => 'nc' . $profile['sourceMin'] . '-' . $profile['sourceMax'] . '-on-nc' . $server,
			'sourceMin' => $profile['sourceMin'],
			'sourceMax' => $profile['sourceMax'],
			'serverVersion' => (string)$server,
			'serverImage' => 'nextcloud:' . $server,
			'tags' => $profile['tags'],
		];
	}

	if (LegacyBaselineCanonicalizer::encode($committed) !== LegacyBaselineCanonicalizer::encode($actual)) {
		throw new RuntimeException('Legacy runtime profile drift detected. Regenerate and review the fixture before changing upgrade code.');
	}
	printf("Legacy runtime profiles OK: %d tags, %d profiles.\n", count($matrix['sourceTags']), count($actual['profiles']));
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
