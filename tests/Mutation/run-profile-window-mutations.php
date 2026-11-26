<?php

declare(strict_types=1);

/**
 * Mutations for "profile save does not own the assignment or policy window".
 *
 * Each mutant must fail the named PHPUnit filter. A green run means the
 * tests did not notice the bug.
 *
 *   docker compose exec -T -u www-data nextcloud php -d opcache.enable_cli=0 \
 *     /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-profile-window-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = getenv('PHPUNIT') ?: '';
if ($phpunit === '' || !is_file($phpunit)) {
	$phpunit = is_file('/tmp/phpunit-9.6.phar') ? '/tmp/phpunit-9.6.phar' : 'phpunit';
}
$bootstrap = getenv('PHPUNIT_BOOTSTRAP') ?: ($appRoot . '/tests/bootstrap.php');

/**
 * @param list<string> $filters
 */
function run_filters(string $appRoot, string $phpunit, string $bootstrap, array $filters): int {
	$filter = implode('|', $filters);
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' --bootstrap ' . escapeshellarg($bootstrap)
		. ' --do-not-cache-result --no-configuration --filter ' . escapeshellarg($filter)
		. ' ' . escapeshellarg($appRoot . '/tests/Unit/Service/AdminUserProfileUpdateServiceTest.php');
	passthru($cmd, $code);

	return (int)$code;
}

function restore_file(string $path, string $backup): void {
	if (is_file($backup)) {
		copy($backup, $path);
		unlink($backup);
	}
}

/**
 * @param array{file:string,from:string,to:string,filters:list<string>} $mutation
 */
function apply_and_expect_fail(string $appRoot, string $phpunit, string $bootstrap, string $name, array $mutation): bool {
	$path = $appRoot . '/' . $mutation['file'];
	$backup = $path . '.mutation-bak';
	$original = file_get_contents($path);
	if ($original === false || !str_contains($original, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		return false;
	}
	file_put_contents($backup, $original);
	file_put_contents($path, str_replace($mutation['from'], $mutation['to'], $original));
	echo "\n== mutation: {$name} ==\n";
	$code = run_filters($appRoot, $phpunit, $bootstrap, $mutation['filters']);
	restore_file($path, $backup);
	if ($code === 0) {
		echo "MUTATION SURVIVED: {$name}\n";
		return false;
	}
	echo "killed {$name}\n";

	return true;
}

$mutations = [
	'profile_save_applies_client_start' => [
		'file' => 'lib/Service/AdminUserProfileUpdateService.php',
		'from' => "unset(\$params['startDate'], \$params['endDate']);",
		'to' => "unset(\$params['endDate']);",
		'filters' => ['testProfileSaveDoesNotRewriteAssignmentWindow'],
	],
	'profile_save_applies_client_end' => [
		'file' => 'lib/Service/AdminUserProfileUpdateService.php',
		'from' => "unset(\$params['startDate'], \$params['endDate']);",
		'to' => "unset(\$params['startDate']);",
		'filters' => ['testProfileSaveDoesNotRewriteAssignmentWindow'],
	],
	'profile_save_lets_policy_start_drift' => [
		'file' => 'lib/Service/AdminUserProfileUpdateService.php',
		'from' => "\$params['effectiveFrom'] = \$from->format('Y-m-d');",
		'to' => '/* mutated: effectiveFrom not pinned */',
		'filters' => ['testProfileSaveKeepsVacationPolicyWindowWhenClientDrifts'],
	],
	'profile_save_clears_policy_end' => [
		'file' => 'lib/Service/AdminUserProfileUpdateService.php',
		'from' => "\$params['effectiveTo'] = \$to !== null ? \$to->format('Y-m-d') : null;",
		'to' => '/* mutated: effectiveTo not pinned */',
		'filters' => ['testProfileSaveKeepsVacationPolicyWindowWhenClientDrifts'],
	],
	'omitted_policy_date_opens_a_new_row' => [
		'file' => 'lib/Service/AdminUserProfileUpdateService.php',
		'from' => "return \$this->userVacationPolicyAssignmentMapper->findCurrentByUser(\$userId, new \\DateTime('today'));",
		'to' => 'return null;',
		'filters' => ['testProfileSaveWithoutPolicyDatesUpdatesCurrentPolicyInPlace'],
	],
];

$killed = 0;
foreach ($mutations as $name => $mutation) {
	if (apply_and_expect_fail($appRoot, $phpunit, $bootstrap, $name, $mutation)) {
		$killed++;
	}
}

echo "\n{$killed}/" . count($mutations) . " killed\n";
exit($killed === count($mutations) ? 0 : 1);
