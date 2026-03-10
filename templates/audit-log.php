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
                <h1 class="audit-log-title"><?php p($l->t('Activity Log')); ?></h1>
                <p class="audit-log-subtitle"><?php p($l->t('See a record of all actions taken in the time tracking system')); ?></p>
            </header>

            <section class="audit-log-filters" aria-labelledby="audit-filters-heading">
                <h2 id="audit-filters-heading" class="visually-hidden"><?php p($l->t('Filter options')); ?></h2>
                <div class="audit-log-filters__grid">
                    <div class="audit-log-filters__field">
                        <label for="start-date" class="audit-log-filters__label"><?php p($l->t('From date')); ?></label>
                        <input type="text" id="start-date" class="form-input datepicker-input audit-log-filters__input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly value="<?php p($startDate); ?>">
                    </div>
                    <div class="audit-log-filters__field">
                        <label for="end-date" class="audit-log-filters__label"><?php p($l->t('To date')); ?></label>
                        <input type="text" id="end-date" class="form-input datepicker-input audit-log-filters__input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly value="<?php p($endDate); ?>">
                    </div>
                    <div class="audit-log-filters__field">
                        <label for="user-filter" class="audit-log-filters__label"><?php p($l->t('Employee or user ID')); ?></label>
                        <input type="text" id="user-filter" class="form-input audit-log-filters__input" placeholder="<?php p($l->t('Filter by user')); ?>" autocomplete="off">
                    </div>
                    <div class="audit-log-filters__field">
                        <label for="action-filter" class="audit-log-filters__label"><?php p($l->t('Action type')); ?></label>
                        <select id="action-filter" class="form-select audit-log-filters__input">
                            <option value=""><?php p($l->t('All Actions')); ?></option>
                            <option value="create"><?php p($l->t('Create')); ?></option>
                            <option value="update"><?php p($l->t('Update')); ?></option>
                            <option value="delete"><?php p($l->t('Delete')); ?></option>
                        </select>
                    </div>
                    <div class="audit-log-filters__actions">
                        <button type="button" id="apply-filters" class="btn btn--primary" aria-label="<?php p($l->t('Apply filters and refresh list')); ?>">
                            <?php p($l->t('Apply Filters')); ?>
                        </button>
                        <button type="button" id="export-logs" class="btn btn--secondary" aria-label="<?php p($l->t('Export audit log as CSV')); ?>">
                            <?php p($l->t('Export')); ?>
                        </button>
                    </div>
                </div>
            </section>

            <section class="audit-log-table-section" aria-labelledby="audit-table-heading">
                <h2 id="audit-table-heading" class="visually-hidden"><?php p($l->t('Audit log entries')); ?></h2>
                <div class="table-responsive" role="region" aria-label="<?php p($l->t('Audit log')); ?>">
                    <table class="table audit-log-table" id="audit-log-table" role="table" aria-label="<?php p($l->t('Audit log')); ?>">
                        <thead>
                            <tr>
                                <th scope="col"><?php p($l->t('Date and Time')); ?></th>
                                <th scope="col"><?php p($l->t('Employee')); ?></th>
                                <th scope="col"><?php p($l->t('Action')); ?></th>
                                <th scope="col"><?php p($l->t('What Was Changed')); ?></th>
                                <th scope="col"><?php p($l->t('Who Did It')); ?></th>
                            </tr>
                        </thead>
                        <tbody id="audit-log-tbody">
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="5" class="text-center audit-log-empty">
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
                                    <td data-label="<?php p($l->t('Date and Time')); ?>"><?php p($log['createdAt'] ?? '-'); ?></td>
                                    <td data-label="<?php p($l->t('Employee')); ?>"><?php p($log['userDisplayName'] ?? $log['userId']); ?></td>
                                    <td data-label="<?php p($l->t('Action')); ?>"><?php p($log['action']); ?></td>
                                    <td data-label="<?php p($l->t('What Was Changed')); ?>"><?php p($log['entityType']); ?></td>
                                    <td data-label="<?php p($l->t('Who Did It')); ?>"><?php p($log['performedByDisplayName'] ?? $log['performedBy'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <p class="audit-log-pagination" aria-live="polite">
                    <?php p($l->t('Showing %d of %d entries', [count($logs ?? []), $total ?? 0])); ?>
                </p>
            </section>
        </main>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->
