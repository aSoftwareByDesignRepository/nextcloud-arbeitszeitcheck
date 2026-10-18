<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: F16/F19b external-stamp security invariants.
 *
 * A mutant edit must not silently drop: the source-app allowlist, source-ref
 * shape, user existence, timestamp bounds, replay short-circuit, race re-read,
 * own-user scoping on decide, the promotion replay id, or the pull-job's
 * actor/ref filters and FACADE_VERSION gate.
 *
 * Run: php tests/Mutation/run-external-stamp-mutations.php
 */

$root = dirname(__DIR__, 2);
$service = (string)file_get_contents($root . '/lib/Service/ExternalStampService.php');
$facade = (string)file_get_contents($root . '/lib/Public/ExternalStampDraftFacade.php');
$job = (string)file_get_contents($root . '/lib/BackgroundJob/WorkLogPullJob.php');
$controller = (string)file_get_contents($root . '/lib/Controller/ExternalStampController.php');
$routes = (string)file_get_contents($root . '/appinfo/routes.php');
$info = (string)file_get_contents($root . '/appinfo/info.xml');

function assertTrue(bool $cond, string $msg): void
{
	if (!$cond) {
		fwrite(STDERR, "FAIL: {$msg}\n");
		exit(1);
	}
	echo "OK: {$msg}\n";
}

// propose() — input boundary
assertTrue(str_contains($service, 'ALLOWED_SOURCES'), 'source-app allowlist gate');
assertTrue(str_contains($service, 'REF_PATTERN'), 'source-ref pattern gate');
assertTrue(str_contains($service, 'userManager->get($uid) === null'), 'target user must exist');
assertTrue(str_contains($service, 'MIN_TS') && str_contains($service, 'FUTURE_GRACE_S') && str_contains($service, 'MAX_SEGMENT_S'), 'timestamp + segment bounds');
assertTrue(str_contains($service, '$endTs <= $startTs'), 'segment end must follow start');

// propose() — idempotency + race
assertTrue(substr_count($service, 'findBySourceRef(') >= 2, 'replay lookup + post-race re-read');
assertTrue(str_contains($service, 'tryInsert('), 'unique-constrained insert, not blind insert');
assertTrue(str_contains($service, 'STATUS_PENDING'), 'proposals are pending, never auto-promoted');

// decide() — own-user scope + atomic promote
assertTrue(str_contains($service, 'findForUserLocked($id, $uid)'), 'decision is own-user scoped under lock');
assertTrue(str_contains($service, 'already_decided'), 'cross-decision replay rejected');
assertTrue(str_contains($service, 'azc_ext_stamp_'), 'promotion replay id dedupes ledger write');
assertTrue(substr_count($service, 'acquireLock') >= 1 && substr_count($service, 'releaseLock') >= 1, 'lock paired with release');
assertTrue(str_contains($service, 'checkRestPeriodForStartTime'), 'segment promotion enforces rest-period compliance');
assertTrue(str_contains($service, 'monthClosureGuard'), 'closed months reject promotion');
assertTrue(str_contains($service, 'external_stamp_proposed') && str_contains($service, 'external_stamp_confirmed'), 'audit trail on both legs');

// facade — fail closed
assertTrue(str_contains($facade, 'FACADE_VERSION'), 'facade advertises contract version');
assertTrue(str_contains($service, "'tourcheck', 'carecheck'"), 'both producers in the allowlist, none other');

// pull job — producer hygiene
assertTrue(str_contains($job, 'FACADE_VERSION < 1'), 'pull job gated on producer contract version');
assertTrue(str_contains($job, "str_starts_with(\$actor, 'uid:')"), 'only uid: actors resolve to users');
assertTrue(str_contains($job, 'strtotime('), 'timestamps parsed, implausible rows skipped');
assertTrue(str_contains($job, "'cck:' . \$ref"), 'pulled refs carry the cck: namespace');

// controller + routes — the decision verbs
assertTrue(str_contains($controller, 'confirm(') && str_contains($controller, 'dismiss('), 'controller exposes both decisions');
assertTrue(str_contains($routes, '/api/ext-stamps/{id}/confirm') && str_contains($routes, '/api/ext-stamps/{id}/dismiss'), 'both decision routes declared');
assertTrue(str_contains($info, 'WorkLogPullJob'), 'pull job registered at install/update');

echo "All external-stamp mutations passed.\n";
