<?php

declare(strict_types=1);

/**
 * Mutation gauntlet for DATEV org credentials + Personalnummer validation.
 *
 * Usage (Docker):
 *   docker compose exec -u www-data -T nextcloud php -d opcache.enable_cli=0 -d opcache.enable=0 \
 *     /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-datev-org-mutations.php
 *
 * @copyright Copyright (c) 2026
 * @license AGPL-3.0-or-later
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = is_file($appRoot . '/vendor/bin/phpunit')
	? $appRoot . '/vendor/bin/phpunit'
	: 'phpunit';

/**
 * @param list<string> $filters
 */
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
		copy($backup, $source); unlink($backup);
	}
}

$suiteFilters = [
	'DatevOrgCredentialsTest',
	'AdminControllerTest::testUpdateAdminSettingsRejectsPartialDatevCredentials',
	'AdminUserProfileUpdateServiceTest::testApplyDatevSettingsRejectsInvalidPersonalnummer',
];

echo "== baseline DATEV org unit tests ==\n";
if (run_unit_tests($appRoot, $phpunit, $suiteFilters) !== 0) {
	fwrite(STDERR, "Baseline tests must pass before mutation run\n");
	exit(1);
}

/** @var list<array{name:string,file:string,from:string,to:string,filters?:list<string>}> $mutations */
$mutations = [
	[
		'name' => 'allow_partial_credentials',
		'file' => 'lib/Support/DatevOrgCredentials.php',
		'from' => 'if (($b === \'\') xor ($m === \'\')) {
			$errors[] = self::ERR_PAIR;
		}',
		'to' => 'if (false) {
			$errors[] = self::ERR_PAIR;
		}',
		'filters' => [
			'DatevOrgCredentialsTest::testValidatePairRejectsPartialAndInvalid',
			'AdminControllerTest::testUpdateAdminSettingsRejectsPartialDatevCredentials',
		],
	],
	[
		'name' => 'accept_alpha_personalnummer',
		'file' => 'lib/Support/DatevOrgCredentials.php',
		'from' => 'if (!preg_match(\'/^\\d{1,8}$/\', $s)) {
			return [self::ERR_PERSONAL];
		}',
		'to' => 'if (false) {
			return [self::ERR_PERSONAL];
		}',
		'filters' => [
			'DatevOrgCredentialsTest::testValidateLohnartAndPersonalnummer',
			'AdminUserProfileUpdateServiceTest::testApplyDatevSettingsRejectsInvalidPersonalnummer',
		],
	],
];

$failures = 0;
foreach ($mutations as $mutation) {
	$source = $appRoot . '/' . $mutation['file'];
	$backup = $source . '.mutbak';
	$filters = $mutation['filters'] ?? $suiteFilters;
	echo "== mutate: {$mutation['name']} ==\n";
	$contents = file_get_contents($source);
	if ($contents === false || !str_contains($contents, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$mutation['name']}\n");
		$failures++;
		continue;
	}
	copy($source, $backup);
	file_put_contents($source, str_replace($mutation['from'], $mutation['to'], $contents));
	$code = run_unit_tests($appRoot, $phpunit, $filters);
	restore($source, $backup);
	if ($code === 0) {
		fwrite(STDERR, "MUTATION SURVIVED: {$mutation['name']}\n");
		$failures++;
	} else {
		echo "killed OK: {$mutation['name']}\n";
	}
}

if ($failures > 0) {
	fwrite(STDERR, "Mutation gauntlet failed: {$failures}\n");
	exit(1);
}

echo "All DATEV org mutations killed.\n";
exit(0);
