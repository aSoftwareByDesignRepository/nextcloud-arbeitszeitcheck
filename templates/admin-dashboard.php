<?php

declare(strict_types=1);

/**
 * Admin dashboard template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <div class="section">
            <div class="section-header">
                <h2><?php p($l->t('Admin Dashboard')); ?></h2>
                <p><?php p($l->t('See how many employees are using the system and check for any problems with working time rules')); ?></p>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card" 
                     title="<?php p($l->t('Total number of employees who can use time tracking')); ?>"
                     aria-label="<?php p($l->t('Total employees: %s', [$_['statistics']['total_users'] ?? 0])); ?>">
                    <div class="stat-number"><?php p($_['statistics']['total_users'] ?? 0); ?></div>
                    <div class="stat-label"><?php p($l->t('Total Employees')); ?></div>
                </div>
                <div class="stat-card"
                     title="<?php p($l->t('Number of employees who clocked in today')); ?>"
                     aria-label="<?php p($l->t('Employees active today: %s', [$_['statistics']['active_users_today'] ?? 0])); ?>">
                    <div class="stat-number"><?php p($_['statistics']['active_users_today'] ?? 0); ?></div>
                    <div class="stat-label"><?php p($l->t('Active Today')); ?></div>
                </div>
                <div class="stat-card"
                     title="<?php p($l->t('Number of working time rule violations that need attention')); ?>"
                     aria-label="<?php p($l->t('Unresolved violations: %s', [$_['statistics']['unresolved_violations'] ?? 0])); ?>">
                    <div class="stat-number"><?php p($_['statistics']['unresolved_violations'] ?? 0); ?></div>
                    <div class="stat-label"><?php p($l->t('Problems to Fix')); ?></div>
                </div>
            </div>

            <!-- Recent Violations -->
            <div class="section">
                <div class="section-header">
                    <h3><?php p($l->t('Recent Problems')); ?></h3>
                    <p><?php p($l->t('Working time rule violations that need your attention')); ?></p>
                </div>

                <?php if (empty($_['recent_violations'])): ?>
                    <div class="empty-state">
                        <h3 class="empty-state__title"><?php p($l->t('No problems found')); ?></h3>
                        <p class="empty-state__description">
                            <?php p($l->t('Great! All employees are following the working time rules correctly.')); ?>
                        </p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive" role="region" aria-label="<?php p($l->t('Recent compliance violations')); ?>">
                        <table class="table" role="table" aria-label="<?php p($l->t('Recent compliance violations')); ?>">
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('Employee')); ?></th>
                                    <th scope="col"><?php p($l->t('Problem Type')); ?></th>
                                    <th scope="col"><?php p($l->t('How Serious')); ?></th>
                                    <th scope="col"><?php p($l->t('Date')); ?></th>
                                    <th scope="col"><?php p($l->t('Fixed?')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($_['recent_violations'] as $violation): ?>
                                    <tr>
                                        <td><?php p($violation['userDisplayName'] ?? $violation['userId']); ?></td>
                                        <td><?php p($violation['type']); ?></td>
                                        <td>
                                            <span class="badge badge--<?php p($violation['severity'] === 'high' ? 'error' : ($violation['severity'] === 'medium' ? 'warning' : 'primary')); ?>">
                                                <?php p($violation['severity']); ?>
                                            </span>
                                        </td>
                                        <td><?php p($violation['date'] ?? '-'); ?></td>
                                        <td>
                                            <?php if ($violation['resolved']): ?>
                                                <span class="badge badge--success"><?php p($l->t('Resolved')); ?></span>
                                            <?php else: ?>
                                                <span class="badge badge--error"><?php p($l->t('Unresolved')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->
