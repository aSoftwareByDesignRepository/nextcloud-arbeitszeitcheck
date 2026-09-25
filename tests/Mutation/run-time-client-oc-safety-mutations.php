<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: prove TimeClientBootstrap cannot regress to addInitScript.
 *
 * Run: php tests/Mutation/run-time-client-oc-safety-mutations.php
 */

$root = dirname(__DIR__, 2);
$target = $root . '/lib/Support/TimeClientBootstrap.php';
$backup = $target . '.mutation-bak';
$phpunit = $root . '/vendor/bin/phpunit';
$config = $root . '/phpunit.xml';

if (!is_file($target) || !is_file($phpunit)) {
	fwrite(STDERR, "Missing target or phpunit\n");
	exit(1);
}

$original = (string)file_get_contents($target);
file_put_contents($backup, $original);

$mutations = [
	'addInitScript_regression' => static function (string $src): string {
		return str_replace(
			"Util::addScript(Application::APP_ID, 'common/time-init');",
			"Util::addInitScript(Application::APP_ID, 'common/time-init');",
			$src
		);
	},
];

$failedToKill = [];
$killed = 0;

try {
	foreach ($mutations as $name => $mutator) {
		$mutated = $mutator($original);
		if ($mutated === $original) {
			fwrite(STDERR, "Mutation $name did not change source\n");
			exit(1);
		}
		file_put_contents($target, $mutated);

		$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($config)
			. ' --testsuite unit --filter TimeClientBootstrapTest 2>&1';
		exec($cmd, $out, $code);
		if ($code === 0) {
			$failedToKill[] = $name;
			echo "SURVIVED (bad): $name\n";
		} else {
			$killed++;
			echo "KILLED (good): $name\n";
		}
	}
} finally {
	file_put_contents($target, $original);
	@unlink($backup);
}

// Locale JS unwrap mutation (separate target).
$enJs = $root . '/l10n/en.js';
$enOriginal = (string)file_get_contents($enJs);
$enBare = preg_replace('/^[\s\S]*?__azcBootL10n\(function \(OC\) \{\n/', '', $enOriginal) ?? '';
$enBare = preg_replace('/\n\t\}\);\n\}\);\n?\z/', "\n", $enBare) ?? $enBare;
// Simpler unwrap: strip wrapper markers if present
if (str_contains($enOriginal, '__azcBootL10n')) {
	$unwrapped = $enOriginal;
	$unwrapped = (string)preg_replace('/^\(function \(\) \{\n\t\'use strict\';\n[\s\S]*?__azcBootL10n\(function \(OC\) \{\s*/', '', $unwrapped);
	$unwrapped = (string)preg_replace('/\n\t\}\);\n\}\)\(\);\s*$/', "\n", $unwrapped);
	if ($unwrapped === $enOriginal) {
		fwrite(STDERR, "en_js_unwrap mutation did not change l10n/en.js — anchor drifted\n");
		exit(1);
	}
	file_put_contents($enJs, $unwrapped);
	$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($config)
		. ' --testsuite unit --filter L10nOcSafeBootstrapContractTest 2>&1';
	exec($cmd, $out2, $code2);
	file_put_contents($enJs, $enOriginal);
	if ($code2 === 0) {
		$failedToKill[] = 'en_js_unwrap';
		echo "SURVIVED (bad): en_js_unwrap\n";
	} else {
		$killed++;
		echo "KILLED (good): en_js_unwrap\n";
	}
}

if ($failedToKill !== []) {
	fwrite(STDERR, 'Mutations survived: ' . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo 'OK: killed ' . $killed . " mutations\n";
exit(0);
