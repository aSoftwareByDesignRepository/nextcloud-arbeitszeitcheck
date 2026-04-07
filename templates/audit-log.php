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
$startDate = $_['startDate'] ?? '';
$endDate = $_['endDate'] ?? '';
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content" class="audit-log-page">
    <div id="app-content-wrapper">
        <main class="audit-log-main" role="main">
            <header class="audit-log-header" role="banner">
                <h1 class="audit-log-title"><?php p($l->t('Activity log')); ?></h1>
                <p class="audit-log-subtitle"><?php p($l->t('Log of all actions in the time tracking system (who changed what and when).')); ?></p>
            </header>

            <section class="audit-log-filters" aria-labelledby="audit-filters-heading">
                <h2 id="audit-filters-heading" class="visually-hidden"><?php p($l->t('Filter options')); ?></h2>
                <div class="audit-log-filters__grid">
                    <div class="audit-log-filters__field">
                        <label for="start-date" class="audit-log-filters__label"><?php p($l->t('From date')); ?></label>
                        <input type="text" id="start-date" class="form-input datepicker-input audit-log-filters__input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly value="<?php p($startDate); ?>" aria-label="<?php p($l->t('Start date for filter')); ?>">
                    </div>
                    <div class="audit-log-filters__field">
                        <label for="end-date" class="audit-log-filters__label"><?php p($l->t('To date')); ?></label>
                        <input type="text" id="end-date" class="form-input datepicker-input audit-log-filters__input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly value="<?php p($endDate); ?>" aria-label="<?php p($l->t('End date for filter')); ?>">
                    </div>
                    <div class="audit-log-filters__field">
                        <label for="user-filter" class="audit-log-filters__label"><?php p($l->t('Employee or user ID')); ?></label>
                        <input type="text" id="user-filter" class="form-input audit-log-filters__input" placeholder="<?php p($l->t('Filter by user')); ?>" autocomplete="off" aria-label="<?php p($l->t('Filter by user')); ?>">
                    </div>
                    <div class="audit-log-filters__field">
                        <label for="action-filter" class="audit-log-filters__label"><?php p($l->t('Action type (simplified)')); ?></label>
                        <select id="action-filter" class="form-select audit-log-filters__input" aria-label="<?php p($l->t('Filter by action type')); ?>">
                            <option value=""><?php p($l->t('All actions')); ?></option>
                            <option value="create"><?php p($l->t('Create')); ?></option>
                            <option value="update"><?php p($l->t('Update')); ?></option>
                            <option value="delete"><?php p($l->t('Delete')); ?></option>
                        </select>
                    </div>
                    <div class="audit-log-filters__actions">
                        <button type="button" id="apply-filters" class="btn btn--primary" aria-label="<?php p($l->t('Apply filters and refresh list')); ?>">
                            <?php p($l->t('Apply filters')); ?>
                        </button>
                        <button type="button" id="export-logs" class="btn btn--secondary" aria-label="<?php p($l->t('Export activity log as CSV')); ?>">
                            <?php p($l->t('Export')); ?>
                        </button>
                    </div>
                </div>
            </section>

            <section class="audit-log-table-section" aria-labelledby="audit-table-heading">
                <h2 id="audit-table-heading" class="visually-hidden"><?php p($l->t('Activity log entries')); ?></h2>
                <div class="table-responsive" role="region" aria-label="<?php p($l->t('Activity log')); ?>">
                    <table class="table audit-log-table" id="audit-log-table" role="table" aria-label="<?php p($l->t('Activity log')); ?>">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Date and time')); ?></th>
                                <th scope="col"><?php p($l->t('Employee')); ?></th>
                                <th scope="col"><?php p($l->t('Action')); ?></th>
                                <th scope="col"><?php p($l->t('What was changed')); ?></th>
                                <th scope="col"><?php p($l->t('Who did it')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="audit-log-tbody">
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center audit-log-empty">
                                        <div class="empty-state">
                                            <h3 class="empty-state__title"><?php p($l->t('No activities found')); ?></h3>
                                            <p class="empty-state__description">
                                                <?php p($l->t('No activities were logged for the selected period.')); ?>
                                            </p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach (($logs ?? []) as $log): ?>
                                <tr>
                                    <td data-label="<?php p($l->t('Date and time')); ?>"><?php p($log['createdAt'] ?? '-'); ?></td>
                                    <td data-label="<?php p($l->t('Employee')); ?>"><?php p($log['userDisplayName'] ?? $log['userId']); ?></td>
                                    <td data-label="<?php p($l->t('Action')); ?>"><?php p($log['action']); ?></td>
                                    <td data-label="<?php p($l->t('What was changed')); ?>"><?php p($log['entityType']); ?></td>
                                    <td data-label="<?php p($l->t('Who did it')); ?>"><?php p($log['performedByDisplayName'] ?? $log['performedBy'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="audit-log-pagination" aria-live="polite">
                    <?php p($l->t('Showing %1$d of %2$d entries', [count($logs ?? []), $total ?? 0])); ?>
                </p>
            </section>
        </main>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->

<?php
$auditLogViewerL10n = [
	'Loading…' => $l->t('Loading…'),
	'Error loading audit logs' => $l->t('Error loading audit logs'),
	'Failed to load audit logs. Please try again.' => $l->t('Failed to load audit logs. Please try again.'),
	'No audit log entries found' => $l->t('No audit log entries found'),
];
?>
<script nonce="<?php p($_['cspNonce'] ?? ''); ?>">
window.ArbeitszeitCheck = window.ArbeitszeitCheck || {};
window.ArbeitszeitCheck.auditLogViewerL10n = <?php echo json_encode($auditLogViewerL10n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
</script>
