<?php

declare(strict_types=1);

/**
 * Mutations for the combined work-schedule periods section.
 *
 * Each mutant must fail the named PHPUnit filter. A green run means the
 * tests did not notice the bug.
 *
 *   docker compose exec -T -u 502:502 nextcloud php -d opcache.enable_cli=0 \
 *     /var/www/html/custom_apps/arbeitszeitcheck/tests/Mutation/run-removed-periods-mutations.php
 */

$appRoot = dirname(__DIR__, 2);
$phpunit = getenv('PHPUNIT') ?: '';
if ($phpunit === '' || !is_file($phpunit)) {
	$phpunit = is_file('/tmp/phpunit-9.6.phar') ? '/tmp/phpunit-9.6.phar' : 'phpunit';
}
$bootstrap = getenv('PHPUNIT_BOOTSTRAP') ?: ($appRoot . '/tests/bootstrap.php');
$testFile = $appRoot . '/tests/Unit/Service/AssignmentWriteServiceTest.php';

/**
 * @param list<string> $filters
 */
function run_filters(string $phpunit, string $bootstrap, string $testFile, array $filters): int {
	$filter = implode('|', $filters);
	$cmd = 'php -d opcache.enable_cli=0 -d opcache.enable=0 '
		. escapeshellarg($phpunit)
		. ' --bootstrap ' . escapeshellarg($bootstrap)
		. ' --do-not-cache-result --no-configuration --filter ' . escapeshellarg($filter)
		. ' ' . escapeshellarg($testFile);
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
function apply_and_expect_fail(string $appRoot, string $phpunit, string $bootstrap, string $testFile, string $name, array $mutation): bool {
	$path = $appRoot . '/' . $mutation['file'];
	$backup = $path . '.mutation-bak';
	$original = file_get_contents($path);
	if ($original === false || !str_contains($original, $mutation['from'])) {
		fwrite(STDERR, "Mutation anchor not found for {$name}\n");
		return false;
	}
	if (substr_count($original, $mutation['from']) !== 1) {
		fwrite(STDERR, "Mutation anchor is not unique for {$name}\n");
		return false;
	}
	file_put_contents($backup, $original);
	file_put_contents($path, str_replace($mutation['from'], $mutation['to'], $original));
	echo "\n== mutation: {$name} ==\n";
	$code = run_filters($phpunit, $bootstrap, $testFile, $mutation['filters']);
	restore_file($path, $backup);
	if ($code === 0) {
		echo "MUTATION SURVIVED: {$name}\n";
		return false;
	}
	echo "killed {$name}\n";

	return true;
}

$mutations = [
	'drop_action_recheck' => [
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => "if (\$log->getAction() !== 'user_working_time_model_deleted') {\n\t\t\t\tcontinue;\n\t\t\t}",
		'to' => '',
		'filters' => ['testListRemovedDropsOtherUsersActionsEntitiesAndGarbage'],
	],
	'drop_user_recheck' => [
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => "if (\$log->getUserId() !== \$userId) {\n\t\t\t\tcontinue;\n\t\t\t}",
		'to' => '',
		'filters' => ['testListRemovedDropsOtherUsersActionsEntitiesAndGarbage'],
	],
	'drop_newest_first_sort' => [
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => "usort(\$entries, static function (array \$a, array \$b): int {\n\t\t\treturn [\$b['removed_at'] ?? '', \$b['id']] <=> [\$a['removed_at'] ?? '', \$a['id']];\n\t\t});",
		'to' => '',
		'filters' => ['testListRemovedCapsAtFiftyNewestFirst'],
	],
	'hide_truncation' => [
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => '$truncated = count($raw) > self::REMOVED_LIST_LIMIT;',
		'to' => '$truncated = false;',
		'filters' => ['testListRemovedCapsAtFiftyNewestFirst'],
	],
	'treat_personal_as_catalog_name' => [
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => "if (!empty(\$old['modelIsPersonal'])) {\n\t\t\treturn (string)\$this->l10n->t('Individual contract');\n\t\t}",
		'to' => '',
		'filters' => ['testListRemovedResolvesPersonalLookupAndDeletedNames'],
	],
	'drop_actor_uid_fallback' => [
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => "\$who = \$display !== '' ? \$display : (\$actorId !== '' ? \$actorId : (string)\$this->l10n->t('Unknown'));",
		'to' => "\$who = \$display !== '' ? \$display : (string)\$this->l10n->t('Unknown');",
		'filters' => ['testListRemovedUsesDisplayNameThenUserId'],
	],
	'drop_removed_payload' => [
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => "return ['deleted' => true, 'assignment_changed' => true, 'removed' => \$removed];",
		'to' => "return ['deleted' => true, 'assignment_changed' => true];",
		'filters' => ['testDeleteOnlyForFutureRows'],
	],
	'drop_model_name_from_audit' => [
		'file' => 'lib/Service/UserWorkingTimeAssignmentService.php',
		'from' => "\t\t\t'modelName' => \$catalogName,\n\t\t\t'modelIsPersonal' => \$catalogIsPersonal,\n",
		'to' => '',
		'filters' => ['testDeleteOnlyForFutureRows'],
	],
	'drop_mapper_action_filter' => [
		'file' => 'lib/Db/AuditLogMapper.php',
		'from' => "->andWhere(\$qb->expr()->eq('action', \$qb->createNamedParameter(\$action)))\n",
		'to' => '',
		'filters' => ['testRemovedAuditQueryFiltersUserActionAndEntity'],
	],
];

$killed = 0;
foreach ($mutations as $name => $mutation) {
	if (apply_and_expect_fail($appRoot, $phpunit, $bootstrap, $testFile, $name, $mutation)) {
		$killed++;
	}
}

echo "\n{$killed}/" . count($mutations) . " mutations killed\n";
exit($killed === count($mutations) ? 0 : 1);
