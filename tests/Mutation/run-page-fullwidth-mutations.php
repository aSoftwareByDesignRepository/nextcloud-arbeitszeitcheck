<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: page roots must stay full-width (no 56rem license/support caps).
 *
 * Usage (Docker):
 *   docker compose exec -T nextcloud bash -lc \
 *     'cd /var/www/html/custom_apps/arbeitszeitcheck && php -d opcache.enable_cli=0 tests/Mutation/run-page-fullwidth-mutations.php'
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = $appRoot . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	$phpunit = 'phpunit';
}

/**
 * @param list<string> $filters
 */
function run_filters(string $appRoot, string $phpunit, array $filters): int {
	$filter = implode('|', $filters);
	$cmd = escapeshellarg('php')
		. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' -c ' . escapeshellarg($appRoot . '/phpunit.xml')
		. ' --filter ' . escapeshellarg($filter);
	passthru($cmd, $code);
	return (int)$code;
}

function restore_file(string $path, string $backup): void {
	if (is_file($backup)) {
		copy($backup, $path); unlink($backup);
	}
}

/**
 * @param array{file:string,from:string,to:string,filters:list<string>} $mutation
 */
function apply_and_expect_fail(string $appRoot, string $phpunit, string $name, array $mutation): bool {
	$path = $appRoot . '/' . $mutation['file'];
	$backup = $path . '.mutation-bak';
	$original = file_get_contents($path);
	if ($original === false) {
		fwrite(STDERR, "Cannot read {$path}\n");
		return false;
	}
	if (!str_contains($original, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		return false;
	}
	file_put_contents($backup, $original);
	$mutated = str_replace($mutation['from'], $mutation['to'], $original);
	if ($mutated === $original) {
		fwrite(STDERR, "Mutation replace had no effect for {$name}\n");
		restore_file($path, $backup);
		return false;
	}
	file_put_contents($path, $mutated);
	echo "\n== mutation: {$name} ==\n";
	$code = run_filters($appRoot, $phpunit, $mutation['filters']);
	restore_file($path, $backup);
	if ($code === 0) {
		echo "MUTATION SURVIVED: {$name}\n";
		return false;
	}
	echo "killed {$name}\n";
	return true;
}

$mutations = [
	'reconstrain_license_page_root' => [
		'file' => 'css/admin-license.css',
		'from' => "max-width: none;\n\tmin-width: 0;\n}",
		'to' => "max-width: 56rem;\n\tmin-width: 0;\n}",
		'filters' => [
			'PageShellLayoutTest::testAdminLicenseAndSupportUsPageRootsAreFullWidth',
		],
	],
	'reconstrain_support_us_page_root' => [
		'file' => 'css/admin-support-us.css',
		'from' => "#app-content.azc-app--admin-support-us .azc-support-us-page,\n.azc-support-us-page {\n\tdisplay: flex;\n\tflex-direction: column;\n\tgap: var(--azc-space-6, 2rem);\n\twidth: 100%;\n\tmax-width: none;",
		'to' => "#app-content.azc-app--admin-support-us .azc-support-us-page,\n.azc-support-us-page {\n\tdisplay: flex;\n\tflex-direction: column;\n\tgap: var(--azc-space-6, 2rem);\n\twidth: 100%;\n\tmax-width: 56rem;",
		'filters' => [
			'PageShellLayoutTest::testAdminLicenseAndSupportUsPageRootsAreFullWidth',
		],
	],
	'drop_admin_user_detail_from_wide_shell' => [
		'file' => 'lib/Controller/PageShellTrait.php',
		'from' => "'admin-user-detail',\n",
		'to' => '',
		'filters' => [
			'PageShellLayoutTest::testSettingsUsesWideShell',
		],
	],
];

echo "== baseline page full-width contracts ==\n";
$baseline = run_filters($appRoot, $phpunit, [
	'PageShellLayoutTest',
]);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

$failed = [];
foreach ($mutations as $name => $mutation) {
	if (!apply_and_expect_fail($appRoot, $phpunit, $name, $mutation)) {
		$failed[] = $name;
	}
}

foreach ($mutations as $mutation) {
	$bak = $appRoot . '/' . $mutation['file'] . '.mutation-bak';
	if (is_file($bak)) {
		restore_file($appRoot . '/' . $mutation['file'], $bak);
	}
}

if ($failed !== []) {
	fwrite(STDERR, "Surviving mutations: " . implode(', ', $failed) . "\n");
	exit(1);
}

echo "\nAll page full-width mutations killed.\n";
exit(0);
