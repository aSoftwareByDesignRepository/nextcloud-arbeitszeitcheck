<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for DbLockGuard stale-lock self-heal.
 *
 * Each mutant must be KILLED by DbLockGuardTest; a survivor means the
 * retry/sweep contract is not actually pinned by tests.
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

function run_unit_tests(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		copy($backup, $source);
		unlink($backup);
	}
}

$suiteFilters = ['DbLockGuardTest'];

echo "== baseline DbLockGuard tests ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$file = 'lib/Service/DbLockGuard.php';

/** @var list<array{name:string,from:string,to:string}> $mutations */
$mutations = [
	[
		'name' => 'no_retry_after_sweep',
		'from' => "		self::sweepExpired(\$provider);\n		\$provider->acquireLock(\$key, \$type, \$label);",
		'to' => "		self::sweepExpired(\$provider);",
	],
	[
		'name' => 'no_sweep_before_retry',
		'from' => "		self::sweepExpired(\$provider);\n		\$provider->acquireLock(\$key, \$type, \$label);",
		'to' => "		\$provider->acquireLock(\$key, \$type, \$label);",
	],
	[
		'name' => 'retry_forever',
		'from' => "		self::sweepExpired(\$provider);\n		\$provider->acquireLock(\$key, \$type, \$label);",
		'to' => "		while (true) {\n			self::sweepExpired(\$provider);\n			try {\n				\$provider->acquireLock(\$key, \$type, \$label);\n				return;\n			} catch (LockedException) {\n			}\n		}",
	],
	[
		'name' => 'retry_on_any_throwable',
		'from' => "		} catch (LockedException) {\n			// maybe a dead holder — sweep + retry once below\n		}",
		'to' => "		} catch (\\Throwable) {\n			// maybe a dead holder — sweep + retry once below\n		}",
	],
	[
		'name' => 'sweep_failure_propagates',
		'from' => "		} catch (\\Throwable) {\n			// best-effort; the retry decides\n		}",
		'to' => "		} catch (\\Throwable \$th) {\n			throw \$th;\n		}",
	],
	[
		'name' => 'sweep_skipped_for_db_provider',
		'from' => "			if (method_exists(\$provider, 'cleanExpiredLocks')) {",
		'to' => "			if (false && method_exists(\$provider, 'cleanExpiredLocks')) {",
	],
];

$killed = 0;
$survived = [];
foreach ($mutations as $m) {
	$path = $appRoot . '/' . $file;
	$src = file_get_contents($path);
	if (!str_contains($src, $m['from'])) {
		fwrite(STDERR, "MUTANT SOURCE NOT FOUND: {$m['name']}\n");
		exit(2);
	}
	$backup = $path . '.mutbak';
	copy($path, $backup);
	file_put_contents($path, str_replace($m['from'], $m['to'], $src));
	echo "== mutant: {$m['name']} ==\n";
	$code = run_unit_tests($appRoot, $phpunit, $suiteFilters);
	restore($path, $backup);
	if ($code !== 0) {
		$killed++;
		echo "   KILLED\n";
	} else {
		$survived[] = $m['name'];
		echo "   *** SURVIVED ***\n";
	}
}

echo "\n== result: {$killed}/" . count($mutations) . " killed ==\n";
if ($survived !== []) {
	echo 'Survivors: ' . implode(', ', $survived) . "\n";
	exit(1);
}
exit(0);
