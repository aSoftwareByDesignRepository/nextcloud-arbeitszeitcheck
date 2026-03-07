<?php

declare(strict_types=1);

/**
 * Manager dashboard template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$teamStats = $_['teamStats'] ?? [];
$teamMembers = $_['teamMembers'] ?? [];
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content" class="manager-dashboard">
    <div id="app-content-wrapper">
        <div class="section manager-dashboard__content">
            <header class="manager-dashboard__header section-header">
                <h1 class="manager-dashboard__title"><?php p($l->t('Manager Dashboard')); ?></h1>
                <p class="manager-dashboard__subtitle"><?php p($l->t('See how your team is doing with time tracking and check for any problems')); ?></p>
            </header>

            <!-- Team Statistics -->
            <section class="manager-dashboard__stats section" aria-labelledby="stats-heading">
                <h2 id="stats-heading" class="visually-hidden"><?php p($l->t('Team statistics')); ?></h2>
                <div class="stats-grid manager-stats-grid">
                    <div class="stat-card manager-stat-card">
                        <span class="stat-number" aria-hidden="true"><?php p($teamStats['total_members'] ?? 0); ?></span>
                        <span class="stat-label"><?php p($l->t('Team Members')); ?></span>
                    </div>
                    <div class="stat-card manager-stat-card">
                        <span class="stat-number" aria-hidden="true"><?php p($teamStats['active_today'] ?? 0); ?></span>
                        <span class="stat-label"><?php p($l->t('Active Today')); ?></span>
                    </div>
                    <div class="stat-card manager-stat-card">
                        <span class="stat-number" aria-hidden="true"><?php p(round($teamStats['total_hours_today'] ?? 0, 1)); ?>h</span>
                        <span class="stat-label"><?php p($l->t('Hours Today')); ?></span>
                    </div>
                    <div class="stat-card manager-stat-card">
                        <span class="stat-number" aria-hidden="true"><?php p($teamStats['pending_absences'] ?? 0); ?></span>
                        <span class="stat-label"><?php p($l->t('Pending Absences')); ?></span>
                    </div>
                </div>
            </section>

            <!-- Pending Approvals: Absences & Time Entry Corrections -->
            <section class="manager-dashboard__approvals section section--pending-approvals" id="pending-approvals-section" aria-labelledby="pending-approvals-title">
                <div class="section-header">
                    <h2 id="pending-approvals-title"><?php p($l->t('Pending approvals')); ?></h2>
                    <p class="section__desc"><?php p($l->t('Review and approve or reject absence requests and time entry corrections from your team.')); ?></p>
                </div>
                <div class="pending-approvals-tabs" role="tablist" aria-label="<?php p($l->t('Filter pending approvals by type')); ?>">
                    <button type="button" class="pending-approvals-tab pending-approvals-tab--active" role="tab" aria-selected="true" aria-controls="pending-absences-panel" id="tab-absences"><?php p($l->t('Absences')); ?></button>
                    <button type="button" class="pending-approvals-tab" role="tab" aria-selected="false" aria-controls="pending-time-entries-panel" id="tab-time-entries"><?php p($l->t('Time entry corrections')); ?></button>
                </div>
                <div id="pending-absences-panel" class="pending-approvals-panel" role="tabpanel" aria-labelledby="tab-absences">
                    <div id="pending-approvals-list" class="pending-approvals-list" role="region" aria-live="polite" aria-label="<?php p($l->t('List of pending absence requests')); ?>">
                        <p class="pending-approvals-loading" id="pending-approvals-loading" aria-hidden="false"><?php p($l->t('Loading…')); ?></p>
                        <div id="pending-approvals-items" class="pending-approvals-items" aria-hidden="true"></div>
                        <p class="pending-approvals-empty visually-hidden" id="pending-approvals-empty"><?php p($l->t('No pending absence requests.')); ?></p>
                    </div>
                </div>
                <div id="pending-time-entries-panel" class="pending-approvals-panel pending-approvals-panel--hidden" role="tabpanel" aria-labelledby="tab-time-entries" aria-hidden="true">
                    <div id="pending-time-entries-list" class="pending-approvals-list" role="region" aria-live="polite" aria-label="<?php p($l->t('List of pending time entry corrections')); ?>">
                        <p class="pending-approvals-loading" id="pending-time-entries-loading" aria-hidden="true"><?php p($l->t('Loading…')); ?></p>
                        <div id="pending-time-entries-items" class="pending-approvals-items" aria-hidden="true"></div>
                        <p class="pending-approvals-empty visually-hidden" id="pending-time-entries-empty"><?php p($l->t('No pending time entry corrections.')); ?></p>
                    </div>
                </div>
            </section>

            <!-- Team Compliance Overview -->
            <section class="manager-dashboard__compliance section" id="team-compliance-section" aria-labelledby="team-compliance-title">
                <div class="section-header">
                    <h2 id="team-compliance-title"><?php p($l->t('Team compliance')); ?></h2>
                    <p class="section__desc"><?php p($l->t('Overview of working time compliance across your team.')); ?></p>
                </div>
                <div id="team-compliance-content" class="team-compliance-content" role="region" aria-live="polite">
                    <p class="team-compliance-loading" id="team-compliance-loading" aria-hidden="false"><?php p($l->t('Loading…')); ?></p>
                    <div id="team-compliance-summary" class="team-compliance-summary visually-hidden" aria-hidden="true"></div>
                </div>
            </section>

            <!-- Team Members -->
            <section class="manager-dashboard__team section" aria-labelledby="team-members-title">
                <div class="section-header">
                    <h2 id="team-members-title"><?php p($l->t('Team Members')); ?></h2>
                </div>

                <?php if (empty($teamMembers)): ?>
                    <div class="empty-state">
                        <p><?php p($l->t('No team members found')); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive" role="region" aria-label="<?php p($l->t('Team members overview')); ?>">
                        <table class="table" role="table" aria-label="<?php p($l->t('Team members overview')); ?>">
                            <thead>
                                <tr>
                                    <th scope="col"><?php p($l->t('Name')); ?></th>
                                    <th scope="col"><?php p($l->t('Hours Today')); ?></th>
                                    <th scope="col"><?php p($l->t('Status')); ?></th>
                                    <th scope="col"><?php p($l->t('Pending Absences')); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teamMembers as $member): ?>
                                    <tr>
                                        <td><?php p($member['displayName']); ?></td>
                                        <td><?php p(round($member['todayHours'], 2)); ?>h</td>
                                        <td>
                                            <?php
                                            $statusLabels = [
                                                'active' => $l->t('Clocked In'),
                                                'break' => $l->t('On Break'),
                                                'clocked_out' => $l->t('Clocked Out')
                                            ];
                                            $statusLabel = $statusLabels[$member['status']] ?? $member['status'];
                                            ?>
                                            <span class="badge badge--primary"><?php p($statusLabel); ?></span>
                                        </td>
                                        <td><?php p($member['pendingAbsences']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->
