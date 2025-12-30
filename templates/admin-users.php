<?php

declare(strict_types=1);

/**
 * Admin users management template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

// Script loading is now handled in AdminController::users() using Util::addScript()
// This replaces the deprecated script() function and follows Nextcloud best practices
style('arbeitszeitcheck', 'arbeitszeitcheck-main');
?>

<div id="arbeitszeitcheck-content"></div>