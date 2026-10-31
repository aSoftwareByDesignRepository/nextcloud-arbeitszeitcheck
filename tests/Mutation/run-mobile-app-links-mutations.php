<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for MobileAppLinks::publicHttpsUrl.
 * No Infection dependency: each edit must make MobileAppLinksTest fail.
 *
 * Run inside the Nextcloud container:
 *   php -d opcache.enable_cli=0 tests/Mutation/run-mobile-app-links-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$source = $appRoot . '/lib/Support/MobileAppLinks.php';
$orig = (string) file_get_contents($source);

register_shutdown_function(static function () use ($source, $orig): void {
	$current = is_file($source) ? (string) file_get_contents($source) : '';
	if ($current !== $orig) {
		file_put_contents($source, $orig);
	}
});

function run_mobile_app_links_tests(string $appRoot): int
{
	$phpunit = '/var/www/html/custom_apps/budgetcheck/vendor/bin/phpunit';
	if (!is_file($phpunit)) {
		fwrite(STDERR, "budgetcheck phpunit is required to run this gauntlet\n");

		return 2;
	}
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --do-not-cache-result --filter ' . escapeshellarg('MobileAppLinksTest');
	passthru($cmd, $code);

	return (int) $code;
}

if (!is_file($source)) {
	fwrite(STDERR, "Missing MobileAppLinks.php\n");
	exit(1);
}

echo "== baseline MobileAppLinksTest ==\n";
if (run_mobile_app_links_tests($appRoot) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$mutations = [
	'drop_prefix_trailing_slash' => [
		'from' => "if (!str_starts_with(\$requiredPrefix, 'https://') || !str_ends_with(\$requiredPrefix, '/')) {",
		'to' => "if (!str_starts_with(\$requiredPrefix, 'https://')) {",
	],
	'drop_prefix_https' => [
		'from' => "if (!str_starts_with(\$requiredPrefix, 'https://') || !str_ends_with(\$requiredPrefix, '/')) {",
		'to' => "if (!str_ends_with(\$requiredPrefix, '/')) {",
	],
	'drop_fallback_https' => [
		'from' => "if (\$fallback !== '' && !str_starts_with(\$fallback, 'https://')) {",
		'to' => "if (false && \$fallback !== '' && !str_starts_with(\$fallback, 'https://')) {",
	],
	'drop_trim' => [
		'from' => '$candidate = trim($candidate);',
		'to' => '$candidate = $candidate;',
	],
	'drop_control_chars' => [
		'from' => "if (\$candidate === '' || preg_match('/[\\x00-\\x20\\x7F\\\\\\\\]/', \$candidate) === 1) {",
		'to' => "if (\$candidate === '') {",
	],
	'drop_host_prefix' => [
		'from' => "if (!str_starts_with(\$candidate, \$requiredPrefix)) {\n\t\t\treturn \$fallback;\n\t\t}",
		'to' => "if (false) {\n\t\t\treturn \$fallback;\n\t\t}",
	],
	'always_return_fallback' => [
		'from' => "\t\treturn \$candidate;\n",
		'to' => "\t\treturn \$fallback;\n",
	],
];

$failedToKill = [];
foreach ($mutations as $name => $pair) {
	echo "\n== mutation: {$name} ==\n";
	$original = (string) file_get_contents($source);
	if (!str_contains($original, $pair['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		$failedToKill[] = $name . ' (anchor missing)';
		continue;
	}
	$mutated = str_replace($pair['from'], $pair['to'], $original);
	if ($mutated === $original || file_put_contents($source, $mutated) === false) {
		fwrite(STDERR, "Mutation did not apply for {$name}\n");
		file_put_contents($source, $original);
		$failedToKill[] = $name . ' (no effect)';
		continue;
	}
	$code = run_mobile_app_links_tests($appRoot);
	file_put_contents($source, $original);
	if ($code === 0) {
		$failedToKill[] = $name;
		echo "MUTATION SURVIVED: {$name}\n";
	} else {
		echo "killed {$name}\n";
	}
}

if ((string) file_get_contents($source) !== $orig) {
	file_put_contents($source, $orig);
}

if ($failedToKill !== []) {
	fwrite(STDERR, 'Mutations not killed: ' . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "\nAll MobileAppLinks publicHttpsUrl mutations killed.\n";
exit(0);
