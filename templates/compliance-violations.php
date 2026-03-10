<?php

declare(strict_types=1);

/**
 * Compliance violations template for arbeitszeitcheck app
 *
 * @copyright Copyright (c) 2024, Nextcloud GmbH
 * @license AGPL-3.0-or-later
 */


/** @var array $_ */
/** @var \OCP\IL10N $l */
$l = $_['l'] ?? \OCP\Util::getL10N('arbeitszeitcheck');

$violations = $_['violations'] ?? [];
$total = $_['total'] ?? 0;
?>

<?php include __DIR__ . '/common/navigation.php'; ?>

<div id="app-content">
    <div id="app-content-wrapper">
        <?php include __DIR__ . '/common/compliance-tabs.php'; ?>
        <div class="section">
            <div class="section-header">
                <h2><?php p($l->t('Compliance Violations')); ?></h2>
                <p><?php p($l->t('See when working time rules were not followed and what needs to be fixed')); ?></p>
            </div>

            <!-- Filters -->
            <div class="section-content mb-3">
                <div class="flex flex--gap">
                    <input type="text" id="start-date" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly>
                    <input type="text" id="end-date" class="form-input datepicker-input" placeholder="<?php p($l->t('dd.mm.yyyy')); ?>" pattern="\d{2}\.\d{2}\.\d{4}" maxlength="10" readonly>
                    <select id="severity-filter" class="form-select">
                        <option value=""><?php p($l->t('All Severities')); ?></option>
                        <option value="low"><?php p($l->t('Low')); ?></option>
                        <option value="medium"><?php p($l->t('Medium')); ?></option>
                        <option value="high"><?php p($l->t('High')); ?></option>
                    </select>
                    <button type="button" id="apply-filters" class="btn btn--primary">
                        <?php p($l->t('Apply Filters')); ?>
                    </button>
                </div>
            </div>

            <!-- Violations Table -->
            <div class="table-responsive" role="region" aria-label="<?php p($l->t('Compliance violations list')); ?>">
                <table class="table" id="violations-table" role="table" aria-label="<?php p($l->t('Compliance violations list')); ?>">
                    <thead>
                        <tr>
                            <th scope="col"><?php p($l->t('Problem Type')); ?></th>
                            <th scope="col"><?php p($l->t('How Serious')); ?></th>
                            <th scope="col"><?php p($l->t('Date')); ?></th>
                            <th scope="col"><?php p($l->t('What Happened')); ?></th>
                            <th scope="col"><?php p($l->t('Fixed?')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="violations-tbody">
                        <?php if (empty($violations)): ?>
                            <tr>
                                <td colspan="5" class="empty-state">
                                    <div class="empty-state">
                                        <h3 class="empty-state__title"><?php p($l->t('No problems found')); ?></h3>
                                        <p class="empty-state__description">
                                            <?php p($l->t('Great! No working time rule violations were found for the selected time period.')); ?>
                                        </p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach (($violations ?? []) as $violation): ?>
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
                                    <td><?php p($typeLabel); ?></td>
                                    <td>
                                        <span class="badge badge--<?php p($severityBadge); ?>">
                                            <?php p($severityLabel); ?>
                                        </span>
                                    </td>
                                    <td><?php p($violation['date'] ?? '-'); ?></td>
                                    <td><?php p($violation['description'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($violation['resolved']): ?>
                                            <span class="badge badge--success"><?php p($l->t('Resolved')); ?></span>
                                        <?php else: ?>
                                            <span class="badge badge--error"><?php p($l->t('Unresolved')); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</div><!-- /#arbeitszeitcheck-app -->
