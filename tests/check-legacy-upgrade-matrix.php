<?php

declare(strict_types=1);

use OCA\ArbeitszeitCheck\Tests\Support\LegacyUpgradeMatrix;

$root = dirname(__DIR__);
require_once $root . '/tests/Support/LegacyUpgradeMatrix.php';

try {
	$matrix = LegacyUpgradeMatrix::load($root . '/tests/fixtures/legacy-upgrade-matrix.json');
	$process = proc_open(
		['git', 'tag', '--list', 'v*', '--sort=version:refname'],
		[['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
		$pipes,
		$root,
	);
	if (!is_resource($process)) {
		throw new RuntimeException('Could not start git tag inventory.');
	}
	fclose($pipes[0]);
	$stdout = stream_get_contents($pipes[1]);
	$stderr = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$exitCode = proc_close($process);
	if ($exitCode !== 0) {
		throw new RuntimeException('Could not read git tags: ' . trim((string)$stderr));
	}
	$tags = array_values(array_filter(preg_split('/\R/', trim((string)$stdout)) ?: [], static fn (string $tag): bool => $tag !== ''));
	$latest = $tags[array_key_last($tags)] ?? null;
	if (!is_string($latest) || $matrix['sourceTags'] !== [$latest]) {
		throw new RuntimeException(sprintf(
			'Production upgrade source is stale. Expected [%s], declared [%s].',
			$latest ?? 'none',
			implode(', ', $matrix['sourceTags']),
		));
	}
	printf("Production upgrade source OK: %s × %d runtimes × %d databases = %d upgrade paths.\n", $latest, count($matrix['serverImages']), count($matrix['databases']), count($matrix['serverImages']) * count($matrix['databases']));
} catch (Throwable $e) {
	fwrite(STDERR, $e->getMessage() . "\n");
	exit(1);
}
