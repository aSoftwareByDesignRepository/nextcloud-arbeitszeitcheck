<?php

declare(strict_types=1);

/**
 * Mutation: mobile web Menu shell must stay wired (GH #33).
 *
 * Usage (from nextcloud/):
 *   docker compose exec -u www-data -T nextcloud bash -lc \
 *     'cd /var/www/html/custom_apps/arbeitszeitcheck && php -d opcache.enable_cli=0 tests/Mutation/run-mobile-nav-shell-mutations.php'
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
$config = $appRoot . '/phpunit.xml';
$filter = 'MobileNavShellContractTest';

/**
 * @param list<array{name:string,file:string,search:string,replace:string}> $mutants
 */
function runShellMutations(string $appRoot, string $phpunit, string $config, string $filter, array $mutants): never
{
	$backupDir = $appRoot . '/tests/Mutation/.originals';
	if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
		fwrite(STDERR, "Cannot create mutation backup dir\n");
		exit(2);
	}

	/** @var array<string, string> $originals */
	$originals = [];
	foreach ($mutants as $mutant) {
		$path = $appRoot . '/' . $mutant['file'];
		if (!isset($originals[$path])) {
			$content = file_get_contents($path);
			if ($content === false) {
				fwrite(STDERR, "Cannot read {$path}\n");
				exit(2);
			}
			$originals[$path] = $content;
			$backupName = str_replace(['/', '\\'], '__', $mutant['file']) . '.bak';
			file_put_contents($backupDir . '/' . $backupName, $content);
		}
	}

	$restore = static function () use ($originals): void {
		foreach ($originals as $path => $content) {
			file_put_contents($path, $content);
		}
	};
	register_shutdown_function($restore);

	$killed = 0;
	$survived = [];

	foreach ($mutants as $mutant) {
		$path = $appRoot . '/' . $mutant['file'];
		$original = $originals[$path];
		$mutated = str_replace($mutant['search'], $mutant['replace'], $original);
		if ($mutated === $original) {
			fwrite(STDERR, "MISS (search string not found): {$mutant['name']}\n");
			$survived[] = $mutant['name'] . ' (miss)';
			continue;
		}
		file_put_contents($path, $mutated);
		$cmd = escapeshellarg(PHP_BINARY) . ' -d opcache.enable_cli=0 '
			. escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($config)
			. ' --filter ' . escapeshellarg($filter)
			. ' 2>&1';
		$out = [];
		exec($cmd, $out, $code);
		file_put_contents($path, $original);
		if ($code === 0) {
			$survived[] = $mutant['name'];
			fwrite(STDERR, "SURVIVED: {$mutant['name']}\n");
		} else {
			$killed++;
			echo "Killed: {$mutant['name']}\n";
		}
	}

	$restore();
	echo "Killed {$killed} / " . count($mutants) . "\n";
	if ($survived !== []) {
		fwrite(STDERR, 'Surviving mutants: ' . implode(', ', $survived) . "\n");
		exit(1);
	}
	exit(0);
}

$mutants = [
	[
		'name' => 'drop-nav-toggle-id',
		'file' => 'templates/common/page-start.php',
		'search' => 'id="azc-nav-toggle"',
		'replace' => 'id="azc-nav-toggle-broken"',
	],
	[
		'name' => 'drop-mobile-nav-script',
		'file' => 'templates/common/navigation.php',
		'search' => "Util::addScript('arbeitszeitcheck', 'common/mobile-nav');",
		'replace' => "// Util::addScript('arbeitszeitcheck', 'common/mobile-nav');",
	],
	[
		'name' => 'drop-menu-icon',
		'file' => 'lib/Service/IconCatalog.php',
		'search' => "'menu' => '<path d=\"M4 6h16M4 12h16M4 18h16\"/>',",
		'replace' => '',
	],
	[
		'name' => 'drop-css-import',
		'file' => 'css/app.css',
		'search' => "@import url('common/mobile-nav.css');\n",
		'replace' => '',
	],
];

runShellMutations($appRoot, $phpunit, $config, $filter, $mutants);
