<?php

declare(strict_types=1);

/**
 * Compliance sub-navigation: Overview, Violations, Reports
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */

$urlGenerator = $_['urlGenerator'] ?? \OCP\Server::get(\OCP\IURLGenerator::class);
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$currentPage = $_SERVER['REQUEST_URI'] ?? '';
$isOverview = strpos($currentPage, '/compliance/violations') === false && strpos($currentPage, '/compliance/reports') === false;
$isViolations = strpos($currentPage, '/compliance/violations') !== false;
$isReports = strpos($currentPage, '/compliance/reports') !== false;
?>

<nav class="compliance-tabs" role="navigation" aria-label="<?php p($l->t('Compliance sections')); ?>">
    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.compliance.dashboard')); ?>"
       class="compliance-tab <?php p($isOverview ? 'compliance-tab--active' : ''); ?>"
       <?php if ($isOverview): ?>aria-current="page" <?php endif; ?>aria-label="<?php p($l->t('Compliance overview')); ?>"
       title="<?php p($l->t('See your compliance status and recent problems')); ?>">
        <?php p($l->t('Overview')); ?>
    </a>
    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.compliance.violations')); ?>"
       class="compliance-tab <?php p($isViolations ? 'compliance-tab--active' : ''); ?>"
       <?php if ($isViolations): ?>aria-current="page" <?php endif; ?>aria-label="<?php p($l->t('List of all compliance problems')); ?>"
       title="<?php p($l->t('See all working time rule violations')); ?>">
        <?php p($l->t('Violations')); ?>
    </a>
    <a href="<?php p($urlGenerator->linkToRoute('arbeitszeitcheck.compliance.reports')); ?>"
       class="compliance-tab <?php p($isReports ? 'compliance-tab--active' : ''); ?>"
       <?php if ($isReports): ?>aria-current="page" <?php endif; ?>aria-label="<?php p($l->t('Compliance reports and statistics')); ?>"
       title="<?php p($l->t('See reports and statistics')); ?>">
        <?php p($l->t('Reports')); ?>
    </a>
</nav>
