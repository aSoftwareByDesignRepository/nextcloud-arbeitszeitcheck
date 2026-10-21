<?php

declare(strict_types=1);

/**
 * Compliance reports template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$reportData = $_['reportData'] ?? [];
$startDate = $_['startDate'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_['endDate'] ?? date('Y-m-d');
?>

<?php include __DIR__ . '/common/page-start.php'; ?>

                <div class="azc-page-stack">

        <p class="azc-callout azc-callout--info" role="note">
            <?php p($l->t('Managers and administrators use Reports in the main menu for team exports. This page shows your personal compliance summary.')); ?>
        </p>

<div class="section azc-card">
<!-- Report Summary -->
            <div class="stats-grid">
                <div class="stat-card"
                     title="<?php p($l->t('Total number of times working time rules were broken')); ?>"
                     aria-label="<?php p($l->t('Total problems: %s', [$reportData['total_violations'] ?? 0])); ?>">
                    <div class="stat-number"><?php p($reportData['total_violations'] ?? 0); ?></div>
                    <div class="stat-label"><?php p($l->t('Total Problems')); ?></div>
                </div>
                <div class="stat-card"
                     title="<?php p($l->t('Number of problems that still need to be fixed')); ?>"
                     aria-label="<?php p($l->t('Problems to fix: %s', [$reportData['unresolved'] ?? 0])); ?>">
                    <div class="stat-number"><?php p($reportData['unresolved'] ?? 0); ?></div>
                    <div class="stat-label"><?php p($l->t('Need to Fix')); ?></div>
                </div>
            </div>

            <!-- Violations by Type -->
            <?php if (!empty($reportData['by_type'])): ?>
                <div class="section">
                    <div class="section-header">
                        <h3><?php p($l->t('Problems by Type')); ?></h3>
                        <p><?php p($l->t('See what kinds of working time problems occurred')); ?></p>
                    </div>
                    <div class="table-container" tabindex="0" role="region" aria-label="<?php p($l->t('Problems by Type')); ?>">
                        <table class="table table--hover azc-table--responsive" role="table" aria-label="<?php p($l->t('Problems by Type')); ?>">
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('Type')); ?></th>
                                    <th scope="col"><?php p($l->t('Count')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (($reportData['by_type'] ?? []) as $typeKey => $count): ?>
                                    <?php
                                    $typeLabel = match ($typeKey) {
                                        'missing_break' => $l->t('Missing break'),
                                        'excessive_working_hours' => $l->t('Excessive working hours'),
                                        'insufficient_rest_period' => $l->t('Insufficient rest period'),
                                        'daily_hours_limit_exceeded' => $l->t('Daily hours limit exceeded'),
                                        'weekly_hours_limit_exceeded' => $l->t('Weekly hours limit exceeded'),
                                        'weekly_absolute_hours_exceeded' => $l->t('Absolute weekly hours maximum exceeded'),
                                        'night_work' => $l->t('Night work'),
                                        'sunday_work' => $l->t('Sunday work'),
                                        'holiday_work' => $l->t('Holiday work'),
                                        'missing_presence' => $l->t('Required presence not covered'),
                                        default => $typeKey,
                                    };
                                    ?>
                                    <tr>
                                        <td data-label="<?php p($l->t('Type')); ?>"><?php p($typeLabel); ?></td>
                                        <td data-label="<?php p($l->t('Count')); ?>"><?php p((string)$count); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Violations by Severity -->
            <?php if (!empty($reportData['by_severity'])): ?>
                <div class="section">
                    <div class="section-header">
                        <h3><?php p($l->t('Problems by How Serious')); ?></h3>
                        <p><?php p($l->t('See how serious the problems were')); ?></p>
                    </div>
                    <div class="table-container" tabindex="0" role="region" aria-label="<?php p($l->t('Problems by How Serious')); ?>">
                        <table class="table table--hover azc-table--responsive" role="table" aria-label="<?php p($l->t('Problems by How Serious')); ?>">
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('How Serious')); ?></th>
                                    <th scope="col"><?php p($l->t('Count')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (($reportData['by_severity'] ?? []) as $severity => $count): ?>
                                    <?php
                                    $severityKey = $severity ?? '';
                                    $severityLabel = match ($severityKey) {
                                        'error' => $l->t('High'),
                                        'warning' => $l->t('Medium'),
                                        'info' => $l->t('Low'),
                                        default => $severityKey,
                                    };
                                    $severityBadge = match ($severityKey) {
                                        'error' => 'error',
                                        'warning' => 'warning',
                                        default => 'primary',
                                    };
                                    ?>
                                    <tr>
                                        <td data-label="<?php p($l->t('How Serious')); ?>">
                                            <span class="badge badge--<?php p($severityBadge); ?>">
                                                <?php p($severityLabel); ?>
                                            </span>
                                        </td>
                                        <td data-label="<?php p($l->t('Count')); ?>"><?php p($count); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

</div><!-- /.azc-page-stack -->
<?php include __DIR__ . '/common/page-end.php'; ?>
