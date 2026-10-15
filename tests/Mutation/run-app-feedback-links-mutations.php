<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for AppFeedbackLinks.
 *
 * Usage (host, from repo nextcloud/):
 *   php tests/Mutation/run-app-feedback-links-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$appId = basename($appRoot);
$workspaceRoot = dirname($appRoot, 2);
$source = $appRoot . '/lib/Support/AppFeedbackLinks.php';
$backup = $source . '.mutation-bak';
$orig = (string) file_get_contents($source);

function run_unit_tests(string $appRoot, string $workspaceRoot, string $appId): int
{
	$filter = 'AppFeedbackLinksTest';
	$inside = is_file('/var/www/html/lib/base.php');
	if ($inside) {
		$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
			? $appRoot . '/vendor/bin/phpunit'
			: 'phpunit';
		$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
			. ' --do-not-cache-result'
			. ' --filter ' . escapeshellarg($filter);
	} else {
		$cmd = 'docker compose -f ' . escapeshellarg($workspaceRoot . '/docker-compose.yml')
			. ' exec -u www-data -T nextcloud php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. '/var/www/html/custom_apps/' . $appId . '/vendor/bin/phpunit '
			. '-c /var/www/html/custom_apps/' . $appId . '/phpunit.xml '
			. '--do-not-cache-result '
			. '--filter ' . escapeshellarg($filter);
	}
	passthru($cmd, $code);

	return (int)$code;
}

function restore(string $source, string $backup): void
{
	if (is_file($backup)) {
		file_put_contents($source, (string) file_get_contents($backup));
		unlink($backup);
	}
}

if (!is_file($source)) {
	fwrite(STDERR, "missing $source\n");
	exit(1);
}

echo "== baseline AppFeedbackLinksTest ==\n";
$run = static fn (): int => run_unit_tests($appRoot, $workspaceRoot, $appId);
if ($run() !== 0) {
	fwrite(STDERR, "baseline AppFeedbackLinksTest failed\n");
	exit(1);
}

$mutations = [
	[
		'name' => 'swap inbox to info@',
		'from' => "public const FEEDBACK_EMAIL = 'dev@software-by-design.de';",
		'to' => "public const FEEDBACK_EMAIL = 'info@software-by-design.de';",
	],
	[
		'name' => 'drop query sanitizer',
		'from' => 'if ($this->isBlockedQueryKey((string)$key)) {',
		'to' => 'if (false && $this->isBlockedQueryKey((string)$key)) {',
	],
	[
		'name' => 'allow unsafe error codes in mailto body',
		'from' => "if (\$errorCode !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,64}$/', \$errorCode)) {",
		'to' => "if (false && \$errorCode !== '' && !preg_match('/^[A-Za-z0-9._:-]{1,64}$/', \$errorCode)) {",
	],
];

copy($source, $backup);
$failed = 0;
try {
	foreach ($mutations as $m) {
		$src = file_get_contents($source);
		if ($src === false || !str_contains($src, $m['from'])) {
			fwrite(STDERR, "mutation needle missing: {$m['name']}\n");
			$failed++;
			file_put_contents($source, $orig);
			continue;
		}
		echo "== mutate: {$m['name']} ==\n";
		if (file_put_contents($source, str_replace($m['from'], $m['to'], $src)) === false) {
			fwrite(STDERR, "MUTANT WRITE FAILED: {$m['name']} — fix file perms, do not trust survivors\n");
			$failed++;
			continue;
		}
		$code = $run();
		if (file_put_contents($source, $orig) === false) {
			fwrite(STDERR, "RESTORE FAILED: {$m['name']} — source left mutated, STOP\n");
			$failed++;
			continue;
		}
		if ($code === 0) {
			fwrite(STDERR, "SURVIVED: {$m['name']}\n");
			$failed++;
		} else {
			echo "killed: {$m['name']}\n";
		}
	}
} finally {
	restore($source, $backup);
}

if ($failed !== 0) {
	fwrite(STDERR, "\n{$failed} AppFeedbackLinks mutation(s) survived.\n");
	exit(1);
}

echo "\nAll AppFeedbackLinks mutations killed.\n";
exit(0);
