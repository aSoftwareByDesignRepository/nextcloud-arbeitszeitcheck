<?php
/**
 * Mutation contract: Oracle-safe IN chunking for Outlook iCal / large user scopes.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = 0;

function assertTrue(bool $cond, string $msg): void
{
	global $failures;
	if (!$cond) {
		fwrite(STDERR, "FAIL: $msg\n");
		$failures++;
	} else {
		fwrite(STDOUT, "OK: $msg\n");
	}
}

$chunker = (string)file_get_contents($root . '/lib/Support/QueryInChunker.php');
$absence = (string)file_get_contents($root . '/lib/Db/AbsenceMapper.php');
$timeEntry = (string)file_get_contents($root . '/lib/Db/TimeEntryMapper.php');
$service = (string)file_get_contents($root . '/lib/Service/OutlookIcalSubscriptionService.php');

assertTrue(str_contains($chunker, 'MAX_EXPRESSIONS_PER_LIST = 1000'), 'chunker documents Oracle ceiling');
assertTrue(str_contains($chunker, 'orX(...$parts)'), 'chunker OR-combines IN chunks');
assertTrue(str_contains($chunker, 'array_chunk'), 'chunker splits large lists');
assertTrue(str_contains($absence, 'QueryInChunker::in'), 'AbsenceMapper uses QueryInChunker');
assertTrue(str_contains($timeEntry, 'QueryInChunker::in'), 'TimeEntryMapper uses QueryInChunker');
assertTrue(str_contains($service, 'listAppAccessUserIds'), 'Outlook org-wide still uses app-access user list');
assertTrue(
	!str_contains($absence, "createNamedParameter(array_values(\$userIds), IQueryBuilder::PARAM_STR_ARRAY)"),
	'AbsenceMapper no longer binds full userIds array in one IN'
);

$phpunit = is_file($root . '/vendor/bin/phpunit') ? $root . '/vendor/bin/phpunit' : 'phpunit';
$appId = basename($root);
$workspaceRoot = dirname($root, 2);

function runQueryInChunkerMutationFilter(string $root, string $phpunit, string $filter, string $workspaceRoot, string $appId): int
{
	$inside = is_file('/var/www/html/lib/base.php');
	if ($inside) {
		$cmd = escapeshellarg(PHP_BINARY)
			. ' -d opcache.enable_cli=0 -d opcache.enable=0 '
			. escapeshellarg($phpunit)
			. ' -c ' . escapeshellarg($root . '/phpunit.xml')
			. ' ' . escapeshellarg($root . '/tests/Unit/Support/QueryInChunkerTest.php')
			. ' --filter ' . escapeshellarg($filter)
			. ' > /dev/null 2>&1';
	} else {
		$cmd = 'docker compose -f ' . escapeshellarg($workspaceRoot . '/docker-compose.yml')
			. ' exec -u www-data -T nextcloud php -d opcache.enable_cli=0 -d opcache.enable=0 '
			. '/var/www/html/custom_apps/' . $appId . '/vendor/bin/phpunit '
			. '-c /var/www/html/custom_apps/' . $appId . '/phpunit.xml '
			. '/var/www/html/custom_apps/' . $appId . '/tests/Unit/Support/QueryInChunkerTest.php '
			. '--filter ' . escapeshellarg($filter)
			. ' > /dev/null 2>&1';
	}
	exec($cmd, $output, $code);
	return (int)$code;
}

function restoreQueryInChunkerMutationFile(string $path, string $backup): void
{
	if (is_file($backup)) {
		copy($backup, $path); unlink($backup);
	}
}

$chunkerPath = $root . '/lib/Support/QueryInChunker.php';
$original = file_get_contents($chunkerPath);
if ($original === false) {
	fwrite(STDERR, "Cannot read QueryInChunker\n");
	exit(1);
}

$baseline = runQueryInChunkerMutationFilter(
	$root,
	$phpunit,
	'testLargeListUsesOrCombinedChunksUnderOracleLimit',
	$workspaceRoot,
	$appId
);
if ($baseline !== 0) {
	fwrite(STDERR, "Baseline QueryInChunker unit test must pass before mutation run\n");
	exit(1);
}

$anchor = 'return $qb->expr()->orX(...$parts);';
if (!str_contains($original, $anchor)) {
	fwrite(STDERR, "Mutation anchor not found for OR-combined IN chunks\n");
	exit(1);
}

$backup = $chunkerPath . '.mutation-bak';
file_put_contents($backup, $original);
// Kill the OR combination: always return only the first chunk (drops users > chunk size).
$mutated = str_replace($anchor, 'return $parts[0];', $original);
file_put_contents($chunkerPath, $mutated);

$mutatedCode = runQueryInChunkerMutationFilter(
	$root,
	$phpunit,
	'testLargeListUsesOrCombinedChunksUnderOracleLimit',
	$workspaceRoot,
	$appId
);
restoreQueryInChunkerMutationFile($chunkerPath, $backup);

assertTrue($mutatedCode !== 0, 'dropping OR-combined chunks must fail large-list unit test');

exit($failures > 0 ? 1 : 0);
