<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for the employee profile layout contract.
 *
 * Each mutant is a one-line layout regression. The contract test must fail
 * on it, then the file is restored.
 *
 * Usage (from nextcloud/):
 *   docker compose exec -T -u www-data -w /var/www/html/custom_apps/arbeitszeitcheck nextcloud \
 *     php -d opcache.enable_cli=0 tests/Mutation/run-admin-user-detail-layout-mutations.php
 *
 * @copyright Copyright (c) 2026, Software by Design GbR
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = getenv('PHPUNIT');
if (!is_string($phpunit) || $phpunit === '') {
	$phpunit = $appRoot . '/vendor/bin/phpunit';
}
if (!is_file($phpunit)) {
	fwrite(STDERR, "phpunit not found at {$phpunit}\n");
	exit(1);
}

$restore = [];
register_shutdown_function(static function () use (&$restore): void {
	foreach ($restore as $path => $original) {
		file_put_contents($path, $original);
	}
});

/**
 * @param list<string> $filters
 */
function run_contract(string $appRoot, string $phpunit, array $filters): int
{
	$filter = implode('|', $filters);
	$bootstrap = getenv('PHPUNIT_BOOTSTRAP');
	$useBootstrap = is_string($bootstrap) && $bootstrap !== '';
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ($useBootstrap
			? ' --no-configuration --bootstrap ' . escapeshellarg($bootstrap)
			: ' -c ' . escapeshellarg($appRoot . '/phpunit.xml'))
		. ' --filter ' . escapeshellarg($filter)
		. ' ' . escapeshellarg($appRoot . '/tests/Unit/Templates/AdminUserDetailLayoutContractTest.php');
	passthru($cmd, $code);

	return (int)$code;
}

/**
 * @param array{file:string,from:string,to:string,filters:list<string>} $mutation
 */
function apply_and_expect_fail(string $appRoot, string $phpunit, string $name, array $mutation, array &$restore): bool
{
	$path = $appRoot . '/' . $mutation['file'];
	$original = file_get_contents($path);
	if ($original === false || !str_contains($original, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		return false;
	}
	$restore[$path] = $original;
	$mutated = str_replace($mutation['from'], $mutation['to'], $original);
	if ($mutated === $original) {
		fwrite(STDERR, "Mutation replace had no effect for {$name}\n");
		return false;
	}
	file_put_contents($path, $mutated);
	echo "\n== mutation: {$name} ==\n";
	$code = run_contract($appRoot, $phpunit, $mutation['filters']);
	file_put_contents($path, $original);
	unset($restore[$path]);
	if ($code === 0) {
		echo "MUTATION SURVIVED: {$name}\n";
		return false;
	}
	echo "killed {$name}\n";
	return true;
}

$filter = ['AdminUserDetailLayoutContractTest'];
$mutations = [
	'underline_the_arrow_with_the_label' => [
		'file' => 'css/admin-users.css',
		'from' => ".admin-user-detail__back-label {\n  text-decoration: underline;",
		'to' => ".admin-user-detail__back-label {\n  text-decoration: none;",
		'filters' => $filter,
	],
	'collapse_section_fields_to_one_column' => [
		'file' => 'css/admin-users.css',
		'from' => 'grid-template-columns: repeat(2, minmax(0, 1fr));',
		'to' => 'grid-template-columns: repeat(1, minmax(0, 1fr));',
		'filters' => $filter,
	],
	'grid_hidden_custom_model_fields' => [
		'file' => 'css/admin-users.css',
		'from' => '.admin-user-detail .user-custom-model__fields:not([hidden])',
		'to' => '.admin-user-detail .user-custom-model__fields',
		'filters' => $filter,
	],
	'dock_save_bar_above_the_edge' => [
		'file' => 'css/admin-users.css',
		'from' => "position: sticky;\n  bottom: 0;",
		'to' => "position: sticky;\n  bottom: 0.5rem;",
		'filters' => $filter,
	],
	'leave_a_gap_under_the_save_bar' => [
		'file' => 'css/admin-users.css',
		'from' => 'margin: 0 calc(-1 * var(--azc-space-5, 24px)) 0;',
		'to' => 'margin: 0 calc(-1 * var(--azc-space-5, 24px)) 1rem;',
		'filters' => $filter,
	],
	'restore_the_review_lead' => [
		'file' => 'lib/Controller/AdminController.php',
		'from' => "\$pageHelp = \$found\n\t\t\t? ''",
		'to' => "\$pageHelp = \$found\n\t\t\t? 'Review and update this employee'",
		'filters' => $filter,
	],
];

$killed = 0;
foreach ($mutations as $name => $mutation) {
	if (apply_and_expect_fail($appRoot, $phpunit, $name, $mutation, $restore)) {
		$killed++;
	}
}

echo "\n{$killed}/" . count($mutations) . " mutations killed\n";
exit($killed === count($mutations) ? 0 : 1);
