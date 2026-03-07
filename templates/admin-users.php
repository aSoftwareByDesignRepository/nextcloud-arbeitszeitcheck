<?php

declare(strict_types=1);

/**
 * Admin users template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$users = $_['users'] ?? [];
$total = $_['total'] ?? 0;
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <div class="section">
            <div class="section-header">
                <h2><?php p($l->t('Manage Employees')); ?></h2>
                <p><?php p($l->t('Add employees to time tracking and set their work schedules')); ?></p>
            </div>

            <!-- Search and Filters -->
            <div class="section-content">
                <div class="flex flex--between flex--gap mb-3">
                    <input type="text" id="user-search" class="form-input" 
                        placeholder="<?php p($l->t('Search users...')); ?>">
                    <button type="button" id="refresh-users" class="btn btn--secondary">
                        <?php p($l->t('Refresh')); ?>
                    </button>
                </div>

                <!-- Users Table -->
                <div class="table-responsive" role="region" aria-label="<?php p($l->t('Employees list')); ?>">
                    <table class="table" id="users-table" role="table" aria-label="<?php p($l->t('Employees list')); ?>">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Employee Name')); ?></th>
                                <th scope="col"><?php p($l->t('Email Address')); ?></th>
                                <th scope="col"><?php p($l->t('Work Schedule')); ?></th>
                                <th scope="col"><?php p($l->t('Status')); ?></th>
                                <th scope="col"><?php p($l->t('Actions')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="users-tbody">
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="5" class="text-center">
                                        <?php p($l->t('No users found')); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                    <tr data-user-id="<?php p($user['userId']); ?>">
                                        <td><?php p($user['displayName']); ?></td>
                                        <td><?php p($user['email'] ?? '-'); ?></td>
                                        <td>
                                            <?php if ($user['workingTimeModel']): ?>
                                                <?php p($user['workingTimeModel']['name']); ?>
                                            <?php else: ?>
                                                <span class="text-muted"><?php p($l->t('Not assigned')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($user['enabled']): ?>
                                                <span class="badge badge--success"><?php p($l->t('Enabled')); ?></span>
                                            <?php else: ?>
                                                <span class="badge badge--error"><?php p($l->t('Disabled')); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button type="button" 
                                                    class="btn btn--sm btn--secondary" 
                                                    data-action="edit-user" 
                                                    data-user-id="<?php p($user['userId']); ?>"
                                                    aria-label="<?php p($l->t('Edit this employee\'s work schedule')); ?>"
                                                    title="<?php p($l->t('Click to change this employee\'s work schedule or other settings')); ?>">
                                                <?php p($l->t('Edit')); ?>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="pagination-info">
                    <p><?php p($l->t('Showing %d of %d users', [count($users), $total])); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<!-- Initialize JavaScript -->
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
    window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
    window.ArbeitszeitCheck.l10n = window.ArbeitszeitCheck.l10n || {};
    window.ArbeitszeitCheck.l10n.editUser = <?php echo json_encode($l->t('Edit User'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.save = <?php echo json_encode($l->t('Save'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.cancel = <?php echo json_encode($l->t('Cancel'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.workingTimeModel = <?php echo json_encode($l->t('Working Time Model'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.vacationDaysPerYear = <?php echo json_encode($l->t('Vacation Days Per Year'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.startDate = <?php echo json_encode($l->t('Start Date'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.endDate = <?php echo json_encode($l->t('End Date (Optional)'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.noModel = <?php echo json_encode($l->t('No Model Assigned'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.selectWorkScheduleHelp = <?php echo json_encode($l->t('Select a work schedule to assign to this employee'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.vacationDaysHelp = <?php echo json_encode($l->t('Number of vacation days per year (standard in Germany: 25 days)'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.endDateHelp = <?php echo json_encode($l->t('Leave empty if the assignment has no end date'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.userUpdated = <?php echo json_encode($l->t('User updated successfully'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.ArbeitszeitCheck.l10n.error = <?php echo json_encode($l->t('An error occurred'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>
