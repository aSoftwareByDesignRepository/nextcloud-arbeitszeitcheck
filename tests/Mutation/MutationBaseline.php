<?php

declare(strict_types=1);

/**
 * Baseline-green guard for mutation runners.
 *
 * Lesson mutation_runner_baseline_red_false_kill: a runner that maps a
 * nonzero phpunit exit to KILLED inflates MSI whenever the filtered test is
 * already red — every mutant assigned to it is "killed" untested. This guard
 * extracts the runner's own kill-test filter literals ('filter' => '…',
 * 'test' => '…', 'filter' => ['…'], $filter = '…') and runs each once against
 * the UNMUTATED tree before any mutation is applied. Any red baseline exits 1.
 *
 * Usage (inside a run-*-mutations.php, before the mutation loop):
 *   require_once __DIR__ . '/MutationBaseline.php';
 *   mutationBaselineSelfCheck(__FILE__);
 *
 * Host or container: when a compose stack is reachable, baseline runs through
 * docker (mirroring the docker-delegating runners); otherwise the local
 * vendor/bin/phpunit is used. Scope-safe: derives its own paths from
 * __FILE__ so it works inside functions too.
 */
function mutationBaselineSelfCheck(string $runnerFile): void
{
	$appRoot = dirname($runnerFile, 3);
	$phpunit = $appRoot . '/vendor/bin/phpunit';
	// Callers invoke this inside the mutation loop (before the first write) —
	// run the sweep once, then no-op on later iterations.
	static $done = [];
	if (isset($done[$runnerFile])) {
		return;
	}
	$done[$runnerFile] = true;

	$src = (string)file_get_contents($runnerFile);
	$filters = [];
	// 'filter' => 'X' | 'test' => 'X' | 'filter' => ['X', 'Y']
	if (preg_match_all("/'(?:filter|tests?)'\\s*=>\\s*(\\[[^\\]]*\\]|'(?:[^'\\\\]|\\\\.)*')/s", $src, $mm)) {
		foreach ($mm[1] as $raw) {
			if (preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $raw, $sm)) {
				foreach ($sm[1] as $lit) {
					$filters[stripcslashes($lit)] = true;
				}
			}
		}
	}
	// $<anything>Filter = 'X' — $filter, $testFilter, $phpFilter, $baselineFilter…
	if (preg_match_all('/\$[a-zA-Z_]*[Ff]ilter[a-zA-Z_]*\s*=\s*\'((?:[^\'\\\\]|\\\\.)*)\'/', $src, $mm)) {
		foreach ($mm[1] as $lit) {
			$filters[stripcslashes($lit)] = true;
		}
	}
	// Inline command args: --filter 'X' | --filter X | --filter=X
	if (preg_match_all('/--filter[=\s]+\'?([A-Za-z0-9_:|\\\\]+)/', $src, $mm)) {
		foreach ($mm[1] as $lit) {
			$filters[$lit] = true;
		}
	}
	// '--filter ' . escapeshellarg('X')
	if (preg_match_all('/--filter[\'"\s.]*escapeshellarg\(\s*\'([^\']+)\'/', $src, $mm)) {
		foreach ($mm[1] as $lit) {
			$filters[$lit] = true;
		}
	}

	$base = mutationBaselinePhpunitPrefix($phpunit, $appRoot);
	$checked = 0;
	foreach (array_keys($filters) as $filter) {
		if ($filter === '') {
			continue;
		}
		$cmd = $base . ' --filter ' . escapeshellarg($filter) . ' 2>&1';
		exec($cmd, $out, $code);
		$checked++;
		if ($code !== 0) {
			fwrite(STDERR, "BASELINE-FAIL {$filter}: kill-test is red unmutated — mutation results would be meaningless\n");
			fwrite(STDERR, "  cmd: {$cmd}\n");
			fwrite(STDERR, '  tail: ' . implode(' | ', array_slice($out, -8)) . "\n");
			exit(1);
		}
		unset($out);
	}
	fwrite(STDOUT, "baseline-green: {$checked} unique kill-test filter(s)\n");
}

/**
 * Resolve the phpunit command prefix: prefer docker compose exec when the
 * stack answers (host-side runners, mirrors the docker-delegating helpers),
 * otherwise fall back to the local binary (in-container or pure-unit runs).
 */
function mutationBaselinePhpunitPrefix(string $phpunit, string $appRoot): string
{
	$composeDir = dirname($appRoot, 2);
	$appId = basename($appRoot);
	$composeFile = $composeDir . '/docker-compose.yml';
	if (is_file($composeFile)) {
		$probe = 'docker compose -f ' . escapeshellarg($composeFile)
			. ' exec -T -u www-data nextcloud php -r ' . escapeshellarg('echo "ok";')
			. ' 2>/dev/null';
		exec($probe, $probeOut, $probeCode);
		unset($probeOut);
		if ($probeCode === 0) {
			return 'docker compose -f ' . escapeshellarg($composeFile)
				. ' exec -T -u www-data nextcloud php '
				. escapeshellarg('/var/www/html/custom_apps/' . $appId . '/vendor/bin/phpunit')
				. ' -c ' . escapeshellarg('/var/www/html/custom_apps/' . $appId . '/phpunit.xml');
		}
	}
	return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml');
}
