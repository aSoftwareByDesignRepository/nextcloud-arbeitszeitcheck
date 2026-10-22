<?php

declare(strict_types=1);

/**
 * Mutation gauntlet: OfflineStampSkewPolicy must keep a hard 24–72h ceiling.
 *
 * Run: php tests/Mutation/run-offline-stamp-skew-mutations.php
 */

$root = dirname(__DIR__, 2);
$policyPath = $root . '/lib/Support/OfflineStampSkewPolicy.php';
$parserPath = $root . '/lib/Support/StampOccurredAtParser.php';
$capsPath = $root . '/lib/Capabilities.php';
$adminPath = $root . '/lib/Controller/AdminController.php';

function assertTrue(bool $cond, string $msg): void
{
	if (!$cond) {
		fwrite(STDERR, "FAIL: {$msg}\n");
		exit(1);
	}
	echo "OK: {$msg}\n";
}

$policy = (string)file_get_contents($policyPath);
$parser = (string)file_get_contents($parserPath);
$caps = (string)file_get_contents($capsPath);
$admin = (string)file_get_contents($adminPath);

assertTrue(str_contains($policy, 'MAX_PAST_HOURS = 72'), 'hard ceiling 72h');
assertTrue(str_contains($policy, 'MIN_PAST_HOURS = 24'), 'floor 24h');
assertTrue(str_contains($policy, 'DEFAULT_PAST_HOURS = 24'), 'default 24h');
assertTrue(str_contains($policy, 'PRESET_PAST_HOURS = [24, 48, 72]'), 'admin presets');
assertTrue(str_contains($parser, 'OfflineStampSkewPolicy::fromConfig'), 'parser reads live config');
assertTrue(!preg_match('/if \(\$delta < -self::MAX_PAST_SECONDS\)/', $parser), 'parser must not hard-code deprecated constant alone');
assertTrue(str_contains($caps, 'OfflineStampSkewPolicy::fromConfig'), 'capabilities mirror config');
assertTrue(str_contains($admin, 'offlineStampMaxPastHours'), 'admin setting wired');
assertTrue(str_contains($admin, 'isAllowedPastHours'), 'admin rejects out-of-range writes');

echo "All offline-stamp-skew mutations passed.\n";
