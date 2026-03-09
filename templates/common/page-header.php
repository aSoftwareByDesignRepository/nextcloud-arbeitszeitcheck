<?php

/**
 * Common page header component for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


$pageTitle = $pageTitle ?? '';
$pageDescription = $pageDescription ?? '';
?>

<?php if (!empty($pageTitle)): ?>
    <div class="section page-header-section">
        <div class="header-content">
            <h1 class="page-title"><?php p($pageTitle); ?></h1>
            <?php if (!empty($pageDescription)): ?>
                <p class="page-description"><?php p($pageDescription); ?></p>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
