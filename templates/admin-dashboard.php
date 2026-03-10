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

            <!-- Quick admin actions: make core HR tasks obvious -->
            <div class="section-content admin-quick-actions">
                <div class="card admin-card">
                    <div class="card-header">
                        <h3 class="card-title"><?php p($l->t('Employees & Work Schedules')); ?></h3>
                    </div>
                    <div class="card-body">
                        <p><?php p($l->t('Assign working time models and set vacation days per year for each employee.')); ?></p>
                    </div>
                    <div class="card-actions">
                        <a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.admin.users')); ?>"
                           class="btn btn--primary"
                           aria-label="<?php p($l->t('Open employee management to assign working time models and vacation entitlements')); ?>">
                            <?php p($l->t('Manage Employees')); ?>
                        </a>
                        <a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.admin.workingTimeModels')); ?>"
                           class="btn btn--secondary"
                           aria-label="<?php p($l->t('Open working time models to define standard schedules')); ?>">
                            <?php p($l->t('Working Time Models')); ?>
                        </a>
                    </div>
                </div>

                <div class="card admin-card">
                    <div class="card-header">
                        <h3 class="card-title"><?php p($l->t('Organization & Teams')); ?></h3>
                    </div>
                    <div class="card-body">
                        <p><?php p($l->t('Configure teams and managers for approvals and compliance responsibilities.')); ?></p>
                    </div>
                    <div class="card-actions">
                        <a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.admin.teams')); ?>"
                           class="btn btn--secondary"
                           aria-label="<?php p($l->t('Open organization settings to configure teams and hierarchy')); ?>">
                            <?php p($l->t('Open Organization')); ?>
                        </a>
                    </div>
                </div>

                <div class="card admin-card">
                    <div class="card-header">
                        <h3 class="card-title"><?php p($l->t('Compliance, Settings & Audit')); ?></h3>
                    </div>
                    <div class="card-body">
                        <p><?php p($l->t('Adjust global rules, view the activity log, and configure app settings.')); ?></p>
                    </div>
                    <div class="card-actions">
                        <a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.admin.auditLog')); ?>"
                           class="btn btn--secondary"
                           aria-label="<?php p($l->t('Open activity log to see all actions in the system')); ?>">
                            <?php p($l->t('Activity Log')); ?>
                        </a>
                        <a href="<?php p(\OCP\Server::get(\OCP\IURLGenerator::class)->linkToRoute('arbeitszeitcheck.admin.settings')); ?>"
                           class="btn btn--secondary"
                           aria-label="<?php p($l->t('Open admin settings to configure compliance and app behaviour')); ?>">
                            <?php p($l->t('Admin Settings')); ?>
                        </a>
                    </div>
                </div>
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
                                <?php foreach (($_['recent_violations'] ?? []) as $violation): ?>
                                    <?php
                                    $typeKey = $violation['type'] ?? '';
                                    $typeLabel = match ($typeKey) {
                                        'missing_break' => $l->t('Missing break'),
                                        'excessive_working_hours' => $l->t('Excessive working hours'),
                                        'insufficient_rest_period' => $l->t('Insufficient rest period'),
                                        'daily_hours_limit_exceeded' => $l->t('Daily hours limit exceeded'),
                                        'weekly_hours_limit_exceeded' => $l->t('Weekly hours limit exceeded'),
                                        'night_work' => $l->t('Night work'),
                                        'sunday_work' => $l->t('Sunday work'),
                                        'holiday_work' => $l->t('Holiday work'),
                                        default => $typeKey,
                                    };
                                    $severityKey = $violation['severity'] ?? '';
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
                                        <td><?php p($violation['userDisplayName'] ?? $violation['userId']); ?></td>
                                        <td><?php p($typeLabel); ?></td>
                                        <td>
                                            <span class="badge badge--<?php p($severityBadge); ?>">
                                                <?php p($severityLabel); ?>
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
