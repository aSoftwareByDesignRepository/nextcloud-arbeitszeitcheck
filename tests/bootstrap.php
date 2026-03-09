<?php

declare(strict_types=1);

/**
 * Bootstrap for tests
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

// Support both Nextcloud layout (apps/ + lib/ siblings) and monorepo (lib at repo root)
$base = __DIR__ . '/../../lib/base.php';
if (!is_file($base)) {
	$base = __DIR__ . '/../../../lib/base.php';
}
require_once $base;