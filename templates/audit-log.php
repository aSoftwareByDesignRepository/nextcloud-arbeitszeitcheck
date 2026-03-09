<?php

declare(strict_types=1);

/**
 * Audit log template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$logs = $_['logs'] ?? [];
$total = $_['total'] ?? 0;
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <div class="section">
            <div class="section-header">
                <h2><?php p($l->t('Activity Log')); ?></h2>
                <p><?php p($l->t('See a record of all actions taken in the time tracking system')); ?></p>
            </div>

            <!-- Filters -->
            <div class="section-content mb-3">
                <div class="flex flex--gap">
                    <input type="text" id="start-date" class="form-input datepicker-input" placeholder="dd.mm.yyyy" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly>
                    <input type="text" id="end-date" class="form-input datepicker-input" placeholder="dd.mm.yyyy" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly>
                    <input type="text" id="user-filter" class="form-input" placeholder="<?php p($l->t('User ID')); ?>">
                    <select id="action-filter" class="form-select">
                        <option value=""><?php p($l->t('All Actions')); ?></option>
                        <option value="create"><?php p($l->t('Create')); ?></option>
                        <option value="update"><?php p($l->t('Update')); ?></option>
                        <option value="delete"><?php p($l->t('Delete')); ?></option>
                    </select>
                    <button type="button" id="apply-filters" class="btn btn--primary">
                        <?php p($l->t('Apply Filters')); ?>
                    </button>
                    <button type="button" id="export-logs" class="btn btn--secondary">
                        <?php p($l->t('Export')); ?>
                    </button>
                </div>
            </div>

            <!-- Audit Log Table -->
            <div class="table-responsive" role="region" aria-label="<?php p($l->t('Audit log')); ?>">
                <table class="table" id="audit-log-table" role="table" aria-label="<?php p($l->t('Audit log')); ?>">
                    <thead>
                        <tr>
                            <th scope="col"><?php p($l->t('Date and Time')); ?></th>
                            <th scope="col"><?php p($l->t('Employee')); ?></th>
                            <th scope="col"><?php p($l->t('What They Did')); ?></th>
                            <th scope="col"><?php p($l->t('What Was Changed')); ?></th>
                            <th scope="col"><?php p($l->t('Who Did It')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="audit-log-tbody">
                        <?php if (empty($logs)): ?>
                            <tr>
                                <td colspan="5" class="text-center">
                                    <div class="empty-state">
                                        <h3 class="empty-state__title"><?php p($l->t('No activity found')); ?></h3>
                                        <p class="empty-state__description">
                                            <?php p($l->t('No activity was recorded for the selected time period.')); ?>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (($logs ?? []) as $log): ?>
                                <tr>
                                    <td><?php p($log['createdAt'] ?? '-'); ?></td>
                                    <td><?php p($log['userDisplayName'] ?? $log['userId']); ?></td>
                                    <td><?php p($log['action']); ?></td>
                                    <td><?php p($log['entityType']); ?></td>
                                    <td><?php p($log['performedByDisplayName'] ?? $log['performedBy'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="pagination-info">
                <p><?php p($l->t('Showing %d of %d entries', [count($logs ?? []), $total ?? 0])); ?></p>
            </div>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->
