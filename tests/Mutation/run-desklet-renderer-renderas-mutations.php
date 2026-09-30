<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: prove DashboardDeskletWorkspaceRenderer cannot regress to a
 * truthy renderAs — that reintroduces the mid-request TemplateLayout which
 * consumed the page's script registry and broke the NC dashboard (OC undefined).
 *
 * Run: php tests/Mutation/run-desklet-renderer-renderas-mutations.php
 */

$root = dirname(__DIR__, 2);
$target = $root . '/lib/Service/DashboardDeskletWorkspaceRenderer.php';
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
	'literal_blank_string' => static function (string $src): string {
		return str_replace(
			'TemplateResponse::RENDER_AS_BLANK',
			"'blank'",
			$src
		);
	},
	'user_layout_renderas' => static function (string $src): string {
		return str_replace(
			'TemplateResponse::RENDER_AS_BLANK',
			'TemplateResponse::RENDER_AS_USER',
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
			. ' --testsuite unit --filter DashboardDeskletRenderServiceTest 2>&1';
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
	unlink($backup);
}

if ($failedToKill !== []) {
	fwrite(STDERR, 'Unkilled mutations: ' . implode(', ', $failedToKill) . "\n");
	exit(1);
}

echo "All $killed mutations killed.\n";
exit(0);
