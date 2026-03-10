<?php

declare(strict_types=1);

/**
 * Bootstrap for tests
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

// Support multiple layouts for running tests:
// 1. Standalone app with NEXTCLOUD_ROOT env (CI or local):
//    NEXTCLOUD_ROOT=/path/to/nextcloud (must contain lib/base.php)
// 2. App inside a Nextcloud checkout (this repo layout):
//    nextcloud-dev/
//      ├─ lib/
//      └─ apps/arbeitszeitcheck/

$candidates = [];

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '';
if ($nextcloudRoot !== '') {
	$candidates[] = rtrim($nextcloudRoot, '/\\') . '/lib/base.php';
}

// Monorepo / this dev setup: lib/ next to apps/
$candidates[] = __DIR__ . '/../../lib/base.php';

// Fallback: traditional Nextcloud layout (apps/ + lib/ siblings)
$candidates[] = __DIR__ . '/../../../lib/base.php';

$base = null;
foreach ($candidates as $candidate) {
	if (is_file($candidate)) {
		$base = $candidate;
		break;
	}
}

if ($base === null) {
	throw new RuntimeException(
		"Could not locate Nextcloud lib/base.php for tests.\n" .
		"Set NEXTCLOUD_ROOT to your Nextcloud server root or run the tests from within a Nextcloud checkout " .
		"where 'lib/base.php' exists next to 'apps/'."
	);
}

require_once $base;